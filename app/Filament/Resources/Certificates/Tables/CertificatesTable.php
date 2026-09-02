<?php

namespace App\Filament\Resources\Certificates\Tables;

use App\Jobs\GenerateCertificate;
use App\Models\Certificate;
use App\Models\User;
use App\Services\BerkasPdfSertifikat;
use App\Services\CertificateExcelExporter;
use App\Services\CetakUlangSertifikat;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            ])
            ->toolbarActions([
                /*
                  Cetak ulang PDF sertifikat TERPILIH pakai tanda tangan terbaru.

                  ## Kenapa perlu dipilih satu-satu, bukan tombol "semua"

                  Yang sudah ada cuma `sertifikat:bangun-ulang --render-ulang-pdf`:
                  baris perintah, dan semua-atau-tidak-sama-sekali. Admin yang cuma
                  mau membetulkan lima sertifikat kepaksa mencetak ulang ratusan,
                  dan tiap berkas yang ditulis ulang itu berkas yang berubah tanpa
                  ada yang memintanya.

                  ## Kenapa dia ada di toolbar, bukan per baris

                  Kasus nyatanya jamak: satu gambar tanda tangan diganti, lalu
                  sekumpulan sertifikat menyusul. Sebagai aksi per baris, admin
                  mengulang alur konfirmasi yang sama sepuluh kali dan berhenti di
                  tengah.

                  Penjaganya — termasuk penolakan waktu penandatangannya sudah ganti
                  orang — ada di [\App\Services\CetakUlangSertifikat], bukan di
                  sini. Yang di sini cuma memilih dan melaporkan.
                */
                BulkAction::make('cetak-ulang-pdf')
                    ->label('Cetak ulang PDF')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Cetak ulang PDF sertifikat terpilih')
                    ->modalDescription(
                        'Lembarnya dirender ulang dari data beku yang sama, pakai gambar tanda '
                        .'tangan, logo, dan kop yang BERLAKU SEKARANG. Angka, nomor, tanggal, dan '
                        .'nama penandatangannya nggak berubah sama sekali. Berkas PDF yang lama '
                        .'ditimpa dan nggak bisa dibalikin.'
                    )
                    ->modalSubmitActionLabel('Cetak ulang')
                    ->action(function (Collection $records): void {
                        $hasil = app(CetakUlangSertifikat::class)->jalankan($records);

                        $jumlahBerhasil = count($hasil['berhasil']);
                        $ditolak = $hasil['ditolak'];

                        if ($jumlahBerhasil > 0) {
                            Notification::make()
                                ->success()
                                ->title("{$jumlahBerhasil} sertifikat dicetak ulang.")
                                ->body('Unduh ulang PDF-nya buat lihat hasilnya.')
                                ->send();
                        }

                        if ($ditolak === []) {
                            return;
                        }

                        // Yang ditolak DISEBUT satu per satu berikut alasannya.
                        // "3 gagal" tanpa nomornya bikin admin mencetak ulang
                        // semuanya lagi buat menebak yang mana — persis yang
                        // ingin dihindari fitur ini.
                        $rincian = collect($ditolak)
                            ->map(fn (array $baris): string => "• {$baris['nomor']} — {$baris['alasan']}")
                            ->implode("\n");

                        Notification::make()
                            ->warning()
                            ->title(count($ditolak).' sertifikat dilewat')
                            ->body($rincian)
                            ->persistent()
                            ->send();
                    }),
            ]);
    }
}
