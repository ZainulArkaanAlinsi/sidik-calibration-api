<?php

namespace App\Filament\Resources\CalibrationCapabilities\Tables;

use App\Models\CalibrationCapability;
use App\Support\Angka;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CalibrationCapabilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nama_alat')
            ->columns([
                // Kolom `Asal` sengaja ditaruh PALING KIRI, sebelum nama alat.
                //
                // Isi tabel ini campuran: salinan lampiran akreditasi yang
                // angkanya nggak boleh disentuh, dan tambahan admin/teknisi yang
                // angkanya justru belum ada. Dua-duanya baris "kemampuan
                // kalibrasi" yang kelihatan sama persis kalau nggak ditandain,
                // dan yang salah bukan tampilannya — orang bakal ngedit yang
                // salah, atau ngandelin yang belum lengkap.
                TextColumn::make('sumber')
                    ->label('Asal')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        CalibrationCapability::SUMBER_AKREDITASI => 'Akreditasi',
                        CalibrationCapability::SUMBER_ADMIN => 'Admin',
                        CalibrationCapability::SUMBER_TEKNISI => 'Teknisi',
                        default => (string) $state,
                    })
                    ->icon(fn (?string $state): ?string => $state === CalibrationCapability::SUMBER_AKREDITASI
                        ? 'heroicon-m-lock-closed'
                        : 'heroicon-m-pencil-square')
                    ->color(fn (?string $state): string => match ($state) {
                        CalibrationCapability::SUMBER_AKREDITASI => 'success',
                        CalibrationCapability::SUMBER_ADMIN => 'info',
                        CalibrationCapability::SUMBER_TEKNISI => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('nama_alat')
                    ->label('Nama alat')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.nama')
                    ->label('Kategori')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('parameter')
                    ->label('Parameter')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('rentang')
                    ->label('Rentang')
                    ->state(fn (CalibrationCapability $record): string => self::rentang($record))
                    ->placeholder('—'),
                // CMC kosong ditulis "belum ada" dengan warna bahaya, BUKAN
                // strip netral kayak kolom opsional lain.
                //
                // Strip kebaca "nggak relevan". Kolom ini justru sebaliknya:
                // kosong artinya tiap sesi yang pakai baris ini dihitung tanpa
                // lantai CMC, dan U95 yang terbit bisa lebih kecil daripada yang
                // diakreditasi lab — tanpa satu pun error. Warna merah di sini
                // itu satu-satunya tempat keadaan itu kelihatan sekilas.
                TextColumn::make('ketidakpastian_terbaik')
                    ->label('CMC (U95)')
                    ->badge()
                    ->color(fn (CalibrationCapability $record): string => $record->punyaCmc() ? 'gray' : 'danger')
                    ->formatStateUsing(fn (?float $state, CalibrationCapability $record): string => $record->punyaCmc()
                        ? Angka::idRingkas((float) $state, 6).' '.($record->satuan_ketidakpastian ?? '')
                        : 'belum ada')
                    // `state()` dipaksa non-null biar `formatStateUsing` tetap
                    // dipanggil buat baris yang CMC-nya NULL — kalau nggak,
                    // Filament nampilin placeholder dan labelnya nggak pernah
                    // muncul.
                    ->state(fn (CalibrationCapability $record): float => (float) ($record->ketidakpastian_terbaik ?? 0)),
                TextColumn::make('pembuat.name')
                    ->label('Ditambah oleh')
                    ->placeholder('— seeder —')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('sumber')
                    ->label('Asal')
                    ->options([
                        CalibrationCapability::SUMBER_AKREDITASI => 'Lampiran akreditasi',
                        CalibrationCapability::SUMBER_ADMIN => 'Ditambah admin',
                        CalibrationCapability::SUMBER_TEKNISI => 'Ditambah teknisi',
                    ]),
                SelectFilter::make('equipment_category_id')
                    ->label('Kategori')
                    ->relationship('category', 'nama')
                    ->searchable()
                    ->preload(),
                // Ini daftar kerjaan admin, bukan sekadar penyaring: tiap baris
                // di sini itu satu nama alat yang teknisi udah pakai tapi
                // angkanya belum pernah diturunkan.
                Filter::make('tanpa_cmc')
                    ->label('Belum ada CMC-nya')
                    // `whereNull` doang, BUKAN `<= 0`. CMC nol itu keputusan
                    // yang udah diambil ("nggak ada klaim buat rentang ini",
                    // lihat `ViscometerCapabilitySeeder`), bukan kolom yang
                    // belum diisi. Nyampur keduanya bikin daftar kerjaan ini
                    // isinya baris yang nggak perlu dikerjain.
                    ->query(fn (Builder $query): Builder => $query->whereNull('ketidakpastian_terbaik'))
                    ->toggle(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (CalibrationCapability $record): string => $record->dariAkreditasi()
                        ? 'Baris ini salinan lampiran akreditasi. Kalau dihapus, semua sesi berikutnya yang '
                            .'pakai nama alat ini kehilangan lantai CMC-nya dan dihitung lewat jalur generik — '
                            .'tanpa error di mana pun. Nonaktifkan cuma kalau lampirannya memang berubah.'
                        : 'Nama alat ini bakal ilang dari dropdown teknisi. Alat pelanggan yang udah nunjuk ke '
                            .'sini tetap bisa dikalibrasi, tapi jatuh ke jalur hitung generik.'),
                // Soft delete, jadi bisa dibalikin. Sesi & sertifikat lama yang
                // nunjuk ke baris ini tetap kebaca waktu audit.
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            // SENGAJA nggak ada `toolbarActions([BulkActionGroup...])`.
            //
            // Master data lain di panel ini punya hapus massal, dan itu wajar
            // buat pelanggan atau ruangan. Di sini nggak: nyentang beberapa
            // baris lalu "Hapus" itu gerakan dua detik yang bisa nyabut lantai
            // CMC belasan jenis alat sekaligus, dan akibatnya baru kelihatan
            // berminggu-minggu kemudian sebagai U95 yang mengecil di
            // sertifikat — bukan sebagai error. Hapus satu-satu maksa orang
            // baca modal konfirmasinya, dan modal itu yang nyebutin akibatnya.
            ->emptyStateHeading('Belum ada kemampuan kalibrasi')
            ->emptyStateDescription('Jalanin `php artisan db:seed --class=CalibrationCapabilitySeeder` buat '
                .'ngisi dari lampiran akreditasi, atau tambah manual lewat tombol di atas.');
    }

    /** Rentang yang kebaca manusia; `—` kalau baris ini emang belum punya. */
    private static function rentang(CalibrationCapability $record): string
    {
        $satuan = $record->satuan === null ? '' : ' '.$record->satuan;

        if ($record->range_max === null) {
            return $record->range_note ?? '—';
        }

        // Titik tunggal ditulis satu angka, bukan "5 – 5" yang kebaca kayak
        // rentang selebar nol.
        if ($record->range_min === null || (float) $record->range_min === (float) $record->range_max) {
            $awal = $record->range_note !== null ? $record->range_note.' – ' : '';

            return $awal.Angka::idRingkas((float) $record->range_max, 6).$satuan;
        }

        return sprintf(
            '%s – %s%s',
            Angka::idRingkas((float) $record->range_min, 6),
            Angka::idRingkas((float) $record->range_max, 6),
            $satuan,
        );
    }
}
