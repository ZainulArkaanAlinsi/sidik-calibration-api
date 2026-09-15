<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Dial Indicator** dari baris
 * `raw_measurements`, plus blok tingkat-sesinya dari `spesifikasi_alat`.
 *
 * Ditulis BERSAMAAN dengan profilnya — pola "alat baru lupa jalur hitung
 * ulang" sudah menggigit repo ini berkali-kali (lihat [HeightGaugeMentah]), dan
 * gejalanya bukan error melainkan `hitung_ulang_gagal` di tiap titik.
 *
 * ## Bentuk yang disusun ulang
 *
 *   peran_sensor    sensor_ke  arti
 *   di_balok        1..n       keping balok ukur yang ditumpuk, SELALU mm
 *   di_pembacaan    1..6       penunjukan dial (UP x1..x3, DOWN x1..x3), ikut satuan alat
 *
 * Nol kolom baru. Blok Evaluation, kapasitas, resolusi, dan satuan hidup di
 * `calibration_sessions.spesifikasi_alat.dial_indicator` — bukan `titik_ke`,
 * yang melahirkan titik hantu.
 */
class DialIndicatorMentah
{
    public const PERAN_BALOK = 'di_balok';

    public const PERAN_PEMBACAAN = 'di_pembacaan';

    /** Keping balok ukur — SELALU mm, nilai sertifikat GB-9122-0. */
    public const SATUAN_BALOK = 'mm';

    public const KUNCI_SESI = 'dial_indicator';

    /** Disalin dari `Satuan_Caliper` master (`DATABASE!R23:T25`). */
    public const FAKTOR_KE_MM = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    /**
     * Ubah satu penunjukan ke mm DI TEMPAT PAKAI, bukan di ujung masuk —
     * mengonversi saat simpan tidak idempoten: draft yang dibuka lalu disimpan
     * lagi dikali 25,4 sekali lagi (bug nyata Micrometer, 645,16 mm dari 1 inch).
     */
    public static function keMm(mixed $nilai, ?string $satuan): float
    {
        return (float) $nilai * (self::FAKTOR_KE_MM[(string) $satuan] ?? 1.0);
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{di_balok: list<float>, di_pembacaan: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            [self::PERAN_BALOK, self::PERAN_PEMBACAAN],
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        return [
            self::PERAN_BALOK => self::deret($milikKita, self::PERAN_BALOK),
            self::PERAN_PEMBACAAN => self::deret($milikKita, self::PERAN_PEMBACAAN),
        ];
    }

    /**
     * Blok tingkat-SESI, dinormalkan ke bentuk `DialIndicatorCalculator::hitungSesi()`.
     *
     * Pembacaan Evaluation, kapasitas, dan resolusi diketik dalam satuan alat
     * (`INPUT DATA!E15`/`E16`/`C31:M31`, dikali faktor di `PERHITUNGAN!G8`/`G9`/
     * `C25`) → dikonversi di sini. Balok ukur Evaluation nilai sertifikat →
     * SELALU mm; menyeretnya ikut satuan alat membuat dial inch mencari keping
     * 355,6 mm yang tidak ada.
     *
     * Balik `null` kalau bloknya belum ada — sesi tanpa Evaluation tidak punya
     * dasar keterulangan, dan menebaknya berarti mengarang.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array{satuan: string, kapasitas_mm: float, resolusi_mm: float, pra_evaluasi: list<float>, balok_pra_evaluasi: list<float>}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $satuan = (string) ($blok['satuan'] ?? 'mm');
        $angka = static function (mixed $x): float {
            $x = AngkaDesimal::bakukan($x);

            return is_numeric($x) ? (float) $x : 0.0;
        };

        return [
            'satuan' => $satuan,
            'kapasitas_mm' => self::keMm($angka($blok['kapasitas_mm'] ?? null), $satuan),
            'resolusi_mm' => self::keMm($angka($blok['resolusi_mm'] ?? null), $satuan),
            'pra_evaluasi' => array_map(
                static fn (float $v): float => self::keMm($v, $satuan),
                self::deretAngka($blok['pra_evaluasi'] ?? null),
            ),
            'balok_pra_evaluasi' => self::deretAngka($blok['balok_pra_evaluasi'] ?? null),
        ];
    }

    /**
     * Rata-rata suhu ruangan mentah — sumber suhu balok ukur DAN suhu UUT
     * (`PERHITUNGAN!O31 = P31 = G14`). Ujung kosong dilewati, bukan dibaca nol.
     */
    public static function rataSuhuRuang(mixed $awal, mixed $akhir): float
    {
        $terisi = array_values(array_filter([$awal, $akhir], static fn ($s): bool => is_numeric($s)));

        return $terisi === [] ? 0.0 : array_sum(array_map('floatval', $terisi)) / count($terisi);
    }

    /**
     * Deret angka dari array ATAU teks tumpukan `14+11` (field `daftar_angka`
     * yang dikirim HP apa adanya). Koma di dalam teks itu KOMA DESIMAL, bukan
     * pemisah — `2,5+1,3` wajib jadi `[2.5, 1.3]`, bukan `[2, 5, 1, 3]`.
     *
     * @return list<float>
     */
    public static function deretAngka(mixed $nilai): array
    {
        if (is_string($nilai)) {
            $nilai = preg_split('/[+;\s]+/', trim($nilai), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($nilai)) {
            return [];
        }

        $hasil = [];

        foreach ($nilai as $v) {
            $v = AngkaDesimal::bakukan($v);

            if (is_numeric($v)) {
                $hasil[] = (float) $v;
            }
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris
     * @return list<float>
     */
    private static function deret(Collection $baris, string $peran): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
            ->map(static fn ($b): float => self::keMm($b->pembacaan, $b->satuan))
            ->values()
            ->all();
    }
}
