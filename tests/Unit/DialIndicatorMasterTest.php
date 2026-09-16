<?php

namespace Tests\Unit;

use App\Services\Calibration\DialIndicatorCalculator;
use App\Services\GumCalculator;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Adu mesin hitung Dial Indicator ke workbook master — sel demi sel, bukan cuma
 * U95 akhirnya.
 *
 * Fixture `database/data/sesi-master-dial-indicator.json` memuat masukan mentah
 * DAN `_acuan_master` yang disalin dari sel workbook oleh
 * `docs/skrip/gen-sesi-dial-indicator.py` — bukan dari keluaran PHP.
 *
 * Tanggal kalibrasi dipatok ke `NOW()` master (`DATABASE!X11`) supaya komponen
 * drift bisa diadu; di produksi umurnya dari tanggal sesi.
 *
 * Satu hal yang SENGAJA beda: panjang koefisien sensitivitas (penyimpangan no. 1
 * `DialIndicatorCalculator`). Kesepuluh komponen diadu dengan `L = C61` master
 * lewat [DialIndicatorCalculator::budget]; jalur sesi penuh diuji ARAHNYA.
 */
class DialIndicatorMasterTest extends TestCase
{
    private const TOLERANSI = 5e-6;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $data = null;

        return $data ??= json_decode(
            (string) file_get_contents(database_path('data/sesi-master-dial-indicator.json')),
            true,
        );
    }

    /** @return array{list<array<string, mixed>>, array<string, mixed>} */
    private function masukan(): array
    {
        $f = self::fixture();

        $titik = array_map(static fn (array $t): array => [
            'titik_ke' => (int) $t['titik_ke'],
            'keping' => $t['balok_mm'],
            'pembacaan' => $t['pembacaan_mm'],
        ], $f['titik']);

        $konteks = [
            'kapasitas_mm' => (float) $f['_sesi']['kapasitas_mm'],
            'resolusi_mm' => (float) $f['_sesi']['resolusi_mm'],
            'tanggal_kalibrasi' => new DateTimeImmutable($f['_acuan_master']['now_master']),
            'pra_evaluasi' => $f['pra_evaluasi_mm'],
            'balok_pra_evaluasi' => $f['balok_pra_evaluasi_mm'],
            'suhu_ruang_rata_c' => ($f['_sesi']['suhu_awal'] + $f['_sesi']['suhu_akhir']) / 2,
        ];

        return [$titik, $konteks];
    }

    private function sama(float $harap, float $nyata, string $apa): void
    {
        $this->assertEqualsWithDelta($harap, $nyata, self::TOLERANSI * max(1.0, abs($harap)), $apa);
    }

    public function test_kesepuluh_titik_cocok_dengan_master(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new DialIndicatorCalculator)->hitungSesi($titik, $konteks);
        $acuan = self::fixture()['_acuan_master']['titik'];

        $this->assertCount(10, $hasil['titik']);

        foreach ($acuan as $i => $a) {
            $h = $hasil['titik'][$i];
            $this->sama($a['total_nominal'], $h['total_nominal'], "H titik {$a['titik_ke']}");
            $this->sama($a['rata_rata'], $h['rata_rata'], "N titik {$a['titik_ke']}");
            $this->sama($a['standar_terkoreksi'], $h['standar_terkoreksi'], "AB titik {$a['titik_ke']}");
            $this->sama($a['koreksi'], $h['koreksi'], "AD titik {$a['titik_ke']}");
            $this->sama($a['simpangan_baku'], $h['simpangan_baku'], "AC titik {$a['titik_ke']}");
        }
    }

    public function test_kesepuluh_komponen_dan_agregat_cocok_dengan_master_pada_panjang_master(): void
    {
        [, $konteks] = $this->masukan();
        $a = self::fixture()['_acuan_master'];
        $ditolak = [];

        $budget = (new DialIndicatorCalculator)->budget(
            $konteks, $a['l_maks_master_mm'], $a['keping_maks'], $a['theta_c'], 0.0, $ditolak,
        );

        // Sepuluh komponen master + SATU komponen histeresis (butir 6 paket
        // keputusan 16 Sep 2026): UP & DOWN cuma boleh digabung jadi
        // pengulangan kalau histeresisnya dinilai sendiri.
        $this->assertCount(11, $budget);
        $this->assertSame('histeresis', $budget[10]['sumber']);

        foreach ($a['komponen'] as $i => $m) {
            // Komponen `pengulangan` SENGAJA menyimpang: master membagi √5
            // (`N5`/`Q5` — workbook merata-rata lima kotak), kertas FM-0526
            // Rev.3 memungut enam (UP×3 + DOWN×3) dan sistem merata-ratakan
            // keenamnya, jadi pembaginya √6 dan `vi` = 10 − 1 = 9.
            if ($budget[$i]['sumber'] === 'pengulangan') {
                $this->assertEqualsWithDelta($m['ui'] * sqrt(5.0 / 6.0), $budget[$i]['u'], 1e-12, 'ui pengulangan ÷√6');
                $this->assertEqualsWithDelta(9.0, $budget[$i]['vi'], 1e-9, 'vi pengulangan = 10 − 1');

                continue;
            }

            // Drift standar: umur dibagi 365 HARI, bukan 12 seperti `K10`
            // master — komponennya µm/tahun dan selisih tanggalnya hari
            // (butir 5 paket keputusan 16 Sep 2026).
            if ($budget[$i]['sumber'] === 'drift_standar') {
                $this->assertEqualsWithDelta($m['ui'] * 12.0 / 365.0, $budget[$i]['u'], 1e-12, 'ui drift ÷365 hari');
                $this->assertEqualsWithDelta($m['ci'], $budget[$i]['ci'], 1e-12, 'ci drift');
                $this->assertEqualsWithDelta($m['vi'], $budget[$i]['vi'], 1e-9, 'vi drift');

                continue;
            }

            $this->assertEqualsWithDelta($m['ui'], $budget[$i]['u'], 1e-12, "ui baris {$m['baris']} ({$m['keterangan']})");
            $this->assertEqualsWithDelta($m['ci'], $budget[$i]['ci'], 1e-12, "ci baris {$m['baris']}");
            $this->assertEqualsWithDelta($m['vi'], $budget[$i]['vi'], 1e-9, "vi baris {$m['baris']}");
        }

        $agregat = (new GumCalculator)->agregasiBudget($budget);

        // uc & U lebih kecil dari master: pembagi keterulangan yang benar lebih
        // besar, dan komponen histeresis pada konteks test ini nol (tidak
        // membawa pembacaan UP/DOWN). Lantai CMC tetap yang dilaporkan.
        $this->assertLessThan($a['uc'], $agregat['ketidakpastian_gabungan'], 'uc AA16 wajib < master');
        $this->assertLessThan($a['u_diperluas'], $agregat['ketidakpastian_diperluas'], 'U AA19 wajib < master');
        // U95 yang DILAPORKAN = MAX(U hitung, lantai CMC) — dan di sesi ini
        // hitungan masih menang, jadi angkanya ikut turun tipis dari master
        // (butir 5 & 6). Yang dijaga aturannya berikut arah perubahannya.
        $u95 = max($agregat['ketidakpastian_diperluas'], $a['cmc_mm']);

        $this->assertGreaterThanOrEqual($a['cmc_mm'], $u95, 'U95 AA21 tidak boleh di bawah lantai CMC');
        $this->assertLessThan($a['u95_sertifikat'], $u95, 'U95 AA21 wajib < master');
    }

    public function test_sesi_penuh_memakai_tumpukan_terpanjang(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new DialIndicatorCalculator)->hitungSesi($titik, $konteks);
        $a = self::fixture()['_acuan_master'];

        // 21 + 1,8 + 1,7 — bukan keping terbesar 21 mm yang dipakai C61.
        $this->assertGreaterThan($a['l_maks_master_mm'], $hasil['l_maks_mm']);
        $this->assertEqualsWithDelta(24.50011, $hasil['l_maks_mm'], 1e-9);

        // Sampai 15 Sep 2026 di sini ditegakkan "tidak pernah lebih kecil dari
        // master". Butir 6 paket keputusan (16 Sep 2026, disetujui pemilik
        // proyek) mengubah arah itu untuk SATU komponen: pembagi keterulangan
        // √6, bukan √5. Yang tersisa dijaga: panjang tumpukan tetap yang
        // terpanjang, sesi tetap boleh terbit, dan lantai CMC tetap terpasang.
        $this->assertLessThan($a['u_diperluas'], $hasil['ketidakpastian_diperluas']);
        $this->assertGreaterThan($a['u_diperluas'] * 0.9, $hasil['ketidakpastian_diperluas'], 'Turunnya wajar, bukan komponen yang hilang.');
        $this->assertTrue($hasil['boleh_terbit']);
        $this->assertEqualsWithDelta(0.0065, $hasil['pita_cmc']['u95_mm'], 1e-12);
    }

    public function test_keping_tidak_terdaftar_memblokir_titiknya_bukan_menyusutkan_total(): void
    {
        [$titik, $konteks] = $this->masukan();
        // 2,5 + 1,3 + 1,25 — 1,25 tidak ada di set GB-9122-0. Master diam-diam
        // menjumlah 3,8 mm dan koreksinya meleset 1,2 mm.
        $titik[2]['keping'] = [2.5, 1.3, 1.25];

        $hasil = (new DialIndicatorCalculator)->hitungSesi($titik, $konteks);

        $this->assertCount(9, $hasil['titik']);
        $this->assertNotContains(3, array_column($hasil['titik'], 'titik_ke'));
        $alasan = collect($hasil['ditolak'])->firstWhere('titik_ke', 3)['alasan'] ?? '';
        $this->assertStringContainsString('1,25', $alasan);
    }

    public function test_kapasitas_di_luar_pita_atau_kosong_tidak_terbit(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new DialIndicatorCalculator;

        foreach ([0.0, 350.0] as $kapasitas) {
            $hasil = $kalk->hitungSesi($titik, ['kapasitas_mm' => $kapasitas] + $konteks);
            $this->assertFalse($hasil['boleh_terbit'], "kapasitas {$kapasitas}");
            $this->assertSame(0.0, $hasil['u95_sertifikat']);
        }
    }

    public function test_pita_cmc_dari_kapasitas_mm_bukan_angka_mentah(): void
    {
        [$titik, $konteks] = $this->masukan();

        // Dial 1 inch = 25,4 mm → pita 0-50 (8,6 µm). Master memilih dari E15
        // mentah (`1`) dan mendarat di 0-25 mm.
        $hasil = (new DialIndicatorCalculator)->hitungSesi($titik, ['kapasitas_mm' => 25.4] + $konteks);

        $this->assertSame('B', $hasil['pita_cmc']['kode']);
    }

    public function test_resolusi_kosong_evaluation_kurang_atau_balok_evaluation_bermasalah_menahan_terbit(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new DialIndicatorCalculator;

        $this->assertFalse($kalk->hitungSesi($titik, ['resolusi_mm' => 0.0] + $konteks)['boleh_terbit']);
        $this->assertFalse($kalk->hitungSesi($titik, ['pra_evaluasi' => [25.01]] + $konteks)['boleh_terbit']);
        $this->assertFalse($kalk->hitungSesi($titik, ['balok_pra_evaluasi' => []] + $konteks)['boleh_terbit']);
        $this->assertFalse($kalk->hitungSesi($titik, ['balok_pra_evaluasi' => [14.0, 11.5]] + $konteks)['boleh_terbit']);
    }

    public function test_evaluation_identik_tetap_terbit_tapi_ditandai(): void
    {
        [$titik, $konteks] = $this->masukan();
        $hasil = (new DialIndicatorCalculator)->hitungSesi($titik, $konteks);

        // Sesi contoh master memang begitu: 25,01 sepuluh kali.
        $this->assertTrue($hasil['evaluasi_tanpa_sebaran']);
        $this->assertTrue($hasil['boleh_terbit']);
    }

    public function test_umur_drift_dari_tanggal_sesi_bukan_now(): void
    {
        [$titik, $konteks] = $this->masukan();
        $kalk = new DialIndicatorCalculator;

        $master = $kalk->hitungSesi($titik, $konteks);
        $sesi = $kalk->hitungSesi($titik, ['tanggal_kalibrasi' => new DateTimeImmutable('2024-05-06')] + $konteks);

        $drift = static fn (array $h): float => collect($h['budget'])->firstWhere('sumber', 'drift_standar')['u'];

        // 103 hari vs 695 hari umur balok ukur.
        $this->assertLessThan($drift($master), $drift($sesi));
        $this->assertGreaterThan(0.0, $drift($sesi));
    }
}
