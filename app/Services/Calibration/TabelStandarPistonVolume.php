<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Pembaca `database/data/tabel-standar-piston-volume.json` — tabel referensi
 * dua master piston volume (Fixed & Graduated): timbangan, koreksi termometer
 * & sensor PT100, pita CMC, tabel MPE ISO 8655, konstanta Tanaka.
 *
 * DIGENERATE `docs/skrip/gen-tabel-standar-piston-volume.py`, yang mengadu 274
 * sel kedua master ke cache Excel (selisih terbesar 2·10⁻¹⁴) dan mengadu tabel
 * referensi kedua master satu sama lain (identik). Jangan disunting tangan.
 */
class TabelStandarPistonVolume
{
    public const PISTON_PIPETTE = 'piston_pipette';

    public const DISPENSETT = 'dispensett';

    public const BURET_DIGITAL = 'buret_digital';

    public const SINGLE_STROKE = 'single_stroke';

    public const MULTI_STROKE = 'multi_stroke';

    public const MOTOR_DRIVEN = 'motor_driven';

    public const HAND_DRIVEN = 'hand_driven';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @return array<string, mixed>|null */
    public static function timbangan(string $nama): ?array
    {
        foreach (self::muat()['timbangan'] as $t) {
            if ($t['nama'] === $nama) {
                return $t;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function namaTimbangan(): array
    {
        return array_map(static fn (array $t): string => (string) $t['nama'], self::muat()['timbangan']);
    }

    /** @return list<float> set point tabel koreksi termometer, urut master */
    public static function indeksSuhu(): array
    {
        return array_map(static fn (array $b): float => (float) $b['indeks'], self::muat()['koreksi_meter_suhu']);
    }

    /**
     * Set point terdekat ke suhu air rata-rata TERBACA — cermin
     * `INDEX(idx_suhu, MATCH(MIN(ABS(idx_suhu−H61)), …, 0))`, kembar diambil
     * yang pertama.
     *
     * Workbook Fixed membaca daftar indeksnya lewat nama `index_suhu` yang
     * menunjuk WORKBOOK LAIN (`[6]STANDAR KALIBRATOR`); yang dipakai di sini
     * daftar lokal berkas itu sendiri. Hasilnya sama di data contoh (25), dan
     * panduan eksternal yang menyebutnya "diketik tangan" keliru — sel `H49`
     * rumus array, bukan ketikan.
     */
    public static function indeksSuhuTerdekat(float $suhu): float
    {
        $terbaik = null;
        $jarak = null;

        foreach (self::indeksSuhu() as $i) {
            $d = abs($i - $suhu);

            if ($jarak === null || $d < $jarak) {
                $jarak = $d;
                $terbaik = $i;
            }
        }

        return (float) $terbaik;
    }

    public static function koreksiMeter(float $indeks): ?float
    {
        return self::cari(self::muat()['koreksi_meter_suhu'], $indeks);
    }

    public static function koreksiSensor(float $indeks): ?float
    {
        return self::cari(self::muat()['koreksi_sensor_suhu'], $indeks);
    }

    public static function u95Termometer(): float
    {
        return (float) self::muat()['termometer']['u95'];
    }

    public static function u95Sensor(): float
    {
        return (float) self::muat()['sensor']['u95'];
    }

    /** @return array<string, mixed> termometer standar (Yokogawa CA150) */
    public static function termometer(): array
    {
        return self::muat()['termometer'];
    }

    /**
     * CMC (ml) untuk kapasitas alat — pita KONTINU.
     *
     * Master (`PERHITUNGAN U95%!J48`) memakai batas bilangan bulat: Piston
     * Pipette `C33<=1`, lalu `C33>=2 … <=5`. Pipet 1,5 ml jatuh di celahnya,
     * sel CMC berisi teks `"cek range"`, `MAX(U, "cek range")` mengabaikan
     * teks, dan U95 terbit TANPA lantai CMC — lebih kecil dari yang
     * diakreditasi, tanpa satu pun error (temuan G-7). Di sini pitanya
     * kontinu: (0, 1], (1, 5], (5, 10].
     *
     * `null` = kapasitas di luar lampiran — pemanggil memperlakukannya sebagai
     * di luar lingkup akreditasi, bukan lantai nol diam-diam.
     */
    public static function cmc(string $jenis, float $kapasitasMl): ?float
    {
        foreach (self::muat()['cmc'][$jenis] ?? [] as $p) {
            if ($kapasitasMl <= (float) $p['maks_ml']) {
                return (float) $p['nilai_ml'];
            }
        }

        return null;
    }

    /** Batas atas pita CMC terbesar satu jenis alat (ml), atau `null` kalau jenisnya tidak punya pita. */
    public static function kapasitasMaksCmc(string $jenis): ?float
    {
        $pita = self::muat()['cmc'][$jenis] ?? [];

        return $pita === [] ? null : (float) max(array_column($pita, 'maks_ml'));
    }

    /**
     * MPE ISO 8655 (µl) untuk nominal PERSIS di tabel sub-jenis itu, atau
     * `null`. `null` WAJIB membuat pernyataan kesesuaian tidak terbit — bukan
     * `#N/A` di sertifikat (temuan G-4) dan bukan dikosongkan diam-diam.
     */
    public static function mpe(string $jenis, ?string $subJenis, float $nominalMl): ?float
    {
        $tabel = self::muat()['mpe'];

        $kolom = match ($jenis) {
            self::PISTON_PIPETTE => 'mpe_ul',
            self::BURET_DIGITAL => $subJenis === self::MOTOR_DRIVEN ? 'motor_driven_ul' : ($subJenis === self::HAND_DRIVEN ? 'hand_driven_ul' : null),
            self::DISPENSETT => $subJenis === self::MULTI_STROKE ? 'multi_stroke_ul' : ($subJenis === self::SINGLE_STROKE ? 'single_stroke_ul' : null),
            default => null,
        };

        if ($kolom === null) {
            return null;
        }

        foreach ($tabel[$jenis] ?? [] as $b) {
            if (abs((float) $b['nominal_ml'] - $nominalMl) < 1e-12) {
                return isset($b[$kolom]) && $b[$kolom] !== null ? (float) $b[$kolom] : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function konstanta(): array
    {
        return self::muat()['konstanta'];
    }

    /** @param  list<array{indeks: float, koreksi: float}>  $tabel */
    private static function cari(array $tabel, float $indeks): ?float
    {
        foreach ($tabel as $b) {
            if ((float) $b['indeks'] === $indeks) {
                return (float) $b['koreksi'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-piston-volume.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar piston volume nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi) || ! isset($isi['timbangan'], $isi['koreksi_meter_suhu'], $isi['cmc'], $isi['mpe'], $isi['konstanta'])) {
            throw new RuntimeException("Tabel standar piston volume rusak: {$berkas}");
        }

        return self::$data = $isi;
    }

    public static function lupakan(): void
    {
        self::$data = null;
    }
}
