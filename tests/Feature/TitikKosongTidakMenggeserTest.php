<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\CalibrationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Baris yang di-hide / dikosongin di lembar kerja nggak boleh ikut olah data,
 * nggak boleh masuk sertifikat, dan yang paling penting: nggak boleh nyeret
 * titik lain ikut rusak.
 *
 * Dua kerusakan yang ditutup di sini, dua-duanya ketahuan dari sesi asli
 * Conductivity Meter yang nyampe admin tanpa satu pun hasil hitung:
 *
 *  1. Baris kosong tetap makan satu slot `$index`, jadi `titik_ke` semua titik
 *     sesudahnya kegeser satu. Titik 25 µS/cm kesimpen sebagai `titik_ke` 2.
 *     `PerhitunganBuilder` nyari standar pakai `keyBy('titik_ke')` dan
 *     sertifikatnya nyetak baris per `titik_ke`, jadi geserannya mendarat di
 *     dokumen.
 *
 *  2. `alasanBelumBisaDihitung()` nolak tiap titik yang alatnya `toleransi`
 *     NULL — padahal buat Conductivity Meter NULL itu jawaban yang benar
 *     (`ConductivityProfile::punyaToleransi()` false). Akibatnya sesinya keluar
 *     tanpa hasil hitung sama sekali dan di admin muncul sebagai `titik_kosong`
 *     yang MEMBLOKIR penerbitan.
 */
class TitikKosongTidakMenggeserTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    /** @var list<Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();

        $this->teknisi = User::factory()->create();

        $kategori = EquipmentCategory::factory()->create([
            'kode' => 'konduktivitas',
            'nama' => 'Konduktivitas',
        ]);

        // `toleransi` NULL disengaja: Conductivity Meter nggak divonis PASS/FAIL.
        $this->alat = Equipment::factory()->create([
            'nama_alat' => 'Conductivitymeter',
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => $kategori->id,
            'satuan' => 'µS/cm',
            'resolusi' => 0.01,
            'toleransi' => null,
        ]);

        $this->standar = [
            Standard::factory()->create(),
            Standard::factory()->create(),
            Standard::factory()->create(),
        ];
    }

    /**
     * Lembar kerja 4 baris, baris PERTAMA di-hide (kekirim tapi kosong total).
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_ruang' => 23.5,
            'kelembaban' => 55.0,
            'measurements' => [
                // Baris yang di-hide teknisi — alat pelanggan nggak punya titik ini.
                [
                    'titik_ukur' => 12.0,
                    'satuan' => 'µS/cm',
                    'pembacaan' => [null, null, null],
                ],
                [
                    'titik_ukur' => 25.0,
                    'satuan' => 'µS/cm',
                    'standard_id' => $this->standar[0]->id,
                    'pembacaan' => [25.02, 25.01, 25.03],
                ],
                [
                    'titik_ukur' => 1412.0,
                    'satuan' => 'µS/cm',
                    'standard_id' => $this->standar[1]->id,
                    'pembacaan' => [1412.4, 1412.1, 1412.3],
                ],
                [
                    'titik_ukur' => 12880.0,
                    'satuan' => 'µS/cm',
                    'standard_id' => $this->standar[2]->id,
                    'pembacaan' => [12881.0, 12879.0, 12880.5],
                ],
            ],
        ];
    }

    private function kirim(): CalibrationSession
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_baris_kosong_nggak_nyimpen_pembacaan_apa_pun(): void
    {
        $sesi = $this->kirim();

        $this->assertNotContains(
            12.0,
            $sesi->rawMeasurements->map(fn ($r): float => (float) $r->titik_ukur)->all(),
            'Titik yang di-hide nggak boleh kesimpen sebagai pembacaan mentah.',
        );
    }

    public function test_titik_sesudah_baris_kosong_nggak_kegeser_nomornya(): void
    {
        $sesi = $this->kirim();

        $nomorPerTitikUkur = $sesi->rawMeasurements
            ->groupBy(fn ($r): string => (string) (float) $r->titik_ukur)
            ->map(fn ($rows): int => (int) $rows->first()->titik_ke);

        $this->assertSame(1, $nomorPerTitikUkur['25']);
        $this->assertSame(2, $nomorPerTitikUkur['1412']);
        $this->assertSame(3, $nomorPerTitikUkur['12880']);
    }

    public function test_alat_tanpa_toleransi_tetap_dapat_hasil_hitung(): void
    {
        $sesi = $this->kirim();

        $this->assertCount(
            3,
            $sesi->uncertaintyCalculations,
            'Conductivity Meter `toleransi` NULL itu benar — titiknya tetap harus kehitung.',
        );

        $this->assertSame(
            [1, 2, 3],
            $sesi->uncertaintyCalculations->sortBy('titik_ke')->pluck('titik_ke')->map(intval(...))->all(),
        );
    }

    /**
     * Centang standar per titik disimpan di barisnya sendiri.
     *
     * Tanpa `raw_measurements.standard_id`, pilihan standar cuma nyangkut di
     * `uncertainty_calculations` — yang cuma kebentuk buat titik yang berhasil
     * dihitung. Draft yang belum kehitung jadi nggak nyimpen centangnya di mana
     * pun, dan teknisi yang mbuka lagi ngirim balik tanpa nilai acuan.
     */
    public function test_centang_standar_kesimpen_per_baris_mentah(): void
    {
        $sesi = $this->kirim();

        $standarPerTitikUkur = $sesi->rawMeasurements
            ->groupBy(fn ($r): string => (string) (float) $r->titik_ukur)
            ->map(fn ($rows): ?int => $rows->first()->standard_id);

        $this->assertSame($this->standar[0]->id, $standarPerTitikUkur['25']);
        $this->assertSame($this->standar[1]->id, $standarPerTitikUkur['1412']);
        $this->assertSame($this->standar[2]->id, $standarPerTitikUkur['12880']);
    }

    /** Baris yang standarnya nggak dikirim TETAP tersimpan, cuma tanpa centang. */
    public function test_titik_tanpa_standar_kesimpen_dengan_standard_id_null(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                ...$this->payload(),
                'status' => 'draft',
                'measurements' => [
                    [
                        'titik_ukur' => 25.0,
                        'satuan' => 'µS/cm',
                        'pembacaan' => [25.02, 25.01],
                    ],
                ],
            ])
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $this->assertCount(2, $sesi->rawMeasurements);
        $this->assertNull($sesi->rawMeasurements->first()->standard_id);
    }

    /** Centangnya ikut kekirim balik ke HP — kalau nggak, pemulihannya percuma. */
    public function test_standard_id_ikut_di_respons_pembacaan_mentah(): void
    {
        $sesi = $this->kirim();

        $baris = $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data.pembacaan_mentah');

        $this->assertNotEmpty($baris);

        foreach ($baris as $b) {
            $this->assertArrayHasKey('titik_ukur', $b);
            $this->assertArrayHasKey('standard_id', $b);
        }
    }

    public function test_sesinya_nggak_keblokir_titik_kosong_di_admin(): void
    {
        $sesi = $this->kirim();

        $hasil = app(CalibrationValidator::class)->periksa($sesi);

        $kode = array_column($hasil['temuan'], 'kode');

        $this->assertNotContains('titik_kosong', $kode);
        $this->assertNotContains('toleransi_kosong', $kode);
        $this->assertNotContains('titik_tidak_terhitung', $kode);
    }
}
