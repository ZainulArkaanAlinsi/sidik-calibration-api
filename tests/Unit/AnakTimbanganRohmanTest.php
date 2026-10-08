<?php

namespace Tests\Unit;

use App\Services\Calibration\AnakTimbanganCalculator;
use App\Services\Calibration\TabelStandarAnakTimbangan;
use App\Support\AnakTimbanganMentah;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rekonsiliasi ke tiga workbook lab Pak Rohman (8 Okt 2026): set F2 1 g–500 g
 * yang ditimbang di TIGA neraca, satu workbook per neraca (hal 1 Semi Micro,
 * hal 2 Analytical, hal 3 Fujitsu).
 *
 * Keputusan pemilik: sertifikat wajib SAMA PERSIS dengan workbook lab. Yang
 * diadu di sini massa konvensional (`SERTIFIKAT!I17..`) dan U95
 * (`SERTIFIKAT!L17..`, miligram) per keping, dengan masukan persis workbook
 * (`INPUT DATA`): kelas UUT F2, kelas standar F1, suhu 20,4/20,6 °C, RH 58/60 %,
 * tekanan 933,2/933,1 hPa, dan bacaan keping 100 g = 100,0002.
 *
 * Nomor kotak workbook dihitung PER NERACA — itulah yang membuat keping 100 g
 * (kotak 1 workbook Analytical) memakai massanya sendiri, sedangkan keping
 * 200 g & 200* (kotak 2–3) koreksi apungnya 0. Lihat
 * `AnakTimbanganCalculator::CARA_KOREKSI_APUNG`.
 */
class AnakTimbanganRohmanTest extends TestCase
{
    /** nominal, bintang, S1, T1, T2, S2, mT workbook (g), U95 workbook (mg), neraca */
    private const KEPING = [
        [1, false, 1, 0.99993, 0.99993, 1, 0.9999518441063938, 0.01003674670178188, 'Semi Micro Balance'],
        [2, false, 2, 1.99998, 1.99998, 2, 1.9999992, 0.010237333246798718, 'Semi Micro Balance'],
        [2, true, 2, 2.00005, 2.00005, 2, 2.0000692, 0.010237333246798718, 'Semi Micro Balance'],
        [5, false, 5, 5.00007, 5.00007, 5, 5.0000984, 0.011002085541745543, 'Semi Micro Balance'],
        [10, false, 10, 10.00001, 10.00001, 10, 10.000071259822677, 0.012184067769578533, 'Semi Micro Balance'],
        [20, false, 20, 19.99996, 19.99996, 20, 20.00005977772891, 0.013419035856028804, 'Semi Micro Balance'],
        [20, true, 20, 19.99998, 19.99998, 20, 20.00007977772891, 0.013419035856028804, 'Semi Micro Balance'],
        [50, false, 49.99992, 50.00012, 50.00012, 49.99992, 50.000413251580504, 0.018032955439294843, 'Semi Micro Balance'],
        [100, false, 100, 100.0002, 100.0002, 100, 100.00041434196183, 0.1263179552470878, 'Analytical Balance'],
        [200, false, 200, 200.0008, 200.0008, 200, 200.000949, 0.12909727223070228, 'Analytical Balance'],
        [200, true, 200, 200.0008, 200.0008, 200, 200.000949, 0.12909727223070228, 'Analytical Balance'],
        [500, false, 500, 500, 500, 500, 500.00086170966136, 1.065278141338942, 'Electronic Balance Fujitsu'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        TabelStandarAnakTimbangan::lupakanCache();
    }

    /** @return array<string, mixed> */
    private function hitung(): array
    {
        $konteks = AnakTimbanganMentah::blokSesi([AnakTimbanganMentah::KUNCI_SESI => [
            'kelas_uut' => 'F2',
            'kelas_standar' => 'F1',
            'timbangan_daftar' => ['Semi Micro Balance', 'Analytical Balance', 'Electronic Balance Fujitsu'],
            'suhu_awal' => 20.4, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 58, 'kelembaban_akhir' => 60,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            'bintang' => [3 => true, 7 => true, 11 => true],
        ]]);

        $titik = [];
        foreach (self::KEPING as $i => $k) {
            $titik[] = [
                'titik_ke' => $i + 1,
                'nominal_g' => (float) $k[0],
                'at_s1' => $k[2], 'at_t1' => $k[3], 'at_t2' => $k[4], 'at_s2' => $k[5],
            ];
        }

        return (new AnakTimbanganCalculator)->hitungSesi($titik, $konteks);
    }

    #[Test]
    public function massa_konvensional_dan_u95_sama_persis_dengan_workbook_lab(): void
    {
        $hasil = $this->hitung();

        $this->assertSame([], $hasil['ditolak']);
        $this->assertCount(12, $hasil['titik']);

        foreach ($hasil['titik'] as $t) {
            $k = self::KEPING[$t['titik_ke'] - 1];
            $label = sprintf('keping %d (%s%s)', $t['titik_ke'], $k[0], $k[1] ? '*' : '');

            $this->assertSame($k[8], $t['timbangan'], "{$label} neraca");
            $this->assertEqualsWithDelta($k[6], $t['mt_g'], 1e-9, "{$label} massa konvensional");
            $this->assertEqualsWithDelta($k[7], $t['u95_g'] * 1000, 1e-9, "{$label} U95 (mg)");
        }
    }

    /** Kotak workbook dihitung per neraca: 1..8 Semi Micro, 1..3 Analytical, 1 Fujitsu. */
    #[Test]
    public function nomor_kotak_workbook_dihitung_per_neraca(): void
    {
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 1, 2, 3, 1],
            array_column($this->hitung()['titik'], 'kotak_master'),
        );
    }
}
