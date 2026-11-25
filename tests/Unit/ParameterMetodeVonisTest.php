<?php

namespace Tests\Unit;

use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\Calibration\Profiles\PhMeterProfile;
use App\Services\Calibration\Profiles\ProfilGenerik;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\Profiles\TurbidimeterProfile;
use App\Services\GumCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter `<alat>.vonis_pass_fail` (lembar centang TM: chlorine_meter#31, ph_meter#26,
 * refractometer#31, turbidimeter#27).
 *
 * Bawaan `dengan_toleransi` = perilaku kode sebelum saklar ada: titik divonis PASS/FAIL
 * terhadap `equipments.toleransi`. Pilihan `tanpa_vonis` meniru workbook master (tanpa sel
 * toleransi maupun PASS/FAIL): hasil titik hanya Correction dan U95, `toleransi` dan
 * `keputusan` kosong, dan gerbang "hasil tidak boleh terbit sebelum toleransi diisi" lepas.
 *
 * Yang dikunci di sini, per alat:
 *  1. bawaan (tanpa lingkup tanggal, dan lingkup SEBELUM penetapan berlaku) = vonis ada;
 *  2. `tanpa_vonis` pada tanggal yang dicakup penetapan = vonis kosong, DAN setiap angka
 *     u / U / k / veff / error / koreksi persis sama dengan bawaan (`assertSame`, tidak
 *     dibulatkan): saklar ini tidak boleh menyentuh hitungan, hanya vonisnya;
 *  3. jejak audit titik menyebut pilihan yang dipakai.
 */
class ParameterMetodeVonisTest extends TestCase
{
    use PenetapanParameterUji;

    /** Kolom hasil hitung yang TIDAK BOLEH bergeser oleh saklar vonis. */
    private const KOLOM_ANGKA = [
        'titik_ukur', 'rata_rata', 'error', 'koreksi', 'standar_deviasi', 'jumlah_pengulangan',
        'type_a', 'type_b', 'ketidakpastian_gabungan', 'ketidakpastian_diperluas',
        'faktor_cakupan_k', 'derajat_kebebasan_efektif',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ParameterKeputusanMetode::lupakan();
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    /** @return array<string, array{class-string<CalibrationProfile>, string, float, list<float>}> */
    public static function alat(): array
    {
        return [
            'chlorine_meter' => [ChlorineProfile::class, 'chlorine_meter', 1.74, [1.70, 1.71, 1.72, 1.71, 1.70]],
            'ph_meter' => [PhMeterProfile::class, 'ph_meter', 7.0, [7.02, 7.03, 7.02, 7.01, 7.02]],
            'refractometer' => [RefractometerProfile::class, 'refractometer', 1.33659, [1.3366, 1.3367, 1.3366, 1.3366, 1.3367]],
            'turbidimeter' => [TurbidimeterProfile::class, 'turbidimeter', 100.0, [99.8, 99.9, 100.1, 99.9, 100.0]],
        ];
    }

    /** Alat belum tersimpan (tanpa kategori), jadi titik jatuh ke jalur generik yang tidak butuh baris CMC. */
    private function alatPelanggan(CalibrationProfile $profil): Equipment
    {
        return (new Equipment)->forceFill([
            'organization_id' => 1,
            'nama_alat' => $profil->namaAlatKemampuan(),
            'nama_alat_kemampuan' => $profil->namaAlatKemampuan(),
            'satuan' => 'u',
            'resolusi' => 0.01,
            'toleransi' => 0.5,
        ]);
    }

    /**
     * @param  list<float>  $pembacaan
     * @return array<string, mixed>
     */
    private function hitung(CalibrationProfile $profil, float $titik, array $pembacaan): array
    {
        $standar = (new Standard)->forceFill(['nama' => 'Larutan uji', 'ketidakpastian' => 0.02, 'faktor_cakupan' => 2]);

        return (new GumCalculator)->hitungTitik(1, $titik, $pembacaan, $this->alatPelanggan($profil), $standar);
    }

    /** @param  array<string, mixed>  $hasil */
    private function jejakVonis(array $hasil, string $kode): array
    {
        $baris = array_values(array_filter(
            $hasil['type_b_components'],
            static fn (array $k): bool => ($k['sumber'] ?? null) === 'parameter_metode' && ($k['parameter'] ?? null) === "{$kode}.vonis_pass_fail",
        ));

        $this->assertCount(1, $baris, "{$kode}: tepat satu baris jejak untuk parameter vonis.");

        return $baris[0];
    }

    #[DataProvider('alat')]
    public function test_bawaan_tanpa_lingkup_tetap_memberi_vonis(string $kelas, string $kode, float $titik, array $pembacaan): void
    {
        $this->pakaiPenetapan("{$kode}.vonis_pass_fail", 'tanpa_vonis');
        $profil = new $kelas;

        // Tanpa lingkup tanggal jawabannya SELALU bawaan, walau penetapan sudah ada di registri.
        $this->assertTrue($profil->punyaToleransi());
        $this->assertFalse($profil->tanpaVonisMetode());

        $hasil = $this->hitung($profil, $titik, $pembacaan);

        $this->assertSame(0.5, $hasil['toleransi']);
        $this->assertContains($hasil['keputusan'], ['PASS', 'FAIL']);

        $jejak = $this->jejakVonis($hasil, $kode);
        $this->assertSame('dengan_toleransi', $jejak['pilihan']);
        $this->assertTrue($jejak['bawaan_kode']);
        $this->assertSame(0, $jejak['penetapan']);
    }

    #[DataProvider('alat')]
    public function test_sesi_sebelum_penetapan_berlaku_tidak_bergeser(string $kelas, string $kode, float $titik, array $pembacaan): void
    {
        $profil = new $kelas;
        $sebelum = $this->hitung($profil, $titik, $pembacaan);

        $this->pakaiPenetapan("{$kode}.vonis_pass_fail", 'tanpa_vonis', '2026-12-01');

        $lama = app(ParameterKeputusanMetode::class)->untukTanggal('2026-11-30', function () use ($profil, $titik, $pembacaan): array {
            $this->assertTrue($profil->punyaToleransi());

            return $this->hitung($profil, $titik, $pembacaan);
        });

        foreach ([...self::KOLOM_ANGKA, 'toleransi', 'keputusan'] as $kolom) {
            $this->assertSame($sebelum[$kolom], $lama[$kolom], "{$kode}: kolom {$kolom} sesi lama bergeser.");
        }
    }

    #[DataProvider('alat')]
    public function test_tanpa_vonis_mengosongkan_vonis_tanpa_menyentuh_angka(string $kelas, string $kode, float $titik, array $pembacaan): void
    {
        $profil = new $kelas;
        $bawaan = $this->hitung($profil, $titik, $pembacaan);

        $this->pakaiPenetapan("{$kode}.vonis_pass_fail", 'tanpa_vonis', '2026-12-01');

        $baru = app(ParameterKeputusanMetode::class)->untukTanggal('2026-12-05', function () use ($profil, $titik, $pembacaan): array {
            $this->assertFalse($profil->punyaToleransi(), 'Gerbang toleransi harus lepas.');
            $this->assertTrue($profil->tanpaVonisMetode());

            return $this->hitung($profil, $titik, $pembacaan);
        });

        $this->assertNull($baru['toleransi']);
        $this->assertNull($baru['keputusan']);

        foreach (self::KOLOM_ANGKA as $kolom) {
            $this->assertSame($bawaan[$kolom], $baru[$kolom], "{$kode}: {$kolom} tidak boleh berubah oleh saklar vonis.");
        }

        // Komponen budget (di luar baris jejak) identik: saklar tidak menyentuh budget.
        $tanpaJejak = static fn (array $h): array => array_values(array_filter(
            $h['type_b_components'],
            static fn (array $k): bool => ($k['sumber'] ?? null) !== 'parameter_metode',
        ));
        $this->assertSame($tanpaJejak($bawaan), $tanpaJejak($baru));

        $jejak = $this->jejakVonis($baru, $kode);
        $this->assertSame('tanpa_vonis', $jejak['pilihan']);
        $this->assertFalse($jejak['bawaan_kode'], 'Pilihan di luar bawaan kode harus tercatat sebagai bukan bawaan.');
        $this->assertSame(1, $jejak['penetapan']);
    }

    #[DataProvider('alat')]
    public function test_toleransi_alat_kosong_tidak_menghalangi_pilihan_tanpa_vonis(string $kelas, string $kode, float $titik, array $pembacaan): void
    {
        $this->pakaiPenetapan("{$kode}.vonis_pass_fail", 'tanpa_vonis', '2026-12-01');
        $profil = new $kelas;
        $alat = $this->alatPelanggan($profil)->forceFill(['toleransi' => null]);
        $standar = (new Standard)->forceFill(['nama' => 'Larutan uji', 'ketidakpastian' => 0.02, 'faktor_cakupan' => 2]);

        $hasil = app(ParameterKeputusanMetode::class)->untukTanggal(
            '2026-12-05',
            fn (): array => (new GumCalculator)->hitungTitik(1, $titik, $pembacaan, $alat, $standar),
        );

        $this->assertNull($hasil['toleransi']);
        $this->assertNull($hasil['keputusan']);
        $this->assertGreaterThan(0.0, $hasil['ketidakpastian_diperluas']);
    }

    public function test_profil_tanpa_saklar_vonis_tidak_berubah(): void
    {
        // Jenis alat yang tidak dikenal registri jatuh ke profil generik: tanpa kunci
        // vonis, jadi pilihan apa pun di registri tidak menyentuhnya.
        $profil = new ProfilGenerik;

        $this->pakaiPenetapan('turbidimeter.vonis_pass_fail', 'tanpa_vonis', '2026-12-01');

        app(ParameterKeputusanMetode::class)->untukTanggal('2026-12-05', function () use ($profil): void {
            $this->assertTrue($profil->punyaToleransi());
            $this->assertFalse($profil->tanpaVonisMetode());
        });
    }
}
