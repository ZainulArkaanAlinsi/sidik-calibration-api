<?php

namespace Tests\Unit;

use App\Services\Calibration\EnclosureCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden test Enclosure lawan kedua master:
 * `Master Olah Data_Suhu_Enclosure_Constant_Yokogawa.xlsm` (sesi 0123-CAL-524,
 * Incubator-02, Yokogawa + Type N, 4 set point 15/35/75/100 °C) dan
 * `Master Olah Data_Suhu_Enclosure_Recorder.xlsm` (sesi 0304-CAL-624, Oven,
 * Recorder GL840 + Type K, 3 set point @ 67 °C).
 *
 * Fixture `tests/Fixtures/enclosure-golden.json` berisi grid mentah 9 termokopel
 * × 5 pembacaan + Indikator tiap set point, plus sel master yang diadu:
 *
 *   besaran                    sel master
 *   sebaran terkoreksi/sensor  `PERHITUNGAN FC` Z##/AC## (AVG Terkoreksi)
 *   Uc                         `PERHITUNGAN U95%` AC## (Ketidakpastian Gabungan)
 *   v_eff                      AC## (Derajat Kebebasan)
 *   U dilaporkan               AC## (Bentangan Yang Dilaporkan, lantai CMC)
 *
 * ## Dua set point yang master-nya sendiri salah sel (tetap diuji)
 *
 *  - **Yokogawa SP3 (75 °C):** sel `O75` (komponen "Temperature Kalibrator")
 *    keisi rumus DRIFT (`VLOOKUP(...Tabel_Drift...)` → 0,035) alih-alih U95
 *    kalibrator (0,24). Uc master jadi 0,6234; kalkulator ini menghitung benar
 *    0,6346. Yang tercetak di sertifikat SAMA (lantai CMC 1,4 menang di
 *    dua-duanya), jadi kalkulator SETIA ke sertifikat yang terbit sambil tidak
 *    menyalin bug sel-nya. Uc & v_eff-nya TIDAK diadu ke master di sini.
 *  - **Recorder SP3 (67 °C):** sel `v_eff` (`AC76`) menghasilkan 1620 padahal
 *    komponennya identik dengan SP1/SP2 (`Uc` sama persis 0,5954). Selisih murni
 *    di rumus `v_eff` SP3. `v_eff`-nya TIDAK diadu; `Uc` & U dilaporkan tetap.
 *
 * Keduanya masuk `docs/pertanyaan-lab-enclosure.md`.
 *
 * ## Kenapa `k` bertoleransi longgar
 *
 * Master cari `k` lewat polinomial Excel (`1.95996 + 2.37356/v + …`), repo ini
 * lewat `StudentTDistribution`. Pada `v_eff` besar (ratusan–ribuan) selisihnya
 * ~10⁻⁶–10⁻⁵ — di bawah presisi cetak, tapi di atas presisi double. Yang diuji
 * ketat: sebaran, `Uc`, `v_eff`. Yang longgar: `k`, `U`.
 *
 * @see EnclosureCalculator
 * @see docs/pertanyaan-lab-enclosure.md
 */
class EnclosureBudgetTest extends TestCase
{
    private const TOLERANSI = 1e-9;

    private const TOLERANSI_K = 5e-4;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /**
     * @return array<string, array{string, int, bool, bool, bool}> [workbook, index, uji_uc, uji_veff, uji_dist]
     */
    public static function setpoints(): array
    {
        return [
            'yokogawa SP1 (15 °C)' => ['yokogawa', 0, true, true, true],
            'yokogawa SP2 (35 °C)' => ['yokogawa', 1, true, true, true],
            // SP3: sel O75 master salah rumus (budget) — sebaran-nya sendiri BENAR,
            // jadi Uc & v_eff tidak diadu tapi sebaran tetap.
            'yokogawa SP3 (75 °C, budget master salah)' => ['yokogawa', 2, false, false, true],
            'yokogawa SP4 (100 °C)' => ['yokogawa', 3, true, true, true],
            'recorder SP1 (67 °C)' => ['recorder', 0, true, true, true],
            'recorder SP2 (67 °C)' => ['recorder', 1, true, true, true],
            // SP3: seluruh blok SP3 master rusak — koreksi sensor jatuh 0 (harusnya
            // −0,08) DAN v_eff salah rumus. Cuma U dilaporkan (1,5) & Uc yang diadu.
            'recorder SP3 (67 °C, blok master salah)' => ['recorder', 2, true, false, false],
        ];
    }

    #[DataProvider('setpoints')]
    public function test_set_point_cocok_master(string $workbook, int $index, bool $ujiUc, bool $ujiVeff, bool $ujiDist): void
    {
        $fix = self::fixture()[$workbook];
        $sp = $fix['setpoints'][$index];
        $hasil = $this->hitung($fix, $sp);
        $e = $sp['expected'];

        // Sebaran suhu — AVG Terkoreksi tiap sensor (yang tercetak di sertifikat).
        if ($ujiDist) {
            foreach ($sp['dist_terkoreksi'] as $i => $harap) {
                $this->assertEqualsWithDelta(
                    $harap,
                    $hasil['sensor'][$i]['rata_rata_terkoreksi'],
                    self::TOLERANSI,
                    "AVG Terkoreksi sensor ke-{$i} meleset",
                );
            }
        }

        if ($ujiUc) {
            $this->assertEqualsWithDelta($e['Uc'], $hasil['ketidakpastian_gabungan'], self::TOLERANSI, 'Uc meleset');
        }

        if ($ujiVeff) {
            $this->assertEqualsWithDelta($e['veff'], $hasil['derajat_kebebasan_efektif'], self::TOLERANSI, 'v_eff meleset');
            $this->assertEqualsWithDelta($e['k'], $hasil['faktor_cakupan_k'], self::TOLERANSI_K, 'k meleset');
            $this->assertEqualsWithDelta($e['U'], $hasil['ketidakpastian_diperluas'], self::TOLERANSI_K, 'U meleset');
        }

        // Yang PALING penting: U95 yang DILAPORKAN sama dengan sertifikat.
        $this->assertEqualsWithDelta($e['reported'], $hasil['u95_sertifikat'], self::TOLERANSI, 'U95 dilaporkan meleset');
    }

    /**
     * Yokogawa SP3: kalkulator menghitung Uc BENAR (0,6346), bukan menyalin bug
     * sel `O75` master (0,6234). Yang tercetak tetap 1,4 (lantai CMC).
     */
    public function test_yokogawa_sp3_menghitung_benar_bukan_menyalin_bug_master(): void
    {
        $fix = self::fixture()['yokogawa'];
        $hasil = $this->hitung($fix, $fix['setpoints'][2]);

        $this->assertEqualsWithDelta(0.6346331673, $hasil['ketidakpastian_gabungan'], self::TOLERANSI);
        $this->assertEqualsWithDelta(1.4, $hasil['u95_sertifikat'], self::TOLERANSI);
        $this->assertSame('cmc', $hasil['sumber_u95']);
    }

    /**
     * Constant/Yokogawa punya 11 komponen budget (termasuk Konduksi Panas),
     * Recorder 10 (tanpa Konduksi Panas). Kalau salinan tabel suatu saat bikin
     * dua-duanya sama, tes ini yang jatuh.
     */
    public function test_jumlah_komponen_budget_beda_dua_template(): void
    {
        $fix = self::fixture();
        $yoko = $this->hitung($fix['yokogawa'], $fix['yokogawa']['setpoints'][0]);
        $rec = $this->hitung($fix['recorder'], $fix['recorder']['setpoints'][0]);

        $this->assertCount(11, $yoko['budget'], 'Constant/Yokogawa harus 11 komponen');
        $this->assertCount(10, $rec['budget'], 'Recorder harus 10 komponen');

        $sumberYoko = array_column($yoko['budget'], 'sumber');
        $this->assertContains('konduksi_panas', $sumberYoko);
        $this->assertNotContains('konduksi_panas', array_column($rec['budget'], 'sumber'));

        // Radiasi 0,6 di Yokogawa, 0,1 di Recorder — beda, bukan salinan.
        $radYoko = $this->komponen($yoko['budget'], 'efek_radiasi');
        $radRec = $this->komponen($rec['budget'], 'efek_radiasi');
        $this->assertEqualsWithDelta(0.6 / sqrt(3.0), $radYoko['u'], self::TOLERANSI);
        $this->assertEqualsWithDelta(0.1 / sqrt(3.0), $radRec['u'], self::TOLERANSI);
    }

    /**
     * Penyimpangan master yang ditiru — dijaga supaya nggak diam-diam "dibenerin":
     *  - drift kalibrator & sensor dibagi 1,73 literal, bukan √3;
     *  - Pengulangan Standar Recorder dibagi √(√3), bukan √3.
     */
    public function test_pembagi_master_ditiru(): void
    {
        $fix = self::fixture();
        $rec = $this->hitung($fix['recorder'], $fix['recorder']['setpoints'][0]);

        // Drift kalibrator Recorder Type K = 0,5 → 0,5 / 1,73.
        $drift = $this->komponen($rec['budget'], 'drift_kalibrator');
        $this->assertEqualsWithDelta(0.5 / 1.73, $drift['u'], self::TOLERANSI);
        $this->assertJauhDari(0.5 / sqrt(3.0), $drift['u'], self::TOLERANSI);

        // Pengulangan Standar Recorder ÷ √(√3) = 3^0,25 ≈ 1,3161.
        $peng = $this->komponen($rec['budget'], 'pengulangan_standar');
        $this->assertEqualsWithDelta(
            $rec['kestabilan'] / EnclosureCalculator::PEMBAGI_PENGULANGAN_RECORDER,
            $peng['u'],
            self::TOLERANSI,
        );

        // Constant/Yokogawa Pengulangan Standar pakai √3 yang benar.
        $yoko = $this->hitung($fix['yokogawa'], $fix['yokogawa']['setpoints'][0]);
        $pengY = $this->komponen($yoko['budget'], 'pengulangan_standar');
        $this->assertEqualsWithDelta($yoko['kestabilan'] / sqrt(3.0), $pengY['u'], self::TOLERANSI);
    }

    /**
     * Peta kolom pembacaan termokopel `[1,2,3,3,4]` (buang ke-5, gandakan ke-3).
     * Sensor dengan pembacaan ke-5 ≠ ke-3 harus rata-rata terkoreksinya ikut
     * versi master, bukan rata-rata bersih kelima pembacaan.
     */
    public function test_peta_kolom_pembacaan_ditiru(): void
    {
        $this->assertSame([0, 1, 2, 2, 3], EnclosureCalculator::PETA_KOLOM_PEMBACAAN);

        // Yokogawa SP4 sensor "5" (index 2) punya pembacaan 100.24/100.1/100.1/100.24/100.22
        // — ke-5 (100.22) ≠ ke-3 (100.1). Master pakai [100.24,100.1,100.1,100.1,100.24].
        $fix = self::fixture()['yokogawa'];
        $hasil = $this->hitung($fix, $fix['setpoints'][3]);
        // dist_terkoreksi[2] sudah diadu di test utama; di sini pastikan ≠ rata bersih.
        $sensor = $fix['setpoints'][3]['sensors'][2]['pembacaan'];
        $rataBersih = array_sum($sensor) / count($sensor);
        $rataMaster = ($sensor[0] + $sensor[1] + $sensor[2] + $sensor[2] + $sensor[3]) / 5;
        $this->assertJauhDari($rataBersih, $rataMaster, 1e-6, 'contoh ini harus punya ke-5 ≠ ke-3');
    }

    /**
     * Koreksi yang nggak ketemu di tabel DILAPORKAN, bukan diam-diam jadi nol.
     *
     * Dua kasus yang beneran bisa terjadi:
     *  - grid Type N dinomori mulai 1, padahal sertifikat sensor Type N lab
     *    mulai dari TCN3 (tabel cuma punya kunci 3..12);
     *  - sesi Recorder yang termokopelnya nggak bawa nomor kanal.
     *
     * Yang dijaga di sini: `koreksi_hilang` keisi. Kalau suatu saat `?? 0.0`
     * balik lagi, sebaran suhu & keseragaman yang TERCETAK ikut salah tanpa satu
     * pun angka kelihatan janggal — dan tes ini yang jatuh duluan.
     */
    public function test_koreksi_hilang_dilaporkan_bukan_dianggap_nol(): void
    {
        $fix = self::fixture()['yokogawa'];
        $sp = $fix['setpoints'][0];

        // Nomori ulang sensor jadi 1..9 — di luar kunci tabel Type N (3..12).
        $sensors = [];

        foreach (array_values($sp['sensors']) as $i => $s) {
            $sensors[] = ['no' => $i + 1, 'pembacaan' => $s['pembacaan']];
        }

        $hasil = (new EnclosureCalculator)->hitungSetpoint($sensors, $sp['indikator'], (float) $sp['setpoint'], [
            'merk' => $fix['merk'],
            'tipe_sensor' => $fix['tipe_sensor'],
            'cmc' => (float) $fix['cmc'],
            'resolusi_alat' => 0.1,
        ]);

        $hilang = collect($hasil['koreksi_hilang']);
        $this->assertNotEmpty($hilang, 'sensor di luar tabel harus kelaporan, bukan dianggap koreksi 0');
        $this->assertEqualsCanonicalizing([1, 2], $hilang->pluck('no')->all());

        // Dan catatan auditnya menyebut sensor mana yang kehilangan koreksi.
        $this->assertNotEmpty(
            collect($hasil['catatan_audit'])->firstWhere('kode', 'koreksi_hilang'),
            'harus ada catatan audit `koreksi_hilang`',
        );
    }

    /**
     * Sensor dengan pembacaan kurang dari 4 DILAPORKAN, bukan ditambal.
     *
     * Peta kolom master baca indeks 0–3, jadi grid 3 pembacaan dulu ditambal
     * dengan mengulang pembacaan terakhir — dan tambalan itu masuk ke `AVG
     * Terkoreksi` yang tercetak di kolom Sebaran Suhu sertifikat.
     */
    public function test_pembacaan_kurang_dilaporkan_bukan_ditambal(): void
    {
        $fix = self::fixture()['yokogawa'];
        $sp = $fix['setpoints'][0];

        $utuh = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
            $sp['sensors'],
        );

        $spek = [
            'merk' => $fix['merk'],
            'tipe_sensor' => $fix['tipe_sensor'],
            'cmc' => (float) $fix['cmc'],
            'resolusi_alat' => 0.1,
        ];

        // Dua jalan buat sensor pertama, sisanya utuh: TIGA pembacaan (kurang,
        // dulu ditambal jadi [a,b,c,c,c]) versus EMPAT (cukup, master memang
        // memetakannya jadi [a,b,c,c,c]). Kalau kode masih menambal, rata-rata
        // sensor pertama keduanya identik.
        $tiga = array_slice($utuh[0]['pembacaan'], 0, 3);
        $empat = array_slice($utuh[0]['pembacaan'], 0, 4);

        $a = $utuh;
        $a[0]['pembacaan'] = $tiga;
        $b = $utuh;
        $b[0]['pembacaan'] = $empat;

        $hasilA = (new EnclosureCalculator)->hitungSetpoint($a, $sp['indikator'], (float) $sp['setpoint'], $spek);
        $hasilB = (new EnclosureCalculator)->hitungSetpoint($b, $sp['indikator'], (float) $sp['setpoint'], $spek);

        $this->assertSame(
            [['no' => $utuh[0]['no'], 'jumlah' => 3]],
            $hasilA['pembacaan_kurang'],
            'sensor berpembacaan 3 harus kelaporan, bukan ditambal diam-diam',
        );
        $this->assertSame([], $hasilB['pembacaan_kurang'], '4 pembacaan itu cukup');

        // Koreksi tabelnya sama di dua jalan (selisih rata-rata mentah jauh di
        // bawah jarak antar-titik tabel), jadi selisih rata-rata TERKOREKSI-nya
        // persis selisih rata-rata mentahnya.
        $mentahA = array_sum($tiga) / 3;
        $mentahB = array_sum([$tiga[0], $tiga[1], $tiga[2], $tiga[2], $empat[3]]) / 5;

        $this->assertEqualsWithDelta(
            $mentahA - $mentahB,
            $hasilA['sensor'][0]['rata_rata_terkoreksi'] - $hasilB['sensor'][0]['rata_rata_terkoreksi'],
            1e-9,
            'rata-rata sensor berpembacaan 3 harus dihitung dari 3 angka, bukan 3 + 2 tambalan',
        );
    }

    /**
     * Grid 4 pembacaan = grid 5 pembacaan, apa pun isi kolom kelimanya —
     * karena master memang membuang kolom ke-5 ([PETA_KOLOM_PEMBACAAN]).
     */
    public function test_empat_pembacaan_sama_hasilnya_dengan_lima(): void
    {
        $fix = self::fixture()['yokogawa'];
        $sp = $fix['setpoints'][0];

        $lima = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
            $sp['sensors'],
        );

        $empat = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => array_slice($s['pembacaan'], 0, 4)],
            $lima,
        );

        $spek = [
            'merk' => $fix['merk'],
            'tipe_sensor' => $fix['tipe_sensor'],
            'cmc' => (float) $fix['cmc'],
            'resolusi_alat' => 0.1,
        ];

        $a = (new EnclosureCalculator)->hitungSetpoint($lima, $sp['indikator'], (float) $sp['setpoint'], $spek);
        $b = (new EnclosureCalculator)->hitungSetpoint($empat, $sp['indikator'], (float) $sp['setpoint'], $spek);

        $this->assertSame([], $b['pembacaan_kurang'], '4 pembacaan itu cukup, bukan kurang');
        $this->assertEqualsWithDelta($a['ketidakpastian_gabungan'], $b['ketidakpastian_gabungan'], 1e-12);
        $this->assertEqualsWithDelta($a['keseragaman'], $b['keseragaman'], 1e-12);
        $this->assertEqualsWithDelta($a['kestabilan'], $b['kestabilan'], 1e-12);
    }

    /** Sensor acuan yang dipakai ikut dipulangkan & dicatat — nggak cuma posisi. */
    public function test_sensor_acuan_dilaporkan(): void
    {
        $fix = self::fixture()['yokogawa'];
        $hasil = $this->hitung($fix, $fix['setpoints'][0]);

        $this->assertSame($fix['setpoints'][0]['sensors'][0]['no'], $hasil['sensor_acuan']);
        $this->assertNotEmpty(
            collect($hasil['catatan_audit'])->firstWhere('kode', 'sensor_acuan'),
            'harus ada catatan audit `sensor_acuan`',
        );
    }

    /** Grid yang koreksinya LENGKAP nggak melaporkan apa-apa. */
    public function test_grid_lengkap_tidak_punya_koreksi_hilang(): void
    {
        $fix = self::fixture();

        foreach (['yokogawa', 'recorder'] as $wb) {
            $hasil = $this->hitung($fix[$wb], $fix[$wb]['setpoints'][0]);
            $this->assertSame([], $hasil['koreksi_hilang'], "{$wb} SP1 mestinya lengkap koreksinya");
        }
    }

    /** v_eff TIDAK dipotong ke bawah — Recorder v_eff 298,25 (pecahan), bukan 298. */
    public function test_v_eff_tidak_dipotong(): void
    {
        $this->assertFalse(EnclosureCalculator::FLOOR_V_EFF);

        $fix = self::fixture()['recorder'];
        $hasil = $this->hitung($fix, $fix['setpoints'][0]);
        $this->assertEqualsWithDelta(298.25471214223484, $hasil['derajat_kebebasan_efektif'], self::TOLERANSI);
        $this->assertGreaterThan(298.0, $hasil['derajat_kebebasan_efektif']);
    }

    /**
     * MERK kalibrator yang nggak punya tabel ditolak DI DEPAN — bukan diam-diam
     * jalan dengan tabel kosong, yang bikin SEMUA koreksi meter/sensor ketemu
     * `null` dan set point-nya baru ketahuan gagal beberapa lapis kemudian
     * (`koreksi_hilang` di `EnclosureProfileBase::hitungPerGrup()`), dengan pesan
     * yang nggak nyebut merk apa yang salah ketik.
     */
    public function test_merk_tidak_dikenal_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Pesannya harus nyebut merk yang salah, biar teknisi tau kolom mana
        // yang mesti dibetulin — bukan cuma "merk nggak dikenal".
        $this->expectExceptionMessageMatches('/siemens-xyz/');

        (new EnclosureCalculator)->hitungSetpoint(
            [['no' => 3, 'pembacaan' => [15.0, 15.1, 15.1, 15.1]]],
            [15.0, 15.0, 15.0],
            15.0,
            ['merk' => 'siemens-xyz', 'tipe_sensor' => 'Type N', 'cmc' => 1.4, 'resolusi_alat' => 0.1],
        );
    }

    /**
     * Sama alasannya dengan merk: TIPE SENSOR yang nggak dikenal (bukan
     * `Type N`/`Type K`) ditolak di depan, bukan lolos sampai tabel koreksi
     * balikin null buat semua sensor.
     */
    public function test_tipe_sensor_tidak_dikenal_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Type Z/');

        (new EnclosureCalculator)->hitungSetpoint(
            [['no' => 3, 'pembacaan' => [15.0, 15.1, 15.1, 15.1]]],
            [15.0, 15.0, 15.0],
            15.0,
            ['merk' => 'yokogawa', 'tipe_sensor' => 'Type Z', 'cmc' => 1.4, 'resolusi_alat' => 0.1],
        );
    }

    /**
     * Grid tanpa satu pun sensor terisi ditolak eksplisit dengan pesan yang
     * nyebut SET POINT-nya — bukan lolos sampai `max()`/`min()` atas array
     * kosong ngelempar `ValueError` PHP native yang nggak nyebut titik mana.
     */
    public function test_sensor_kosong_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Nyebut angka set point-nya, biar teknisi langsung tau baris mana di
        // lembar kerja yang belum ada satu pun termokopel terisi.
        $this->expectExceptionMessageMatches('/42\.5/');

        (new EnclosureCalculator)->hitungSetpoint(
            [],
            [42.5, 42.5, 42.5],
            42.5,
            ['merk' => 'yokogawa', 'tipe_sensor' => 'Type N', 'cmc' => 1.4, 'resolusi_alat' => 0.1],
        );
    }

    /**
     * @param  array<string, mixed>  $fix
     * @param  array<string, mixed>  $sp
     * @return array<string, mixed>
     */
    private function hitung(array $fix, array $sp): array
    {
        $sensors = array_map(
            static fn (array $s): array => [
                'no' => $s['no'],
                'channel' => $s['channel'] ?? null,
                'pembacaan' => $s['pembacaan'],
            ],
            $sp['sensors'],
        );

        return (new EnclosureCalculator)->hitungSetpoint($sensors, $sp['indikator'], (float) $sp['setpoint'], [
            'merk' => $fix['merk'],
            'tipe_sensor' => $fix['tipe_sensor'],
            'cmc' => (float) $fix['cmc'],
            'resolusi_alat' => 0.1,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $budget
     * @return array<string, mixed>
     */
    private function komponen(array $budget, string $sumber): array
    {
        foreach ($budget as $k) {
            if ($k['sumber'] === $sumber) {
                return $k;
            }
        }

        $this->fail("komponen `{$sumber}` nggak ada di budget");
    }

    private function assertJauhDari(float $tak, float $aktual, float $delta, string $pesan = ''): void
    {
        $this->assertGreaterThan($delta, abs($aktual - $tak), $pesan);
    }
}
