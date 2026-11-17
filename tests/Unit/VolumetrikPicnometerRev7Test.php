<?php

namespace Tests\Unit;

use App\Services\Calibration\Profiles\FixedVolumetricGlasswareRev7Profile;
use App\Services\Calibration\Profiles\PicnometerProfile;
use App\Services\Calibration\TabelStandarVolumetric;
use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use ReflectionMethod;
use Tests\TestCase;

/**
 * **Picnometer pindah ke jalur Rev.7** (PK-3, 17 Nov 2026) — diadu ke master
 * Fixed 12 Nov 2026 (`Master Olah Data_Fixed Volumetric Glassware`, tanpa
 * akhiran `_2026`, `FORM VALIDASI` rev 21 `2026-10-19`).
 *
 * Picnometer TIDAK punya sampel sesi di master; ia tipe alat ke-4 di dalam
 * master Fixed (`DATABASE!V39`, `Type_Alat`), dan satu-satunya percabangan
 * tipenya ada di CMC (`PERHITUNGAN U95%!B48`/`J48` → `CMC_picno`,
 * `DATABASE!AB5:AC7`). Jadi rantai hitungnya diadu ke sampel master (Pipet
 * Volume 1 mL, `INPUT DATA!E6` = 5) sel demi sel, dan percabangan CMC-nya
 * diadu ke tabel `CMC_picno`.
 *
 * Masukan = sel `INPUT DATA` sampel master (nama pelanggan sengaja tidak
 * disalin): `E14:E15` 1 mL, `I16:I17` kelas A ±0,01, `H33:L34` kosong 0 g dan
 * isi 0,9998/0,9997/0,9996 g (Analytical Balance, `Q24` = 1), `H39:M39` ENAM
 * suhu air berbeda 27,0/27,1/27,0/27,2/27,0/27,1 (awal/akhir per ulangan —
 * ketujuh sampel Rev.7 lama kembar awal = akhir), `E22:F25` lingkungan.
 *
 * Yang diadu (cache Excel, toleransi 1e-9 relatif): `PERHITUNGAN!H60` V20,
 * `H61` deviasi, `N35`/`N45` suhu & ρ air rata-rata, `PERHITUNGAN U95%!I36:I42`
 * ketujuh u·c, `I44` u_c, `I45` ν_eff (= `I44^4/K43`, `K43 = SUM`), `I46` k,
 * `I47` U, `H21 = O35 − P35` rentang suhu.
 *
 * **Satu data standar yang sengaja DIGANTI di sini dan dilaporkan sebagai
 * pertanyaan lab no. 26:** U95 termometer Yokogawa. Master 12 Nov memakai
 * 0,058 °C (`DATABASE!AB22 = 'STANDAR-YOKOGAWA'!H13`, kalibrasi ulang
 * 2026-09-17), tabel repo (`tabel-standar-volumetric.json`, dari master
 * `_2026`) masih 0,72 °C. Itu data standar volumetric, bukan struktur rumus,
 * dan di luar PK-3; test ini memasok 0,058 lewat subkelas tabel supaya RUMUS
 * Rev.7 yang diadu, bukan tabelnya.
 */
class VolumetrikPicnometerRev7Test extends TestCase
{
    private const TOLERANSI_RELATIF = 1e-9;

    /** Tabel repo dengan U95 termometer/sensor milik master 12 Nov (`DATABASE!AB22:AB23`). */
    private function tabelMaster12Nov(): TabelStandarVolumetric
    {
        return new class extends TabelStandarVolumetric
        {
            public function u95Suhu(): array
            {
                return ['termometer_c' => 0.058, 'sensor_c' => 0.08];
            }
        };
    }

    /** @return array<string, mixed> */
    private function blok(): array
    {
        return [
            'kelas' => 'A', 'toleransi_ml' => 0.01, 'resolusi_ml' => null, 'neraca' => 'Analytical Balance',
            'suhu_awal' => 21.0, 'suhu_akhir' => 20.5,
            'kelembaban_awal' => 48.0, 'kelembaban_akhir' => 47.0,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function titik(float $nominal = 1.0): array
    {
        return [[
            'titik_ke' => 1,
            'nominal' => $nominal,
            'kosong' => [0.0, 0.0, 0.0],
            'isi' => [0.9998, 0.9997, 0.9996],
            'suhu' => [27.0, 27.1, 27.0, 27.2, 27.0, 27.1],
        ]];
    }

    private function samaRelatif(float $harap, float $nyata, string $label): void
    {
        $this->assertLessThanOrEqual(
            self::TOLERANSI_RELATIF,
            abs($nyata - $harap) / max(abs($harap), 1e-300),
            sprintf('%s: harap %.17g, server %.17g', $label, $harap, $nyata),
        );
    }

    /** @return array<string, mixed> */
    private function hitung(?TabelStandarVolumetric $tabel, float $nominal = 1.0): array
    {
        // Parameter yang DIPAKAI profil Picnometer, bukan `parameterRev7()` ditulis ulang:
        // kalau profilnya kembali ke non-Rev.7, test ini ikut jatuh.
        $profil = new PicnometerProfile;
        $parameter = (new ReflectionMethod($profil, 'parameterHitung'))->invoke($profil);

        $hasil = (new V)->hitungSesi(V::KELUARGA_FIXED, $this->titik($nominal), $this->blok(), $tabel, $parameter);
        $this->assertTrue($hasil['boleh_terbit'], (string) json_encode($hasil['ditolak']));

        return $hasil['titik'][0];
    }

    public function test_picnometer_turunan_profil_rev7_dan_pakai_parameter_rev7(): void
    {
        $profil = new PicnometerProfile;

        $this->assertInstanceOf(FixedVolumetricGlasswareRev7Profile::class, $profil);
        $this->assertSame(V::parameterRev7(), (new ReflectionMethod($profil, 'parameterHitung'))->invoke($profil));
        $this->assertSame([3, 6], $profil->jumlahBacaanSuhuDiterima());
        $this->assertSame('picnometer', $profil->kode());
        $this->assertSame('Picnometer', $profil->namaAlatKemampuan());
    }

    /** Rantai hitung ke cache master 12 Nov, sel demi sel. */
    public function test_rantai_hitung_sama_dengan_cache_master_12_nov(): void
    {
        $h = $this->hitung($this->tabelMaster12Nov());

        // `PERHITUNGAN!H60`, `H61`.
        $this->samaRelatif(1.003932006686465, $h['v20'], 'V20 H60');
        $this->samaRelatif(0.003932006686464984, $h['deviasi'], 'deviasi H61');
        // `PERHITUNGAN!N35`, `N45`, dan `H21 = O35 − P35` (O35 melewatkan M39).
        $this->samaRelatif(27.391695673725778, $h['suhu_rata_rata'], 'suhu rata-rata N35');
        $this->samaRelatif(0.9967487851375182, $h['rho_air_rata_rata'], 'ρ air rata-rata N45');
        $this->samaRelatif(0.1999999999999993, $h['rentang_suhu'], 'rentang suhu H21');

        // Ketujuh komponen, bukan delapan — `PERHITUNGAN U95%!I36:I42`.
        $uici = [
            'Weight of Destillate Water' => 2.8987574504007923e-05,
            'Density of Air' => 5.612754568213035e-05,
            'Density of Destillate Water' => -2.2068667061535274e-05,
            'Density of Weight Standard' => 7.979615282981556e-06,
            'Temperature of Destillate Water' => -2.819481852853503e-06,
            'Expansion Coefficient of Material' => 5.796204084097871e-07,
            'Meniscus' => 0.0006365474356653112,
        ];
        $this->assertSame(array_keys($uici), array_column($h['komponen_budget'], 'nama'));

        foreach ($h['komponen_budget'] as $i => $k) {
            $this->samaRelatif(array_values($uici)[$i], $k['u'] * $k['ci'], sprintf('u·c baris %d (%s)', 36 + $i, $k['nama']));
        }

        $a = $h['agregat'];
        $this->samaRelatif(0.0006401110876056454, $a['ketidakpastian_gabungan'], 'u_c I44');
        // `I45 = (I44^4)/K43` dengan `K43 = SUM(K36:K42)` — penyebut JUMLAH, bukan baris terakhir.
        $this->samaRelatif(51.125355300627355, $a['derajat_kebebasan_efektif'], 'ν_eff I45');
        $this->samaRelatif(2.007583770315835, $a['faktor_cakupan_k'], 'k I46');
        $this->samaRelatif(0.0012850766306763113, $a['ketidakpastian_diperluas'], 'U I47');

        // Jalur K43 lama (veff dibagi baris terakhir) TIDAK dipakai Rev.7.
        $this->assertNull($h['pembanding_master']['veff_dibagi_baris_akhir']);
    }

    /**
     * Kejanggalan master yang ditiru tercatat sebagai pembanding jejak:
     * H40/J40 angka mati 25,5 °C (butir D) dan O35 melewatkan M39.
     */
    public function test_kejanggalan_master_ditiru_dan_pembandingnya_ada(): void
    {
        $h = $this->hitung($this->tabelMaster12Nov());
        $r = $h['pembanding_master']['rev7'];

        // Cabang yang dipakai = master (H40/J40 = 25,5 °C); pembanding = suhu terukur.
        $this->assertSame(V::SUHU_DENSITAS_AIR_MASTER, $r['suhu_densitas_air']);
        $this->assertSame(V::SUHU_DENSITAS_AIR_UKUR, $r['suhu_densitas_air_lain']);
        // `O35 = MAX(H39:L39)` melewatkan M39; hasilnya sama dengan `H21 = O35 − P35` master.
        $this->assertArrayHasKey('rentang_suhu_o35', $r);
        $this->assertSame(0.2, round($r['rentang_suhu_o35'], 12));
        $this->assertGreaterThan(0.0, abs($r['v20_lain'] - $h['v20']), 'cabang 25,5 °C ≠ cabang suhu terukur pada sampel ini');
    }

    /**
     * Percabangan tipe di master: `J48` Picnometer → `VLOOKUP(B48, CMC_picno, 2, 0)`
     * dengan `B48` = nominal TERDEKAT di `Index_picno` (`DATABASE!AB5:AB7` = 25/50/100).
     * Nilai `DATABASE!AC5:AC7`; imbang jarak jatuh ke baris PERTAMA (`MATCH(...,0)`).
     */
    public function test_cmc_picnometer_mengikuti_tabel_cmc_picno_master(): void
    {
        $tabel = new TabelStandarVolumetric;

        foreach ([
            [1.0, 25.0, 0.018], [10.0, 25.0, 0.018], [25.0, 25.0, 0.018],
            [38.0, 50.0, 0.019], [50.0, 50.0, 0.019],
            [75.0, 50.0, 0.019], // 25 mL dari 50 dan 100: imbang → baris pertama
            [100.0, 100.0, 0.022], [250.0, 100.0, 0.022],
        ] as [$nominal, $indeks, $cmc]) {
            $this->assertSame($indeks, $tabel->nominalTerindeks('Picnometer', $nominal), "indeks {$nominal} mL (B48)");
            $this->assertSame($cmc, $tabel->cmc('Picnometer', $nominal), "CMC {$nominal} mL (J48)");
        }
    }

    /** `J49 = MAX(I47, J48)` untuk picnometer: lantai CMC_picno menang di sampel master (U 0,00129 < 0,018). */
    public function test_u_cetak_picnometer_memakai_lantai_cmc_picno(): void
    {
        $tabel = $this->tabelMaster12Nov();
        $h = $this->hitung($tabel, 1.0);

        $u = $h['agregat']['ketidakpastian_diperluas'];
        $cmc = $tabel->cmc('Picnometer', 1.0);

        $this->assertSame(0.018, $cmc);
        $this->samaRelatif(0.018, max($u, $cmc), 'J49 Picnometer');
        // Pipet Volume di sampel yang sama: lantainya 0,003 (J48 master = 0,003).
        $this->assertSame(0.003, $tabel->cmc('Pipet Volume', 1.0));
    }

    /**
     * Pertanyaan lab no. 26: tabel repo masih U95 termometer 0,72 °C, master
     * 12 Nov 0,058 °C. Dikunci di sini supaya selisihnya TIDAK hilang diam-diam
     * (dan supaya yang memutakhirkan tabel tahu test ini yang harus diikutkan):
     * komponen suhu air dan U versi repo LEBIH BESAR dari master, komponen lain sama.
     */
    public function test_selisih_u95_termometer_repo_lawan_master_terdokumentasi(): void
    {
        $repo = $this->hitung(null);
        $master = $this->hitung($this->tabelMaster12Nov());

        $this->assertSame(0.72, (new TabelStandarVolumetric)->u95Suhu()['termometer_c'], 'tabel repo (pertanyaan lab no. 26)');

        foreach ($repo['komponen_budget'] as $i => $k) {
            if ($k['nama'] === 'Temperature of Destillate Water') {
                $this->assertGreaterThan(abs($master['komponen_budget'][$i]['u'] * $master['komponen_budget'][$i]['ci']), abs($k['u'] * $k['ci']));

                continue;
            }
            $this->assertSame($master['komponen_budget'][$i]['u'], $k['u'], $k['nama']);
        }

        $this->assertGreaterThan(
            $master['agregat']['ketidakpastian_diperluas'],
            $repo['agregat']['ketidakpastian_diperluas'],
            'tabel repo lebih konservatif dari master 12 Nov',
        );
    }
}
