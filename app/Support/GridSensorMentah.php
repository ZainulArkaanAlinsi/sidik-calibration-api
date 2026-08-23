<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang GRID enclosure dari baris `raw_measurements` satu titik.
 *
 * ## Kenapa berdiri sendiri, bukan method privat
 *
 * Sepuluh alat menyimpan satu titik sebagai satu deret pembacaan datar, jadi
 * `raw_measurements` cukup diurutkan `pembacaan_ke` dan selesai. Enclosure
 * tidak: satu set point itu 9 termokopel × 5 pembacaan + baris Indikator, yang
 * dibedakan lewat `sensor_ke`/`peran_sensor`/`channel`. Bentuk itu harus
 * disusun ULANG setiap kali sesi tersimpan dihitung lagi.
 *
 * Ada DUA tempat yang menghitung ulang sesi tersimpan — `CalibrationValidator`
 * (buat membandingkan hasil tersimpan vs hitung ulang sebelum approve) dan
 * `kalibrasi:hitung-ulang` (buat membetulkan angka yang terlanjur terbit).
 * Dua salinan rekonstruksi berarti dua bentuk grid yang bisa berbeda diam-diam,
 * dan buat lab terakreditasi selisih antara "yang divalidasi" dan "yang ditulis
 * ke database" itu temuan audit. Makanya satu kelas, dipakai dua-duanya.
 *
 * Balik `[]` — bukan grid kosong — kalau tidak ada satu pun baris ber-`peran_sensor`.
 * Itu alat single-channel biasa, dan kunci `sensor_grid`/`indikator` sebaiknya
 * tidak muncul sama sekali ketimbang muncul kosong: profil non-enclosure tidak
 * pernah menengoknya, tapi profil enclosure membedakan "tidak ada grid" dari
 * "grid kosong".
 */
final class GridSensorMentah
{
    /**
     * @param  Collection<int, RawMeasurement>  $pembacaan  baris mentah SATU titik
     * @return array{sensor_grid?: list<array{no: int, channel: int|null, pembacaan: list<float>}>, indikator?: list<float>}
     */
    public static function dari($pembacaan): array
    {
        $berperan = $pembacaan
            ->filter(fn (RawMeasurement $m): bool => $m->peran_sensor !== null && $m->pembacaan !== null);

        if ($berperan->isEmpty()) {
            return [];
        }

        $grid = $berperan
            ->where('peran_sensor', 'termokopel')
            ->groupBy('sensor_ke')
            ->map(fn ($baris, $no): array => [
                'no' => (int) $no,
                'channel' => $baris->first()?->channel !== null ? (int) $baris->first()->channel : null,
                'pembacaan' => $baris->sortBy('pembacaan_ke')
                    ->map(fn (RawMeasurement $m): float => (float) $m->pembacaan)
                    ->values()
                    ->all(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'sensor_grid' => $grid,
            'indikator' => $berperan
                ->where('peran_sensor', 'indikator')
                ->sortBy('pembacaan_ke')
                ->map(fn (RawMeasurement $m): float => (float) $m->pembacaan)
                ->values()
                ->all(),
        ];
    }
}
