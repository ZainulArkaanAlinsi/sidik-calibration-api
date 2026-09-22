<?php

namespace Tests\Unit;

use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use Tests\TestCase;

/**
 * `VolumetricGlasswareCalculator::hitungSesi()` diadu ke master dari MASUKAN
 * MENTAH — berat kosong, berat berisi air, dan suhu BACAAN persis seperti yang
 * diketik di `INPUT_DATA` kedua workbook. Koreksi suhu, densitas, V20,
 * agregasi budget, dan angka pembanding master semuanya lahir di dalam.
 *
 * Beda dari `VolumetricGlasswareBudgetTest` yang menyuapi budget dengan sel
 * master yang sudah jadi: kalau di sini cocok, berarti rantai dari layar HP
 * sampai U95 yang tersimpan cocok.
 */
class VolumetricGlasswareSesiTest extends TestCase
{
    private function lingkungan(float $suhuAwal, float $suhuAkhir, float $rhAwal, float $rhAkhir): array
    {
        return [
            'suhu_awal' => $suhuAwal, 'suhu_akhir' => $suhuAkhir,
            'kelembaban_awal' => $rhAwal, 'kelembaban_akhir' => $rhAkhir,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
        ];
    }

    /** Fixed, Pipet Volume 1 mL, Class B ± 0,008 — `Fixed_…/INPUT_DATA`. */
    private function sesiFixed(array $timpa = []): array
    {
        return (new V)->hitungSesi(V::KELUARGA_FIXED, [[
            'titik_ke' => 1,
            'nominal' => 1.0,
            'kosong' => [0.0, 0.0, 0.0],
            'isi' => [0.9998, 0.9997, 0.9996],
            'suhu' => [27.0, 27.0, 27.0],
        ]], $timpa + [
            'kelas' => 'B', 'toleransi_ml' => 0.008, 'resolusi_ml' => null,
            'neraca' => 'Analytical Balance',
        ] + $this->lingkungan(21.0, 20.5, 48.0, 47.0));
    }

    /** Graduated, Gelas Ukur 100 mL titik 10/50/100, Class B — `Graduated_…/INPUT_DATA`. */
    private function titikGraduated(): array
    {
        return [
            ['titik_ke' => 1, 'nominal' => 10.0,
                'kosong' => [60.234, 60.24, 60.243],
                'isi' => [70.7791, 70.79650000000001, 70.8854],
                'suhu' => [25.4, 25.3, 25.4]],
            ['titik_ke' => 2, 'nominal' => 50.0,
                'kosong' => [60.255, 60.258, 60.263],
                'isi' => [110.944, 110.9023, 111.0863],
                'suhu' => [25.4, 25.3, 25.5]],
            ['titik_ke' => 3, 'nominal' => 100.0,
                'kosong' => [60.241, 60.247, 60.25],
                'isi' => [159.4398, 159.5042, 159.48520000000002],
                'suhu' => [25.3, 25.2, 25.4]],
        ];
    }

    private function blokGraduated(): array
    {
        return [
            'kelas' => 'B', 'toleransi_ml' => 0.5, 'resolusi_ml' => 1.0,
            'neraca' => 'Electronic Balance Precisa',
        ] + $this->lingkungan(20.4, 20.5, 64.0, 62.0);
    }

    public function test_fixed_dari_mentah_cocok_dengan_master(): void
    {
        $hasil = $this->sesiFixed();

        $this->assertTrue($hasil['boleh_terbit']);
        $this->assertSame([], $hasil['ditolak']);
        $t = $hasil['titik'][0];

        $this->assertEqualsWithDelta(0.001101287184161609, $hasil['praolah']['rho_udara'], 1e-15);
        $this->assertEqualsWithDelta(27.32502900705911, $t['suhu_rata_rata'], 1e-12);
        $this->assertEqualsWithDelta(1.0042575664928188, $t['v20'], 1e-12, 'V20 tercetak (H60).');
        $this->assertEqualsWithDelta(0.004257566492818832, $t['deviasi'], 1e-12, 'Deviation (H61).');
        $this->assertEqualsWithDelta(0.00010045589341722838, $t['stdev_v20'], 1e-15, 'STDEV V20 (N47).');

        $this->assertEqualsWithDelta(5.7735026918962585e-05, $t['masukan_budget']['u_massa'], 1e-18, 'C20.');
        $this->assertEqualsWithDelta(0.36221540552549664, $t['masukan_budget']['u_suhu'], 1e-15, 'H25.');
        $this->assertEqualsWithDelta(0.0008670325000000002, $t['masukan_budget']['u_meniskus'], 1e-15, 'B32.');

        $this->assertEqualsWithDelta(0.000508808998503895, $t['agregat']['ketidakpastian_gabungan'], 1e-15);
        $this->assertEqualsWithDelta(53.11949372298425, $t['agregat']['derajat_kebebasan_efektif'], 1e-9);

        // Pembanding K4 — Veff master yang membagi baris terakhir saja.
        $this->assertEqualsWithDelta(11846.497219116, $t['pembanding_master']['veff_dibagi_baris_akhir'], 1e-6);
    }

    public function test_graduated_dari_mentah_cocok_dengan_master(): void
    {
        $hasil = (new V)->hitungSesi(V::KELUARGA_GRADUATED, $this->titikGraduated(), $this->blokGraduated());

        $this->assertTrue($hasil['boleh_terbit']);
        $this->assertEqualsWithDelta(0.0011008519333332652, $hasil['praolah']['rho_udara'], 1e-15);

        // `PERHITUNGAN!H53/J53/L53` dan `H89−H82` dst.
        $harapan = [
            [10.62484944968544, 0.6248494496854402],
            [50.92790025937759, 0.9279002593775871],
            [99.63673552715461, -0.3632644728453869],
        ];
        foreach ($hasil['titik'] as $i => $t) {
            $this->assertEqualsWithDelta($harapan[$i][0], $t['v20'], 1e-10, "V20 titik {$t['titik_ke']}.");
            $this->assertEqualsWithDelta($harapan[$i][1], $t['deviasi'], 1e-10, "Deviasi titik {$t['titik_ke']}.");
        }

        $m = $hasil['titik'][0]['masukan_budget'];
        $this->assertEqualsWithDelta(99.23039999999999, $m['massa'], 1e-10, 'R38 = MAX massa rata-rata.');
        $this->assertEqualsWithDelta(0.9968847850530534, $m['rho_air'], 1e-15, 'R85 = MAX ρ air rata-rata.');
        $this->assertEqualsWithDelta(25.680584562614666, $m['suhu_air'], 1e-12, 'Z49 = rata-rata gabungan.');
        $this->assertEqualsWithDelta(0.37242448899072145, $m['u_suhu'], 1e-12, 'H25, Tmax−Tmin 0,3.');
        $this->assertEqualsWithDelta(0.00095, $m['u_massa'], 1e-15, 'C20 = U95 Precisa / 2.');
        $this->assertEqualsWithDelta(0.2886751345948129, $m['u_meniskus'], 1e-15, 'B28.');

        // Pembanding K3 — keterulangan master (lima nol hantu) mereproduksi U master PERSIS.
        $p = $hasil['titik'][0]['pembanding_master'];
        $this->assertEqualsWithDelta(0.03537981705907074, $p['stdev_keterulangan_nol_hantu'], 1e-12, 'H55 master.');
        $this->assertEqualsWithDelta(0.33730591902069956, $p['u95_nol_hantu'], 1e-9, 'U master.');
        $this->assertEqualsWithDelta(0.03339744467165117, $p['stdev_keterulangan_benar'], 1e-12);

        // Satu budget untuk semua titik.
        $u = array_map(static fn (array $t): float => $t['agregat']['ketidakpastian_diperluas'], $hasil['titik']);
        $this->assertCount(1, array_unique(array_map('strval', $u)));
        $this->assertLessThan(0.33730591902069956, $u[0]);
    }

    public function test_kelas_neraca_dan_lingkungan_yang_salah_menahan_semua_titik(): void
    {
        $this->assertFalse($this->sesiFixed(['kelas' => 'C'])['boleh_terbit']);
        $this->assertStringContainsString('bukan A atau B', $this->sesiFixed(['kelas' => 'C'])['ditolak'][0]['alasan']);

        // Precisa milik workbook Graduated, bukan Fixed.
        $this->assertStringContainsString(
            'bukan neraca workbook Fixed',
            $this->sesiFixed(['neraca' => 'Electronic Balance Precisa'])['ditolak'][0]['alasan'],
        );

        $this->assertStringContainsString('Kondisi lingkungan', $this->sesiFixed(['tekanan_akhir' => null])['ditolak'][0]['alasan']);

        // 0,009 tidak ada di tabel ISO 4787 — tidak memakai diameter tetangga.
        $this->assertStringContainsString('ISO 4787', $this->sesiFixed(['toleransi_ml' => 0.009])['ditolak'][0]['alasan']);
    }

    public function test_graduated_satu_titik_rusak_menahan_seluruh_sesi(): void
    {
        $titik = $this->titikGraduated();
        $titik[1]['suhu'] = [25.4, 25.3];

        $hasil = (new V)->hitungSesi(V::KELUARGA_GRADUATED, $titik, $this->blokGraduated());

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertSame([1, 2, 3], array_column($hasil['ditolak'], 'titik_ke'));
        $this->assertStringContainsString('harus tepat 3', $hasil['ditolak'][1]['alasan']);
        $this->assertStringContainsString('ditahan', $hasil['ditolak'][0]['alasan']);
    }

    public function test_graduated_satu_titik_ditolak_karena_keterulangan_tak_terdefinisi(): void
    {
        $hasil = (new V)->hitungSesi(V::KELUARGA_GRADUATED, [$this->titikGraduated()[0]], $this->blokGraduated());

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertStringContainsString('minimal dua', $hasil['ditolak'][0]['alasan']);
    }

    public function test_deret_tertukar_dan_titik_berlebih_ditolak(): void
    {
        $hasil = (new V)->hitungSesi(V::KELUARGA_FIXED, [
            ['titik_ke' => 1, 'nominal' => 1.0, 'kosong' => [1.0, 1.0, 1.0], 'isi' => [0.5, 0.5, 0.5], 'suhu' => [27.0, 27.0, 27.0]],
            ['titik_ke' => 2, 'nominal' => 1.0, 'kosong' => [0.0, 0.0, 0.0], 'isi' => [1.0, 1.0, 1.0], 'suhu' => [27.0, 27.0, 27.0]],
        ], ['kelas' => 'B', 'toleransi_ml' => 0.008, 'neraca' => 'Analytical Balance'] + $this->lingkungan(21.0, 20.5, 48.0, 47.0));

        $this->assertFalse($hasil['boleh_terbit']);
        $this->assertStringContainsString('tertukar', $hasil['ditolak'][0]['alasan']);
        $this->assertStringContainsString('melebihi batas 1', $hasil['ditolak'][1]['alasan']);
    }
}
