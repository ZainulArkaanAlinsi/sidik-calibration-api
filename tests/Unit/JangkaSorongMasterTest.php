<?php

namespace Tests\Unit;

use App\Services\Calibration\JangkaSorongCalculator;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Adu mesin hitung Jangka Sorong ke workbook master — sel demi sel, KETIGA
 * budget, bukan cuma U95 akhirnya.
 *
 * Fixture `database/data/sesi-master-jangka-sorong.json` memuat masukan mentah
 * DAN `_acuan_master` yang disalin dari sel workbook oleh
 * `docs/skrip/gen-sesi-jangka-sorong.py`, bukan dari keluaran PHP.
 *
 * ## Umur drift diberi SAMA dengan master
 *
 * `DATABASE!X11 = NOW()` — di snapshot yang kami terima 2026-06-04 11:20,
 * sesinya 2026-01-15. `tanggal_kalibrasi` disetel ke saat itu supaya jalur umur
 * drift yang diuji sama dengan produksi; yang diuji rumusnya, bukan tanggalnya.
 *
 * ## Tiga hal yang SENGAJA beda dari master, dan diuji ARAHNYA
 *
 *  - Lantai CMC 0,015 mm dipasang (master tidak punya) — U95 tidak pernah di
 *    bawahnya, dan kapasitas 600 mm sesi contoh menahan penerbitan.
 *  - Bacaan Depth dari lembar ini, bukan dari workbook lain; koreksi Depth
 *    diadu ke masukan lokal, total nominalnya tetap ke master.
 *  - Repeatability Depth dari STDEV terbesar titiknya, bukan sel kosong: pada
 *    sesi contoh keduanya nol (bacaan lokal identik), dan begitu ada sebaran
 *    U Depth wajib NAIK.
 */
class JangkaSorongMasterTest extends TestCase
{
    private const TOLERANSI = 5e-6;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $data = null;

        return $data ??= json_decode(
            (string) file_get_contents(database_path('data/sesi-master-jangka-sorong.json')),
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $ganti
     * @return array{array<string, list<array<string, mixed>>>, array<string, mixed>}
     */
    private function masukan(array $ganti = []): array
    {
        $f = self::fixture();
        $offset = ['outside' => 0, 'inside' => 100, 'depth' => 200];
        $titik = [];

        foreach ($offset as $grup => $awal) {
            $titik[$grup] = array_map(static fn (array $t): array => [
                'titik_ke' => $awal + (int) $t['urutan'],
                'nominal' => $t['nominal_mm'],
                'pembacaan' => $t['pembacaan_mm'],
            ], $f[$grup]);
        }

        $m = $f['_sesi'];

        return [$titik, array_replace([
            'kapasitas_mm' => (float) $m['kapasitas_mm'],
            'resolusi_mm' => (float) $m['resolusi_mm'],
            'tanggal_kalibrasi' => new DateTimeImmutable($f['_acuan_master']['now_master']),
            'pra_evaluasi_outside' => $f['pra_evaluasi_outside_mm'],
            'pra_evaluasi_inside' => $f['pra_evaluasi_inside_mm'],
            'suhu_ruang_rata_c' => ($m['suhu_awal'] + $m['suhu_akhir']) / 2,
            'kesejajaran' => array_map(static fn (array $k): array => [
                'posisi' => $k['posisi'], 'nominal' => $k['nominal_mm'], 'pembacaan' => $k['pembacaan_mm'],
            ], $f['kesejajaran']),
        ], $ganti)];
    }

    private function sama(float $harap, float $nyata, string $pesan): void
    {
        $this->assertEqualsWithDelta($harap, $nyata, self::TOLERANSI * max(1.0, abs($harap)), $pesan);
    }

    public function test_titik_outside_dan_inside_cocok_dengan_master(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);
        $acuan = self::fixture()['_acuan_master'];

        foreach (['outside', 'inside'] as $grup) {
            $this->assertCount(count($acuan[$grup]), $hasil['grup'][$grup]['titik']);

            foreach ($hasil['grup'][$grup]['titik'] as $i => $h) {
                $a = $acuan[$grup][$i];
                foreach (['total_nominal', 'rata_rata', 'standar_terkoreksi', 'simpangan_baku', 'koreksi'] as $kunci) {
                    $this->sama($a[$kunci], $h[$kunci], "{$grup} titik {$a['urutan']} {$kunci}");
                }
            }
        }
    }

    public function test_depth_total_ke_master_dan_koreksi_dari_bacaan_lokal(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);
        $f = self::fixture();

        foreach ($hasil['grup']['depth']['titik'] as $i => $h) {
            $this->sama($f['_acuan_master']['depth'][$i]['total_nominal'], $h['total_nominal'], "depth {$i} total");
            $lokal = $f['depth'][$i]['pembacaan_mm'];
            $this->sama($h['total_nominal'] - array_sum($lokal) / count($lokal), $h['koreksi'], "depth {$i} koreksi");
        }
    }

    public function test_ketiga_budget_cocok_komponen_demi_komponen(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);

        foreach (['outside', 'inside', 'depth'] as $grup) {
            $acuan = self::fixture()['_acuan_master']['budget'][$grup];
            $budget = $hasil['grup'][$grup]['budget'];

            $this->assertCount(count($acuan['komponen']), $budget, "{$grup}: jumlah komponen");

            foreach ($acuan['komponen'] as $i => $a) {
                $b = $budget[$i];

                // Drift standar SENGAJA menyimpang sejak 16 Sep 2026: umur
                // dibagi 365 HARI, bukan 12 seperti master — komponennya
                // µm/tahun dan selisih tanggalnya hari (butir 5 paket
                // keputusan, disetujui pemilik proyek). Rasionya persis 12/365.
                // Depth dikecualikan: masternya memang memakai drift TANPA
                // faktor umur, jadi pembagi 12/365 tidak menyentuhnya.
                if ($b['sumber'] === 'drift_standar' && $grup !== 'depth') {
                    $this->sama($a['ui'] * 12.0 / 365.0, $b['u'], "{$grup} baris {$a['baris']} ui drift ÷365");
                    $this->sama($a['ci'], $b['ci'], "{$grup} baris {$a['baris']} ci");
                    $this->sama($a['vi'], $b['vi'], "{$grup} baris {$a['baris']} vi");

                    continue;
                }

                $this->sama($a['ui'], $b['u'], "{$grup} baris {$a['baris']} ({$a['keterangan']}) ui");
                $this->sama($a['ci'], $b['ci'], "{$grup} baris {$a['baris']} ci");
                $this->sama($a['vi'], $b['vi'], "{$grup} baris {$a['baris']} vi");
            }

            // uc & U turun tipis dari master karena komponen drift itu — arah
            // yang disetujui, dan batas bawahnya ikut dijaga supaya komponen
            // yang HILANG tidak lolos sebagai "turun tipis".
            $g = $hasil['grup'][$grup];

            if ($grup === 'depth') {
                // Depth tidak tersentuh butir 5 — tetap diadu sama persis.
                $this->sama($acuan['uc'], $g['ketidakpastian_gabungan'], "{$grup} uc");
                $this->sama($acuan['u_diperluas'], $g['ketidakpastian_diperluas'], "{$grup} U");

                continue;
            }

            $this->assertLessThan($acuan['uc'], $g['ketidakpastian_gabungan'], "{$grup} uc wajib < master");
            $this->assertGreaterThan($acuan['uc'] * 0.9, $g['ketidakpastian_gabungan'], "{$grup} uc turun terlalu jauh");
            $this->assertLessThan($acuan['u_diperluas'], $g['ketidakpastian_diperluas'], "{$grup} U wajib < master");
            $this->assertGreaterThan($acuan['u_diperluas'] * 0.9, $g['ketidakpastian_diperluas'], "{$grup} U turun terlalu jauh");
        }
    }

    public function test_kesejajaran_cocok(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);

        $this->assertSame(
            self::fixture()['_acuan_master']['kesejajaran_koreksi'],
            array_column($hasil['kesejajaran'], 'koreksi'),
        );
    }

    /**
     * 600 mm di luar lampiran (0-300 mm): TERBIT dengan U telanjang — persis
     * master — dan tanpa klaim akreditasi (`dalamLingkupAkreditasiSesi`). Yang
     * menahan cuma kapasitas KOSONG.
     */
    public function test_kapasitas_600_mm_terbit_tanpa_lantai_cmc_dan_kapasitas_kosong_ditahan(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new JangkaSorongCalculator;
        $hasil = $kalk->hitungSesi($titik, $konteks);

        $this->assertNull($hasil['pita_cmc']);

        foreach ($hasil['grup'] as $grup => $g) {
            $this->assertTrue($g['boleh_terbit'], "{$grup}: ".implode(' ', $g['alasan_tahan']));
            $this->assertSame($g['ketidakpastian_diperluas'], $g['u95_sertifikat'], "{$grup}: tanpa lantai CMC");
        }

        foreach ($kalk->hitungSesi($titik, ['kapasitas_mm' => 0.0] + $konteks)['grup'] as $grup => $g) {
            $this->assertFalse($g['boleh_terbit'], "{$grup}: kapasitas kosong wajib menahan");
        }
    }

    public function test_lantai_cmc_tidak_pernah_dilewati_ke_bawah(): void
    {
        // Caliper digital resolusi 0,01 dengan sebaran kecil — master menerbitkan
        // U di bawah CMC terakreditasi. Di sini lantainya menahan.
        $f = self::fixture();
        [$titik, $konteks] = $this->masukan([
            'kapasitas_mm' => 150.0,
            'resolusi_mm' => 0.01,
            'tanggal_kalibrasi' => new DateTimeImmutable('2026-01-15'),
            'pra_evaluasi_outside' => [150.00, 150.01, 150.00, 150.01, 150.00, 150.00, 150.01, 150.00, 150.00, 150.01],
            'pra_evaluasi_inside' => [150.00, 150.01, 150.00, 150.01, 150.00, 150.00, 150.01, 150.00, 150.00, 150.01],
        ]);
        $titik['outside'] = array_slice($titik['outside'], 0, 4);
        $titik['inside'] = array_slice($titik['inside'], 0, 4);
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);

        foreach ($hasil['grup'] as $grup => $g) {
            $this->assertTrue($g['boleh_terbit'], "{$grup}: ".implode(' ', $g['alasan_tahan']));
            $this->assertLessThan(0.015, $g['ketidakpastian_diperluas'], "{$grup}: U hitung memang di bawah CMC");
            $this->assertSame(0.015, $g['u95_sertifikat'], "{$grup}: yang terbit lantai CMC");
        }
    }

    public function test_sebaran_depth_menaikkan_u_depth_bukan_diam_seperti_master(): void
    {
        [$titik, $konteks] = $this->masukan();
        $awal = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks)['grup']['depth']['ketidakpastian_diperluas'];

        $titik['depth'][2]['pembacaan'] = [30.0, 30.0, 30.0, 30.01, 30.01];
        $baru = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks)['grup']['depth']['ketidakpastian_diperluas'];

        $this->sama(self::fixture()['_acuan_master']['budget']['depth']['u_diperluas'], $awal, 'nol = master');
        $this->assertGreaterThan($awal, $baru);
    }

    public function test_nominal_tidak_terdaftar_diblokir_dengan_alasan(): void
    {
        [$titik, $konteks] = $this->masukan();
        $titik['outside'][3]['nominal'] = [155.0];
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);

        $this->assertNotContains(4, array_column($hasil['grup']['outside']['titik'], 'titik_ke'));
        $this->assertContains(4, array_column($hasil['ditolak'], 'titik_ke'));
    }

    public function test_evaluation_inside_kosong_menahan_inside_saja(): void
    {
        [$titik, $konteks] = $this->masukan(['kapasitas_mm' => 300.0, 'pra_evaluasi_inside' => []]);
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);

        $this->assertTrue($hasil['grup']['outside']['boleh_terbit']);
        $this->assertFalse($hasil['grup']['inside']['boleh_terbit']);
        $this->assertTrue($hasil['grup']['depth']['boleh_terbit']);
    }

    public function test_evaluation_identik_cuma_peringatan(): void
    {
        [$titik, $konteks] = $this->masukan(['kapasitas_mm' => 300.0, 'pra_evaluasi_outside' => array_fill(0, 10, 599.95)]);
        $hasil = (new JangkaSorongCalculator)->hitungSesi($titik, $konteks);

        $this->assertTrue($hasil['grup']['outside']['boleh_terbit']);
        $this->assertNotEmpty(array_filter($hasil['peringatan'], static fn (string $p): bool => str_contains($p, 'Outside')));
    }
}
