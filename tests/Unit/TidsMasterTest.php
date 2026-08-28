<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarTids;
use App\Services\Calibration\TidsCalculator;
use Tests\TestCase;

/**
 * Golden test TIDS lawan DUA workbook master lab yang turun 28 Agt 2026:
 *
 *   `Master_Olah_Data_Suhu_TIDS_-_Recorder_Graptech.xlsm`   sesi `071-CAL-325`
 *   `Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K,N.xlsm`        sesi Thermometer Bola Basah
 *
 * Sel yang diadu, sama untuk dua-duanya:
 *
 *   besaran                        sel master
 *   Standard / UUT / Correction    `SERTIFIKAT` kolom E / J / L
 *   `ui` tiap komponen budget      `PERHITUNGAN U95%` kolom U
 *   Uc                             `AC37`
 *   v_eff                          `AC38`
 *   k                              `AC39`
 *   U = k·Uc                       `AC40`
 *   U dilaporkan                   `AC42`
 *
 * ## Kenapa `k` & `U` bertoleransi lebih longgar dari `Uc` & `v_eff`
 *
 * Alasan yang sama persis dengan `Suhu3AlatMasterTest`: master mencari `k`
 * lewat aproksimasi polinomial Excel (`1.95996 + 2.37356/v + …`), repo ini
 * lewat `StudentTDistribution` yang punya test-nya sendiri. Pada `v_eff` yang
 * sama keduanya berbeda di orde 1e-5 — di bawah presisi cetak sertifikat, tapi
 * bukan nol.
 *
 * ## Yang paling penting diuji di sini
 *
 * Bukan "angkanya cocok", tapi **empat penyimpangan master yang sengaja
 * ditiru** — kalau salah satunya diam-diam "dibenerin" nanti, test ini yang
 * jatuh duluan, dan catatan auditnya yang menjelaskan kenapa:
 *
 *  1. Recorder: U95 kalibrator dari sel TETAP `T30` (0,83) walau sesinya Type K
 *     yang tabelnya berbunyi 0,67.
 *  2. Recorder: U95 sensor literal 0,14 walau tabelnya berbunyi 0,44.
 *  3. Recorder: drift kalibrator dari `AM9` (−0,2) — sel di tabel KOREKSI,
 *     bukan di `Tabel_Drift_Recorder` (0,5).
 *  4. Constant/Yokogawa: `AC36 = SUM(AC24:AD32)` cuma menjumlah SEMBILAN dari
 *     dua belas komponen.
 *
 * Plus dua yang bentuk, bukan angka:
 *
 *  5. Sisi UUT TIDAK dikoreksi apa pun (`SERTIFIKAT!J20` langsung dari `J42`).
 *  6. Index tabel dicocokkan ke RATA-RATA pembacaan standar, bukan ke set
 *     point — set point 100 °C yang terbaca rata-rata 93,98 mengambil baris
 *     tabel 100, dan set point 60 yang terbaca 60,14 mengambil baris 50.
 */
class TidsMasterTest extends TestCase
{
    /** Toleransi `ui`, `Uc`, dan kolom sertifikat — harus identik. */
    private const KETAT = 5e-6;

    /** Toleransi `k` & `U`: selisih aproksimasi polinomial Excel vs StudentTDistribution. */
    private const LONGGAR = 5e-5;

    /**
     * Sesi `071-CAL-325` — `INPUT DATA` baris 33..36 (standar) & 50..53 (UUT).
     *
     * @return list<array<string, mixed>>
     */
    private function titikRecorder(): array
    {
        return [
            ['titik_ke' => 1, 'titik_ukur' => 0.0, 'no_sensor' => 1,
                'standar' => [0.0, 0.0, 0.0, 0.0, 0.0], 'uut' => [0.0, 0.0, 0.0, 0.0, 0.0]],
            ['titik_ke' => 2, 'titik_ukur' => 60.0, 'no_sensor' => 2,
                'standar' => [60.1, 60.1, 60.1, 60.2, 60.2], 'uut' => [60.3, 60.34, 60.45, 60.47, 60.41]],
            ['titik_ke' => 3, 'titik_ukur' => 100.0, 'no_sensor' => 3,
                'standar' => [92.8, 93.4, 94.5, 94.6, 94.6], 'uut' => [92.29, 92.85, 94.05, 94.09, 94.12]],
            ['titik_ke' => 4, 'titik_ukur' => 200.0, 'no_sensor' => 4,
                'standar' => [193.0, 193.0, 193.1, 193.1, 193.1], 'uut' => [192.17, 192.19, 192.21, 192.24, 192.25]],
        ];
    }

    /** @return array<string, mixed> */
    private function spekRecorder(): array
    {
        return [
            'keluarga_standar' => 'recorder',
            'tipe_sensor' => 'Type K',
            'dryblock' => 'A',
            // `INPUT DATA!E16` — resolusi UUT sesi contoh.
            'resolusi' => 0.01,
            // Pita 150…400 °C, dipilih dari set point tertinggi 200 (`AC41`).
            'cmc' => 1.4,
            // `INPUT DATA!N50` & `P50` — uji titik es 0 °C.
            'titik_es' => [0.2, 0.4],
        ];
    }

    /**
     * Kolom `SERTIFIKAT` sesi Recorder: Standard Reading, UUT, Correction.
     */
    public function test_recorder_kolom_sertifikat_cocok_master(): void
    {
        $hasil = (new TidsCalculator)->hitungSesi($this->titikRecorder(), $this->spekRecorder());

        $this->assertSame([], $hasil['belum_dihitung'], 'Keempat titik master harusnya kehitung semua.');
        $this->assertCount(4, $hasil['titik']);

        // `SERTIFIKAT!E20:E23`, `J20:J23`, `L20:L23`.
        $master = [
            ['standar' => -0.27, 'uut' => 0.0, 'koreksi' => -0.27, 'index' => 0.0],
            ['standar' => 60.214999999999996, 'uut' => 60.394000000000005, 'koreksi' => -0.179, 'index' => 50.0],
            ['standar' => 94.23499999999999, 'uut' => 93.47999999999999, 'koreksi' => 0.755, 'index' => 100.0],
            ['standar' => 193.15, 'uut' => 192.21200000000002, 'koreksi' => 0.938, 'index' => 200.0],
        ];

        foreach ($hasil['titik'] as $i => $t) {
            $this->assertEqualsWithDelta($master[$i]['standar'], $t['standar_terkoreksi'], self::KETAT, "Standard Reading titik ke-{$i}");
            $this->assertEqualsWithDelta($master[$i]['uut'], $t['uut_terkoreksi'], self::KETAT, "Unit Under Test titik ke-{$i}");
            $this->assertEqualsWithDelta($master[$i]['koreksi'], $t['koreksi'], self::KETAT, "Correction titik ke-{$i}");

            // Penyimpangan 5: sisi UUT nggak dikoreksi apa pun. Rata-rata
            // mentahnya yang dicetak, bukan rata-rata + koreksi meter seperti
            // Thermocouple.
            $this->assertSame(
                $t['rata_rata_uut'],
                $t['uut_terkoreksi'],
                "Sisi UUT titik ke-{$i} kena koreksi — master mencetak rata-rata mentahnya.",
            );

            // Penyimpangan 6: index dicocokkan ke RATA-RATA standar, bukan set
            // point. Titik ke-1 paling tajam: set point 60, rata-rata 60,14,
            // dan baris tabel yang diambil 50 — bukan 100 (nggak ada baris 60).
            $this->assertSame($master[$i]['index'], $t['index_standar'], "Index tabel titik ke-{$i}");
        }
    }

    /**
     * Dua belas komponen `PERHITUNGAN U95%!B24:B35` sesi Recorder, berikut
     * Uc / v_eff / k / U.
     */
    public function test_recorder_budget_dua_belas_komponen_cocok_master(): void
    {
        $hasil = (new TidsCalculator)->hitungSesi($this->titikRecorder(), $this->spekRecorder());

        // Kolom `U` (`U24:U35`) master, urut seperti barisnya.
        $master = [
            'ketidakpastian_standar' => 0.415,                 // O24 0,83 ÷ 2   ← sel tetap T30
            'ketidakpastian_sensor' => 0.07,                   // O25 0,14 ÷ 2   ← literal
            'inhomogenitas_termokopel' => 0.34641016151377546, // N26 0,6 ÷ √3
            'drift_standar' => -0.11547005383792516,           // N27 −0,2 ÷ √3  ← sel AM9
            'drift_sensor' => 0.3175426480542942,              // N28 0,55 ÷ √3
            'daya_baca_uut' => 0.002886751345948129,           // N29 (0,01÷2) ÷ √3
            'keterulangan_pembacaan' => 0.37202150475476453,   // N30 M23 ÷ √5
            'stabilitas_media' => 0.0002886751345948129,       // O31 0,0005 ÷ √3
            'keseragaman_media' => 0.27135462651912345,        // O32 0,47 ÷ √3
            'self_heating_rtd' => 0.0,                         // O33 0 ÷ √3
            'interpolasi' => 0.19788162882115856,              // O34 ÷ 1
            'drift_uut' => 0.05773502691896258,                // O35 (0,5×0,2) ÷ √3
        ];

        $ui = [];
        foreach ($hasil['budget'] as $k) {
            $ui[$k['sumber']] = $k['u'];
            $this->assertTrue($k['disertakan'], "Komponen `{$k['sumber']}` harusnya ikut dijumlah di workbook Recorder.");
        }

        $this->assertSame(
            array_keys($master),
            array_keys($ui),
            'Komponen budget & urutannya harus persis seperti `B24:B35` master.',
        );

        foreach ($master as $sumber => $nilai) {
            $this->assertEqualsWithDelta($nilai, $ui[$sumber], self::KETAT, "ui komponen `{$sumber}`");
        }

        $this->assertEqualsWithDelta(0.8159803239201995, $hasil['ketidakpastian_gabungan'], self::KETAT, 'Uc (`AC37`)');
        $this->assertEqualsWithDelta(82.78188731677758, $hasil['derajat_kebebasan_efektif'], 1e-4, 'v_eff (`AC38`)');
        $this->assertEqualsWithDelta(1.98904830751697, $hasil['faktor_cakupan_k'], self::LONGGAR, 'k (`AC39`)');
        $this->assertEqualsWithDelta(1.6230242822606218, $hasil['ketidakpastian_diperluas'], self::LONGGAR, 'U = k·Uc (`AC40`)');

        // `AC42 = MAX(AC40:AI41)` — hitungan 1,623 menang atas CMC 1,4.
        $this->assertEqualsWithDelta(1.6230242822606218, $hasil['u95_sertifikat'], self::LONGGAR, 'U dilaporkan (`AC42`)');
        $this->assertSame('hitung', $hasil['sumber_u95']);

        // `N30 = 'PERHITUNGAN FC'!M23` — STDEV terbesar tabel STANDAR (titik
        // ke-3), bukan tabel UUT (`M42` = 0,8543, titik ke-3 juga tapi angkanya
        // beda). Master menghitung dua-duanya lalu cuma memakai yang pertama.
        $this->assertEqualsWithDelta(0.8318653737234147, $hasil['standar_deviasi_maks'], self::KETAT, '`M23`');
        $this->assertEqualsWithDelta(0.8543418519538885, $hasil['standar_deviasi_maks_uut'], self::KETAT, '`M42` — dihitung, nggak dipakai');
    }

    /**
     * Penyimpangan 1–3: tiga angka Recorder datang dari sel tetap, dan catatan
     * auditnya menyebut berapa U95-nya kalau dibaca dari tabel.
     */
    public function test_recorder_tiga_sel_tetap_dilaporkan_di_catatan_audit(): void
    {
        $hasil = (new TidsCalculator)->hitungSesi($this->titikRecorder(), $this->spekRecorder());

        $kode = array_column($hasil['catatan_audit'], 'kode');
        $this->assertContains('tids_recorder_sel_tetap', $kode);

        $catatan = collect($hasil['catatan_audit'])->firstWhere('kode', 'tids_recorder_sel_tetap');

        // Angka pembandingnya wajib DIHITUNG, bukan ditaksir: 0,67 (U95 Type K
        // dari tabel) · 0,44 (U95 sensor) · 0,5 (drift Type K).
        $this->assertStringContainsString('0,67', $catatan['pesan']);
        $this->assertStringContainsString('0,44', $catatan['pesan']);
        $this->assertStringContainsString('0,5', $catatan['pesan']);

        // Dan angka "kalau ketiganya dibaca dari tabel" DIHITUNG, bukan
        // ditaksir. Dipatok di sini karena dia ikut dikutip di
        // `docs/pertanyaan-lab-tids-workbook.md` & ringkasan buat lab —
        // dokumen yang dipakai memutuskan, jadi angkanya nggak boleh bergeser
        // diam-diam.
        //
        // Perhatikan arahnya: hasilnya LEBIH BESAR dari 1,6230 yang terbit
        // sekarang. D1 sendirian menurunkan (0,83 → 0,67), tapi D2 (0,14 →
        // 0,44) dan D3 (0,2 → 0,5) menaikkan, dan dua yang menaikkan menang.
        $this->assertStringContainsString('1,683632', $catatan['pesan']);

        // Dan tabelnya sendiri memang berbunyi begitu — kalau nggak, catatan
        // auditnya cuma mengulang angka yang kita ketik sendiri.
        $tabel = new TabelStandarTids;
        $this->assertSame(0.67, $tabel->u95Meter('recorder', 'Type K', 200.0, 4));
        $this->assertSame(0.44, $tabel->u95Sensor('recorder', 'Type K', 4, 200.0));
        $this->assertSame(0.5, $tabel->driftMeter('recorder', 'Type K'));
    }

    /**
     * Sesi Yokogawa: dua set point, sensor PRT PT100.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function sesiYokogawa(): array
    {
        return [
            [
                ['titik_ke' => 1, 'titik_ukur' => 0.0, 'no_sensor' => TabelStandarTids::NOMOR_RTD,
                    'standar' => [0.1, 0.1, 0.1, 0.1, 0.1], 'uut' => [0.0, 0.0, 0.0, 0.0, 0.0]],
                ['titik_ke' => 2, 'titik_ukur' => 35.0, 'no_sensor' => TabelStandarTids::NOMOR_RTD,
                    'standar' => [35.2, 35.2, 35.2, 35.2, 35.2], 'uut' => [35.0, 35.0, 35.0, 35.0, 35.0]],
            ],
            [
                'keluarga_standar' => 'yokogawa',
                'tipe_sensor' => 'RTD',
                'dryblock' => 'A',
                'resolusi' => 0.2,
                // Pita −20…150 °C, dari set point tertinggi 35.
                'cmc' => 0.86,
                'titik_es' => [0.2, 0.4],
            ],
        ];
    }

    public function test_yokogawa_kolom_sertifikat_dan_budget_cocok_master(): void
    {
        [$titik, $spek] = $this->sesiYokogawa();
        $hasil = (new TidsCalculator)->hitungSesi($titik, $spek);

        $this->assertSame([], $hasil['belum_dihitung']);

        // `SERTIFIKAT!E20:E21` — `S23` & `S24` di `PERHITUNGAN FC`.
        $this->assertEqualsWithDelta(0.4, $hasil['titik'][0]['standar_terkoreksi'], self::KETAT, '`S23`');
        $this->assertEqualsWithDelta(35.52502900705911, $hasil['titik'][1]['standar_terkoreksi'], self::KETAT, '`S24`');

        // `P37 = MAX(P23:P36)` — index tertinggi, dipakai buat U95 meter & sensor.
        $this->assertSame(25.0, $hasil['index_maks'], '`P37`');

        $ui = [];
        foreach ($hasil['budget'] as $k) {
            $ui[$k['sumber']] = $k['u'];
        }

        $this->assertEqualsWithDelta(0.36, $ui['ketidakpastian_standar'], self::KETAT, 'O24 = U95_Yokogawa RTD @25 (0,72) ÷ 2');
        $this->assertEqualsWithDelta(0.03, $ui['ketidakpastian_sensor'], self::KETAT, 'O25 = U95_Sensor RTD @25 (0,06) ÷ 2');
        $this->assertEqualsWithDelta(0.025259074277046132, $ui['inhomogenitas_termokopel'], self::KETAT, 'N26 = 0,25% × 35 ÷ 2 ÷ √3');
        $this->assertEqualsWithDelta(0.041569219381653054, $ui['drift_standar'], self::KETAT, 'N27 = Tabel_Drift_Yokogawa RTD (0,072) ÷ √3');
        $this->assertEqualsWithDelta(0.28982983513319216, $ui['drift_sensor'], self::KETAT, 'N28 = Tabel_Drift_Sensor RTD (0,502) ÷ √3');

        $this->assertEqualsWithDelta(0.5420646678825934, $hasil['ketidakpastian_gabungan'], self::KETAT, 'Uc (`AC37`)');
        $this->assertEqualsWithDelta(258.7971020878842, $hasil['derajat_kebebasan_efektif'], 1e-3, 'v_eff (`AC38`)');
        $this->assertEqualsWithDelta(1.969173742440642, $hasil['faktor_cakupan_k'], self::LONGGAR, 'k (`AC39`)');
        $this->assertEqualsWithDelta(1.0674195106992102, $hasil['ketidakpastian_diperluas'], self::LONGGAR, 'U (`AC40`)');
        $this->assertSame('hitung', $hasil['sumber_u95']);
    }

    /**
     * Penyimpangan 4: `AC36 = SUM(AC24:AD32)` berhenti di baris 32, jadi tiga
     * komponen terakhir lahir tapi nggak ikut dijumlah — dan catatan auditnya
     * menyebut berapa U95-nya kalau ikut.
     *
     * Ini penyimpangan yang paling mahal dari keempatnya: dia menggeser U95 ke
     * arah lebih KECIL, dan workbook Recorder untuk alat yang SAMA menjumlah
     * keduabelasnya. Dua master, satu alat, dua jawaban.
     */
    public function test_yokogawa_tiga_komponen_terakhir_nggak_ikut_dijumlah(): void
    {
        [$titik, $spek] = $this->sesiYokogawa();
        $hasil = (new TidsCalculator)->hitungSesi($titik, $spek);

        $dibuang = ['self_heating_rtd', 'interpolasi', 'drift_uut'];

        foreach ($hasil['budget'] as $k) {
            $this->assertSame(
                ! in_array($k['sumber'], $dibuang, true),
                $k['disertakan'],
                "Komponen `{$k['sumber']}` salah status di workbook Constant/Yokogawa.",
            );
        }

        // Keduabelasnya tetap DIHITUNG & dipulangkan — yang beda cuma
        // `disertakan`. Kalau komponennya dibuang dari daftar, jejak auditnya
        // ikut hilang dan penyimpangan ini jadi nggak kelihatan.
        $this->assertCount(12, $hasil['budget']);

        $catatan = collect($hasil['catatan_audit'])->firstWhere('kode', 'tids_tiga_komponen_tidak_dijumlah');
        $this->assertNotNull($catatan, 'Penyimpangan `AC36` wajib melahirkan catatan audit.');
        $this->assertStringContainsString('1,141102', $catatan['pesan'], 'U95 "kalau ketiganya ikut" harus DIHITUNG.');

        // Dan angka itu bukan karangan: hitung ulang dengan keluarga Recorder
        // (yang menjumlah keduabelasnya) atas komponen yang sama.
        $penuh = (new TidsCalculator)->hitungSesi($titik, [...$spek, 'keluarga_standar' => 'constant']);
        $this->assertLessThan(
            $penuh['ketidakpastian_diperluas'] + 1.0,
            $hasil['ketidakpastian_diperluas'],
            'Sanity: U95 sembilan komponen nggak boleh lebih besar dari dua belas komponen.',
        );
    }

    /**
     * PRT PT100 di recorder: kombinasi yang master-nya memulangkan KOSONG lalu
     * menjumlahkannya sebagai nol. Di sini titiknya ditahan.
     */
    public function test_rtd_di_recorder_ditahan_bukan_dikoreksi_nol(): void
    {
        $hasil = (new TidsCalculator)->hitungSesi(
            [[
                'titik_ke' => 1, 'titik_ukur' => 100.0, 'no_sensor' => TabelStandarTids::NOMOR_RTD,
                'standar' => [100.0, 100.1, 100.0, 100.1, 100.0], 'uut' => [99.9, 99.9, 100.0, 99.9, 99.9],
            ]],
            [...$this->spekRecorder(), 'tipe_sensor' => 'RTD'],
        );

        $this->assertSame([], $hasil['titik']);
        $this->assertCount(1, $hasil['belum_dihitung']);
        $this->assertStringContainsString('kanal recorder', $hasil['belum_dihitung'][0]['alasan']);
    }

    /**
     * Set point tertinggi dihitung dari SELURUH titik, bukan cuma yang berhasil
     * dihitung.
     *
     * `MAX` master (`D56 = MAX(B42:C55)`) menyapu seluruh kolom set point tabel
     * UUT, dan baris yang koreksinya nggak ketemu tetap punya set point di
     * situ. Selain lebih setia, ini juga yang bikin pita CMC (dipilih
     * `TidsProfile::kemampuanUntukTitik()` dari daftar titik yang sama) dan
     * komponen in-homogeneity nggak bisa diam-diam memakai dua set point
     * tertinggi yang berbeda — dan selisih itu nggak akan memunculkan error di
     * mana pun, cuma pita CMC yang bergeser satu tingkat.
     */
    public function test_set_point_tertinggi_ikut_titik_yang_ditahan(): void
    {
        [$titik, $spek] = $this->sesiYokogawa();

        // Titik ketiga jauh lebih panas DAN sengaja ditahan: No. Termokopel 99
        // nggak ada di tabel sensor mana pun.
        $titik[] = [
            'titik_ke' => 3, 'titik_ukur' => 420.0, 'no_sensor' => 99,
            'standar' => [420.0, 420.1, 420.0, 420.1, 420.0],
            'uut' => [419.9, 419.9, 420.0, 419.9, 419.9],
        ];

        $hasil = (new TidsCalculator)->hitungSesi($titik, $spek);

        $this->assertCount(1, $hasil['belum_dihitung'], 'Titik ke-3 memang harus ditahan.');
        $this->assertSame(3, $hasil['belum_dihitung'][0]['titik_ke']);
        $this->assertSame(
            420.0,
            $hasil['set_point_maks'],
            'Set point titik yang ditahan tetap ikut `MAX` — kertasnya juga begitu.',
        );
    }

    /**
     * CMC yang tercetak di kedua master harus sama dengan pita lampiran
     * akreditasi yang jadi lantai U95 — dan justru karena sama, kecocokannya
     * bisa diuji.
     */
    public function test_cmc_master_sama_dengan_pita_lampiran_akreditasi(): void
    {
        $this->assertSame(
            [
                ['min' => -20.0, 'maks' => 150.0, 'u95' => 0.86],
                ['min' => 150.0, 'maks' => 400.0, 'u95' => 1.4],
                ['min' => 400.0, 'maks' => 600.0, 'u95' => 3.1],
            ],
            (new TabelStandarTids)->cmcMaster(),
            'CMC master TIDS bergeser dari 0,86 / 1,4 / 3,1 — pita LK-285-IDN no. 2. Kalau ini merah, yang '
            .'berubah dokumen lab, dan lantai U95 seluruh sesi TIDS ikut bergeser.',
        );
    }
}
