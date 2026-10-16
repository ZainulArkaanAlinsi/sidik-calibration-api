<?php

namespace Tests\Unit;

use App\Models\CalibrationSession;
use App\Services\Calibration\FlowmeterCalculator;
use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Support\FlowmeterMentah;
use Tests\TestCase;

/**
 * Isian LAPANGAN Flowmeter (lembar lapangan 14 Okt 2026): UUT Coriolis membaca
 * MASSA (kg/h), UFM clamp-on membaca VOLUME fluida yang sama (L/h), densitas
 * minyak 0,91 kg/L, suhu tidak tercatat, dan tiap ulangan cuma satu durasi
 * (kolom 20").
 *
 * Angka harapan dihitung TANGAN dari data mentah, bukan disalin dari keluaran:
 *   UUT rata  = (16442,4 + 16455,8 + 16478,0)/3 / 0,91 / 60 = 301,442002442 Lpm
 *   std rata  = (18070 + 18080 + 18110)/3 / 60              = 301,444444444 Lpm
 *   koreksi tabel UFM terdekat (baris 236,147 Lpm)          = −2,911 Lpm
 *   deviasi   = 301,444444444 − 2,911 − 301,442002442       = −2,908558 Lpm
 *   U95       = lantai CMC 1,2 % × 301,442002442             = 3,617304029 Lpm
 */
class FlowmeterIsianLapanganTest extends TestCase
{
    private const TOL = 1e-9;

    /** @return array<string, mixed> */
    private static function konteks(?string $satuanStandar = 'L/h', string $satuan = 'kg/h'): array
    {
        return [
            'mode' => FlowmeterMentah::MODE_FLOWRATE,
            'satuan' => $satuan,
            'satuan_standar' => $satuanStandar,
            'resolusi' => 0.1,
            'diameter_pipa_mm' => [76.2],
            'ketebalan_pipa_mm' => [5.14],
        ];
    }

    /** @return array<string, mixed> */
    private static function titikF1(array $tambahan = []): array
    {
        return $tambahan + [
            'titik_ke' => 1,
            'uut' => [[16442.4], [16455.8], [16478.0]],
            'std' => [18070, 18080, 18110],
            'suhu_awal' => [],
            'suhu_akhir' => [],
            'densitas_uut' => [0.91],
        ];
    }

    public function test_isian_lapangan_satu_durasi_tanpa_suhu_terhitung_dengan_rasio_densitas_satu(): void
    {
        $hasil = (new FlowmeterCalculator)->hitungSesi([self::titikF1()], self::konteks());

        $this->assertSame([], $hasil['ditolak'], 'Titik lapangan yang terisi wajib terhitung, bukan ditolak.');
        $t = $hasil['titik'][0];

        $uutRata = (16442.4 + 16455.8 + 16478.0) / 3 / 0.91 / 60;
        $stdRata = (18070 + 18080 + 18110) / 3 / 60;

        $this->assertEqualsWithDelta($uutRata, $t['uut_rata'], self::TOL);
        $this->assertEqualsWithDelta($stdRata, $t['std_rata'], self::TOL);
        $this->assertEqualsWithDelta(-2.911, $t['koreksi_standar'], self::TOL);
        // Rasio densitas = 1: standar terkoreksi = std rata + koreksi tabel.
        $this->assertEqualsWithDelta($stdRata - 2.911, $t['std_terkoreksi'], self::TOL);
        $this->assertEqualsWithDelta(-2.908558, $t['deviasi'], 1e-6);
        $this->assertEqualsWithDelta(0.012 * $uutRata, $t['u95_sertifikat'], self::TOL);
        $this->assertTrue($t['lantai_cmc_dipakai']);
        $this->assertTrue($t['fluida_sama']);
        $this->assertTrue($t['uut_satu_durasi']);
        $this->assertFalse($t['suhu_tercatat']);

        // Pengulangan UUT = simpangan baku ANTAR-ULANGAN, bukan nol.
        $uutLpm = array_map(static fn (float $x): float => $x / 0.91 / 60, [16442.4, 16455.8, 16478.0]);
        $rata = array_sum($uutLpm) / 3;
        $sd = sqrt(array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $uutLpm)) / 2);
        $this->assertEqualsWithDelta($sd, $t['simpangan_baku_uut'], self::TOL);
        $this->assertGreaterThan(0.0, $sd);
    }

    public function test_dalam_kg_per_jam_koreksi_sama_dengan_std_kali_densitas_ditambah_koreksi_tabel_dikurangi_uut(): void
    {
        $t = (new FlowmeterCalculator)->hitungSesi([self::titikF1()], self::konteks())['titik'][0];

        // Arah balik ke satuan alat: Lpm × 0,91 × 60. Kontrol kewajaran dari
        // lembar: std × 0,91 − UUT = +0,1333 kg/h, ditambah koreksi tabel UFM
        // −2,911 Lpm × 54,6 = −158,94 kg/h.
        $kgh = $t['deviasi'] * 0.91 * 60;
        $harap = (18070 + 18080 + 18110) / 3 * 0.91 - (16442.4 + 16455.8 + 16478.0) / 3 - 2.911 * 0.91 * 60;

        $this->assertEqualsWithDelta($harap, $kgh, 1e-6);
    }

    public function test_tanpa_satuan_standar_jalur_master_tetap_menolak_tanpa_suhu(): void
    {
        $hasil = (new FlowmeterCalculator)->hitungSesi(
            [self::titikF1(['std' => [16443.7, 16452.8, 16480.1]])],
            self::konteks(null),
        );

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('Suhu air', $hasil['ditolak'][0]['alasan']);
    }

    public function test_satuan_standar_sama_dengan_satuan_uut_dianggap_jalur_master(): void
    {
        $titik = [[
            'titik_ke' => 1,
            'uut' => [[101.255, 101.276, 101.289], [102.654, 102.625, 102.678], [101.986, 101.910, 101.945]],
            'std' => [101.998, 101.897, 101.123],
            'suhu_awal' => [24.5, 24.5, 24.5],
            'suhu_akhir' => [24.5, 24.6, 24.6],
            'densitas_uut' => [],
        ]];
        $kalk = new FlowmeterCalculator;
        $master = $kalk->hitungSesi($titik, self::konteks(null, 'LPM'))['titik'][0];
        $sama = $kalk->hitungSesi($titik, self::konteks('LPM', 'LPM'))['titik'][0];

        $this->assertSame($master['u95_sertifikat'], $sama['u95_sertifikat']);
        $this->assertSame($master['deviasi'], $sama['deviasi']);
        $this->assertFalse($sama['fluida_sama']);
        $this->assertFalse($sama['uut_satu_durasi']);
        // Angka master yang sudah diadu (AlurPenuhFlowmeterTest titik 1).
        $this->assertEqualsWithDelta(-1.278888888888872, $master['deviasi'], 1.3e-5);
    }

    public function test_satuan_standar_beda_huruf_saja_tetap_jalur_master(): void
    {
        // F-5 penjaga-angka: `Lpm` lawan `LPM` itu satuan yang sama — tidak
        // boleh memindahkan sesi lab ke jalur "fluida sama" (rasio densitas 1).
        $titik = [[
            'titik_ke' => 1,
            'uut' => [[101.255, 101.276, 101.289], [102.654, 102.625, 102.678], [101.986, 101.910, 101.945]],
            'std' => [101.998, 101.897, 101.123],
            'suhu_awal' => [24.5, 24.5, 24.5],
            'suhu_akhir' => [24.5, 24.6, 24.6],
            'densitas_uut' => [],
        ]];
        $kalk = new FlowmeterCalculator;
        $master = $kalk->hitungSesi($titik, self::konteks(null, 'LPM'))['titik'][0];
        $hurufLain = $kalk->hitungSesi($titik, self::konteks(' Lpm ', 'LPM'))['titik'][0];

        $this->assertFalse($hurufLain['fluida_sama']);
        $this->assertSame($master['deviasi'], $hurufLain['deviasi']);
        $this->assertSame($master['u95_sertifikat'], $hurufLain['u95_sertifikat']);
    }

    public function test_satuan_standar_huruf_kecil_dikenali_dan_yang_asing_ditolak(): void
    {
        $kalk = new FlowmeterCalculator;
        $besar = $kalk->hitungSesi([self::titikF1()], self::konteks('L/h'))['titik'][0];
        $kecil = $kalk->hitungSesi([self::titikF1()], self::konteks('l/h'))['titik'][0];

        $this->assertSame($besar['std_rata'], $kecil['std_rata']);
        $this->assertSame($besar['deviasi'], $kecil['deviasi']);

        $asing = $kalk->hitungSesi([self::titikF1()], self::konteks('gal/h'));
        $this->assertSame([], $asing['titik']);
        $this->assertStringContainsString('tidak dikenal', $asing['ditolak'][0]['alasan']);
    }

    public function test_satuan_standar_massa_ditolak(): void
    {
        $hasil = (new FlowmeterCalculator)->hitungSesi([self::titikF1()], self::konteks('kg/min'));

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('bukan satuan VOLUME', $hasil['ditolak'][0]['alasan']);
    }

    public function test_fluida_sama_tanpa_densitas_dan_tanpa_suhu_ditolak(): void
    {
        $hasil = (new FlowmeterCalculator)->hitungSesi(
            [self::titikF1(['densitas_uut' => [], 'uut' => [[301.1], [301.4], [301.8]]])],
            self::konteks('L/h', 'LPM'),
        );

        $this->assertSame([], $hasil['titik']);
        $this->assertStringContainsString('Densitas fluida belum diisi', $hasil['ditolak'][0]['alasan']);
    }

    public function test_blok_sesi_memungut_satuan_standar(): void
    {
        $blok = FlowmeterMentah::blokSesi(['flowmeter' => ['mode' => 'flowrate', 'satuan' => 'kg/h', 'satuan_standar' => ' L/h ']]);
        $this->assertSame('L/h', $blok['satuan_standar']);

        $tanpa = FlowmeterMentah::blokSesi(['flowmeter' => ['mode' => 'flowrate', 'satuan' => 'kg/h']]);
        $this->assertNull($tanpa['satuan_standar']);
    }

    private static function sesi(array $blok): CalibrationSession
    {
        return new CalibrationSession(['spesifikasi_alat' => ['flowmeter' => $blok + ['mode' => 'flowrate']]]);
    }

    public function test_desimal_sertifikat_ikut_resolusi_alat_kg_per_jam(): void
    {
        $profil = app(FlowmeterFlowrateProfile::class);
        $sesi = self::sesi(['satuan' => 'kg/h', 'resolusi' => 0.1]);

        // Satuan cetak (null) dan label kg/h: 1 desimal, U95 ikut baris.
        $this->assertSame(1, $profil->desimalSertifikatTitikSesi($sesi, 298.5));
        $this->assertSame(1, $profil->desimalSertifikatTitikSesi($sesi, 298.5, 'kg/h'));
        $this->assertSame(1, $profil->desimalU95TitikSesi($sesi, 298.5, 'kg/h'));
        // Ditulis dalam Lpm (layar hitungan): resolusi kg/h tidak berlaku -> bawaan lama.
        $this->assertNull($profil->desimalSertifikatTitikSesi($sesi, 298.5, 'Lpm'));
    }

    public function test_sesi_lpm_lama_tidak_bergeser(): void
    {
        $profil = app(FlowmeterFlowrateProfile::class);

        $this->assertSame(3, $profil->desimalSertifikatTitikSesi(self::sesi(['satuan' => 'LPM', 'resolusi' => 0.001]), 100.0, 'Lpm'));
        $this->assertSame(3, $profil->desimalU95TitikSesi(self::sesi(['satuan' => 'LPM', 'resolusi' => 0.001]), 100.0));

        // Tanpa resolusi: hook pulang null, jatuh ke desimalSertifikat()/desimalU95() = 3.
        $tanpa = self::sesi(['satuan' => 'LPM']);
        $this->assertNull($profil->desimalSertifikatTitikSesi($tanpa, 100.0));
        $this->assertNull($profil->desimalU95TitikSesi($tanpa, 100.0));
        $this->assertSame(3, $profil->desimalSertifikat());
        $this->assertSame(3, $profil->desimalU95());
    }
}
