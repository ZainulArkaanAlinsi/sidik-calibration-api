<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\User;
use App\Services\CertificateSnapshotBuilder;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Data PT yang dicetak di kop sertifikat (nama, alamat, akreditasi) + logo.
 * Satu instalasi = satu organisasi, jadi ini halaman tunggal (bukan resource
 * ber-list): admin ngedit baris organisasinya sendiri, nggak bikin/hapus.
 *
 * Logo diupload ke disk `public` di folder `logos/` — persis path yang dibaca
 * job GenerateCertificate buat naruh logo di kop PDF.
 */
class PengaturanOrganisasi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Organisasi';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Pengaturan Organisasi';

    protected string $view = 'filament.pages.pengaturan-organisasi';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public Organization $record;

    public function mount(): void
    {
        $this->record = Organization::findOrFail(User::yangLogin()?->organization_id);
        $this->form->fill($this->record->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Identitas laboratorium')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nama')
                            ->label('Nama PT / laboratorium')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('alamat')->maxLength(255)->columnSpanFull(),
                        TextInput::make('telepon')->maxLength(50),
                        TextInput::make('email')->email()->maxLength(255),
                    ]),

                Section::make('Akreditasi')
                    ->description('Dicetak di kop sertifikat. Kalau tanggal berakhir kelewat, sertifikat terakreditasi nggak boleh terbit.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('no_akreditasi')
                            ->label('No. akreditasi KAN')
                            ->maxLength(255),
                        TextInput::make('standar_akreditasi')
                            ->label('Standar akreditasi')
                            ->maxLength(255)
                            ->placeholder('SNI ISO/IEC 17025:2017'),
                        DatePicker::make('akreditasi_mulai')->label('Berlaku mulai'),
                        DatePicker::make('akreditasi_berakhir')->label('Berlaku sampai'),
                    ]),

                Section::make('Penerbitan sertifikat')
                    ->description('Dicetak di footer sertifikat. Yang tanda tangan itu penanggung jawab teknis, belum tentu admin yang mencet tombol setujui.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('settings.penandatangan_nama')
                            ->label('Nama penandatangan')
                            ->maxLength(255)
                            ->placeholder('Alex Misramto'),
                        TextInput::make('settings.penandatangan_jabatan')
                            ->label('Jabatan')
                            ->maxLength(255)
                            ->placeholder('Technical Manager'),
                        TextInput::make('settings.kode_dokumen_form')
                            ->label('Kode dokumen form')
                            ->maxLength(255)
                            ->placeholder(CertificateSnapshotBuilder::KODE_DOKUMEN_DEFAULT)
                            ->columnSpanFull(),
                        TextInput::make('settings.'.Organization::KEY_MASA_BERLAKU_BULAN)
                            ->label('Masa berlaku sertifikat (bulan)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->placeholder(Organization::DEFAULT_MASA_BERLAKU_BULAN)
                            ->helperText(
                                'Cuma nilai BAKU. Admin tetap bisa nimpa per sertifikat waktu '
                                .'menyetujui sesi. Dihitung dari tanggal kalibrasi, bukan tanggal terbit.',
                            )
                            ->columnSpanFull(),
                        TextInput::make('settings.'.Organization::KEY_DESIMAL_SERTIFIKAT)
                            ->label('Desimal tabel hasil sertifikat')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(6)
                            ->placeholder('otomatis dari resolusi alat')
                            ->helperText(
                                'Kosongin buat ngikut resolusi alat per sertifikat (0,01 → 2 desimal; '
                                .'0,001 → 3). Isi cuma kalau resolusi di master alat nggak ngikut spek '
                                .'fisiknya — kalau kekecilan, U95% kehilangan angka penting (0,023 '
                                .'kecetak 0,02). Sertifikat yang udah terbit nggak ikut berubah.',
                            )
                            ->columnSpanFull(),
                        Toggle::make('settings.tampilkan_qr_di_pdf')
                            ->label('Cetak QR Code di sertifikat')
                            ->default(true)
                            ->helperText('QR-nya buat verifikasi & unduh cepat oleh pelanggan.'),
                        // Struktur baku sertifikat pH nggak punya baris keputusan.
                        // Buat alat lain yang formatnya nggak dikunci pelanggan,
                        // ini informasi paling penting di dokumennya.
                        Toggle::make('settings.tampilkan_keputusan_di_pdf')
                            ->label('Cetak keputusan PASS/FAIL')
                            ->default(false)
                            ->helperText('Matikan kalau layout sertifikat dikunci pelanggan — struktur baku pH nggak punya baris ini.'),
                    ]),

                Section::make('Logo')
                    ->description('Muncul di kop sertifikat PDF, panel admin, dan halaman verifikasi QR.')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->hiddenLabel()
                            ->image()
                            ->disk('public')
                            ->directory('logos')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->helperText('PNG/JPG, maksimal 2 MB.'),
                    ]),

                Section::make('Tanda tangan')
                    ->description(
                        'Dicetak di atas garis tanda tangan. Posisinya disetel SEKALI di sini '
                        .'dan berlaku buat semua sertifikat — sertifikat yang udah terbit nggak '
                        .'bisa diedit, karena itu dokumen terkendali.',
                    )
                    ->schema([
                        FileUpload::make('tanda_tangan_path')
                            ->label('Gambar tanda tangan')
                            ->image()
                            // Disk `local` (PRIVAT), beda dari logo. Gambar tanda tangan
                            // yang URL-nya bisa diakses siapa pun berarti siapa pun bisa
                            // nempelin ke dokumen palsu.
                            ->disk('arsip')
                            ->directory('tanda-tangan')
                            ->visibility('private')
                            // PNG doang: JPG nggak punya latar transparan, jadi bakal
                            // kecetak sebagai kotak putih yang nutupin garis & nama.
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(2048)
                            ->helperText('PNG dengan latar transparan, maksimal 2 MB. JPG nggak bisa — '
                                .'nggak punya latar transparan, jadi kecetak sebagai kotak putih.'),

                        TextInput::make('settings.'.Organization::KEY_TTD_LEBAR)
                            ->label('Lebar cetak (mm)')
                            ->numeric()
                            ->minValue(Organization::MIN_TTD_LEBAR_MM)
                            ->maxValue(Organization::MAKS_TTD_LEBAR_MM)
                            ->placeholder(Organization::DEFAULT_TTD_LEBAR_MM)
                            ->helperText('Tingginya ngikut, rasionya dijaga.'),

                        TextInput::make('settings.'.Organization::KEY_TTD_GESER_X)
                            ->label('Geser horizontal (mm)')
                            ->numeric()
                            ->minValue(-Organization::MAKS_TTD_GESER_MM)
                            ->maxValue(Organization::MAKS_TTD_GESER_MM)
                            ->placeholder(0)
                            ->helperText('Negatif = ke kiri.'),

                        TextInput::make('settings.'.Organization::KEY_TTD_GESER_Y)
                            ->label('Geser vertikal (mm)')
                            ->numeric()
                            ->minValue(-Organization::MAKS_TTD_GESER_MM)
                            ->maxValue(Organization::MAKS_TTD_GESER_MM)
                            ->placeholder(0)
                            ->helperText('POSITIF = naik.'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * `settings` DIGABUNG, bukan ditimpa.
     *
     * Kolom `settings` itu satu JSON yang dipakai bareng-bareng, tapi form ini cuma
     * nampilin sebagian kuncinya — `reminder_hari_sebelum`, `catatan_ketidakpastian`,
     * dan `catatan_penggandaan` nggak punya field di sini. Nimpa seluruh array bikin
     * ketiganya ilang tiap admin nyimpan halaman ini, dan ilangnya SUNYI: nggak ada
     * error, pengingat jatuh tempo diam-diam balik ke default 30 hari dan catatan
     * sertifikat ilang dari PDF. Ketahuannya baru pas ada yang nyariin.
     *
     * Alasannya sama persis kayak `OrganizationController::updatePosisiTandaTangan()`
     * yang udah hati-hati nge-merge — jalur panel ini yang ketinggalan.
     */
    public function save(): void
    {
        $data = $this->form->getState();

        if (array_key_exists('settings', $data)) {
            $data['settings'] = array_merge(
                $this->record->settings ?? [],
                $data['settings'] ?? [],
            );
        }

        $this->record->update($data);

        Notification::make()
            ->title('Pengaturan organisasi disimpan.')
            ->success()
            ->send();
    }
}
