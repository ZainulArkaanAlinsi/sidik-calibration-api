<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\Room;
use App\Support\Angka;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Diurutin kode, sama kayak `RoomController@index`. Daftar di panel
            // dan daftar di HP jadi urutannya sama, biar admin yang lagi ditelpon
            // teknisi nunjuk baris yang sama.
            ->defaultSort('kode')
            ->columns([
                TextColumn::make('kode')
                    ->label('Kode')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nama')
                    ->label('Nama ruangan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lokasi')
                    ->label('Letak')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                // Rentang syarat ditulis gabungan, bukan empat kolom angka.
                // Yang mau dilihat admin waktu ngecek "ruangan ini syaratnya apa"
                // itu rentangnya, dan empat kolom bikin barisnya kepanjangan
                // sampai nama ruangannya kedorong keluar layar.
                TextColumn::make('syarat_suhu')
                    ->label('Syarat suhu')
                    ->state(fn (Room $record): string => self::rentang($record->suhu_min, $record->suhu_max, '°C'))
                    ->placeholder('—'),
                TextColumn::make('syarat_kelembaban')
                    ->label('Syarat kelembaban')
                    ->state(fn (Room $record): string => self::rentang($record->kelembaban_min, $record->kelembaban_max, '%RH'))
                    ->placeholder('—'),
                // Nonaktif itu keadaan normal di sini, bukan kesalahan — ruangan
                // lama emang ditandain begitu, bukan dihapus. Makanya dikasih
                // badge yang kebaca dari jauh, bukan ikon centang/silang yang
                // gampang kebaca kebalik pas nyari ruangan yang masih kepakai.
                TextColumn::make('aktif')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Room $record): string => $record->aktif ? 'Aktif' : 'Nonaktif')
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('aktif')
                    ->label('Aktif')
                    ->placeholder('Semua ruangan')
                    ->trueLabel('Cuma yang aktif')
                    ->falseLabel('Cuma yang nonaktif'),
                // Ruangan nggak bisa dihapus dari panel, tapi `DELETE api/rooms/{room}`
                // masih ada dan HP bisa manggilnya. Filter ini satu-satunya cara
                // admin nemuin ruangan yang ilang gara-gara itu.
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                // Pengganti tombol hapus.
                //
                // Ruangan yang udah pernah kepakai NGGAK BOLEH dihapus:
                // `CalibrationSession::room()` itu `belongsTo` polos tanpa
                // `withTrashed()` (beda dari `standard()` dan `thermohygro()`
                // yang sengaja pakai). Begitu ruangannya kehapus, `$sesi->room`
                // jadi null, dan `CertificateSnapshotBuilder::lokasiKalibrasi()`
                // jatuh ke teks bawaan — sertifikat revisi buat sesi tahun lalu
                // kecetak "Laboratorium" gantiin "Ruang Kalibrasi Massa". Nggak
                // ada error, nggak ada yang aneh di layar; yang ketahuan cuma
                // dua salinan dokumen yang sama isinya beda.
                //
                // Jadi yang disediain cuma sakelar ini. Ruangan nonaktif ilang
                // dari dropdown HP (`?hanya_aktif=1` di `RoomController@index`)
                // — efek yang dicari — tapi barisnya tetap ada buat sesi lama.
                Action::make('ubahStatus')
                    ->label(fn (Room $record): string => $record->aktif ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (Room $record): string => $record->aktif ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Room $record): string => $record->aktif ? 'gray' : 'success')
                    ->hidden(fn (Room $record): bool => $record->trashed())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Room $record): string => $record->aktif
                        ? "Nonaktifkan {$record->nama}?"
                        : "Aktifkan lagi {$record->nama}?")
                    ->modalDescription(fn (Room $record): string => $record->aktif
                        ? 'Ruangan ini bakal ilang dari dropdown teknisi di HP. Sesi & sertifikat lama yang '
                            .'nunjuk ke sini tetap kebaca — makanya dinonaktifin, bukan dihapus.'
                        : 'Ruangan ini bakal muncul lagi di dropdown teknisi di HP.')
                    ->action(function (Room $record): void {
                        $record->update(['aktif' => ! $record->aktif]);

                        Notification::make()
                            ->title($record->aktif
                                ? "{$record->nama} diaktifin lagi."
                                : "{$record->nama} dinonaktifin. Sesi lama tetap nunjuk ke sini.")
                            ->success()
                            ->send();
                    }),

                // Buat mulangin ruangan yang terlanjur kehapus lewat API. Soft
                // delete, jadi barisnya masih utuh.
                RestoreAction::make(),
            ])
            // SENGAJA nggak ada aksi hapus — nggak per baris, nggak massal.
            //
            // Master data lain di panel ini punya hapus massal dan itu wajar.
            // Di sini nggak: alasan lengkapnya ada di aksi `ubahStatus` di atas.
            // Ringkasnya, hapus ruangan itu satu-satunya cara bikin sertifikat
            // lama kehilangan nama tempat kerjanya tanpa ninggalin jejak error,
            // dan tombolnya nggak perlu ada karena "nonaktifkan" udah ngasih
            // yang sebenernya dimau: ruangannya ilang dari pilihan teknisi.
            ->emptyStateHeading('Belum ada ruangan lab')
            ->emptyStateDescription('Daftarin ruangan lab di sini biar teknisi bisa milihnya waktu bikin sesi kalibrasi.');
    }

    /**
     * Rentang yang kebaca manusia. Satu sisi doang (mis. cuma batas atas) tetap
     * ditulis apa adanya — itu bukan data rusak, ada ruangan yang emang cuma
     * dibatesin dari satu arah.
     */
    private static function rentang(?float $min, ?float $max, string $satuan): string
    {
        if ($min === null && $max === null) {
            return '—';
        }

        if ($min === null) {
            return 'maks '.Angka::idRingkas($max, 2).' '.$satuan;
        }

        if ($max === null) {
            return 'min '.Angka::idRingkas($min, 2).' '.$satuan;
        }

        return Angka::idRingkas($min, 2).' – '.Angka::idRingkas($max, 2).' '.$satuan;
    }
}
