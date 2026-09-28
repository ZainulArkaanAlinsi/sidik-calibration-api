<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Bentuk ulang baris `raw_measurements` keluarga PISTON VOLUME jadi masukan
 * kalkulator — dipakai `CalibrationValidator` DAN `HitungUlangSesi`.
 *
 * ## Nol kolom baru
 *
 *   - `peran_sensor = piston_kumulatif`, `sensor_ke` 0..10 = M0..M10 — massa
 *     KUMULATIF di timbangan, persis yang ditulis teknisi di kertas FM-0528/
 *     FM-0529 (bukan massa per pemindahan; sistem yang menghitung selisihnya);
 *   - `peran_sensor = piston_suhu_air`, `sensor_ke` 1..2 = suhu air awal/akhir;
 *   - `titik_ke` = titik (1 untuk volume tetap; 1..3 = MIN/MID/MAX graduated),
 *     `titik_ukur` = nominal dalam satuan alat.
 *
 * Varian (fixed/graduated), jenis, sub-jenis, satuan, kapasitas, timbangan, dan
 * koreksi penguapan masuk `spesifikasi_alat.piston`.
 */
class PistonVolumeMentah
{
    public const KUNCI_SESI = 'piston';

    public const PERAN_KUMULATIF = 'piston_kumulatif';

    public const PERAN_SUHU_AIR = 'piston_suhu_air';

    public const PERAN_SEMUA = [self::PERAN_KUMULATIF, self::PERAN_SUHU_AIR];

    /** Bukan besaran alat — lihat `TekananMentah::PERAN_BUKAN_BESARAN_ALAT`. */
    public const PERAN_BUKAN_BESARAN_ALAT = self::PERAN_SEMUA;

    public const KONTEKS_KUMULATIF = 'piston_kumulatif';

    public const KONTEKS_SUHU_AIR = 'piston_suhu_air';

    public const LABEL_TITIK = [1 => 'MIN', 2 => 'MID', 3 => 'MAX'];

    /**
     * Deret SATU titik, urut `sensor_ke`. Urutan bukan kosmetik: selisih
     * `M_i − M_{i−1}` dari urutan yang tertukar menghasilkan massa negatif dan
     * positif yang saling menutupi — rata-ratanya nyaris sama, STDEV-nya yang
     * membengkak diam-diam.
     *
     * @param  Collection<int, RawMeasurement>  $baris
     * @return array<string, mixed>
     */
    public static function dari(Collection $baris): array
    {
        $piston = $baris->filter(
            static fn ($b): bool => in_array((string) $b->peran_sensor, self::PERAN_SEMUA, true)
        );

        if ($piston->isEmpty()) {
            return [];
        }

        $deret = [self::PERAN_KUMULATIF => [], self::PERAN_SUHU_AIR => []];

        foreach ($piston as $b) {
            $deret[(string) $b->peran_sensor][(int) $b->sensor_ke] = (float) $b->pembacaan;
        }

        foreach ($deret as $peran => $nilai) {
            ksort($nilai);
            $deret[$peran] = array_values($nilai);
        }

        return [
            self::KONTEKS_KUMULATIF => $deret[self::PERAN_KUMULATIF],
            self::KONTEKS_SUHU_AIR => $deret[self::PERAN_SUHU_AIR],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array<string, mixed>|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = $spesifikasiAlat[self::KUNCI_SESI] ?? null;

        if (! is_array($blok) || $blok === []) {
            return null;
        }

        $teks = static fn (string $k): ?string => isset($blok[$k]) && trim((string) $blok[$k]) !== ''
            ? trim((string) $blok[$k])
            : null;

        $penguapan = [];
        foreach ((array) ($blok['penguapan'] ?? []) as $i => $v) {
            $penguapan[(int) $i] = TekananMentah::angka($v) ?? 0.0;
        }

        return [
            'keluarga' => $teks('keluarga'),
            'sub_jenis' => $teks('sub_jenis'),
            'satuan' => $teks('satuan'),
            'kapasitas' => TekananMentah::angka($blok['kapasitas'] ?? null),
            'timbangan' => $teks('timbangan'),
            'penguapan' => $penguapan,
        ];
    }
}
