<?php

namespace Tests\Unit;

use App\Services\Calibration\AutoclaveCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Olah data Autoklaf, dicocokin ke `Master Olah Data_Autoclave.xlsm`
 * (sheet `PERHITUNGAN FC` & `PERHITUNGAN U95%`, sesi contoh 0281-CAL-624).
 * Angka acuan DISALIN dari sel workbook (data_only), bukan dari keluaran kode.
 *
 * Budget SUHU — 6 komponen, k dari polinomial t-student (sel AC18):
 *   Uc 0,2241822142971117 · v_eff 209,38864755710154 · k 1,9713602363081708
 *   U  0,4419439029528431 (menang atas CMC 0,34)
 *
 * Budget TEKANAN — 5 komponen, k dari TINV(0,05, floor v_eff):
 *   Uc 0,004428378183187759 · v_eff 20,38075689639711 · k 2,085963447265865
 *   U  0,009237435020799286 (KALAH dari CMC 0,059 bar -> U95 = 0,059 bar = 0,0059 MPa)
 *
 * ## Satu penyimpangan SENGAJA dari master (keputusan final, disetujui)
 *
 * Baris disk master (`PERHITUNGAN FC!D23:I25`) salah tarik sel: titik waktu ke-5
 * (kolom K) kebuang, ke-3 (H) kehitung dua kali. Lebih jauh, cache master-nya
 * kontradiksi: disk 1&2 cocok mapping-buang-K, disk 3 cocok mapping-penuh — jadi
 * "persis master" nggak terdefinisi. Kalkulator merata-rata SEMUA titik waktu
 * (satu-satunya nilai konsisten, sesuai formulir SIDIK-FM-CAL-0539). Efeknya:
 * Keseragaman 0,464 (sertifikat lama cetak 0,466). Kestabilan/Variasi/U95 identik.
 * `test_metrik_kinerja_suhu` MENGUNCI 0,464 — kalau berubah, itu regresi.
 */
class AutoclaveCalculatorTest extends TestCase
{
    private const TOL = 1e-9;

    /**
     * @return array<string, mixed>
     */
    private function inputContoh(): array
    {
        $u95_1 = 0.43;
        $u95_23 = 0.44;

        return [
            'set_point' => 121.0,
            'cmc' => ['suhu' => 0.34, 'tekanan' => 0.059],
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
                'resolusi_alat' => 0.01,
                'standar' => [
                    ['resolusi' => 0.01, 'tabel' => $this->tabelSuhu([-0.18, -0.10, -0.05, -0.08, 0.13, 0.19], $u95_1)],
                    ['resolusi' => 0.01, 'tabel' => $this->tabelSuhu([-0.16, -0.15, -0.10, 0.11, 0.20, 0.23], $u95_23, [23, 50, 75, 100, 120, 140])],
                    ['resolusi' => 0.01, 'tabel' => $this->tabelSuhu([-0.23, -0.11, -0.07, 0.10, 0.15, 0.18], $u95_23, [23, 50, 75, 100, 120, 140])],
                ],
            ],
            'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'resolusi_alat' => 0.001,
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
                'standar' => [
                    'resolusi' => 0.001,
                    'tabel' => [
                        ['set' => 1.5, 'koreksi_up' => 0.0, 'u95' => 0.0046],
                        ['set' => 2.0, 'koreksi_up' => -0.0006, 'u95' => 0.0046],
                        ['set' => 2.5, 'koreksi_up' => -0.001145618, 'u95' => 0.0046],
                        ['set' => 3.0, 'koreksi_up' => -0.0014, 'u95' => 0.0046],
                        ['set' => 3.5, 'koreksi_up' => -0.0015, 'u95' => 0.0046],
                        ['set' => 4.0, 'koreksi_up' => -0.0018, 'u95' => 0.0046],
                        ['set' => 4.5, 'koreksi_up' => -0.0013, 'u95' => 0.0046],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<float>  $koreksi
     * @param  list<int>  $set
     * @return list<array{set: float, koreksi: float, u95: float}>
     */
    private function tabelSuhu(array $koreksi, float $u95, array $set = [21, 50, 75, 100, 120, 140]): array
    {
        $tabel = [];
        foreach ($set as $i => $s) {
            $tabel[] = ['set' => (float) $s, 'koreksi' => $koreksi[$i], 'u95' => $u95];
        }

        return $tabel;
    }

    public function test_budget_suhu_cocok_master(): void
    {
        $suhu = (new AutoclaveCalculator)->hitung($this->inputContoh())['suhu'];

        $this->assertEqualsWithDelta(0.2241822142971117, $suhu['uc'], self::TOL, 'Uc suhu');
        $this->assertEqualsWithDelta(209.38864755710154, $suhu['v_eff'], 1e-6, 'v_eff suhu');
        $this->assertEqualsWithDelta(1.9713602363081708, $suhu['k'], self::TOL, 'k suhu');
        $this->assertEqualsWithDelta(0.4419439029528431, $suhu['u_bentangan'], self::TOL, 'U bentangan suhu');
        // Hitung > CMC 0,34 -> hitung menang.
        $this->assertEqualsWithDelta(0.4419439029528431, $suhu['u95'], self::TOL, 'U95 suhu (hitung menang)');
    }

    public function test_metrik_kinerja_suhu(): void
    {
        $suhu = (new AutoclaveCalculator)->hitung($this->inputContoh())['suhu'];

        $this->assertEqualsWithDelta(0.045, $suhu['kestabilan'], self::TOL, 'Kestabilan (SS)');
        $this->assertEqualsWithDelta(0.10, $suhu['variasi'], self::TOL, 'Variasi Keseluruhan (VK)');
        // 0,464 = nilai BENAR (rata-rata semua titik waktu). Master nyetak 0,466
        // karena bug tarik sel; lihat docblock kelas.
        $this->assertEqualsWithDelta(0.464, $suhu['keseragaman'], self::TOL, 'Keseragaman (KS)');
        $this->assertEqualsWithDelta(121.0, $suhu['indikator_rata'], self::TOL, 'Indikator rata');
        $this->assertCount(3, $suhu['sensor']);
    }

    public function test_budget_tekanan_cocok_master(): void
    {
        $tekanan = (new AutoclaveCalculator)->hitung($this->inputContoh())['tekanan'];

        $this->assertEqualsWithDelta(1.12, $tekanan['uut_setting_bar'], self::TOL, 'UUT setting -> bar');
        $this->assertEqualsWithDelta(1.231, $tekanan['standar_rata_bar'], self::TOL, 'Std rata (bar)');
        $this->assertEqualsWithDelta(0.004428378183187759, $tekanan['uc'], self::TOL, 'Uc tekanan');
        $this->assertEqualsWithDelta(20.38075689639711, $tekanan['v_eff'], 1e-6, 'v_eff tekanan');
        $this->assertEqualsWithDelta(2.085963447265865, $tekanan['k'], 1e-9, 'k tekanan (TINV floor)');
        $this->assertEqualsWithDelta(0.009237435020799286, $tekanan['u_bentangan_bar'], 1e-9, 'U bentangan (bar)');
        // Hitung 0,00924 bar < CMC 0,059 bar -> CMC menang.
        $this->assertEqualsWithDelta(0.059, $tekanan['u95_bar'], self::TOL, 'U95 (bar, CMC menang)');
        // Diturunkan ke satuan display MPa: /10.
        $this->assertEqualsWithDelta(0.0059, $tekanan['u95'], self::TOL, 'U95 (MPa)');
        $this->assertEqualsWithDelta(0.0111, $tekanan['koreksi'], 1e-9, 'Koreksi (MPa)');
        $this->assertEqualsWithDelta(0.1231, $tekanan['standar_terkoreksi'], self::TOL, 'Std terkoreksi (MPa)');
    }

    /**
     * Pembanding dihitung ULANG di dalam test (bukan cuma percaya satu angka
     * master) — Welch–Satterthwaite dari komponen suhu, biar kalau engine-nya
     * bergeser ketahuan dari dua arah.
     */
    public function test_uc_suhu_recompute_independen(): void
    {
        $akar3 = sqrt(3);
        $akarAkar3 = sqrt($akar3);
        // [U, pembagi, vi, ci] — 6 komponen suhu sesi contoh.
        $komponen = [
            [0.44, 2.0, 200, 1.0],
            [0.01 / 2, $akar3, 200, 1.0],
            [0.01 / 2, $akar3, 201, 2.0],
            [0.44 / 10, 1.73, 50, 1.0],
            [0.045, $akarAkar3, 4, 1.0],
            [0.0, $akarAkar3, 4, 1.0],
        ];

        $jumlahKuadrat = 0.0;
        foreach ($komponen as [$u, $div, , $ci]) {
            $jumlahKuadrat += (($u / $div) * $ci) ** 2;
        }
        $ucHarapan = sqrt($jumlahKuadrat);

        $suhu = (new AutoclaveCalculator)->hitung($this->inputContoh())['suhu'];
        $this->assertEqualsWithDelta($ucHarapan, $suhu['uc'], self::TOL, 'Uc suhu = recompute WS');
    }

    public function test_konversi_satuan_tekanan_konsisten(): void
    {
        // UUT 1,12 bar dikirim sebagai Bar harus kasih U95 bar yang sama persis
        // dgn 0,112 MPa — konversi cuma ganti satuan, bukan angka.
        $input = $this->inputContoh();
        $input['tekanan']['uut_setting'] = 1.12;
        $input['tekanan']['satuan'] = 'Bar';
        $input['tekanan']['resolusi_alat'] = 0.01; // 0,001 MPa = 0,01 bar

        $tekanan = (new AutoclaveCalculator)->hitung($input)['tekanan'];
        $this->assertEqualsWithDelta(0.059, $tekanan['u95_bar'], self::TOL, 'U95 bar invarian satuan');
        $this->assertEqualsWithDelta(0.059, $tekanan['u95'], self::TOL, 'U95 display (Bar) = bar');
    }
}
