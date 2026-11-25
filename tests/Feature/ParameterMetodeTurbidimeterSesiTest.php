<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\CalibrationValidator;
use Database\Seeders\TurbidimeterCapabilitySeeder;
use Database\Seeders\TurbidimeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MenyiapkanAlatAnalitik;
use Tests\Support\PenetapanParameterUji;
use Tests\TestCase;

/**
 * Saklar keputusan metode yang terlihat dari LEMBAR sampai SERTIFIKAT, diuji lewat API
 * sungguhan dengan Turbidimeter sebagai alatnya:
 *
 *  - `turbidimeter.vonis_pass_fail` (butir TM turbidimeter#27): bawaan `dengan_toleransi`
 *    menahan titik selama `equipments.toleransi` kosong; `tanpa_vonis` melepas gerbang itu
 *    TANPA menggeser satu pun angka hitung dan membiarkan vonis kosong.
 *  - `turbidimeter.tanda_minus_nol` (butir TM turbidimeter#29): bawaan `dipertahankan`
 *    mencetak `-0,00`; `tanpa_minus` mencetak `0,00` (PDF master). Nilainya dibekukan per
 *    baris snapshot, jadi sertifikat yang sudah terbit tidak bergeser.
 *
 * Penetapannya disimulasikan (`PenetapanParameterUji`), BUKAN keputusan Manajer Teknis.
 */
class ParameterMetodeTurbidimeterSesiTest extends TestCase
{
    use MenyiapkanAlatAnalitik;
    use PenetapanParameterUji;
    use RefreshDatabase;

    private const BERLAKU = '2026-11-10';

    private const SESUDAH = '2026-11-12';

    private const SEBELUM = '2026-11-09';

    private User $teknisi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        ParameterKeputusanMetode::lupakan();

        $this->siapkanAnalitik([TurbidimeterCapabilitySeeder::class, TurbidimeterSeeder::class]);

        $this->teknisi = User::factory()->create(['organization_id' => 1]);
        $this->admin = User::factory()->admin()->create(['organization_id' => 1]);
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    private function alat(?float $toleransi): Equipment
    {
        return tap($this->alatAnalitik('Turbidimeter', 'NTU', 0.01), function (Equipment $a) use ($toleransi): void {
            $a->update(['toleransi' => $toleransi]);
        });
    }

    private function standar100(): Standard
    {
        return $this->standarSeeder('Turbidity Standard 100 NTU');
    }

    /** @return list<array<string, mixed>> */
    private function pengukuran(): array
    {
        return [[
            'titik_ukur' => 100,
            'standard_id' => $this->standar100()->id,
            'pembacaan_sebelum' => [99.8, 99.9, 100.1, 99.9, 100.0],
            'pembacaan' => [99.8, 99.9, 100.1, 99.9, 100.0],
        ]];
    }

    /** @return array<string, mixed> */
    private function badan(Equipment $alat, string $tanggal): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => $this->standar100()->id,
            'tanggal_kalibrasi' => $tanggal,
            'suhu_awal' => 25.0,
            'suhu_akhir' => 25.2,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 56,
            'measurements' => $this->pengukuran(),
        ];
    }

    /** @return array<string, mixed> */
    private function preview(Equipment $alat, string $tanggal): array
    {
        return $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->badan($alat, $tanggal))
            ->assertOk()
            ->json('data');
    }

    private function simpan(Equipment $alat, string $tanggal): CalibrationSession
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->badan($alat, $tanggal))
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    // -------------------------------------------------------------- vonis PASS/FAIL

    public function test_bawaan_alat_tanpa_toleransi_menahan_titik(): void
    {
        // Penetapan sudah ada di registri, tapi baru berlaku 10 Nov: sesi 9 Nov tetap bawaan.
        $this->pakaiPenetapan('turbidimeter.vonis_pass_fail', 'tanpa_vonis', self::BERLAKU);

        $data = $this->preview($this->alat(null), self::SEBELUM);

        $this->assertSame([], $data['titik'], 'Titik tanpa dasar vonis tidak boleh terhitung pada bawaan.');
        $this->assertCount(1, $data['belum_dihitung']);
        $this->assertStringContainsString('Toleransi alat masih kosong', $data['belum_dihitung'][0]['alasan']);
    }

    public function test_tanpa_vonis_melepas_gerbang_dan_mengosongkan_vonis(): void
    {
        $this->pakaiPenetapan('turbidimeter.vonis_pass_fail', 'tanpa_vonis', self::BERLAKU);

        $data = $this->preview($this->alat(null), self::SESUDAH);

        $this->assertSame([], $data['belum_dihitung']);
        $this->assertCount(1, $data['titik']);
        $this->assertNull($data['keputusan']);
        $this->assertNull($data['titik'][0]['keputusan']);
        $this->assertNull($data['titik'][0]['toleransi']);
        $this->assertGreaterThan(0.0, (float) $data['titik'][0]['ketidakpastian_diperluas']);
    }

    public function test_tanpa_vonis_tidak_menggeser_angka_hitung_dibanding_bawaan(): void
    {
        // Pembanding: alat yang sama ber-toleransi, tanggal sebelum penetapan (= bawaan).
        $bawaan = $this->preview($this->alat(1.0), self::SEBELUM)['titik'][0];
        $this->assertContains($bawaan['keputusan'], ['PASS', 'FAIL']);

        $this->pakaiPenetapan('turbidimeter.vonis_pass_fail', 'tanpa_vonis', self::BERLAKU);
        $baru = $this->preview($this->alat(1.0), self::SESUDAH)['titik'][0];

        // Toleransi alat terisi TAPI tanpa_vonis memilih tidak memvonis.
        $this->assertNull($baru['keputusan']);
        $this->assertNull($baru['toleransi']);

        foreach (['rata_rata', 'koreksi', 'standar_deviasi', 'ketidakpastian_gabungan', 'ketidakpastian_diperluas', 'faktor_cakupan_k', 'derajat_kebebasan_efektif'] as $kolom) {
            $this->assertSame($bawaan[$kolom], $baru[$kolom], "{$kolom} bergeser oleh saklar vonis.");
        }
    }

    public function test_simpan_dan_validator_mengikuti_tanggal_kalibrasi_sesi(): void
    {
        $this->pakaiPenetapan('turbidimeter.vonis_pass_fail', 'tanpa_vonis', self::BERLAKU);
        $alat = $this->alat(null);

        $lama = $this->simpan($alat, self::SEBELUM);
        $baru = $this->simpan($alat, self::SESUDAH);

        $this->assertSame(0, $lama->uncertaintyCalculations()->count(), 'Sesi lama: bawaan, titik tertahan.');
        $this->assertSame(1, $baru->uncertaintyCalculations()->count());
        $this->assertNull($baru->uncertaintyCalculations()->firstOrFail()->keputusan);

        $kode = static fn (CalibrationSession $s): array => array_column(
            app(CalibrationValidator::class)->periksa($s->fresh(['equipment', 'rawMeasurements', 'uncertaintyCalculations']))['temuan'],
            'kode',
        );

        $this->assertContains('toleransi_kosong', $kode($lama));
        $this->assertNotContains('toleransi_kosong', $kode($baru), 'tanpa_vonis melepas gerbang toleransi di validator.');
    }

    // ------------------------------------------------------------ tanda minus nol

    public function test_tanda_minus_nol_bawaan_dipertahankan_dan_dibekukan_di_snapshot(): void
    {
        $this->pakaiPenetapan('turbidimeter.tanda_minus_nol', 'tanpa_minus', self::BERLAKU);
        $alat = $this->alat(1.0);

        $sebelum = $this->terbitkan($this->simpan($alat, self::SEBELUM));
        $sesudah = $this->terbitkan($this->simpan($alat, self::SESUDAH));

        $this->assertTrue((bool) $sebelum['hasil'][0]['tanda_nol'], 'Sebelum penetapan berlaku: bawaan, tanda minus nol dipertahankan.');
        $this->assertFalse((bool) $sesudah['hasil'][0]['tanda_nol'], 'Sesudah penetapan: tanpa_minus (PDF master).');

        // Jawaban API untuk layar preview ikut tanggal sesi, sama dengan yang dibekukan.
        $this->assertTrue((bool) $this->preview($alat, self::SEBELUM)['titik'][0]['tanda_nol']);
        $this->assertFalse((bool) $this->preview($alat, self::SESUDAH)['titik'][0]['tanda_nol']);
    }

    public function test_tanpa_penetapan_tanda_minus_nol_tetap_bawaan(): void
    {
        $sesi = $this->simpan($this->alat(1.0), self::SESUDAH);

        $this->assertTrue((bool) $this->terbitkan($sesi)['hasil'][0]['tanda_nol']);
    }

    /** @return array<string, mixed> snapshot sertifikat */
    private function terbitkan(CalibrationSession $sesi): array
    {
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->certificate()->firstOrFail()->snapshot;
    }
}
