<?php

namespace Tests\Unit;

use App\Services\Calibration\SieveCalculator;
use App\Services\Calibration\TabelStandarSieve;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Adu mesin hitung Sieve Mesh ke workbook master — komponen demi komponen di
 * KETIGA budget (warp, weft, kawat), bukan cuma U akhirnya.
 *
 * Fixture `database/data/sesi-master-sieve.json` memuat masukan mentah dan
 * `_acuan_master` (nilai sel workbook, disalin `docs/skrip/gen-sesi-sieve.py`).
 *
 * ## Yang SENGAJA beda dari master — diuji ARAHNYA
 *
 * Koreksi standar. Master memakai `VLOOKUP(...; 3; 0)` ke kolom kosong, jadi
 * koreksinya selalu 0; di sini dari kolom `Koreksi`. Test menegakkan
 * `terkoreksi = terkoreksi_master + koreksi kolom N`, bukan sekadar "beda".
 * Budget tidak tersentuh selisih itu (tidak ada komponen yang memakai nilai
 * terkoreksi), jadi keenam komponen ketiga budget tetap diadu persis.
 */
class SieveMasterTest extends TestCase
{
    private const TOLERANSI = 5e-6;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $data = null;

        return $data ??= json_decode(
            (string) file_get_contents(database_path('data/sesi-master-sieve.json')),
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $ubah  timpa kunci konteks
     * @return array<string, mixed>
     */
    private function hitung(array $ubah = [], ?callable $ubahOpening = null): array
    {
        $f = self::fixture();
        $s = $f['_sesi'];

        $opening = ['warp' => [], 'weft' => [], 'kawat' => []];

        foreach ($f['opening'] as $o) {
            foreach (array_keys($opening) as $p) {
                if ($o[$p] !== null) {
                    $opening[$p][(int) $o['no']] = (float) $o[$p];
                }
            }
        }

        if ($ubahOpening !== null) {
            $opening = $ubahOpening($opening);
        }

        return (new SieveCalculator)->hitungSesi($opening, [
            'tipe' => $s['tipe'],
            'nominal' => (float) $s['nominal'],
            'satuan' => $s['satuan'],
            'standar_dipakai' => $s['standar_dipakai'],
            'jumlah_opening_total' => null,
            'suhu_ruang_rata_c' => ($s['suhu_awal'] + $s['suhu_akhir']) / 2,
            'tanggal_kalibrasi' => new DateTimeImmutable($s['tanggal']),
            ...$ubah,
        ]);
    }

    private function dekat(float $harap, float $aktual, string $pesan): void
    {
        $this->assertEqualsWithDelta($harap, $aktual, self::TOLERANSI, $pesan);
    }

    public function test_sesi_contoh_boleh_terbit(): void
    {
        $h = $this->hitung();

        $this->assertTrue($h['boleh_terbit'], json_encode($h['ditolak']));
        $this->assertSame([], $h['ditolak']);
        $this->assertSame(15, $h['minimum_opening']);
        $this->dekat(self::fixture()['_acuan_master']['nominal_mm'], $h['nominal_mm'], 'nominal');
    }

    public function test_rata_rata_stdev_dan_pengulangan_cocok_master(): void
    {
        $a = self::fixture()['_acuan_master'];
        $h = $this->hitung();

        foreach (['warp', 'weft', 'kawat'] as $p) {
            $r = $h['parameter'][$p];
            $this->dekat($a['rata_rata'][$p], $r['rata_rata'], "rata-rata {$p}");
            $this->dekat($a['simpangan_baku'][$p], $r['simpangan_baku'], "stdev {$p}");
            $this->dekat($a['simpangan_baku_pengulangan'][$p], $r['simpangan_baku_pengulangan'], "stdev pengulangan {$p}");
            // Pengulangan diturunkan dari opening 1..6 — sama persis dengan
            // sel `H89:N91` master yang rumusnya `=G34..G39`.
            $this->assertSame($a['pengulangan_cache'][$p], $r['pengulangan'], "pengulangan {$p}");
            $this->dekat($a['indeks_koreksi'][$p], $r['titik_koreksi']['nilai_standar_mm'], "indeks koreksi {$p}");
        }

        $this->dekat($a['kawat_nominal_mm'], $h['parameter']['kawat']['nominal_parameter_mm'], 'Ø kawat nominal');
    }

    public function test_keenam_komponen_ketiga_budget_cocok_master(): void
    {
        $a = self::fixture()['_acuan_master']['budget'];
        $h = $this->hitung();

        foreach (['warp', 'weft', 'kawat'] as $p) {
            $r = $h['parameter'][$p];

            foreach ($a[$p]['komponen'] as $i => $m) {
                $b = $r['budget'][$i];
                $this->dekat($m['u'], $b['nilai_asal'], "{$p} komponen {$i} U");
                $this->dekat($m['ci'], $b['ci'], "{$p} komponen {$i} ci");
                $this->assertEqualsWithDelta($m['vi'], $b['vi'], 1e-9, "{$p} komponen {$i} vi");

                // Komponen pengulangan SENGAJA menyimpang dari master: master
                // membagi n, GUM 4.2.3 membagi √n. Yang diadu arahnya — kita
                // wajib LEBIH BESAR, persis √n kali (jawaban lab 16 Sep 2026).
                if ($b['sumber'] === 'pengulangan') {
                    $this->dekat(sqrt($m['pembagi']), $b['pembagi'], "{$p} pembagi pengulangan √n");
                    $this->dekat($m['ui'] * sqrt($m['pembagi']), $b['u'], "{$p} ui pengulangan");

                    continue;
                }

                $this->dekat($m['pembagi'], $b['pembagi'], "{$p} komponen {$i} pembagi");
                $this->dekat($m['ui'], $b['u'], "{$p} komponen {$i} ui");
            }

            // uc & U kita lebih besar dari master — dua penyimpangan di atas
            // dan `k` t-Student sama-sama menaikkan, tidak ada yang menurunkan.
            $this->assertGreaterThan($a[$p]['uc'], $r['ketidakpastian_gabungan'], "{$p} uc wajib > master");
            $this->assertGreaterThan(
                $a[$p]['u_diperluas'],
                $r['ketidakpastian_diperluas'],
                "{$p} U wajib > master — kalau lebih kecil, sertifikat mengaku lebih teliti dari yang bisa dibuktikan",
            );

            // `k` dari t-Student pada v_eff yang dibulatkan ke bawah, bukan 2.
            $this->assertGreaterThanOrEqual(2.0, $r['faktor_cakupan_k'], "{$p} k");
            $this->assertLessThan(3.0, $r['faktor_cakupan_k'], "{$p} k");
        }
    }

    /**
     * Koreksi standar dari kolom yang BENAR: terkoreksi = master + koreksi
     * kolom N. Warp & weft (titik 21 mm) +0,01 mm; kawat (titik 2,5 mm) 0.
     */
    public function test_koreksi_standar_dari_kolom_koreksi_bukan_kolom_kosong(): void
    {
        $a = self::fixture()['_acuan_master'];
        $h = $this->hitung();

        foreach (['warp' => 0.01, 'weft' => 0.01, 'kawat' => 0.0] as $p => $koreksi) {
            $r = $h['parameter'][$p];
            $this->dekat($koreksi, $r['koreksi_standar'], "koreksi standar {$p}");
            $this->dekat($a['terkoreksi_master'][$p] + $koreksi, $r['terkoreksi'], "terkoreksi {$p}");
        }

        // Deviasi sertifikat master = terkoreksi_master − nominal; kita + koreksi.
        $this->dekat($a['sertifikat']['warp']['koreksi'] + 0.01, $h['parameter']['warp']['deviasi'], 'deviasi warp');
        $this->dekat($a['sertifikat']['kawat']['koreksi'], $h['parameter']['kawat']['deviasi'], 'deviasi kawat');
    }

    public function test_vonis_guarded_acceptance_dan_vonis_master_tercatat(): void
    {
        $a = self::fixture()['_acuan_master']['sertifikat'];
        $h = $this->hitung();

        foreach (['warp', 'weft', 'kawat'] as $p) {
            $this->assertSame($a[$p]['vonis'] === 'PASS', $h['parameter'][$p]['vonis']['lulus_master'], "vonis master {$p}");
            $this->assertSame('PASS', $h['parameter'][$p]['keputusan'], "keputusan {$p}");
        }

        $this->dekat($a['warp']['y_mm'], $h['parameter']['warp']['vonis']['batas']['y_mm'], 'Y');
        $this->dekat($a['kawat']['min_mm'], $h['parameter']['kawat']['vonis']['batas']['min_mm'], 'min kawat');
        $this->dekat($a['kawat']['max_mm'], $h['parameter']['kawat']['vonis']['batas']['max_mm'], 'max kawat');
        $this->assertTrue($h['parameter']['warp']['stdev']['lulus']);
    }

    /**
     * Guarded acceptance lebih ketat dari vonis master: deviasi yang masih di
     * dalam ±Y tapi |deviasi| + U melewatinya jadi FAIL, sementara master
     * tetap mencetak PASS.
     */
    public function test_deviasi_dekat_batas_gagal_dengan_u_tapi_lulus_versi_master(): void
    {
        // Geser seluruh warp sampai rata-rata terkoreksi 19,50 mm: deviasi 0,50
        // < Y 0,522, tapi + U 0,026 > 0,522.
        $h = $this->hitung(ubahOpening: static function (array $o): array {
            $rata = array_sum($o['warp']) / count($o['warp']);
            foreach ($o['warp'] as $no => $v) {
                $o['warp'][$no] = $v - $rata + 19.49;
            }

            return $o;
        });

        $warp = $h['parameter']['warp'];
        $this->assertTrue($warp['vonis']['lulus_master']);
        $this->assertFalse($warp['vonis']['lulus']);
        $this->assertSame('FAIL', $warp['keputusan']);
    }

    public function test_nominal_yang_tidak_persis_di_tabel_diblokir_bukan_dijepret(): void
    {
        $h = $this->hitung(['nominal' => 19.5]);

        $this->assertFalse($h['boleh_terbit']);
        $this->assertSame([], $h['parameter']);
        $this->assertStringContainsString('tidak ada PERSIS', $h['ditolak'][0]['alasan']);
    }

    public function test_nominal_inch_dicocokkan_ke_kolom_inch(): void
    {
        $h = $this->hitung(['nominal' => 0.75, 'satuan' => 'inch']);

        $this->dekat(19.0, $h['nominal_mm'], 'penanda 3/4" = sieve 19,0 mm');
    }

    public function test_opening_di_bawah_minimum_diblokir(): void
    {
        $h = $this->hitung(ubahOpening: static function (array $o): array {
            foreach ($o as $p => $d) {
                $o[$p] = array_slice($d, 0, 10, true);
            }

            return $o;
        });

        $this->assertFalse($h['boleh_terbit']);
        $this->assertStringContainsString('di bawah minimum 15', implode(' ', array_column($h['ditolak'], 'alasan')));
    }

    /** Tipe Calibration memakai kolom Calibration (30), bukan Inspection (15) seperti master. */
    public function test_tipe_calibration_memakai_kolom_calibration(): void
    {
        $h = $this->hitung(['tipe' => 'calibration']);

        $this->assertSame(30, $h['minimum_opening']);
        $this->assertFalse($h['boleh_terbit']);
    }

    public function test_opening_satu_sampai_enam_kosong_diblokir(): void
    {
        $h = $this->hitung(ubahOpening: static function (array $o): array {
            unset($o['weft'][3]);

            return $o;
        });

        $this->assertFalse($h['boleh_terbit']);
        $this->assertStringContainsString('nomor 1..6', implode(' ', array_column($h['ditolak'], 'alasan')));
    }

    public function test_standar_kedaluwarsa_diblokir(): void
    {
        $h = $this->hitung(['tanggal_kalibrasi' => new DateTimeImmutable('2026-08-01')]);

        $this->assertFalse($h['boleh_terbit']);
        $this->assertStringContainsString('kedaluwarsa', implode(' ', array_column($h['ditolak'], 'alasan')));
    }

    public function test_mikroskop_di_atas_dua_mm_tanpa_lantai_diblokir(): void
    {
        $h = $this->hitung(['standar_dipakai' => 'mikroskop']);

        $this->assertFalse($h['boleh_terbit']);
        $this->assertStringContainsString('lantai CMC', implode(' ', array_column($h['ditolak'], 'alasan')));
    }

    public function test_baris_mpe_yang_janggal_diblokir(): void
    {
        // 0,080 mm: Ø kawat preferred 0,56 di luar pita 0,048–0,064.
        $h = $this->hitung(['nominal' => 0.08, 'standar_dipakai' => 'mikroskop', 'tanggal_kalibrasi' => new DateTimeImmutable('2026-05-11')]);

        $this->assertFalse($h['boleh_terbit']);
        $this->assertStringContainsString('kawat_preferred_mm', implode(' ', array_column($h['ditolak'], 'alasan')));
    }

    public function test_lantai_cmc_mengambil_yang_lebih_besar(): void
    {
        $t = new TabelStandarSieve;

        $this->assertEqualsWithDelta(0.02, $t->lantaiCmc(3.0, 'caliper')['u95_mm'], 1e-12);
        $this->assertEqualsWithDelta(0.00433, $t->lantaiCmc(1.0, 'mikroskop')['u95_mm'], 1e-12);
        $this->assertNull($t->lantaiCmc(125.0, 'caliper')['u95_mm'], 'di luar lampiran 4–100 mm');
        $this->assertNull($t->lantaiCmc(0.038, 'mikroskop')['u95_mm'], 'di bawah 45 µm');
    }

    public function test_max_stdev_strip_tidak_dinilai_bukan_lulus(): void
    {
        // 50 mm: max stdev "-", minimum inspection "all".
        $h = $this->hitung(['nominal' => 50.0, 'jumlah_opening_total' => 15]);

        $this->assertNull($h['parameter']['warp']['stdev']['lulus']);
        $this->assertStringContainsString('TIDAK DINILAI', implode(' ', $h['catatan']));
    }
}
