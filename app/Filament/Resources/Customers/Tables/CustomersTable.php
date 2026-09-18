<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Mail\Pelanggan\UndanganEmail;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeUndangan;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')
                    ->label('Nama pelanggan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_person')
                    ->label('Narahubung')
                    ->searchable(),
                TextColumn::make('telepon')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('equipments_count')
                    ->label('Jumlah alat')
                    ->counts('equipments')
                    ->badge(),
                TextColumn::make('members_count')
                    ->label('Anggota aktif')
                    ->counts([
                        'members' => fn ($kueri) => $kueri->where('status', CustomerMember::STATUS_AKTIF),
                    ])
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'success'),
            ])
            ->recordActions([
                self::undangAnggota(),
                self::cabutAnggota(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Pelanggan yang masih punya alat ditolak di API; di panel,
                    // hapus massal dibiarin tapi FK bakal nahan yang masih kepakai.
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Cabut akses satu anggota pelanggan — SATU-SATUNYA pintunya di panel.
     *
     * ## Kenapa aksi ini harus ada
     *
     * `UserResource` menyaring baris ber-role `pelanggan` keluar dari layar
     * Pengguna, dan itu benar: form di sana memaksa memilih salah satu dari
     * tiga role internal, jadi admin yang cuma mau membetulkan nomor telepon
     * malah mempromosikan akun pelanggan jadi akun lab.
     *
     * Tapi penyaringan itu ikut mencabut satu-satunya jalan yang tersisa buat
     * MENUTUP akses. Kejadian yang harus bisa ditangani hari itu juga: HP PIC
     * utama hilang, atau orangnya keluar kerja, dan lab diminta mencabut
     * aksesnya. Sesudah penyaringan, barisnya tidak ada di daftar Pengguna,
     * menebak URL `/admin/users/{id}/edit` memulangkan 404, dan layar Pelanggan
     * cuma memperlihatkan lencana angka tanpa tautan ke anggotanya. Yang
     * tersisa cuma `PUT /api/users/{user}` lewat alat pengembang — dan di
     * lapangan itu sama saja dengan tidak ada jalan.
     *
     * Lewat `Keanggotaan::nonaktifkan()`, bukan `update()` langsung: di sana
     * sudah ada penjaga PIC utama terakhir DAN pencabutan token + perangkat
     * (REQ-AUTH-09). Menulis statusnya sendiri di sini berarti akun yang
     * "dicabut" tetap bisa memakai token yang sudah beredar di HP-nya.
     */
    private static function cabutAnggota(): Action
    {
        return Action::make('cabutAnggota')
            ->label('Cabut akses anggota')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->modalHeading(fn (Customer $record): string => 'Cabut akses anggota — '.$record->nama)
            ->modalSubmitActionLabel('Cabut akses')
            ->modalDescription(
                'Tokennya ikut dicabut, jadi aplikasi di HP orangnya langsung keluar sendiri. '
                .'Kalau dia masih anggota aktif di perusahaan lain, dia TIDAK ikut ter-logout dari situ.'
            )
            ->visible(fn (Customer $record): bool => $record->members()
                ->where('status', CustomerMember::STATUS_AKTIF)
                ->exists())
            ->schema([
                Select::make('anggota')
                    ->label('Anggota yang dicabut')
                    ->options(fn (Customer $record): array => $record->members()
                        ->where('status', CustomerMember::STATUS_AKTIF)
                        ->with('user')
                        ->get()
                        ->mapWithKeys(fn (CustomerMember $a): array => [
                            $a->getKey() => sprintf(
                                '%s (%s) — %s',
                                $a->user?->name ?? '(akun terhapus)',
                                $a->user?->email ?? '—',
                                $a->peran === CustomerMember::PERAN_PIC_UTAMA ? 'PIC utama' : 'staf',
                            ),
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(function (Customer $record, array $data): void {
                $anggota = CustomerMember::query()
                    ->where('customer_id', $record->getKey())
                    ->whereKey($data['anggota'])
                    ->first();

                if ($anggota === null) {
                    Notification::make()->title('Anggotanya sudah tidak ada.')->warning()->send();

                    return;
                }

                $oleh = User::yangLogin();

                if ($oleh === null) {
                    Notification::make()->title('Sesi panel sudah habis. Masuk lagi.')->danger()->send();

                    return;
                }

                try {
                    app(Keanggotaan::class)->nonaktifkan($anggota, $oleh);
                } catch (AksiPelangganDitolak $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Akses '.($anggota->user?->email ?? 'anggota').' dicabut, tokennya ikut mati.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Undangan anggota pertama sebuah perusahaan — SATU-SATUNYA pintunya.
     *
     * Pendaftaran mandiri sudah ditutup: akun pelanggan cuma lahir dari kode
     * undangan, dan yang boleh menerbitkan kode itu PIC utama perusahaannya
     * sendiri (REQ-ANG-01). Buat perusahaan yang belum punya satu pun anggota
     * aktif, itu berputar: tidak ada PIC utama yang bisa mengundang PIC utama
     * pertama, jadi perusahaannya tidak akan pernah bisa masuk. Aksi ini
     * memutusnya dari sisi lab.
     *
     * ## Kodenya ditampilkan, bukan cuma dikirim
     *
     * `UndanganEmail::kirim()` sengaja menelan kegagalan kirim (kalau dia
     * melempar, admin menekan "undang" lagi dan undangan kedua membatalkan yang
     * pertama). Konsekuensinya: di server yang SMTP-nya belum disetel, admin
     * melihat notifikasi sukses buat email yang tidak ke mana-mana, dan
     * undangannya mati diam-diam. Jadi kodenya ikut ditampilkan supaya bisa
     * dibacakan lewat telepon atau WhatsApp.
     *
     * Itu memang menaruh kode mentah di layar, dan itu ditimbang: yang membuka
     * layar ini sudah admin lab aktif (`User::canAccessPanel`), kodenya
     * terikat ke satu alamat email, dan mati dalam 7 hari.
     */
    private static function undangAnggota(): Action
    {
        return Action::make('undangAnggota')
            ->label('Undang anggota')
            ->icon('heroicon-o-envelope')
            ->color('info')
            ->modalHeading(fn (Customer $record): string => 'Undang anggota — '.$record->nama)
            ->modalSubmitActionLabel('Terbitkan kode')
            ->schema([
                TextInput::make('email')
                    ->label('Email orangnya')
                    ->helperText('Kode undangan cuma berlaku buat alamat ini.')
                    ->email()
                    ->required()
                    ->maxLength(254),
                Select::make('peran')
                    ->label('Peran')
                    ->options([
                        CustomerMember::PERAN_PIC_UTAMA => 'PIC utama — bisa mengundang & menonaktifkan anggota',
                        CustomerMember::PERAN_STAF => 'Staf — lihat & unduh sertifikat saja',
                    ])
                    // Perusahaan yang belum punya anggota aktif butuh PIC utama
                    // dulu; sesudah itu default-nya turun ke staf. Sama persis
                    // dengan aturan REQ-AUTH-04 yang dipakai jalur pengajuan.
                    ->default(fn (Customer $record): string => app(Keanggotaan::class)->peranUntukAnggotaBaru($record))
                    ->required(),
            ])
            ->action(function (Customer $record, array $data): void {
                $email = mb_strtolower(trim((string) $data['email']));

                // Urutan pemeriksaan SENGAJA sama dengan
                // `Api\\Admin\\PelangganKeanggotaanController::undang()`: batas
                // anggota dulu, baru anggota ganda. Dua pintu yang menerbitkan
                // undangan yang sama persis tidak boleh memulangkan alasan
                // penolakan yang berbeda buat keadaan yang sama.
                try {
                    app(Keanggotaan::class)->pastikanMuat($record);
                } catch (AksiPelangganDitolak $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $sudah = CustomerMember::query()
                    ->where('customer_id', $record->getKey())
                    ->where('status', CustomerMember::STATUS_AKTIF)
                    ->whereHas('user', fn ($kueri) => $kueri->where('email', $email))
                    ->exists();

                if ($sudah) {
                    Notification::make()
                        ->title($email.' sudah jadi anggota aktif di sini.')
                        ->warning()
                        ->send();

                    return;
                }

                $hasil = app(KodeUndangan::class)->terbitkan(
                    $record,
                    $email,
                    (string) $data['peran'],
                    User::yangLogin(),
                );

                UndanganEmail::kirim($hasil['undangan'], $hasil['kode'], $record);

                $catatan = 'Sudah dikirim ke '.$email.' dan berlaku '
                    .UndanganPelanggan::BERLAKU_HARI.' hari. Kalau emailnya nggak masuk, '
                    .'kodenya bisa dibacakan langsung — dia cuma jalan buat alamat itu.';

                // Undangannya sah, tapi kalau sakelar modulnya mati orangnya
                // cuma akan ketemu 503 waktu menukarnya — dan dari layar ini
                // tidak ada satu pun gejala yang menjelaskan kenapa.
                if (config('pelanggan.fitur') !== true) {
                    $catatan .= ' CATATAN: FITUR_PELANGGAN masih mati di server ini, '
                        .'jadi kodenya belum bisa ditukar sampai sakelarnya dinyalakan.';
                }

                Notification::make()
                    ->title('Kode undangan: '.$hasil['kode'])
                    ->body($catatan)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
