<?php

namespace Tests\Concerns;

/**
 * Perangkat bersama tes PARITAS aplikasi-lawan-Excel (`tests/Feature/Paritas/`).
 *
 * Fixture-nya (`tests/Fixtures/paritas/*.json`, dibangun
 * `docs/skrip/gen-fixture-paritas-micrometer-ph.py`) menyimpan tiap nilai Excel
 * sebagai `{sel: 'SHEET!SEL', nilai: ...}`. Pesan galat tiap banding menyebut sel
 * itu, jadi tes merah langsung menunjuk ke sel workbook mana aplikasi menyimpang.
 *
 * ## Toleransi — asal tiap angka (bukan dipilih agar lulus)
 *
 * Nilai-cache workbook adalah double IEEE-754 yang ditulis Excel; aplikasi
 * menghitung double yang sama dengan urutan operasi yang bisa berbeda. Selisih
 * yang sah hanya galat pembulatan urutan operasi.
 *
 * - [TOL_REL] 1e-11 relatif. Dasar terukur: rekonstruksi mandiri Terminal 1 dan
 *   skrip pembangkit fixture mereproduksi seluruh rantai cache dengan selisih
 *   relatif <= 1,2e-12 (pH, `MATRIKS-ALAT-PERTAMA.md` bagian I) dan <= 1,5e-13
 *   (Micrometer, `rekonstruksi.selisih_relatif_maks_terhadap_cache` di fixture);
 *   1e-11 = sepuluh kali batas teramati itu, empat orde di atas epsilon double
 *   (2,2e-16) — cukup untuk urutan penjumlahan, tidak cukup untuk menyembunyikan
 *   satu konstanta yang salah (konstanta terkecil di budget berbeda >= 1e-4).
 * - [TOL_MUTLAK] 1e-12 mutlak: lantai untuk nilai yang dihasilkan pengurangan dua
 *   bilangan besar (koreksi = standar - rata-rata, kedua suku ~50-100 mm, ulp 7e-15
 *   ~ 1,4e-14). 1e-12 ~ 70 ulp; resolusi data di workbook 1e-3 mm, sembilan orde di atasnya.
 * - [TOL_K] 1e-9 mutlak untuk faktor cakupan `k`: Excel `TINV` dan
 *   `StudentTDistribution::quantile` adalah dua algoritma. Kuantil aplikasi
 *   dibatasi `EPS = 3e-12` pada CDF (`StudentTDistribution::EPS`); di t ~ 1,97
 *   kerapatan Student-t ~ 0,058, jadi galat kuantil <= 3e-12 / 0,058 ~ 5e-11;
 *   1e-9 memberi margin dua puluh kali.
 * - U = k * uc: relatif [TOL_K_REL] 1e-9 (mewarisi galat `k`; uc sendiri 1e-11).
 * - ANGKA CETAK: teks harus SAMA PERSIS (tanpa toleransi) — pelanggan hanya
 *   memegang yang tercetak. Dibandingkan sesudah pemisah desimal diseragamkan.
 */
trait MenegakkanParitas
{
    private const TOL_REL = 1e-11;

    private const TOL_MUTLAK = 1e-12;

    private const TOL_K = 1e-9;

    private const TOL_K_REL = 1e-9;

    /** @return array<string, mixed> */
    protected static function fixtureParitas(string $nama): array
    {
        static $cache = [];

        return $cache[$nama] ??= json_decode(
            (string) file_get_contents(base_path("tests/Fixtures/paritas/{$nama}.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** Nilai angka dari `{sel, nilai}`. */
    protected static function nilai(array $sel): float
    {
        if (! isset($sel['nilai']) || ! is_numeric($sel['nilai'])) {
            throw new \LogicException('Sel '.($sel['sel'] ?? '?').' tidak punya nilai angka di fixture.');
        }

        return (float) $sel['nilai'];
    }

    /**
     * Banding SATU tahap: aplikasi harus sama dengan sel Excel dalam toleransi bertulis.
     *
     * @param  array{sel: string, nilai: float|int|null}  $excel
     */
    protected function assertSamaExcel(
        array $excel,
        float $aplikasi,
        string $tahap,
        float $rel = self::TOL_REL,
        float $mutlak = self::TOL_MUTLAK,
    ): void {
        $acuan = self::nilai($excel);
        $batas = max($mutlak, $rel * abs($acuan));

        $this->assertLessThanOrEqual(
            $batas,
            abs($acuan - $aplikasi),
            sprintf(
                'Paritas %s: Excel %s = %.15g, aplikasi = %.15g, selisih %.3e (batas %.3e).',
                $tahap,
                $excel['sel'],
                $acuan,
                $aplikasi,
                abs($acuan - $aplikasi),
                $batas,
            ),
        );
    }

    /** Banding `k` (algoritma TINV berbeda). */
    protected function assertKSamaExcel(array $excel, float $aplikasi, string $tahap): void
    {
        $this->assertSamaExcel($excel, $aplikasi, $tahap, rel: 0.0, mutlak: self::TOL_K);
    }

    /** Banding nilai turunan `k` (U = k * uc). */
    protected function assertUSamaExcel(array $excel, float $aplikasi, string $tahap): void
    {
        $this->assertSamaExcel($excel, $aplikasi, $tahap, rel: self::TOL_K_REL, mutlak: self::TOL_MUTLAK);
    }

    /** Banding nilai tanpa sel Excel (hitung ulang Python), dengan batas yang sama. */
    protected function assertSamaHitungUlang(float $acuan, float $aplikasi, string $tahap, float $rel = self::TOL_REL, float $mutlak = self::TOL_MUTLAK): void
    {
        $batas = max($mutlak, $rel * abs($acuan));

        $this->assertLessThanOrEqual(
            $batas,
            abs($acuan - $aplikasi),
            sprintf('Paritas %s: acuan = %.15g, aplikasi = %.15g, selisih %.3e (batas %.3e).', $tahap, $acuan, $aplikasi, abs($acuan - $aplikasi), $batas),
        );
    }

    /** Teks cetak aplikasi memakai koma desimal; Excel (fixture) titik. */
    protected static function keTitik(string $teks): string
    {
        return str_replace(',', '.', $teks);
    }
}
