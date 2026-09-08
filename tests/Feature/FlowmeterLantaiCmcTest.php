<?php

namespace Tests\Feature;

use App\Services\Calibration\FlowmeterCalculator;
use App\Services\Calibration\TabelStandarFlowmeter;
use Tests\TestCase;

/**
 * Lantai CMC Flowmeter — dan yang ditegakkan di sini **ARAHNYA**, bukan sekadar
 * "beda dari master".
 *
 * ## Temuan yang melahirkan test ini
 *
 * Di kedua workbook master, sel U95 sertifikat blok titik yang benar-benar
 * dipakai berbunyi `=MAX(J60:K61)` dengan **`K61` KOSONG** — `MAX` atas satu
 * angka, jadi tidak ada lantai sama sekali. Blok titik 3 & 4 punya rumus CMC,
 * tapi menunjuk `DATABASE!S42`/`S43` yang ada di area tabel thermohygro dan
 * kosong; rumus itu bahkan membaca `D63` (Standar Terkoreksi Titik 1) dari
 * dalam blok Titik 3.
 *
 * Akibatnya bukan hipotetis. Sesi contoh varian Flowrate titik 2 terbit dengan
 * **1,0466 %OR** pada pita terakreditasi **1,2 %** — 0,153 poin persen lebih
 * kecil dari yang diakui KAN, dan tidak ada satu pun sel yang memprotes.
 *
 * ## Kenapa arahnya yang diuji, bukan nilainya saja
 *
 * Test yang cuma memastikan "hasil kita ≠ hasil master" tetap hijau kalau suatu
 * saat lantainya terpasang TERBALIK (mis. `min()` alih-alih `max()`), dan yang
 * terbit justru lebih kecil lagi. Yang mengikat di sini: hasil kita harus
 * **LEBIH BESAR** dari master, dan persisnya harus mendarat di pita
 * terakreditasi.
 */
class FlowmeterLantaiCmcTest extends TestCase
{
    private const TOL = 5e-6;

    /** Geometri pipa sesi contoh — sama di kedua workbook. */
    private const PIPA = [
        'diameter_pipa_mm' => [50.81, 50.82, 50.81],
        'ketebalan_pipa_mm' => [2.32, 2.31, 2.32],
    ];

    /**
     * U95 yang TERCETAK di kedua master, per titik.
     *
     * Diambil dari `PERHITUNGAN U95%!K62`/`K81` (Totalizer) dan `K62`/`K82`
     * (Flowrate) — sel `U95% sert` yang lantainya hilang.
     */
    private const U95_MASTER = [
        'totalizer' => [1 => 13.57233577327868, 2 => 25.622762985099662],
        'flowrate' => [1 => 1.9309241213575181, 2 => 3.2512387943296726],
    ];

    /** @return array<string, mixed> */
    private function hitungTotalizer(): array
    {
        return (new FlowmeterCalculator)->hitungSesi([
            [
                'titik_ke' => 1,
                'uut' => [[1002.65], [1004.56], [1006.52]],
                'std' => [1010.885, 1011.52, 1012.215],
                'suhu_awal' => [25.5, 25.5, 25.5],
                'suhu_akhir' => [25.4, 25.4, 25.4],
                'densitas_uut' => [],
            ],
            [
                'titik_ke' => 2,
                'uut' => [[1901.37], [1902.66], [1904.27]],
                'std' => [1901.654, 1903.158, 1904.998],
                'suhu_awal' => [25.4, 25.4, 25.4],
                'suhu_akhir' => [25.4, 25.4, 25.4],
                'densitas_uut' => [],
            ],
        ], [
            'mode' => TabelStandarFlowmeter::MODE_TOTALIZER,
            'satuan' => 'L',
            'resolusi' => 0.01,
        ] + self::PIPA);
    }

    /** @return array<string, mixed> */
    private function hitungFlowrate(): array
    {
        return (new FlowmeterCalculator)->hitungSesi([
            [
                'titik_ke' => 1,
                'uut' => [
                    [101.255, 101.276, 101.289],
                    [102.654, 102.625, 102.678],
                    [101.986, 101.910, 101.945],
                ],
                'std' => [101.998, 101.897, 101.123],
                'suhu_awal' => [24.5, 24.5, 24.5],
                'suhu_akhir' => [24.5, 24.6, 24.6],
                'densitas_uut' => [],
            ],
            [
                'titik_ke' => 2,
                'uut' => [
                    [309.785, 309.776, 309.779],
                    [311.376, 311.374, 311.398],
                    [310.772, 310.778, 310.745],
                ],
                'std' => [308.711, 310.255, 310.251],
                'suhu_awal' => [24.6, 24.6, 24.6],
                'suhu_akhir' => [24.6, 24.6, 24.6],
                'densitas_uut' => [],
            ],
        ], [
            'mode' => TabelStandarFlowmeter::MODE_FLOWRATE,
            'satuan' => 'LPM',
            'resolusi' => 0.001,
        ] + self::PIPA);
    }

    /**
     * **Temuan utama.** Flowrate titik 2: master menerbitkan 3,2512388 Lpm
     * (1,0466 %OR) di bawah pita terakreditasi 1,2 %. Kita menerbitkan
     * 3,7277107 Lpm — dan itu HARUS lebih besar.
     */
    public function test_flowrate_titik_2_naik_ke_lantai_cmc(): void
    {
        $titik = collect($this->hitungFlowrate()['titik'])->keyBy('titik_ke');
        $t2 = $titik[2];

        $this->assertTrue(
            $t2['lantai_cmc_dipakai'],
            'Lantai CMC tidak menyala di titik yang justru menerbitkan angka di bawah akreditasi.',
        );

        $this->assertGreaterThan(
            self::U95_MASTER['flowrate'][2],
            $t2['u95_sertifikat'],
            'U95 kita TIDAK lebih besar dari master. Lantai CMC yang terpasang terbalik menerbitkan '
            .'angka yang lebih kecil lagi, dan test yang cuma menguji "beda" tetap hijau.',
        );

        // Persisnya: 1,2 % × rata-rata pembacaan UUT.
        $this->assertEqualsWithDelta(3.727710666666666, $t2['u95_sertifikat'], 3.7277 * self::TOL);
        $this->assertEqualsWithDelta(1.2, $t2['u95_persen_of_reading'], 1e-9);
        $this->assertSame('190.6 - 519.4 Lpm', $t2['pita_cmc']['label']);

        // Budget mentahnya sendiri TIDAK berubah — yang naik cuma yang terbit.
        // Kalau ini ikut bergeser, berarti lantai CMC bocor ke dalam budget.
        $this->assertEqualsWithDelta(
            self::U95_MASTER['flowrate'][2],
            $t2['ketidakpastian_diperluas'],
            self::U95_MASTER['flowrate'][2] * self::TOL,
        );
    }

    /**
     * Ketiga titik lain SUDAH di atas lantainya, jadi angkanya tidak bergerak —
     * dan itu yang membuktikan lantainya bukan pengali buta.
     *
     * Toleransinya 5·10⁻⁶ relatif, dan selisih yang tersisa berasal dari satu
     * hal: `Ut-water` yang di master menunjuk sel kosong (selalu nol) dan di
     * sini dihitung dari suhu air sesi. Pergeserannya ~1·10⁻⁷ relatif.
     */
    public function test_tiga_titik_lain_tidak_bergerak_dari_master(): void
    {
        $hasil = [
            'totalizer' => collect($this->hitungTotalizer()['titik'])->keyBy('titik_ke'),
            'flowrate' => collect($this->hitungFlowrate()['titik'])->keyBy('titik_ke'),
        ];

        foreach ([['totalizer', 1], ['totalizer', 2], ['flowrate', 1]] as [$mode, $titikKe]) {
            $t = $hasil[$mode][$titikKe];
            $master = self::U95_MASTER[$mode][$titikKe];

            $this->assertFalse(
                $t['lantai_cmc_dipakai'],
                "Lantai CMC menyala di {$mode} titik {$titikKe}, padahal budget-nya sudah di atas pita.",
            );

            $this->assertEqualsWithDelta(
                $master,
                $t['u95_sertifikat'],
                abs($master) * self::TOL,
                "U95 {$mode} titik {$titikKe} bergeser dari master.",
            );
        }
    }

    /**
     * Titik di luar KEDUA pita CMC DIBLOKIR — bukan diterbitkan tanpa lantai.
     *
     * Aturannya sama dengan `TabelStandarMicrometer::pitaCmc()`: `null` itu
     * pemblokir. Sertifikat yang terbit di titik seperti itu membawa nomor
     * lingkup LK-285-IDN untuk pengukuran yang tidak diakreditasi.
     */
    public function test_di_luar_kedua_pita_diblokir_bukan_diterbitkan_tanpa_lantai(): void
    {
        // 2500 L jauh di atas pita Totalizer teratas (78–1991 L), tapi bacaan
        // standarnya tetap di dalam jangkauan tabel sertifikat UFM — jadi yang
        // menahan memang pita CMC.
        $hasil = (new FlowmeterCalculator)->hitungSesi([[
            'titik_ke' => 1,
            'uut' => [[2500.10], [2500.42], [2500.85]],
            'std' => [1901.654, 1903.158, 1904.998],
            'suhu_awal' => [25.4, 25.4, 25.4],
            'suhu_akhir' => [25.4, 25.4, 25.4],
            'densitas_uut' => [],
        ]], [
            'mode' => TabelStandarFlowmeter::MODE_TOTALIZER,
            'satuan' => 'L',
            'resolusi' => 0.01,
        ] + self::PIPA);

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('LUAR kedua pita CMC', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Pita CMC dibandingkan dalam PERSEN, bukan angka absolut.
     *
     * Lampiran akreditasi menulisnya `% of reading`. Dibandingkan absolut,
     * lantai 1,2 pada bacaan 310 Lpm jadi 1,2 Lpm — sepertiga dari yang
     * seharusnya, dan angkanya masih terlihat masuk akal.
     */
    public function test_lantai_dihitung_persen_bukan_absolut(): void
    {
        $t2 = collect($this->hitungFlowrate()['titik'])->keyBy('titik_ke')[2];

        $this->assertEqualsWithDelta(
            $t2['pita_cmc']['cmc_persen_of_reading'] / 100 * $t2['uut_rata'],
            $t2['lantai_cmc'],
            1e-9,
        );

        $this->assertGreaterThan(
            $t2['pita_cmc']['cmc_persen_of_reading'],
            $t2['lantai_cmc'],
            'Lantai CMC terbaca sebagai angka absolut — pita 1,2 % pada 310 Lpm harusnya 3,7 Lpm.',
        );
    }
}
