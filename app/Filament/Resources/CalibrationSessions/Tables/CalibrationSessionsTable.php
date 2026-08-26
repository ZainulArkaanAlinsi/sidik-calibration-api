<?php

namespace App\Filament\Resources\CalibrationSessions\Tables;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\CalibrationValidator;
use App\Services\PerhitunganBuilder;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class CalibrationSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('nomor_sesi')
                    ->label('Nomor sesi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('equipment.nama_alat')
                    ->label('Alat')
                    ->searchable(),
                TextColumn::make('teknisi.name')
                    ->label('Teknisi')
                    ->searchable(),
                TextColumn::make('tanggal_kalibrasi')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CalibrationSession::STATUS_DISETUJUI => 'success',
                        CalibrationSession::STATUS_MENUNGGU_APPROVAL => 'warning',
                        CalibrationSession::STATUS_PERLU_REVISI => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('keputusan')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                TextColumn::make('certificate.nomor')
                    ->label('Sertifikat')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    CalibrationSession::STATUS_DRAFT => 'Draft',
                    CalibrationSession::STATUS_MENUNGGU_APPROVAL => 'Menunggu approval',
                    CalibrationSession::STATUS_DISETUJUI => 'Disetujui',
                    CalibrationSession::STATUS_PERLU_REVISI => 'Perlu revisi',
                ]),
                // Sesi FAIL = alat "tidak laik pakai" — perlu gampang
                // ditemukan lintas status, bukan cuma keliatan badge-nya doang
                // pas udah scroll ke barisnya.
                SelectFilter::make('keputusan')->options([
                    'PASS' => 'PASS',
                    'FAIL' => 'FAIL',
                ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => self::detail($schema)),

                // Lembar PERHITUNGAN — layar utama admin (spesifikasi poin 12A).
                // Dirender dari PerhitunganBuilder yang SAMA dengan yang dipakai
                // API, jadi angka di web & mobile mustahil beda.
                Action::make('perhitungan')
                    ->label('Perhitungan')
                    ->icon('heroicon-o-calculator')
                    ->color('info')
                    ->modalHeading(fn (CalibrationSession $record): string => 'Lembar Perhitungan — '.$record->nomor_sesi)
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (CalibrationSession $record): View => view(
                        'filament.perhitungan',
                        ['perhitungan' => app(PerhitunganBuilder::class)->bangun($record)],
                    )),

                // Periksa → hitung ulang tanpa nyetujuin (spesifikasi poin 11).
                // Admin bisa lihat temuannya dulu sebelum mutusin.
                Action::make('periksa')
                    ->label('Periksa')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->visible(fn (CalibrationSession $record): bool => $record->status === CalibrationSession::STATUS_MENUNGGU_APPROVAL)
                    ->action(function (CalibrationSession $record, CalibrationValidator $validator): void {
                        $hasil = $validator->periksa($record);

                        Notification::make()
                            ->title(self::judulTemuan($hasil))
                            ->body(self::ringkasTemuan($hasil))
                            ->status($hasil['boleh_terbit'] ? ($hasil['valid'] ? 'success' : 'warning') : 'danger')
                            ->persistent()
                            ->send();
                    }),

                // Approve → sesi disetujui + sertifikat digenerate di queue.
                // Nongol cuma buat sesi yang emang lagi nunggu approval.
                //
                // LEWAT `CalibrationValidator`, sama persis kayak jalur API.
                // Dulu tombol ini cuma ngubah status & nembak job — jadi admin
                // yang nyetujuin dari web ngelewatin SELURUH pemeriksaan yang
                // di API nahan penerbitan. Dua pintu ke keputusan yang sama,
                // satu ada penjaganya satu nggak, itu justru yang paling
                // berbahaya: yang lolos diam-diam nggak ketahuan sampai ada
                // yang ngebandingin sertifikatnya.
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CalibrationSession $record): bool => $record->status === CalibrationSession::STATUS_MENUNGGU_APPROVAL)
                    ->schema([
                        Checkbox::make('abaikan_peringatan')
                            ->label('Saya sudah periksa, lanjutkan walau ada peringatan')
                            ->helperText('Cuma berlaku buat peringatan. Temuan fatal tetap nahan penerbitan.'),
                    ])
                    ->modalDescription('Sesi disetujui dan sertifikat langsung diterbitkan. Sesi FAIL pun tetap terbit (hasil "tidak laik pakai").')
                    ->action(function (CalibrationSession $record, array $data, CalibrationValidator $validator): void {
                        // Angka hasil pindai AI yang belum dikonfirmasi manusia
                        // nggak boleh ikut disertifikasi — dicek duluan biar
                        // pesannya langsung nunjuk ke tindakannya.
                        if ($record->rawMeasurements()->where('is_verified', false)->exists()) {
                            Notification::make()
                                ->title('Masih ada pembacaan hasil pindai yang belum diverifikasi.')
                                ->body('Teknisi harus konfirmasi angkanya dulu sebelum sesi ini bisa disetujui.')
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $hasil = $validator->periksa($record);

                        if (! $hasil['boleh_terbit']) {
                            Notification::make()
                                ->title('Nggak bisa disetujui — ada temuan fatal.')
                                ->body(self::ringkasTemuan($hasil))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        if (! $hasil['valid'] && ! ($data['abaikan_peringatan'] ?? false)) {
                            Notification::make()
                                ->title('Hasil hitung ulang beda dari yang tersimpan.')
                                ->body(self::ringkasTemuan($hasil)
                                    ."\n\nKalau memang mau lanjut, centang \"Saya sudah periksa\" lalu setujui lagi.")
                                ->warning()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => CalibrationSession::STATUS_DISETUJUI,
                            'reviewed_by' => User::yangLogin()?->id,
                            'reviewed_at' => now(),
                            'catatan_revisi' => null,
                        ]);
                        GenerateCertificate::dispatch($record->id, User::yangLogin()?->id);
                        Notification::make()->title('Sesi disetujui. Sertifikat sedang diterbitkan.')->success()->send();
                    }),

                // Reject → perlu_revisi + catatan buat teknisi.
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CalibrationSession $record): bool => $record->status === CalibrationSession::STATUS_MENUNGGU_APPROVAL)
                    ->schema([
                        Textarea::make('catatan_revisi')
                            ->label('Catatan revisi')
                            ->required()
                            ->minLength(5)
                            ->helperText('Teknisi perlu tahu apa yang harus dibenerin.'),
                    ])
                    ->action(function (CalibrationSession $record, array $data): void {
                        $record->update([
                            'status' => CalibrationSession::STATUS_PERLU_REVISI,
                            'reviewed_by' => User::yangLogin()?->id,
                            'reviewed_at' => now(),
                            'catatan_revisi' => $data['catatan_revisi'],
                        ]);
                        Notification::make()->title('Sesi dikembalikan ke teknisi buat revisi.')->warning()->send();
                    }),
            ]);
    }

    private static function detail(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan')
                ->columns(2)
                ->schema([
                    TextEntry::make('nomor_sesi')->label('Nomor sesi'),
                    TextEntry::make('equipment.nama_alat')->label('Alat'),
                    TextEntry::make('teknisi.name')->label('Teknisi'),
                    TextEntry::make('standard.nama')->label('Standar acuan'),
                    TextEntry::make('tanggal_kalibrasi')->label('Tanggal')->date(),
                    TextEntry::make('keputusan')->badge()
                        ->color(fn (?string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                    TextEntry::make('catatan_revisi')->label('Catatan revisi')->placeholder('—')->columnSpanFull(),
                ]),

            // Rincian GUM per titik — biar admin approve/reject nggak cuma
            // percaya badge PASS/FAIL doang, tapi bisa liat angka yang
            // ngebentuk keputusannya (mepet atau nggak ke batas toleransi).
            Section::make('Titik Ukur & Ketidakpastian')
                ->schema([
                    RepeatableEntry::make('uncertaintyCalculations')
                        ->label('')
                        ->table([
                            TableColumn::make('Titik'),
                            TableColumn::make('Nilai ukur'),
                            TableColumn::make('Rata-rata'),
                            TableColumn::make('Error'),
                            TableColumn::make('U diperluas'),
                            TableColumn::make('Toleransi'),
                            // Kolom BENTUK, bukan angka. Angkanya sudah ada di
                            // dua kolom sebelumnya; yang ini menjawab
                            // pertanyaan yang beda — ada titik yang mepet batas
                            // nggak — tanpa admin membandingkan sembilan baris
                            // di kepala.
                            TableColumn::make('Posisi'),
                            TableColumn::make('Keputusan'),
                        ])
                        ->schema([
                            TextEntry::make('titik_ke'),
                            TextEntry::make('titik_ukur')->numeric(4),
                            TextEntry::make('rata_rata')->numeric(4),
                            TextEntry::make('error')->numeric(4),
                            TextEntry::make('ketidakpastian_diperluas')
                                ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : '± '.number_format($state, 4)),
                            TextEntry::make('toleransi')
                                ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : '± '.number_format($state, 4)),
                            ViewEntry::make('posisi_toleransi')
                                ->view('filament.pita-toleransi'),
                            TextEntry::make('keputusan')
                                ->badge()
                                ->color(fn (?string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                        ]),
                ])
                ->collapsible(),
        ]);
    }

    /** @param  array<string, mixed>  $hasil */
    private static function judulTemuan(array $hasil): string
    {
        if (! $hasil['boleh_terbit']) {
            return 'Ada temuan fatal — sertifikat nggak bisa terbit.';
        }

        return $hasil['valid']
            ? 'Bersih. Aman disetujui.'
            : 'Ada peringatan — perlu konfirmasi sadar.';
    }

    /**
     * Temuan dirangkum jadi teks notifikasi, diurut dari yang paling berat:
     * yang nahan penerbitan harus kebaca duluan, bukan ketimbun di bawah
     * daftar info.
     *
     * @param  array<string, mixed>  $hasil
     */
    private static function ringkasTemuan(array $hasil): string
    {
        if ($hasil['temuan'] === []) {
            return 'Nggak ada temuan.';
        }

        $urutan = [
            CalibrationValidator::ERROR => '[FATAL]',
            CalibrationValidator::PERINGATAN => '[PERINGATAN]',
            CalibrationValidator::INFO => '[info]',
        ];

        $baris = [];

        foreach ($urutan as $tingkat => $label) {
            foreach ($hasil['temuan'] as $t) {
                if ($t['tingkat'] === $tingkat) {
                    $baris[] = $label.' '.$t['pesan'];
                }
            }
        }

        return implode("\n", $baris);
    }
}
