<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarHydrometer;
use App\Services\Calibration\VolumetricGlasswareCalculator as V;
use PHPUnit\Framework\TestCase;

/**
 * Adu rantai **Volumetric Glassware** ke cache Excel workbook master.
 *
 * Angka "Excel" di bawah dibaca langsung dari sel workbook (`data_only`), bukan
 * dihitung ulang dari definisi rumus. Bedanya penting: kalau suatu saat
 * implementasi PHP-nya digeser, test ini gagal terhadap angka yang BENAR-BENAR
 * tercetak di sertifikat lab — bukan terhadap tafsiran kita sendiri atas
 * rumusnya.
 *
 * Sumber: `Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026/`
 * (`Fixed_…/PERHITUNGAN.csv` dan `Graduated_…/PERHITUNGAN.csv`).
 */
class VolumetricGlasswareMasterTest extends TestCase
{
    /** Toleransi rekonsiliasi repo. Yang diadu di sini sebenarnya cocok NOL. */
    private const TOLERANSI = 5e-6;

    // ---------------------------------------------------------------- Fixed

    /**
     * Fixed, nominal 1 mL — `Fixed_…/PERHITUNGAN.csv`.
     *
     * Kondisi lingkungan: `G16`/`G17`/`G19`. Suhu air terkoreksi `H39`
     * (= pembacaan + koreksi meter + koreksi sensor). Class B → γ = 15·10⁻⁶.
     */
    public function test_fixed_rantai_v20_cocok_dengan_master(): void
    {
        $rhoUdara = V::densitasUdara(20.75, 47.5, 933.1500000000001);
        $this->assertEqualsWithDelta(
            0.001101287184161609, $rhoUdara, self::TOLERANSI,
            'ρ udara meleset dari PERHITUNGAN!H57.',
        );

        $tAir = 27.0 + 0.3249999999999979 + 2.9007059112018396e-05;
        $this->assertEqualsWithDelta(
            27.32502900705911, $tAir, 1e-12,
            'Suhu air terkoreksi meleset dari PERHITUNGAN!H39.',
        );

        $rhoAir = V::densitasAirSuling($tAir);
        $this->assertEqualsWithDelta(
            0.9964251596993278, $rhoAir, self::TOLERANSI,
            'ρ air suling meleset dari PERHITUNGAN!H45.',
        );

        $gamma = V::gammaDariKelas('B');
        $this->assertNotNull($gamma);

        // `H60` — V20 dari massa rata-rata (N29).
        $this->assertEqualsWithDelta(
            1.0042575664928188,
            V::v20(0.9997000000000001, $rhoAir, $rhoUdara, $gamma, $tAir),
            1e-12,
            'V20 meleset dari PERHITUNGAN!H60.',
        );
    }

    /**
     * Fixed, V20 per ulangan — `H46`/`J46`/`L46`.
     *
     * Diuji terpisah dari rata-ratanya karena simpangan bakunya jadi komponen
     * budget: menghitung V20 sekali dari massa yang sudah dirata-rata memberi
     * angka yang mirip tapi menghapus sebarannya.
     */
    public function test_fixed_v20_per_ulangan_cocok_dengan_master(): void
    {
        $rhoUdara = V::densitasUdara(20.75, 47.5, 933.1500000000001);
        $tAir = 27.0 + 0.3249999999999979 + 2.9007059112018396e-05;
        $rhoAir = V::densitasAirSuling($tAir);
        $gamma = V::GAMMA_KELAS_B;

        $harapan = [
            ['massa' => 0.9998, 'v20' => 1.0043580223862358],   // H46
            ['massa' => 0.9997, 'v20' => 1.0042575664928186],   // J46
            ['massa' => 0.9996, 'v20' => 1.0041571105994014],   // L46
        ];

        foreach ($harapan as $baris) {
            $this->assertEqualsWithDelta(
                $baris['v20'],
                V::v20($baris['massa'], $rhoAir, $rhoUdara, $gamma, $tAir),
                1e-12,
                "V20 ulangan massa {$baris['massa']} g meleset dari master.",
            );
        }
    }

    // ------------------------------------------------------------ Graduated

    /**
     * Graduated, titik nominal 10 mL — `Graduated_…/PERHITUNGAN.csv`.
     *
     * Beda dari Fixed: densitas air dihitung PER ULANGAN dari suhu ulangan itu
     * sendiri, bukan sekali dari suhu rata-rata.
     */
    public function test_graduated_rantai_v20_cocok_dengan_master(): void
    {
        $rhoUdara = 0.0011008519333332652;   // H67, sama untuk semua titik
        $gamma = V::GAMMA_KELAS_B;

        $koreksi = 0.3249999999999979 + 2.9007059112018396e-05;
        $massa = [10.545099999999998, 10.556500000000007, 10.642400000000002];
        $suhu = [25.4 + $koreksi, 25.3 + $koreksi, 25.4 + $koreksi];

        $this->assertEqualsWithDelta(
            25.725029007059112, $suhu[0], 1e-12,
            'Suhu terkoreksi ulangan 1 meleset dari master.',
        );

        $v20 = [];
        foreach ($massa as $i => $m) {
            $v20[] = V::v20($m, V::densitasAirSuling($suhu[$i]), $rhoUdara, $gamma, $suhu[$i]);
        }

        $this->assertEqualsWithDelta(
            10.588560085479635, $v20[0], 1e-12,
            'V20 ulangan 1 titik 10 mL meleset dari master.',
        );

        $this->assertEqualsWithDelta(
            10.62484944968544, array_sum($v20) / count($v20), 1e-12,
            'Rata-rata V20 titik 10 mL meleset dari master.',
        );
    }

    // ------------------------------------------------------ penjaga struktur

    /**
     * Densitas air Volumetric dan Hydrometer **tidak boleh disatukan**.
     *
     * Keduanya sekeluarga metode, jadi menyatukannya terasa seperti
     * pembersihan yang wajar. Test ini ada supaya penyatuan itu gagal berisik,
     * bukan diam-diam menggeser sertifikat.
     *
     * Selisihnya mencapai 5,0·10⁻⁶ pada 15–35 °C — persis di ambang toleransi
     * rekonsiliasi, jadi sebagian suhu lolos dan sebagian tidak. Yang diuji di
     * sini 18 °C, tempat selisihnya paling besar.
     */
    public function test_densitas_air_hydrometer_bukan_pengganti(): void
    {
        $t = 18.0;

        $selisih = abs(
            TabelStandarHydrometer::densitasAirSuling($t) - V::densitasAirSuling($t)
        );

        $this->assertGreaterThan(
            1e-6,
            $selisih,
            'Kedua rumus densitas air jadi setara — periksa apakah salah satunya diubah. '
            .'Kalau memang disatukan sengaja, vektor uji V20 di berkas ini harus diadu ulang '
            .'ke master lebih dulu, bukan disesuaikan.',
        );
    }

    /** ρ anak timbangan Volumetric 7,95 — Hydrometer 8,0. Jangan tertukar. */
    public function test_densitas_anak_timbangan_bukan_milik_hydrometer(): void
    {
        $this->assertSame(7.95, V::DENSITAS_ANAK_TIMBANGAN);
        $this->assertNotSame(
            TabelStandarHydrometer::DENSITAS_BEBAN_STANDAR,
            V::DENSITAS_ANAK_TIMBANGAN,
            'ρ anak timbangan tertukar dengan milik Hydrometer — seluruh V20 bergeser tanpa error.',
        );
    }

    /**
     * Kelas di luar A/B ditolak, bukan dipetakan diam-diam.
     *
     * Workbook Graduated menyertakan tabel 14 material, tapi rumusnya cuma
     * memetakan dua. Mengaktifkan sisanya butuh keputusan lab lebih dulu.
     */
    public function test_kelas_di_luar_a_b_ditolak(): void
    {
        $this->assertSame(9.9e-6, V::gammaDariKelas('A'));
        $this->assertSame(15e-6, V::gammaDariKelas('B'));
        $this->assertSame(15e-6, V::gammaDariKelas(' b '));

        $this->assertNull(V::gammaDariKelas('C'));
        $this->assertNull(V::gammaDariKelas('Borosilicate 3.3'));
        $this->assertNull(V::gammaDariKelas(null));
        $this->assertNull(V::gammaDariKelas(''));
    }
}
