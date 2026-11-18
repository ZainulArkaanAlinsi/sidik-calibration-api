<?php

namespace Tests\Unit;

use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\Profiles\MicrometerProfile;
use App\Services\GumCalculator;
use App\Support\PenyimpanganMaster;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PK-9: catatan `penyimpangan_master.micrometer.evaluasi_n_dan_v_angka_mati`.
 *
 * Type A dan v_eff Micrometer lahir dari baris Evaluasi dengan n SEBENARNYA (`s/akar n`, `v = n - 1`);
 * master memakai angka mati `SQRT(10)` dan `v = 9`. Pada n = 10 (kertas dan keempat workbook master)
 * keduanya identik, jadi catatan TIDAK boleh ada; pada n lain ia wajib ada beserta angka versi
 * masternya. Angka hasil hitung tidak disentuh: kalkulator cuma menambah satu method pembanding
 * dan `MicrometerMasterTest` tetap penjaga angkanya.
 */
class PenyimpanganMasterMicrometerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        static $data = null;

        return $data ??= json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/micrometer-master.json'),
            true,
        );
    }

    /** @return array<string, array{string}> */
    public static function workbook(): array
    {
        return ['0-25 mm' => ['025'], '25-50 mm' => ['2550'], '50-75 mm' => ['5075'], '75-100 mm' => ['75100']];
    }

    /**
     * @param  list<float>|null  $praEvaluasi  default: sepuluh pembacaan master
     * @return array<string, mixed>
     */
    private function hitung(string $kode, ?array $praEvaluasi = null): array
    {
        $wb = self::fixture()['workbook'][$kode];

        return (new MicrometerCalculator)->hitungSesi(
            array_map(static fn (array $t): array => [
                'titik_ke' => $t['titik_ke'],
                'nominal' => $t['nominal_mm'],
                'pembacaan' => $t['pembacaan_mm'],
            ], $wb['titik']),
            [
                'kapasitas_mm' => $wb['masukan']['kapasitas_mm'],
                'resolusi_mm' => $wb['masukan']['resolusi_mm'],
                'suhu_ruang_rata_c' => $wb['masukan']['suhu_balok_c'],
                'pra_evaluasi' => $praEvaluasi ?? $wb['masukan']['pra_evaluasi_mm'],
                'balok_pra_evaluasi' => $wb['masukan']['balok_pra_evaluasi_mm'],
                'tanggal_kalibrasi' => new DateTimeImmutable($wb['masukan']['saat_master_dihitung']),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function catatan(array $hasil): array
    {
        return PenyimpanganMaster::dari((new MicrometerProfile)->catatanPenyimpanganMaster($hasil));
    }

    /** @param  list<float>  $x */
    private function stdev(array $x): float
    {
        $n = count($x);
        $m = array_sum($x) / $n;

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $m) ** 2, $x)) / ($n - 1));
    }

    #[DataProvider('workbook')]
    public function test_tidak_ada_catatan_pada_sepuluh_pembacaan_evaluasi_seperti_master(string $kode): void
    {
        $hasil = $this->hitung($kode);

        $this->assertCount(10, self::fixture()['workbook'][$kode]['masukan']['pra_evaluasi_mm']);
        $this->assertSame([], $this->catatan($hasil));
    }

    #[DataProvider('workbook')]
    public function test_lima_pembacaan_evaluasi_mencatat_angka_mati_master(string $kode): void
    {
        $lima = array_slice(self::fixture()['workbook'][$kode]['masukan']['pra_evaluasi_mm'], 0, 5);
        $hasil = $this->hitung($kode, $lima);
        $catatan = $this->catatan($hasil);

        if ($this->stdev(array_map('floatval', $lima)) === 0.0) {
            // Pembacaan identik: angka mati master tidak menggeser apa pun, jadi tidak ada yang dicatat.
            $this->assertSame([], $catatan);

            return;
        }

        $this->assertCount(1, $catatan);
        $c = $catatan[0];
        $this->assertSame('penyimpangan_master.micrometer.evaluasi_n_dan_v_angka_mati', $c['sumber']);
        $a = $c['penyimpangan']['angka'];

        // n, u(A) kedua sisi dihitung ulang di sini dari pembacaan mentah (stdev independen).
        $s = $this->stdev(array_map('floatval', $lima)) * 1000;
        $this->assertSame(5, $a['n_evaluasi']);
        $this->assertEqualsWithDelta($s / sqrt(5), $a['u_a_sistem'], 1e-9);
        $this->assertEqualsWithDelta($s / sqrt(10), $a['u_a_master'], 1e-9);

        // U hitung versi master = agregasi GUM manual atas budget yang sama dengan komponen pengulangan ditukar.
        $komponen = array_map(static function (array $k) use ($s): array {
            if ($k['sumber'] === 'pengulangan') {
                $k['u'] = $s / sqrt(10);
                $k['vi'] = 9.0;
            }

            return ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']];
        }, $hasil['budget']);
        $master = (new GumCalculator)->agregasiBudget($komponen);
        $this->assertEqualsWithDelta($master['ketidakpastian_diperluas'], $a['u95_hitung_master'], 1e-9);
        $this->assertEqualsWithDelta($hasil['ketidakpastian_diperluas'], $a['u95_hitung_sistem'], 1e-12);

        // n kecil => u(A) naik dan v turun => sistem LEBIH BESAR dari master (konservatif).
        $this->assertGreaterThan($a['u95_hitung_master'], $a['u95_hitung_sistem']);
        $this->assertGreaterThanOrEqual($a['u95_cetak_master'], $a['u95_cetak_sistem']);
        $this->assertContains($c['penyimpangan']['arah'], [PenyimpanganMaster::ARAH_KONSERVATIF, PenyimpanganMaster::ARAH_NETRAL]);

        foreach (['akar 10', 'v = 9', 'PERHITUNGAN U95%', 'Sumber:', 'Master:', 'Sistem:', 'Arah:'] as $penanda) {
            $this->assertStringContainsString($penanda, $c['keterangan']);
        }
    }

    public function test_dua_pembacaan_evaluasi_tetap_tercatat(): void
    {
        // Dua pembacaan yang BEDA (kalau identik, s = 0 dan angka mati master tidak menggeser apa pun).
        $dua = array_slice(array_values(array_unique(self::fixture()['workbook']['2550']['masukan']['pra_evaluasi_mm'])), 0, 2);
        $this->assertCount(2, $dua);
        $catatan = $this->catatan($this->hitung('2550', $dua));

        $this->assertCount(1, $catatan);
        $this->assertSame(2, $catatan[0]['penyimpangan']['angka']['n_evaluasi']);
    }

    public function test_hasil_tanpa_budget_pengulangan_tidak_menimbulkan_catatan(): void
    {
        $hasil = $this->hitung('2550');
        $hasil['budget'] = array_values(array_filter(
            $hasil['budget'],
            static fn (array $k): bool => $k['sumber'] !== 'pengulangan',
        ));

        $this->assertSame([], $this->catatan($hasil));
    }
}
