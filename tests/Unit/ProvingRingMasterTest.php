<?php

namespace Tests\Unit;

use App\Services\Calibration\GayaCalculator;
use App\Services\GumCalculator;
use Tests\TestCase;

/**
 * Rantai & budget PROVING RING diadu ke workbook master, sel demi sel.
 *
 * ## Yang bisa diadu, dan yang SENGAJA tidak
 *
 * Kolom `J` (rata-rata divisi sesudah faktor ruangan), `K` (simpangan baku),
 * dan `L` (RSD %) tidak bergantung pada koreksi standar sama sekali, jadi
 * ketiganya diadu ke master **persis**. Begitu juga seluruh budget-nya: yang
 * masuk ke sana cuma RSD terbesar, dan itu lahir dari `L`.
 *
 * Yang tidak diadu: `Y`, `Z`, dan faktor kalibrasi. Master memperolehnya
 * dengan `W = 0` yang datang dari `VLOOKUP` gagal yang ditelan `ISERROR` —
 * pola yang AGENTS.md larang ditiru. Di sini pencariannya dijalankan
 * sungguhan.
 *
 * **Dan hasilnya kebetulan sama.** Dengan standar yang disebut workbook
 * (Load Cell 3000 kN) pencarian yang benar pun memulangkan nol, karena
 * satu-satunya baris tabel di bawah 300 kN adalah baris nol. Itu dibuktikan
 * `test_koreksi_standar_3000kn_memang_nol_bukan_karena_error` di bawah —
 * jadi bukan asumsi, dan bukan kebetulan yang dibiarkan tidak dijelaskan.
 *
 * Bedanya: sekarang keadaannya KEBACA. Titik 0,2943 kN yang mengambil koreksi
 * dari baris 0 kN sementara baris berikutnya 300 kN menerbitkan temuan.
 */
class ProvingRingMasterTest extends TestCase
{
    private const TOLERANSI = 5e-6;

    /** Suhu ruangan sesi master, awal & akhir sama. */
    private const SUHU_RUANG = 23.1;

    /** Suhu sertifikat Load Cell 3000 kN. */
    private const SUHU_SERTIFIKAT = 28.6;

    /**
     * `PERHITUNGAN FC` baris 24..33 — B (kN), enam bacaan dial, lalu J, K, L.
     *
     * @return list<array{0: float, 1: list<float>, 2: float, 3: float, 4: float}>
     */
    private static function master(): array
    {
        return [
            [0.0, [0, 0, 0, 0, 0, 0], 0.0, 0.0, 0.0],
            [0.2943, [237, 237, 238, 238, 237, 238], 237.4935875, 0.5477225575051661, 0.23062625112148177],
            [0.8828999999999999, [726, 726, 725, 725, 725, 724], 725.1470871666667, 0.7527726527090811, 0.10380964993603636],
            [1.1772, [934, 935, 935, 936, 936, 937], 935.4747415, 1.0488088481701516, 0.11211514342850395],
            [1.4714999999999998, [1205, 1205, 1206, 1205, 1206, 1208], 1205.8007758333333, 1.1690451944500122, 0.09695176996731328],
            [2.0601, [1698, 1697, 1697, 1696, 1697, 1696], 1696.7875188333333, 0.752772652709081, 0.04436457979291761],
            [2.3544, [1941, 1942, 1943, 1942, 1943, 1944], 1942.4475525, 1.0488088481701516, 0.05399419133969907],
            [3.4334999999999996, [2832, 2833, 2832, 2833, 2833, 2834], 2832.7568468333334, 0.752772652709081, 0.026573853437176803],
            [4.414499999999999, [3642, 3641, 3644, 3643, 3644, 3644], 3642.901639, 1.2649110640673518, 0.03472262469361039],
            [4.904999999999999, [4047, 4045, 4045, 4046, 4048, 4045], 4045.890758, 1.2649110640673518, 0.031264093365996705],
        ];
    }

    /**
     * @param  list<float>  $bacaan
     * @return array<string, mixed>
     */
    private function hitung(float $B, array $bacaan): array
    {
        return GayaCalculator::hitungTitikProvingRing(
            $B,
            array_map('floatval', $bacaan),
            '3000kN',
            'Push',
            self::SUHU_RUANG,
            self::SUHU_SERTIFIKAT,
            // Dua suhu yang SAMA: lembar Proving Ring tidak punya field "actual
            // temperature of standard", jadi koreksi termalnya tepat 1.
            self::SUHU_SERTIFIKAT,
        );
    }

    /**
     * `G52 = 1 + 0,00027 x (23 - 23,1)`.
     *
     * Acuannya 23 °C dan dipatok di rumus master, bukan diambil dari sertifikat
     * standar — beda dari koreksi termal yang dipakai UTM.
     */
    public function test_faktor_ruangan_cocok_master(): void
    {
        $this->assertEqualsWithDelta(
            0.999973,
            GayaCalculator::faktorRuangan(self::SUHU_RUANG),
            1e-12,
        );
    }

    public function test_rantai_per_titik_cocok_master(): void
    {
        foreach (self::master() as $i => [$B, $bacaan, $Jm, $Km, $Lm]) {
            $h = $this->hitung($B, $bacaan);
            $titik = $i + 1;

            $this->assertEqualsWithDelta($Jm, $h['J'], self::TOLERANSI,
                "Titik {$titik}: rata-rata divisi (J) meleset dari master.");
            $this->assertEqualsWithDelta($Km, $h['K'], self::TOLERANSI,
                "Titik {$titik}: simpangan baku (K) meleset dari master.");
            $this->assertEqualsWithDelta($Lm, $h['L'] ?? 0.0, self::TOLERANSI,
                "Titik {$titik}: RSD (L) meleset dari master.");
        }
    }

    /**
     * Rata-rata dikali faktor ruangan, BUKAN rata-rata mentah.
     *
     * Bedanya kecil (237,5 lawan 237,4936) dan justru itu bahayanya: lupa
     * mengalikannya tetap menerbitkan angka yang kelihatan benar.
     */
    public function test_rata_rata_sudah_dikali_faktor_ruangan(): void
    {
        $h = $this->hitung(0.2943, [237, 237, 238, 238, 237, 238]);

        $this->assertEqualsWithDelta(237.4935875, $h['J'], self::TOLERANSI);
        $this->assertNotEqualsWithDelta(237.5, $h['J'], 1e-9,
            'J sama dengan rata-rata mentah — faktor ruangan tidak terpakai.');
    }

    /**
     * RSD dibagi rata-rata PEMBACAAN, bukan beban nominal.
     *
     * Ini yang membedakannya dari RRPE milik UTM & Load Cell. Dipakai pembagi
     * yang salah, angkanya tetap berorde persen yang wajar.
     */
    public function test_rsd_dibagi_rata_rata_bukan_beban(): void
    {
        [$B, $bacaan, $Jm, $Km, $Lm] = self::master()[1];
        $h = $this->hitung($B, $bacaan);

        $this->assertEqualsWithDelta($Km / $Jm * 100, $h['L'], 1e-12);
        $this->assertEqualsWithDelta($Lm, $h['L'], self::TOLERANSI);
    }

    /**
     * Koreksi standar 3000 kN memang NOL — dan sebabnya bisa disebut.
     *
     * Bukan karena `ISERROR` menelannya, melainkan karena baris tabel terdekat
     * untuk beban 0–4,9 kN selalu baris 0 kN yang koreksinya nol. Test ini yang
     * membuat kesamaan angka dengan master berhenti jadi kebetulan yang tidak
     * dijelaskan.
     */
    public function test_koreksi_standar_3000kn_memang_nol_bukan_karena_error(): void
    {
        foreach (self::master() as [$B, $bacaan]) {
            $h = $this->hitung($B, $bacaan);

            $this->assertNotNull($h['W'], "Beban {$B} kN gagal menemukan tabel standar.");
            $this->assertEqualsWithDelta(0.0, (float) $h['W'], 1e-12,
                "Beban {$B} kN mendapat koreksi standar bukan nol dari tabel 3000 kN.");
            $this->assertEqualsWithDelta(0.0, (float) $h['set_point_standar_kn'], 1e-12,
                "Beban {$B} kN tidak jatuh ke baris nol tabel 3000 kN.");
        }
    }

    /**
     * Dan keadaan itu WAJIB kebaca, bukan diam.
     *
     * `di_luar_rentang` tidak menangkapnya — 0,29 kN memang ada di dalam
     * [0, 3000]. Yang menangkapnya jarak ke baris yang terpilih.
     */
    public function test_baris_tabel_yang_terlalu_jauh_jadi_temuan(): void
    {
        $h = $this->hitung(0.2943, [237, 237, 238, 238, 237, 238]);

        $this->assertFalse($h['di_luar_rentang_tabel'],
            'Premis test ini runtuh kalau bebannya dianggap di luar rentang.');

        $this->assertNotEmpty(array_filter(
            $h['temuan'],
            static fn (string $t): bool => str_contains($t, 'meleset'),
        ), 'Baris tabel yang meleset 100% dari bebannya lolos tanpa temuan. Temuan: '
            .implode(' | ', $h['temuan']));
    }

    /** Titik nol tidak punya sebaran, dan itu normal — bukan temuan. */
    public function test_titik_nol_tidak_meledak(): void
    {
        $h = $this->hitung(0.0, [0, 0, 0, 0, 0, 0]);

        $this->assertNull($h['L']);
        $this->assertNull($h['CF']);
        $this->assertSame([], array_values(array_filter(
            $h['temuan'],
            static fn (string $t): bool => str_contains($t, 'dial tidak bergerak'),
        )));
    }

    /** Dial yang tidak bergerak saat dibebani itu TEMUAN, bukan nol yang wajar. */
    public function test_dial_diam_saat_dibebani_jadi_temuan(): void
    {
        $h = $this->hitung(2.0601, [0, 0, 0, 0, 0, 0]);

        $this->assertNotEmpty(array_filter(
            $h['temuan'],
            static fn (string $t): bool => str_contains($t, 'dial tidak bergerak'),
        ), 'Temuan: '.implode(' | ', $h['temuan']));
    }

    // -----------------------------------------------------------------
    // Budget
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function blok(): array
    {
        return [
            'satuan' => 'kgf',
            'kapasitas' => 500.0,
            'resolusi_dial_mm' => 0.002,
            'kapasitas_dial_mm' => 25.0,
            'resolusi_standar' => 0.1,
            'kapasitas_standar' => 3000.0,
            'preload_zero' => [0.0, 0.0, 0.0],
            'misalignment' => [8.237, 8.234, 8.237, 8.238],
        ];
    }

    /** @return array{dijumlahkan: list<array<string, mixed>>, di_luar_jumlah: list<array<string, mixed>>} */
    private function budget(): array
    {
        return GayaCalculator::komponenBudgetProvingRing(
            $this->blok(),
            0.23062625112148177,   // RSD terbesar, dari titik 0,2943 kN
            GayaCalculator::keKn(500.0, 'kgf'),
            0.36,                  // U95% sertifikat Load Cell 3000 kN
            0.07428573463572993,   // drift 3000 kN arah Tekan
        );
    }

    public function test_tiap_komponen_budget_cocok_master(): void
    {
        $harap = [
            'sertifikat_kalibrator' => 0.18,
            'daya_baca_uut' => 0.002309401076758503,
            'daya_baca_standar' => 0.0009622504486493764,
            'temperature' => 0.015588457268119896,
            'drift_standar' => 0.07428573463572993,
            'pengulangan' => 0.09415277275643451,
        ];

        $dapat = [];
        foreach ($this->budget()['dijumlahkan'] as $k) {
            $dapat[$k['sumber']] = (float) $k['u'];
        }

        foreach ($harap as $sumber => $nilai) {
            $this->assertArrayHasKey($sumber, $dapat);
            $this->assertEqualsWithDelta($nilai, $dapat[$sumber], self::TOLERANSI,
                "Komponen `{$sumber}` meleset dari master.");
        }
    }

    /** Misalignment TANPA divisor — beda dari UTM yang membaginya akar 3. */
    public function test_misalignment_tidak_dibagi_divisor(): void
    {
        $diluar = [];
        foreach ($this->budget()['di_luar_jumlah'] as $k) {
            $diluar[$k['sumber']] = $k;
        }

        $this->assertEqualsWithDelta(
            0.021028966278987093,
            (float) $diluar['misalignment']['u'],
            self::TOLERANSI,
        );
        $this->assertSame(2.0, (float) $diluar['misalignment']['ci'],
            'ci misalignment Proving Ring 2, bukan 1.');
    }

    /**
     * ENAM dari delapan — dan ini yang membuktikannya, bukan menafsirkannya.
     *
     * Jumlah master cocok NOL BEDA dengan enam komponen; selisih terhadap
     * delapan persis suku misalignment.
     */
    public function test_budget_cocok_master_dan_hanya_enam_komponen(): void
    {
        $budget = $this->budget();
        $agregat = app(GumCalculator::class)->agregasiBudget($budget['dijumlahkan']);

        $this->assertEqualsWithDelta(0.2168694866673367, (float) $agregat['ketidakpastian_gabungan'], self::TOLERANSI,
            'u_c meleset dari master.');
        $this->assertEqualsWithDelta(102.52446920498103, (float) $agregat['derajat_kebebasan_efektif'], 1e-6,
            'v_eff meleset dari master.');
        $this->assertEqualsWithDelta(1.9834952585628811, (float) $agregat['faktor_cakupan_k'], self::TOLERANSI,
            'k meleset dari master.');
        $this->assertEqualsWithDelta(0.43015959853162833, (float) $agregat['ketidakpastian_diperluas'], self::TOLERANSI,
            'U95% meleset dari master.');

        // Dan kalau kedelapan komponen ikut dijumlahkan, hasilnya BERBEDA —
        // jadi "enam" bukan kebetulan yang kebetulan cocok.
        $semua = [...$budget['dijumlahkan'], ...$budget['di_luar_jumlah']];
        $agregatSemua = app(GumCalculator::class)->agregasiBudget($semua);

        $this->assertGreaterThan(
            (float) $agregat['ketidakpastian_gabungan'],
            (float) $agregatSemua['ketidakpastian_gabungan'],
            'Menjumlahkan delapan komponen seharusnya menaikkan u_c.',
        );
    }
}
