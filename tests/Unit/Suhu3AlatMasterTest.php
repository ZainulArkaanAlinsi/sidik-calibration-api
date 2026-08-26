<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\ThermocoupleCalculator;
use App\Services\Calibration\ThermohygroCalculator;
use App\Services\Calibration\ThermometerGlassCalculator;
use Tests\TestCase;

/**
 * Golden test tiga alat suhu lawan tiga workbook master lab:
 *
 *   `Master_Olah_Data_Suhu_Thermocouple.xlsm`        sesi 0513-CAL-1124
 *   `Master_Olah_Data_Suhu_Thermometer_Glass.xlsm`   sesi 0135-CAL-125
 *   `Master_Olah_Data_Suhu__Kelembapan.xlsm`         sesi 0312-CAL-624
 *
 * Sel yang diadu, sama untuk ketiganya:
 *
 *   besaran                        sel master
 *   Standard / UUT / Correction    `SERTIFIKAT` kolom E / J-K / L-N
 *   `ui` tiap komponen budget      `PERHITUNGAN U95%` kolom U
 *   Uc                             baris `Ketidakpastian Baku Gabungan`
 *   v_eff                          baris `Derajat Kebebasan , v eff`
 *   k                              baris `Factor Cakupan`
 *   U = k·Uc                       baris `Ketidakpastian Bentangan`
 *   U dilaporkan                   baris `… Yang Dilaporkan`
 *
 * ## Kenapa `k` & `U` bertoleransi lebih longgar dari `Uc` & `v_eff`
 *
 * Master mencari `k` lewat aproksimasi polinomial Excel
 * (`1.95996 + 2.37356/v + …`), sementara repo ini memakai
 * `StudentTDistribution` yang punya test-nya sendiri. Pada `v_eff` yang sama
 * keduanya berbeda di orde 1e-6 — jauh di bawah presisi cetak sertifikat, tapi
 * bukan nol. Yang diadu ketat justru bagian yang MEMANG harus identik: tiap
 * `ui`, `Uc`, dan `v_eff`.
 *
 * ## Yang paling penting diuji di sini
 *
 * Bukan "angkanya cocok", tapi **penyimpangan master yang sengaja ditiru** —
 * kalau salah satunya diam-diam "dibenerin" nanti, test ini yang jatuh duluan:
 *
 *  1. Thermocouple: budget TANPA komponen keterulangan, walau STDEV-nya
 *     dihitung dan dipajang di `PERHITUNGAN FC!M23`.
 *  2. Gelas: keterulangan STANDAR dibagi 5, bukan √5 — sementara baris
 *     keterulangan UUT tepat di atasnya dibagi √5.
 *  3. Thermohygro: delapan baris memakai `U = N/SQRT(Q)` padahal `Q` sudah
 *     berisi pembaginya, dan baris drift GEA justru tidak.
 *  4. Ketiganya: `v_eff` TIDAK dipotong ke bawah sebelum dicari `k`-nya.
 *  5. Index tabel dicocokkan ke RATA-RATA pembacaan, bukan ke set point —
 *     titik 150 °C yang terbaca 150,1 mengambil baris tabel 200.
 */
class Suhu3AlatMasterTest extends TestCase
{
    /** Toleransi `ui`, `Uc`, `v_eff`, dan kolom sertifikat — harus identik. */
    private const KETAT = 5e-6;

    /** Toleransi `k` & `U`: selisih aproksimasi polinomial Excel vs StudentTDistribution. */
    private const LONGGAR = 5e-5;

    // ================================================================
    // THERMOCOUPLE — sesi 0513-CAL-1124
    // ================================================================

    /** `INPUT DATA` baris 34–36 (standar) & 51–53 (UUT). */
    private const TITIK_THERMOCOUPLE = [
        ['titik_ke' => 1, 'titik_ukur' => 50.0, 'no_probe' => 1, 'standar' => [49.5, 49.5, 49.5, 49.5, 49.5], 'uut' => [49.9, 49.9, 49.9, 49.9, 49.9]],
        ['titik_ke' => 2, 'titik_ukur' => 100.0, 'no_probe' => 2, 'standar' => [99.0, 99.0, 99.0, 99.0, 99.0], 'uut' => [99.9, 99.9, 99.9, 99.9, 99.9]],
        ['titik_ke' => 3, 'titik_ukur' => 150.0, 'no_probe' => 3, 'standar' => [148.6, 148.6, 148.6, 148.6, 148.6], 'uut' => [150.1, 150.1, 150.1, 150.1, 150.1]],
    ];

    private const SPEK_THERMOCOUPLE = [
        'merk_kalibrator' => 'yokogawa', 'tipe_sensor' => 'Type K', 'dryblock' => 'A',
        'resolusi' => 0.1, 'cmc' => 0.84,
    ];

    public function test_thermocouple_kolom_sertifikat_sama_dengan_master(): void
    {
        $hasil = (new ThermocoupleCalculator)->hitungSesi(self::TITIK_THERMOCOUPLE, self::SPEK_THERMOCOUPLE);

        // `SERTIFIKAT!E20:L22`.
        $master = [
            ['standar' => 49.275, 'uut' => 49.755, 'koreksi' => -0.48],
            ['standar' => 99.105, 'uut' => 99.73, 'koreksi' => -0.625],
            ['standar' => 148.705, 'uut' => 149.96, 'koreksi' => -1.255],
        ];

        $this->assertSame([], $hasil['belum_dihitung'], 'Ketiga titik master harusnya kehitung semua.');
        $this->assertCount(3, $hasil['titik']);

        foreach ($hasil['titik'] as $i => $t) {
            $this->assertEqualsWithDelta($master[$i]['standar'], $t['standar_terkoreksi'], self::KETAT, "Standard Reading titik ke-{$i}");
            $this->assertEqualsWithDelta($master[$i]['uut'], $t['uut_terkoreksi'], self::KETAT, "Unit Under Test titik ke-{$i}");
            $this->assertEqualsWithDelta($master[$i]['koreksi'], $t['koreksi'], self::KETAT, "Correction titik ke-{$i}");
        }
    }

    public function test_thermocouple_budget_sama_dengan_master(): void
    {
        $hasil = (new ThermocoupleCalculator)->hitungSesi(self::TITIK_THERMOCOUPLE, self::SPEK_THERMOCOUPLE);

        // `PERHITUNGAN U95%!U20:U28`.
        $master = [
            'ketidakpastian_standar' => 0.12,
            'ketidakpastian_sensor' => 0.22,
            'stabilitas_cold_junction' => 0.0577350269,
            'drift_standar' => 0.012,
            'drift_sensor' => 0.25,
            'ac_pick_up' => 0.0577350269,
            'variasi_aksial' => 0.1154700538,
            'variasi_antar_lubang' => 0.0750555350,
            'daya_baca_indikator' => 0.0288675135,
        ];

        $ui = collect($hasil['budget'])->keyBy('sumber')->map(fn (array $k): float => $k['u'])->all();

        $this->assertSame(array_keys($master), array_keys($ui), 'Komponen budget & urutannya harus persis seperti `B20:B28` master.');

        foreach ($master as $sumber => $nilai) {
            $this->assertEqualsWithDelta($nilai, $ui[$sumber], self::KETAT, "ui komponen `{$sumber}`");
        }

        $this->assertEqualsWithDelta(0.3897571894, $hasil['ketidakpastian_gabungan'], self::KETAT, 'Uc (`AC30`)');
        $this->assertEqualsWithDelta(196.89067959, $hasil['derajat_kebebasan_efektif'], 1e-4, 'v_eff (`AC31`)');
        $this->assertEqualsWithDelta(1.9720880, $hasil['faktor_cakupan_k'], self::LONGGAR, 'k (`AC32`)');
        $this->assertEqualsWithDelta(0.7686360, $hasil['ketidakpastian_diperluas'], self::LONGGAR, 'U = k·Uc (`AC33`)');
        // `AC35 = MAX(AC33:AI34)` — CMC 0,84 menang atas hitungan 0,7686.
        $this->assertEqualsWithDelta(0.84, $hasil['u95_sertifikat'], 1e-9, 'U dilaporkan (`AC35`)');
        $this->assertSame('cmc', $hasil['sumber_u95']);
    }

    /**
     * Budget Thermocouple TIDAK memuat komponen keterulangan — sembilan
     * komponen `AC29 = SUM(AC20:AD28)` semuanya Type B.
     *
     * STDEV-nya tetap dihitung (`PERHITUNGAN FC!M23`) dan tetap dipulangkan,
     * tapi tidak ikut dijumlah. Yang menjaga arah sebaliknya: kalau suatu saat
     * ada yang menambahkan komponen keterulangan "biar lengkap", test ini merah.
     */
    public function test_thermocouple_budget_nggak_punya_komponen_keterulangan(): void
    {
        $hasil = (new ThermocoupleCalculator)->hitungSesi(self::TITIK_THERMOCOUPLE, self::SPEK_THERMOCOUPLE);

        $tStudent = array_filter($hasil['budget'], static fn (array $k): bool => $k['distribusi'] === 't-student');

        $this->assertSame([], $tStudent, 'Master Thermocouple nggak punya komponen keterulangan di budget-nya.');
        $this->assertArrayHasKey('standar_deviasi_maks', $hasil, 'STDEV terbesar tetap dihitung & dilaporkan, cuma nggak masuk budget.');
    }

    /**
     * Index tabel dicocokkan ke RATA-RATA pembacaan, bukan ke set point.
     *
     * Titik ke-3 set point 150 °C terbaca rata-rata 150,1 — dan 150,1 lebih
     * dekat ke titik tabel 200 (49,9) daripada ke 100 (50,1). Master mengambil
     * baris 200, koreksi −0,14. Dicocokkan ke set point-nya, yang terambil baris
     * 100 dengan koreksi −0,17 dan kolom `Correction` bergeser 0,03 °C.
     */
    public function test_thermocouple_index_dicocokkan_ke_rata_rata_bukan_set_point(): void
    {
        $hasil = (new ThermocoupleCalculator)->hitungSesi(self::TITIK_THERMOCOUPLE, self::SPEK_THERMOCOUPLE);

        $titik3 = $hasil['titik'][2];

        $this->assertSame(200.0, $titik3['index_uut'], 'Rata-rata UUT 150,1 mendarat di baris tabel 200 — bukan 150 (nggak ada) atau 100.');
        $this->assertEqualsWithDelta(-0.14, $titik3['koreksi_meter_uut'], self::KETAT);
        $this->assertSame(100.0, $titik3['index_standar'], 'Rata-rata standar 148,6 mendarat di baris tabel 100.');
    }

    /**
     * Pasangan (tipe sensor, No. Termokopel) yang tidak menunjuk probe mana pun
     * MEMBLOKIR titiknya — bukan diam-diam dikoreksi nol.
     *
     * Master membungkus VLOOKUP-nya `IFNA(…,"")`, jadi pasangan yang tidak cocok
     * pulang KOSONG dan kosong ikut dijumlah `J+Q+R` sebagai nol. Sertifikat
     * terbit dengan koreksi probe yang hilang, tanpa error di mana pun. Ini
     * satu-satunya tempat implementasi sengaja TIDAK meniru master.
     */
    public function test_probe_yang_nggak_ada_memblokir_titik_bukan_dikoreksi_nol(): void
    {
        $titik = self::TITIK_THERMOCOUPLE;
        // Type K cuma punya probe 1..16; nomor 17 itu RTD.
        $titik[0]['no_probe'] = 17;

        $hasil = (new ThermocoupleCalculator)->hitungSesi($titik, self::SPEK_THERMOCOUPLE);

        $diblokir = collect($hasil['belum_dihitung'])->firstWhere('titik_ke', 1);

        $this->assertNotNull($diblokir, 'Titik ber-No. Termokopel yang nggak menunjuk probe mana pun wajib diblokir.');
        $this->assertStringContainsString('No. Termokopel 17', $diblokir['alasan']);
        $this->assertCount(2, $hasil['titik'], 'Dua titik lain tetap kehitung — yang diblokir cuma yang bermasalah.');
    }

    // ================================================================
    // TERMOMETER GELAS — sesi 0135-CAL-125
    // ================================================================

    /** `INPUT DATA` baris 33–37 (standar) & 50–54 (UUT). */
    private const TITIK_GELAS = [
        ['titik_ke' => 1, 'titik_ukur' => 30.0, 'standar' => [29.9, 30.3, 30.3, 30.3, 30.3], 'uut' => [30, 30, 30, 30, 30]],
        ['titik_ke' => 2, 'titik_ukur' => 50.0, 'standar' => [51.5, 51.6, 51.5, 51.5, 51.5], 'uut' => [51, 51, 51, 51, 51]],
        ['titik_ke' => 3, 'titik_ukur' => 60.0, 'standar' => [61.7, 61.8, 61.7, 61.7, 61.7], 'uut' => [61, 61, 61, 61, 61]],
        ['titik_ke' => 4, 'titik_ukur' => 80.0, 'standar' => [81.9, 81.8, 81.9, 81.9, 81.9], 'uut' => [82, 82, 82, 82, 82]],
        ['titik_ke' => 5, 'titik_ukur' => 100.0, 'standar' => [99.2, 99.3, 99.3, 99.3, 99.3], 'uut' => [99, 99, 99, 99, 99]],
    ];

    private const SPEK_GELAS = [
        'merk_kalibrator' => 'yokogawa', 'tipe_sensor' => 'RTD', 'no_probe' => 17,
        'oilbath' => 'dua', 'resolusi' => 1.0, 'resolusi_standar' => 0.1, 'cmc' => 0.58,
        'titik_es' => [0.0, 0.0, 0.0],
    ];

    public function test_gelas_kolom_sertifikat_sama_dengan_master(): void
    {
        $hasil = (new ThermometerGlassCalculator)->hitungSesi(self::TITIK_GELAS, self::SPEK_GELAS);

        // `SERTIFIKAT!E21:L25`.
        $master = [
            ['standar' => 30.545029, 'uut' => 30.0, 'koreksi' => 0.545029],
            ['standar' => 51.870060, 'uut' => 51.0, 'koreksi' => 0.870060],
            ['standar' => 62.070060, 'uut' => 61.0, 'koreksi' => 1.070060],
            ['standar' => 82.280123, 'uut' => 82.0, 'koreksi' => 0.280123],
            ['standar' => 99.680123, 'uut' => 99.0, 'koreksi' => 0.680123],
        ];

        $this->assertSame([], $hasil['belum_dihitung']);

        foreach ($hasil['titik'] as $i => $t) {
            $this->assertEqualsWithDelta($master[$i]['standar'], $t['standar_terkoreksi'], self::KETAT, "Standard Reading titik ke-{$i}");
            // Sisi UUT APA ADANYA — termometer gelas dibaca dengan mata, nggak
            // ada koreksi instrumen di jalur itu.
            $this->assertEqualsWithDelta($master[$i]['uut'], $t['uut_terkoreksi'], self::KETAT, "Unit Under Test titik ke-{$i}");
            $this->assertEqualsWithDelta($master[$i]['koreksi'], $t['koreksi'], self::KETAT, "Correction titik ke-{$i}");
        }
    }

    public function test_gelas_budget_sama_dengan_master(): void
    {
        $hasil = (new ThermometerGlassCalculator)->hitungSesi(self::TITIK_GELAS, self::SPEK_GELAS);

        // `PERHITUNGAN U95%!U20:U30`.
        $master = [
            'ketidakpastian_standar' => 0.36,
            'ketidakpastian_sensor' => 0.03,
            'resolusi_standar' => 0.0288675135,
            'drift_standar' => 0.0415692194,
            'drift_sensor' => 0.2896570478,
            'resolusi_alat' => 0.2886751346,
            'pengulangan_uut' => 0.0,
            'pengulangan_standar' => 0.0357770876,
            'variasi_spasial_bath' => 0.1443375673,
            'stabilitas_titik_es' => 0.0,
            'stabilitas_bath' => 0.0288675135,
        ];

        $ui = collect($hasil['budget'])->keyBy('sumber')->map(fn (array $k): float => $k['u'])->all();

        $this->assertSame(array_keys($master), array_keys($ui), 'Komponen budget & urutannya harus persis seperti `B20:B30` master.');

        foreach ($master as $sumber => $nilai) {
            $this->assertEqualsWithDelta($nilai, $ui[$sumber], self::KETAT, "ui komponen `{$sumber}`");
        }

        $this->assertEqualsWithDelta(0.5685437, $hasil['ketidakpastian_gabungan'], self::KETAT, 'Uc (`AC32`)');
        $this->assertEqualsWithDelta(446.6202514, $hasil['derajat_kebebasan_efektif'], 1e-4, 'v_eff (`AC33`)');
        $this->assertEqualsWithDelta(1.9652890, $hasil['faktor_cakupan_k'], self::LONGGAR, 'k (`AC34`)');
        // Di sini hitungan MENANG atas CMC 0,58 — kebalikan Thermocouple.
        $this->assertEqualsWithDelta(1.1173545, $hasil['ketidakpastian_diperluas'], self::LONGGAR, 'U = k·Uc (`AC35`)');
        $this->assertEqualsWithDelta(1.1173545, $hasil['u95_sertifikat'], self::LONGGAR, 'U dilaporkan (`AC37`)');
        $this->assertSame('hitung', $hasil['sumber_u95']);
    }

    /**
     * Dua baris keterulangan bersebelahan, DUA pembagi berbeda.
     *
     * `U26 = N26/SQRT(Q26)` (÷√5) untuk UUT, `U27 = N27/Q27` (÷5) untuk standar.
     * Dua-duanya ber-`Q` 5 dan ber-`vi` 4, jadi ini bukan pembagi yang sengaja
     * beda arti — satu `SQRT` yang hilang. Ditiru; kalau suatu saat
     * "dibenerin", test ini yang jatuh duluan dan catatan auditnya yang
     * menjelaskan berapa selisihnya.
     */
    public function test_gelas_pengulangan_standar_dibagi_n_bukan_akar_n(): void
    {
        $hasil = (new ThermometerGlassCalculator)->hitungSesi(self::TITIK_GELAS, self::SPEK_GELAS);

        $stdevStandar = $hasil['standar_deviasi_maks_standar'];
        $komponen = collect($hasil['budget'])->firstWhere('sumber', 'pengulangan_standar');

        $this->assertEqualsWithDelta(0.1788854382, $stdevStandar, self::KETAT, 'STDEV terbesar standar (`PERHITUNGAN FC!M23`)');
        $this->assertEqualsWithDelta($stdevStandar / 5.0, $komponen['u'], self::KETAT, 'Dibagi 5 — mengikuti master.');
        $this->assertNotEqualsWithDelta($stdevStandar / sqrt(5.0), $komponen['u'], self::KETAT, 'BUKAN dibagi √5.');

        $catatan = collect($hasil['catatan_audit'])->firstWhere('kode', 'pengulangan_standar_dibagi_n');
        $this->assertNotNull($catatan, 'Penyimpangan ini wajib melahirkan catatan audit tiap sesi.');
    }

    /**
     * Uji titik es masuk budget lewat RENTANGNYA (Tmax − Tmin), bukan STDEV atau
     * rata-ratanya.
     *
     * Di sesi master ketiganya 0,0 jadi komponennya nol dan tidak kelihatan —
     * justru itu yang bikin dia gampang dikira catatan. Diuji dengan angka yang
     * bukan nol supaya perannya kebukti.
     */
    public function test_gelas_rentang_titik_es_masuk_budget(): void
    {
        $spek = self::SPEK_GELAS;
        $spek['titik_es'] = [0.0, 0.2, 0.4];

        $hasil = (new ThermometerGlassCalculator)->hitungSesi(self::TITIK_GELAS, $spek);
        $komponen = collect($hasil['budget'])->firstWhere('sumber', 'stabilitas_titik_es');

        $this->assertEqualsWithDelta(0.4, $hasil['rentang_titik_es'], self::KETAT, 'Tmax − Tmin, bukan STDEV.');
        $this->assertEqualsWithDelta((0.4 / 2.0) / sqrt(3.0), $komponen['u'], self::KETAT, '`N29 = Q46/2`, distribusi persegi.');
        $this->assertGreaterThan(1.1173545, $hasil['ketidakpastian_diperluas'], 'Titik es yang melar wajib menaikkan U95.');
    }

    // ================================================================
    // THERMOHYGROMETER — sesi 0312-CAL-624
    // ================================================================

    /** `INPUT DATA` baris 36–40, 53–57 (suhu) & 73–75, 90–92 + blok GEA (RH). */
    private const TITIK_THERMOHYGRO = [
        ['parameter' => 'suhu', 'titik_ke' => 1, 'titik_ukur' => 15.0, 'standar' => [14.96, 14.95, 14.99, 14.95, 14.97], 'uut' => [14.2, 14.2, 14.3, 14.5, 14.6]],
        ['parameter' => 'suhu', 'titik_ke' => 2, 'titik_ukur' => 25.0, 'standar' => [24.86, 24.85, 24.88, 24.85, 24.87], 'uut' => [24.2, 24.2, 24.5, 24.5, 24.6]],
        ['parameter' => 'suhu', 'titik_ke' => 3, 'titik_ukur' => 35.0, 'standar' => [34.94, 34.96, 34.93, 34.96, 34.92], 'uut' => [34.6, 34.6, 34.3, 34.5, 34.7]],
        ['parameter' => 'suhu', 'titik_ke' => 4, 'titik_ukur' => 45.0, 'standar' => [44.83, 44.85, 44.81, 44.85, 44.82], 'uut' => [44.5, 44.5, 44.3, 44.5, 44.6]],
        ['parameter' => 'suhu', 'titik_ke' => 5, 'titik_ukur' => 50.0, 'standar' => [54.91, 54.95, 54.99, 54.94, 54.97], 'uut' => [54.6, 54.6, 54.3, 54.5, 54.6]],
        ['parameter' => 'kelembaban', 'titik_ke' => 6, 'titik_ukur' => 50.0, 'standar' => [49.4, 49.5, 49.42, 49.43, 49.4], 'uut' => [49, 49, 49, 49, 49]],
        ['parameter' => 'kelembaban', 'titik_ke' => 7, 'titik_ukur' => 70.0, 'standar' => [69.4, 69.5, 69.42, 69.43, 69.4], 'uut' => [69, 69, 69, 69, 69]],
        ['parameter' => 'kelembaban', 'titik_ke' => 8, 'titik_ukur' => 90.0, 'standar' => [89.4, 89.5, 89.42, 89.43, 89.4], 'uut' => [89, 89, 89, 89, 89]],
        ['parameter' => 'kelembaban', 'titik_ke' => 9, 'titik_ukur' => 30.0, 'standar' => [29.49, 29.45, 29.32, 29.41, 29.46], 'uut' => [29, 29, 29, 29, 29]],
        ['parameter' => 'kelembaban', 'titik_ke' => 10, 'titik_ukur' => 49.0, 'standar' => [48.49, 48.45, 48.32, 48.41, 48.46], 'uut' => [48, 48, 48, 48, 48]],
    ];

    private const SPEK_THERMOHYGRO = [
        'resolusi_suhu' => 0.1, 'resolusi_kelembaban' => 1.0,
        'cmc_suhu' => 1.7, 'cmc_kelembaban' => 4.8,
    ];

    public function test_thermohygro_kolom_sertifikat_sama_dengan_master(): void
    {
        $hasil = (new ThermohygroCalculator)->hitungSesi(self::TITIK_THERMOHYGRO, self::SPEK_THERMOHYGRO);

        // `SERTIFIKAT!E22:N26` (suhu), `E49:N51` (RH Biobase), `E35:N36` (RH GEA).
        $master = [
            1 => [15.774, 14.36, 1.414], 2 => [25.602, 24.4, 1.202], 3 => [35.642, 34.54, 1.102],
            4 => [45.352, 44.48, 0.872], 5 => [56.152, 54.52, 1.632],
            6 => [48.83, 49.0, -0.17], 7 => [69.83, 69.0, 0.83], 8 => [88.53, 89.0, -0.47],
            9 => [29.926, 29.0, 0.926], 10 => [47.826, 48.0, -0.174],
        ];

        $this->assertSame([], $hasil['belum_dihitung']);

        $jumlah = 0;

        foreach ($hasil['grup'] as $grup) {
            foreach ($grup['titik'] as $t) {
                [$standar, $uut, $koreksi] = $master[$t['titik_ke']];
                $this->assertEqualsWithDelta($standar, $t['standar_terkoreksi'], self::KETAT, "Standard titik ke-{$t['titik_ke']}");
                $this->assertEqualsWithDelta($uut, $t['uut_terkoreksi'], self::KETAT, "UUT titik ke-{$t['titik_ke']}");
                $this->assertEqualsWithDelta($koreksi, $t['koreksi'], self::KETAT, "Correction titik ke-{$t['titik_ke']}");
                $jumlah++;
            }
        }

        $this->assertSame(10, $jumlah, 'Kesepuluh titik master wajib kehitung.');
    }

    /**
     * TIGA budget, bukan dua — kelembapan dikalibrasi di dua chamber dan
     * masing-masing punya stabilitas & homogenitas sendiri.
     */
    public function test_thermohygro_tiga_grup_dengan_u95_sendiri_sendiri(): void
    {
        $hasil = (new ThermohygroCalculator)->hitungSesi(self::TITIK_THERMOHYGRO, self::SPEK_THERMOHYGRO);

        $this->assertCount(3, $hasil['grup'], 'Suhu + kelembapan Biobase + kelembapan GEA.');

        // `PERHITUNGAN U95%` blok 20–32 (suhu), 41–53 (RH Biobase), 60–72 (RH GEA).
        $master = [
            'suhu|biobase' => ['uc' => 1.0085539, 'veff' => 1143.276667, 'k' => 1.9620420, 'u' => 1.9788250, 'u95' => 1.9788250, 'sumber' => 'hitung'],
            'kelembaban|biobase' => ['uc' => 2.2084832, 'veff' => 939.673145, 'k' => 1.9624940, 'u' => 4.3341340, 'u95' => 4.8, 'sumber' => 'cmc'],
            'kelembaban|gea' => ['uc' => 1.6912537, 'veff' => 322.070349, 'k' => 1.9673590, 'u' => 3.3273030, 'u95' => 4.8, 'sumber' => 'cmc'],
        ];

        foreach ($hasil['grup'] as $grup) {
            $kunci = $grup['parameter'].'|'.$grup['chamber'];

            $this->assertArrayHasKey($kunci, $master, "Grup `{$kunci}` nggak ada di master.");

            $this->assertEqualsWithDelta($master[$kunci]['uc'], $grup['ketidakpastian_gabungan'], self::KETAT, "Uc grup {$kunci}");
            $this->assertEqualsWithDelta($master[$kunci]['veff'], $grup['derajat_kebebasan_efektif'], 1e-4, "v_eff grup {$kunci}");
            $this->assertEqualsWithDelta($master[$kunci]['k'], $grup['faktor_cakupan_k'], self::LONGGAR, "k grup {$kunci}");
            $this->assertEqualsWithDelta($master[$kunci]['u'], $grup['ketidakpastian_diperluas'], self::LONGGAR, "U grup {$kunci}");
            $this->assertEqualsWithDelta($master[$kunci]['u95'], $grup['u95_sertifikat'], self::LONGGAR, "U dilaporkan grup {$kunci}");
            $this->assertSame($master[$kunci]['sumber'], $grup['sumber_u95'], "Sumber U95 grup {$kunci}");
        }
    }

    /**
     * Chamber diturunkan dari SET POINT, bukan dipilih teknisi.
     *
     * Titik 49 %RH jatuh ke GEA, 50 %RH ke Biobase. Digabung jadi satu budget,
     * titik 30 %RH akan memakai homogenitas Biobase (1,8) padahal chamber-nya
     * GEA (0,8) — `uc` naik dari 1,69 ke 2,21.
     */
    public function test_thermohygro_chamber_diturunkan_dari_set_point(): void
    {
        $this->assertSame(ThermohygroCalculator::CHAMBER_GEA, ThermohygroCalculator::chamberUntuk(30.0));
        $this->assertSame(ThermohygroCalculator::CHAMBER_GEA, ThermohygroCalculator::chamberUntuk(49.0));
        $this->assertSame(ThermohygroCalculator::CHAMBER_BIOBASE, ThermohygroCalculator::chamberUntuk(50.0));
        $this->assertSame(ThermohygroCalculator::CHAMBER_BIOBASE, ThermohygroCalculator::chamberUntuk(90.0));

        $hasil = (new ThermohygroCalculator)->hitungSesi(self::TITIK_THERMOHYGRO, self::SPEK_THERMOHYGRO);
        $gea = collect($hasil['grup'])->first(fn (array $g): bool => $g['chamber'] === ThermohygroCalculator::CHAMBER_GEA
            && $g['parameter'] === ThermohygroCalculator::PARAMETER_KELEMBABAN);

        $this->assertSame([9, 10], collect($gea['titik'])->pluck('titik_ke')->all(), 'Cuma titik 30 & 49 %RH yang masuk chamber GEA.');
    }

    /**
     * Delapan baris budget memakai `U = N/SQRT(Q)` padahal `Q` sudah berisi
     * pembaginya — dan baris drift budget GEA justru TIDAK.
     *
     * Tiga perlakuan untuk satu komponen dalam satu sheet; semuanya ditiru.
     */
    public function test_thermohygro_pembagi_akar_ganda_ditiru_apa_adanya(): void
    {
        $hasil = (new ThermohygroCalculator)->hitungSesi(self::TITIK_THERMOHYGRO, self::SPEK_THERMOHYGRO);
        $sqrt3 = sqrt(3.0);

        $suhu = collect($hasil['grup'])->firstWhere('parameter', 'suhu');
        $ui = collect($suhu['budget'])->keyBy('sumber')->map(fn (array $k): float => $k['u'])->all();

        // Stabilitas chamber Biobase suhu = 0,1 °C. `U24 = N24/SQRT(Q24)`.
        $this->assertEqualsWithDelta(0.1 / sqrt($sqrt3), $ui['stabilitas_chamber'], self::KETAT, 'Akar ganda, bukan ÷√3.');
        $this->assertNotEqualsWithDelta(0.1 / $sqrt3, $ui['stabilitas_chamber'], self::KETAT);
        // Drift kalibrator suhu = 0,615, `Q23 = 0.5*SQRT(3)`, `U23 = N23/SQRT(Q23)`.
        $this->assertEqualsWithDelta(0.615 / sqrt(0.5 * $sqrt3), $ui['drift_standar'], self::KETAT);

        // Budget GEA menulis baris drift-nya BENAR.
        $gea = collect($hasil['grup'])->first(fn (array $g): bool => $g['chamber'] === ThermohygroCalculator::CHAMBER_GEA
            && $g['parameter'] === ThermohygroCalculator::PARAMETER_KELEMBABAN);
        $uiGea = collect($gea['budget'])->keyBy('sumber')->map(fn (array $k): float => $k['u'])->all();

        $this->assertEqualsWithDelta(0.635 / $sqrt3, $uiGea['drift_standar'], self::KETAT, 'Drift GEA ÷√3 — `U61 = N61/Q61`.');

        $catatan = collect($suhu['catatan_audit'])->firstWhere('kode', 'pembagi_akar_ganda');
        $this->assertNotNull($catatan, 'Penyimpangan ini wajib melahirkan catatan audit tiap grup.');
    }

    // ================================================================
    // TABEL MASTER vs LAMPIRAN AKREDITASI
    // ================================================================

    /**
     * Tabel CMC di ketiga workbook sama persis dengan lampiran akreditasi
     * LK-285-IDN.
     *
     * Ini yang membedakan Thermocouple dari TIDS, dan yang membuat workbook
     * Thermocouple TIDAK bisa dipakai sebagai workbook TIDS: 0,84/1,5/3,3
     * (no. 5 Thermocouple) versus 0,86/1,4/3,1 (no. 2 TIDS).
     *
     * Kecocokan ini juga yang bikin dua sumber angka bisa DIUJI: begitu lab
     * memperbarui salah satunya tanpa yang lain, test ini merah — bukan
     * sertifikatnya yang jadi korban.
     */
    public function test_cmc_workbook_sama_dengan_lampiran_akreditasi(): void
    {
        $tabel = new TabelKalibratorSuhu3Alat;

        $this->assertSame(
            [0.84, 1.5, 3.3],
            array_column($tabel->cmcMaster(TabelKalibratorSuhu3Alat::ALAT_THERMOCOUPLE), 'u'),
            'CMC Thermocouple workbook = lampiran akreditasi no. 5. Kalau ini jadi 0,86/1,4/3,1 berarti '
            .'workbook-nya ketuker sama TIDS.',
        );

        $this->assertSame(
            [0.58, 1.0],
            array_column($tabel->cmcMaster(TabelKalibratorSuhu3Alat::ALAT_GLASS), 'u'),
            'CMC Termometer Gelas workbook = lampiran akreditasi no. 4.',
        );

        $this->assertSame(
            [1.7, 4.8],
            array_column($tabel->cmcMaster(TabelKalibratorSuhu3Alat::ALAT_THERMOHYGRO), 'u'),
            'CMC Thermohygrometer workbook = lampiran akreditasi no. 11.',
        );
    }

    /**
     * Peta No. Termokopel → probe mengikuti rumus `PERHITUNGAN FC!R23`, termasuk
     * penomoran Type N yang MULAI DARI 3.
     */
    public function test_peta_nomor_probe_mengikuti_master(): void
    {
        $tabel = new TabelKalibratorSuhu3Alat;
        $alat = TabelKalibratorSuhu3Alat::ALAT_THERMOCOUPLE;

        $this->assertSame('TCK-01', $tabel->probe($alat, 'Type K', 1));
        $this->assertSame('TCK-16', $tabel->probe($alat, 'Type K', 16));
        $this->assertNull($tabel->probe($alat, 'Type K', 17), 'Type K berhenti di 16.');

        $this->assertSame('TCN3', $tabel->probe($alat, 'Type N', 3));
        $this->assertNull($tabel->probe($alat, 'Type N', 1), 'Type N MULAI dari 3, bukan 1.');
        $this->assertSame('TCN12', $tabel->probe($alat, 'Type N', 12));

        $this->assertSame('RTD', $tabel->probe($alat, 'RTD', 17));
        $this->assertSame([17], $tabel->nomorProbeTersedia($alat, 'RTD'), 'RTD cuma punya satu probe, nomor 17.');
    }
}
