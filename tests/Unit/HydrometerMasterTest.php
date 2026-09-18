<?php

namespace Tests\Unit;

use App\Services\Calibration\HydrometerCalculator;
use App\Services\Calibration\TabelStandarHydrometer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adu **Hydrometer** ke dua workbook master lab (password `spirit285`).
 *
 * Kedua file itu sertifikat yang SUDAH TERBIT — 8 Sep 2025 (rentang ringan,
 * pakai beban tambahan) dan 7 Nov 2025 (rentang berat, tanpa). Kalau aplikasi
 * mencetak ulang salah satunya dan angkanya bergeser, yang rusak bukan test
 * ini melainkan sertifikat pelanggan.
 *
 * Toleransinya **1·10⁻¹²**, bukan "kira-kira sama": rantai ini penuh selisih
 * yang cuma kelihatan di digit belasan — urutan penjumlahan polinomial
 * densitas air, `π` 3,14 lawan `M_PI`, `TINV` yang memotong derajat kebebasan.
 * Toleransi longgar membuat ketiganya lolos diam-diam.
 *
 * Yang diadu berlapis, dari luar ke dalam, supaya kegagalan menunjuk
 * LANGKAHNYA bukan cuma "hasil akhir beda":
 *
 *   1. nilai antara sesi (ρ udara, faktor koreksi, M_air, πDγ/g)
 *   2. densitas tiap titik skala
 *   3. ketujuh koefisien sensitivitas
 *   4. `uc`, `v_eff`, `k`, `U`, dan `U95%` sertifikat
 */
class HydrometerMasterTest extends TestCase
{
    /** Selisih float yang masih dianggap sama — lihat docblock kelas. */
    private const TOL = 1.0e-12;

    /**
     * `Master Olah Data Hydrometer 0.600-0.650.xlsm`, sheet `INPUT DATA`.
     * Sl terisi (54,0052 g) → varian beban tambahan.
     */
    private const RINGAN = [
        'pakai_beban_tambahan' => true,
        'beban_tambahan' => 54.0052,      // E31
        'massa_udara' => 39.9327,         // E32
        'tegangan_permukaan' => 17.5,     // E33
        'satuan_tegangan' => 'mN/m',      // F33 -> Satuan_teg_muka
        'suhu_acuan_alat' => 15.0,        // E34 (tr)
        'suhu_acuan_faktor' => 20.0,      // E17 (Temperature) — BUKAN tr, temuan 6
        'diameter_stem' => [0.708, 0.710, 0.709], // L33:N33
        'resolusi' => 0.0005,             // E16
        'suhu_awal' => 20.4,              // E22
        'suhu_akhir' => 20.5,             // F22
        'kelembaban_awal' => 56.0,        // E23
        'kelembaban_akhir' => 55.0,       // F23
        'tekanan_awal' => 933.2,          // E25
        'tekanan_akhir' => 933.1,         // F25
    ];

    private const TITIK_RINGAN = [
        ['titik_ke' => 1, 'titik_ukur' => 0.610, 'massa' => [21.2727, 21.2726, 21.2856], 'suhu' => [20.6, 20.6, 20.6]],
        ['titik_ke' => 2, 'titik_ukur' => 0.625, 'massa' => [22.7483, 22.7446, 22.7491], 'suhu' => [20.6, 20.6, 20.6]],
        ['titik_ke' => 3, 'titik_ukur' => 0.650, 'massa' => [25.4602, 25.4608, 25.4621], 'suhu' => [20.7, 20.7, 20.7]],
    ];

    /** `Master Olah Data Hydrometer 1.800-2.000.xlsm`. Sl kosong → varian non-sinker. */
    private const BERAT = [
        'pakai_beban_tambahan' => false,
        'beban_tambahan' => null,
        'massa_udara' => 54.2144,
        'tegangan_permukaan' => 75.0,
        'satuan_tegangan' => 'mN/m',
        'suhu_acuan_alat' => 20.0,
        'suhu_acuan_faktor' => 20.0,
        'diameter_stem' => [0.615, 0.615, 0.615],
        'resolusi' => 0.001,
        'suhu_awal' => 20.4,
        'suhu_akhir' => 20.5,
        'kelembaban_awal' => 56.0,
        'kelembaban_akhir' => 55.0,
        'tekanan_awal' => 933.2,
        'tekanan_akhir' => 933.1,
    ];

    private const TITIK_BERAT = [
        ['titik_ke' => 1, 'titik_ukur' => 1.800, 'massa' => [24.1957, 24.1985, 24.1929], 'suhu' => [20.4, 20.4, 20.4]],
        ['titik_ke' => 2, 'titik_ukur' => 1.900, 'massa' => [25.7667, 25.7658, 25.7685], 'suhu' => [20.4, 20.4, 20.4]],
        ['titik_ke' => 3, 'titik_ukur' => 2.000, 'massa' => [27.1826, 27.1814, 27.1837], 'suhu' => [20.4, 20.4, 20.4]],
    ];

    #[Test]
    public function nilai_antara_sesi_ringan_cocok_master(): void
    {
        $p = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, self::RINGAN)['praolah'];

        // PERHITUNGAN!J78 / J85 / J88 / J80 / M74 / M75
        $this->cocok(0.0011016523357163883, $p['rho_udara'], 'densitas udara (J78)');
        $this->cocok(1.0000000020025, $p['f_press'], 'faktor koreksi tekanan (J85)');
        $this->cocok(1.000006, $p['f_temp'], 'faktor koreksi suhu (J88)');
        $this->cocok(39.927001033513, $p['m_air'], 'massa terkoreksi di udara (J80)');
        $this->cocok(0.03972768478532425, $p['pid_yx'], 'πDγx/g (M74)');
        $this->cocok(0.1649466230445667, $p['pid_yl'], 'πDγL/g (M75)');
        $this->cocok(0.709, $p['diameter'], 'diameter stem rata-rata (Q26)');
        $this->cocok(72.6588, $p['st_maks'], 'tegangan permukaan maksimum (L53)');
    }

    #[Test]
    public function densitas_varian_beban_tambahan_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, self::RINGAN);

        // PERHITUNGAN!G109 / H109 / I109 -> 'INPUT DATA'!G39:I39 -> SERTIFIKAT!J17:J19
        $harap = [0.6039099485606535, 0.6176250479037468, 0.6446329414723625];

        $this->assertTrue($hasil['boleh_terbit'], 'sesi master mestinya boleh terbit');
        $this->assertCount(3, $hasil['titik']);

        foreach ($harap as $i => $h) {
            $this->cocok($h, $hasil['titik'][$i]['densitas'], 'densitas titik ke-'.($i + 1));
        }
    }

    #[Test]
    public function densitas_varian_tanpa_beban_tambahan_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_BERAT, self::BERAT);
        $harap = [1.797703991485271, 1.8964429206286084, 1.9951727389072496];

        $this->assertTrue($hasil['boleh_terbit']);

        foreach ($harap as $i => $h) {
            $this->cocok($h, $hasil['titik'][$i]['densitas'], 'densitas titik ke-'.($i + 1));
        }
    }

    /**
     * Varian ditentukan TOGGLE, bukan angka rentang.
     *
     * Hydrometer ringan yang sama persis, dihitung tanpa sinker, memulangkan
     * densitas yang tampak wajar (masih ber-orde satuan yang sama) dan salah
     * jauh. Tidak ada satu pun yang memprotes — itulah kenapa toggle-nya harus
     * eksplisit dan kenapa test ini ada.
     */
    #[Test]
    public function varian_salah_memberi_angka_yang_tampak_wajar_tapi_beda_jauh(): void
    {
        $tanpaSinker = ['pakai_beban_tambahan' => false, 'beban_tambahan' => null] + self::RINGAN;
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, $tanpaSinker);

        $salah = $hasil['titik'][0]['densitas'];

        $this->assertGreaterThan(
            0.01,
            abs($salah - 0.6039099485606535),
            'rumus non-sinker atas hydrometer ber-sinker mestinya meleset jauh; '
            .'kalau tidak, kedua varian sedang menghitung hal yang sama dan test ini tidak menjaga apa pun',
        );
    }

    #[Test]
    public function koefisien_sensitivitas_ringan_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, self::RINGAN);
        $c = $hasil['titik'][0]['koefisien_sensitivitas'];

        // NILAI U95%! C55 / C57 / C58 / F55 / F57 / I55 / I57 (SKALA 1)
        $this->cocok(0.004996826995391796, $c['massa_di_udara'], 'koef 7.9 massa di udara');
        $this->cocok(0.2996837039708289, $c['diameter_stem'], 'koef 7.10 diameter stem');
        $this->cocok(0.019203318642506156, $c['massa_di_cairan'], 'koef 7.10 massa di cairan');
        $this->cocok(-0.29968190587857835, $c['gravitasi_lokal'], 'koef 7.11 gravitasi lokal');
        $this->cocok(0.01358887753332108, $c['tegangan_permukaan'], 'koef 7.12 tegangan permukaan');
        $this->cocok(0.7919941535297305, $c['suhu_air'], 'koef sensitivitas suhu air');
        $this->cocok(0.00022746830589413856, $c['suhu_udara'], 'koef sensitivitas suhu udara');
    }

    #[Test]
    public function koefisien_sensitivitas_berat_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_BERAT, self::BERAT);
        $c = $hasil['titik'][0]['koefisien_sensitivitas'];

        $this->cocok(-0.10881102585593508, $c['massa_di_udara'], 'koef 7.9');
        $this->cocok(2.1409067209711052, $c['diameter_stem'], 'koef 7.10 stem');
        $this->cocok(0.15531405176676305, $c['massa_di_cairan'], 'koef 7.10 massa cairan');
        $this->cocok(-2.140898157374662, $c['gravitasi_lokal'], 'koef 7.11');
        $this->cocok(0.09533803843207936, $c['tegangan_permukaan'], 'koef 7.12');
        $this->cocok(3.333585669987012, $c['suhu_air'], 'koef suhu air');
        $this->cocok(-0.0025777230285131984, $c['suhu_udara'], 'koef suhu udara');
    }

    /**
     * Budget lengkap skala 1 & 2 file ringan — `uc`, `v_eff`, `k`, `U`.
     *
     * `k` di sini yang membuktikan temuan 5: kuantil-t TEPAT pada v_eff
     * 125,2997 adalah 1,9790778; master menulis 1,9791241 karena `TINV`
     * memotong derajat kebebasan jadi 125. Selisihnya masuk desimal ke-5
     * `U95%` — desimal yang memang dicetak.
     */
    #[Test]
    public function budget_ringan_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, self::RINGAN);

        $harap = [
            ['uc' => 0.00024371400607472074, 'veff' => 125.29969882563776, 'k' => 1.9791241094237992, 'U' => 0.0004823402652267381],
            ['uc' => 0.00024390293166672185, 'veff' => 123.44128780971201, 'k' => 1.979438685093305, 'U' => 0.0004827908983487781],
        ];

        foreach ($harap as $i => $h) {
            $t = $hasil['titik'][$i];
            $this->cocok($h['uc'], $t['ketidakpastian_gabungan'], 'uc skala '.($i + 1));
            // v_eff ber-orde 10² — toleransi absolut 1e-12 di situ berarti 1e-14
            // relatif, lebih ketat dari presisi float ganda.
            $this->assertEqualsWithDelta($h['veff'], $t['derajat_kebebasan_efektif'], 1e-9, 'v_eff skala '.($i + 1));
            $this->cocok($h['k'], $t['faktor_cakupan_k'], 'k skala '.($i + 1));
            $this->cocok($h['U'], $t['ketidakpastian_diperluas'], 'U skala '.($i + 1));
        }
    }

    /**
     * File berat: angka yang tercetak sertifikat datang MURNI dari budget.
     *
     * Dua sebabnya, dan dua-duanya menunjuk arah yang sama: rentangnya
     * (1,800-2,000 g/mL) ada di ATAS pita CMC tertinggi lampiran (1,70), jadi
     * tidak ada lantai sama sekali — dan `U` hitungnya pun sudah di atas kedua
     * pita. Sesinya tetap terbit, tanpa klaim akreditasi; lihat
     * `HydrometerProfile::dalamLingkupAkreditasiSesi()`.
     */
    #[Test]
    public function u95_sertifikat_berat_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_BERAT, self::BERAT);

        // NILAI U95%!L80 / L114 / L148
        $harap = [0.0008669981172366112, 0.0008765155395305542, 0.0009011821747909535];

        foreach ($harap as $i => $h) {
            $this->cocok($h, $hasil['titik'][$i]['ketidakpastian_diperluas'], 'U95% sertifikat skala '.($i + 1));
            $this->assertGreaterThan(
                0.0007,
                $hasil['titik'][$i]['ketidakpastian_diperluas'],
                'skala '.($i + 1).' file berat mestinya di atas kedua pita CMC lampiran',
            );
        }
    }

    /**
     * File ringan: ketiga `U` hitungnya di bawah lantai CMC, jadi yang tercetak
     * sertifikat lantainya — dan DI SITU aplikasi sengaja berbeda dari master.
     *
     * Master mencetak **0,0007**. Lampiran akreditasi LK-285-IDN (kelompok
     * Densitas no. 32) memuat DUA pita CMC hydrometer:
     *
     *     1,10 – 1,70 g/mL  →  0,00070
     *     0,60 – 1,00 g/mL  →  0,00051
     *
     * Alat ini 0,600-0,650 g/mL — pita KEDUA. Masternya memakai angka pita
     * PERTAMA, pita yang alat ini tidak ada di dalamnya. Lihat
     * `docs/pertanyaan-lab-hydrometer.md` §7.
     *
     * Yang dijaga di sini ANGKA HITUNGNYA, dan itu tidak berubah sedikit pun:
     * ketiganya tetap di bawah 0,00051, jadi lantainya tetap yang menang dan
     * kesimpulan "U95 sertifikat = CMC" juga tidak berubah. Lantai mana yang
     * dipasang diputuskan profil dari `calibration_capabilities`, bukan
     * kalkulator ini — itu diadu di `HydrometerSesiTest`.
     */
    #[Test]
    public function u95_hitung_ringan_di_bawah_kedua_pita_cmc_lampiran(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, self::RINGAN);

        foreach ($hasil['titik'] as $i => $t) {
            $this->assertLessThan(
                0.00051,
                $t['ketidakpastian_diperluas'],
                'skala '.($i + 1).' file ringan mestinya di bawah lantai CMC pita 0,60-1,00',
            );
        }
    }

    /** Correction sertifikat = Actual − Nominal (`SERTIFIKAT!O17`). */
    #[Test]
    public function koreksi_sertifikat_cocok_master(): void
    {
        $hasil = (new HydrometerCalculator)->hitungSesi(self::TITIK_RINGAN, self::RINGAN);
        $harap = [-0.006090051439346489, -0.007374952096253051, -0.005367058527637525];

        foreach ($harap as $i => $h) {
            $this->cocok($h, $hasil['titik'][$i]['koreksi'], 'koreksi skala '.($i + 1));
        }
    }

    /**
     * Tabel tegangan permukaan diinterpolasi LINEAR dari dua titik yang
     * mengapit, persis `FORECAST` master — bukan dibulatkan ke baris terdekat.
     */
    #[Test]
    public function tabel_tegangan_permukaan_diinterpolasi_seperti_master(): void
    {
        // 'Tabel Surface Tension'!B9 pada 20,6 °C dan B11 pada 20,7 °C
        $this->cocok(72.6588, TabelStandarHydrometer::teganganPermukaan(20.6), 'γ(20,6)');
        $this->cocok(72.64359999999999, TabelStandarHydrometer::teganganPermukaan(20.7), 'γ(20,7)');
        // Titik tabel dikembalikan apa adanya.
        $this->cocok(72.75, TabelStandarHydrometer::teganganPermukaan(20.0), 'γ(20,0)');
    }

    /** Polinomial densitas air orde-5, `PERHITUNGAN!G56`. */
    #[Test]
    public function polinomial_densitas_air_cocok_master(): void
    {
        $this->cocok(0.9980761251005232, TabelStandarHydrometer::densitasAirSuling(20.6), 'ρ air 20,6 °C');
        $this->cocok(0.9980548064013033, TabelStandarHydrometer::densitasAirSuling(20.7), 'ρ air 20,7 °C');
    }

    private function cocok(float $harap, ?float $dapat, string $apa): void
    {
        $this->assertNotNull($dapat, $apa.' tidak dihitung sama sekali');
        $this->assertEqualsWithDelta(
            $harap,
            $dapat,
            self::TOL,
            sprintf('%s: master %.17g, aplikasi %.17g (selisih %.3e)', $apa, $harap, $dapat, abs($harap - $dapat)),
        );
    }
}
