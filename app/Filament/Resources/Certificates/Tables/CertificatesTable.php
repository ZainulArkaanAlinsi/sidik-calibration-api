<?php

namespace App\Filament\Resources\Certificates\Tables;

use App\Jobs\GenerateCertificate;
use App\Models\Certificate;
use App\Models\User;
use App\Services\BerkasPdfSertifikat;
use App\Services\CertificateExcelExporter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('nomor')
                    ->label('Nomor sertifikat')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('session.nomor_sesi')
                    ->label('Sesi')
                    ->searchable(),
                TextColumn::make('session.equipment.nama_alat')
                    ->label('Alat')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Certificate::STATUS_TERBIT => 'success',
                        Certificate::STATUS_MENUNGGU_GENERATE => 'warning',
                        Certificate::STATUS_GAGAL => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('diterbitkan_pada')
                    ->label('Terbit')
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('berlaku_sampai')
                    ->label('Berlaku sampai')
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Certificate::STATUS_MENUNGGU_GENERATE => 'Menunggu generate',
                    Certificate::STATUS_TERBIT => 'Terbit',
                    Certificate::STATUS_GAGAL => 'Gagal',
                ]),
                // Sama kayak filter "Kadaluarsa" di resource Standards — sertifikat
                // yang masa berlakunya lewat perlu gampang ditemukan biar pelanggan
                // bisa diingetin kalibrasi ulang.
                Filter::make('kadaluarsa')
                    ->label('Kadaluarsa')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('berlaku_sampai')
                        ->whereDate('berlaku_sampai', '<', now()))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),

                // Unduh PDF — cuma buat sertifikat yang beneran udah terbit DAN
                // file-nya masih ada di disk. PDF disimpen di disk `local` (privat),
                // jadi distream lewat sini, bukan URL publik.
                Action::make('download')
                    ->label('Unduh PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    // Berkas yang RAIB nggak lagi menyembunyikan tombolnya.
                    //
                    // Disk arsip Render kehapus tiap deploy, jadi syarat
                    // `exists()` di sini bikin tombol unduh lenyap dari SELURUH
                    // baris sehabis tiap deploy — dan admin tidak punya cara
                    // tahu kenapa. Sekarang yang menentukan cuma status &
                    // `pdf_path`; berkasnya dibangun ulang dari snapshot waktu
                    // diklik. Lihat [\App\Services\BerkasPdfSertifikat].
                    ->visible(fn (Certificate $record): bool => $record->status === Certificate::STATUS_TERBIT
                        && $record->pdf_path)
                    ->action(function (Certificate $record): ?StreamedResponse {
                        $path = app(BerkasPdfSertifikat::class)->pastikanAda($record);

                        // Notifikasi, BUKAN `abort(404)`.
                        //
                        // `abort()` di dalam aksi Filament melempar admin ke
                        // halaman galat mentah dan menelan konteksnya — yang
                        // dia lihat cuma "404", tanpa tahu bahwa masalahnya
                        // sertifikat ini nggak punya snapshot buat dibangun
                        // ulang. Di panel, kegagalan yang bisa ditindaklanjuti
                        // itu notifikasi yang menyebut langkah berikutnya.
                        if ($path === null) {
                            Notification::make()
                                ->danger()
                                ->title('Berkas PDF-nya nggak bisa dibangun ulang')
                                ->body('Sertifikat ini nggak punya data beku (snapshot), jadi '
                                    .'lembarnya nggak bisa dirender dari mana pun. Jalankan '
                                    .'`sertifikat:bangun-ulang` atau terbitkan ulang sesinya.')
                                ->send();

                            return null;
                        }

                        // `nomor` ada slash-nya (CAL/2026/07/0001) — nggak boleh jadi nama file.
                        $namaFile = 'Sertifikat-'.str_replace('/', '-', (string) $record->nomor).'.pdf';

                        return Storage::disk('arsip')->download($path, $namaFile);
                    }),

                // Export Excel (spesifikasi poin 10). Dibikin on demand dari
                // snapshot yang sama dengan PDF, jadi angkanya mustahil beda.
                Action::make('excel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->visible(fn (Certificate $record): bool => $record->status === Certificate::STATUS_TERBIT
                        && filled($record->snapshot))
                    ->action(function (Certificate $record): BinaryFileResponse {
                        $tmp = tempnam(sys_get_temp_dir(), 'sertifikat-').'.xlsx';
                        app(CertificateExcelExporter::class)->satu($record, $tmp);

                        return response()->download($tmp, $record->namaFile('xlsx'))->deleteFileAfterSend();
                    }),

                // Terbitin ulang yang gagal. Job-nya idempoten (updateOrCreate di
                // baris sesi yang sama), jadi aman dipanggil ulang.
                Action::make('retry')
                    ->label('Terbitkan ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Certificate $record): bool => $record->status === Certificate::STATUS_GAGAL)
                    ->requiresConfirmation()
                    ->modalDescription('Coba bikin ulang PDF sertifikat yang tadinya gagal. Statusnya balik ke "menunggu generate" selagi diproses.')
                    ->action(function (Certificate $record): void {
                        $record->update(['status' => Certificate::STATUS_MENUNGGU_GENERATE]);
                        GenerateCertificate::dispatch($record->calibration_session_id, User::yangLogin()?->id);
                        Notification::make()->title('Sertifikat sedang diterbitkan ulang.')->success()->send();
                    }),
            ]);
    }
}
