<?php

namespace Tests\Unit;

use App\Models\CalibrationSession;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CertificateSnapshotBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Parameter `sertifikat.tambah_hari.<profil>` (butir TM ph_meter#28,
 * conductivity_meter#36, spectrophotometer#4, turbidimeter#4).
 *
 * Yang dijaga: pilihan non-bawaan hanya mengganti N di `tata_letak.tanggal_master`
 * untuk sesi yang `tanggal_kalibrasi`-nya sudah masuk masa berlaku penetapan, dan
 * Issuance Date yang dihasilkan sama dengan sel workbook yang dikutip.
 *
 * Sel acuan (cache workbook, dibuka dengan sandi pemilik):
 *   pH revisi 17  SERTIFIKAT!T12 = 2024-05-26, B41 =T12+4 = 2024-05-30
 *   pH revisi 13  SERTIFIKAT!T12 = 2025-05-30, B41 =T12+3 = 2025-06-02
 *   Conductivity  'SERTIFIKAT STYLE 1'!S12 +4 -> 2024-05-17; STYLE 2 B55 =S12+2 -> 2024-05-15
 *   Spectro       SERTIFIKAT!R9 = 2023-07-21, B70 =R9+1 = 2023-07-22; INPUT DATA!D80 =O16+4 = 2023-07-25
 *   Turbidimeter  INPUT DATA!D55 =O16+4 = 2024-06-26 (O16 = 2024-06-22), SERTIFIKAT!B40 =T9+1 = 2024-06-23
 */
class ParameterMetodeTanggalTerbitTest extends TestCase
{
    use PenetapanParameterUji;

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

    private function tambahHari(string $kodeProfil, string $tanggalKalibrasi): ?int
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($kodeProfil);
        $sesi = new CalibrationSession(['tanggal_kalibrasi' => $tanggalKalibrasi]);

        return $profil->tataLetakSertifikat($sesi)['tanggal_master']['tambah_hari'];
    }

    /**
     * Issuance Date seperti `CertificateSnapshotBuilder::footer()`: tanggal
     * kalibrasi + N, dibatasi hari penerbitan (di sini jauh di masa depan).
     */
    private function terbit(string $kalibrasi, int $n): string
    {
        $metode = new ReflectionMethod(CertificateSnapshotBuilder::class, 'tanggalTerbitMaster');

        return (string) $metode->invoke(
            app(CertificateSnapshotBuilder::class),
            CarbonImmutable::parse($kalibrasi),
            $n,
            CarbonImmutable::parse('2099-01-01'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?int, 4: int|null, 5: string, 6: string}>
     */
    public static function kasus(): array
    {
        // kunci profil, pilihan non-bawaan, tanggal kalibrasi sesi baru, N bawaan tabel lama, N pilihan, sel, tanggal terbit sel
        return [
            'ph revisi 17 +4' => ['ph_meter', 'tambah_4', '2024-05-26', null, 4, 'SERTIFIKAT!B41 =T12+4', '2024-05-30'],
            'ph revisi 13 +3' => ['ph_meter', 'tambah_3', '2025-05-30', null, 3, 'SERTIFIKAT!B41 =T12+3', '2025-06-02'],
            'conductivity style 1 +4' => ['conductivity_meter', 'tambah_4', '2024-05-13', null, 4, "'SERTIFIKAT STYLE 1'!B55 =S12+4", '2024-05-17'],
            'conductivity style 2 +2' => ['conductivity_meter', 'tambah_2', '2024-05-13', null, 2, "'SERTIFIKAT STYLE 2'!B55 =S12+2", '2024-05-15'],
            'spectro input data +4' => ['spectrophotometer', 'tambah_4', '2023-07-21', 1, 4, 'INPUT DATA!D80 =O16+4', '2023-07-25'],
            'spectro tanggal terbit sistem' => ['spectrophotometer', 'terbit_sistem', '2023-07-21', 1, null, 'tanpa sel (pilihan C)', ''],
            'turbidimeter input data +4' => ['turbidimeter', 'tambah_4', '2024-06-22', null, 4, 'INPUT DATA!D55 =O16+4', '2024-06-26'],
            'turbidimeter sertifikat +1' => ['turbidimeter', 'tambah_1', '2024-06-22', null, 1, 'SERTIFIKAT!B40 =T9+1', '2024-06-23'],
        ];
    }

    #[DataProvider('kasus')]
    public function test_pilihan_non_bawaan_berlaku_mulai_tanggalnya_dan_cocok_sel_workbook(
        string $profil,
        string $pilihan,
        string $kalibrasi,
        ?int $bawaan,
        ?int $n,
        string $sel,
        string $terbitSel,
    ): void {
        // Penetapan berlaku mulai 2026-12-01; sesi bertanggal kalibrasi sebelum itu (tanggal sel workbook di atas
        // sengaja tahun lama) harus tetap bawaan, sesi baru memakai pilihan.
        $this->pakaiPenetapan("sertifikat.tambah_hari.{$profil}", $pilihan, '2026-12-01');

        $this->assertSame($bawaan, $this->tambahHari($profil, $kalibrasi), "{$profil}: sesi lama ({$kalibrasi}) tidak boleh bergeser");
        $this->assertSame($n, $this->tambahHari($profil, '2026-12-01'), "{$profil}: sesi baru memakai {$pilihan}");
        $this->assertSame($n, $this->tambahHari($profil, '2027-03-15'));

        if ($n !== null) {
            // Issuance Date untuk tanggal kalibrasi sel workbook = sel yang dikutip ({$sel}).
            $this->assertSame($terbitSel, $this->terbit($kalibrasi, $n), $sel);
        }
    }

    public function test_tanpa_penetapan_tabel_format_master_dipakai_persis(): void
    {
        $this->assertNull($this->tambahHari('ph_meter', '2026-12-05'));
        $this->assertNull($this->tambahHari('conductivity_meter', '2026-12-05'));
        $this->assertSame(1, $this->tambahHari('spectrophotometer', '2026-12-05'));
        $this->assertNull($this->tambahHari('turbidimeter', '2026-12-05'));
        // Profil tanpa parameter tidak tersentuh.
        $this->assertSame(4, $this->tambahHari('refractometer', '2026-12-05'));
    }

    public function test_penerbitan_dibatasi_hari_terbit_sebenarnya(): void
    {
        $metode = new ReflectionMethod(CertificateSnapshotBuilder::class, 'tanggalTerbitMaster');

        // Sertifikat tidak pernah mengaku terbit sesudah hari ia diterbitkan: kalibrasi 10 Des + 4, terbit 12 Des.
        $this->assertSame('2026-12-12', (string) $metode->invoke(
            app(CertificateSnapshotBuilder::class),
            CarbonImmutable::parse('2026-12-10'),
            4,
            CarbonImmutable::parse('2026-12-12'),
        ));
    }
}
