<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simpan sesi Autoklaf lewat `POST /calibrations/autoclave` → masuk DB dengan
 * snapshot `hasil_autoclave`, dan angkanya IDENTIK dengan preview (jalur hitung
 * sama, `AutoclaveInputBuilder`). Sesi tersimpan lewat kolom `decimal`/`json`
 * DB — di situ dulu bug lolos dari suite yang cuma nguji hitungan murni.
 */
class AutoclaveStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['id' => 1]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $customer = Customer::factory()->create(['organization_id' => $org->id]);
        $category = EquipmentCategory::factory()->create(['organization_id' => $org->id]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
            'nama_alat' => 'Autoclave',
            'nama_alat_kemampuan' => 'Autoklaf',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.4,
            'suhu_akhir' => 24.5,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 56,
            'set_point' => 121.0,
            'suhu' => [
                'disk' => [
                    [121.27, 121.26, 121.26, 121.26, 121.28],
                    [121.30, 121.26, 121.26, 121.25, 121.25],
                    [121.26, 121.26, 121.28, 121.35, 121.28],
                ],
                'indikator' => [121, 121, 121, 121, 121],
                'suhu_ruang' => [25, 25, 25, 25, 25],
            ],
            'tekanan' => [
                'uut_setting' => 0.112,
                'satuan' => 'MPa',
                'display' => 'Digital',
                'pembacaan_standar' => [1.233, 1.231, 1.225, 1.224, 1.242],
            ],
        ];
    }

    public function test_simpan_bikin_sesi_dengan_snapshot_hasil(): void
    {
        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->assertSame('menunggu_approval', $data['status']);
        $this->assertNotNull($data['hasil_autoclave']);
        $this->assertEqualsWithDelta(
            0.4419439029528431,
            $data['hasil_autoclave']['suhu']['u95'],
            1e-9,
        );
        $this->assertEqualsWithDelta(0.464, $data['hasil_autoclave']['suhu']['keseragaman'], 1e-9);
        $this->assertEqualsWithDelta(0.0059, $data['hasil_autoclave']['tekanan']['u95'], 1e-9);

        // Beneran mendarat di DB (bukan cuma di respons).
        $sesi = CalibrationSession::findOrFail($data['id']);
        $this->assertTrue($sesi->adalahAutoclave());
        $this->assertNull($sesi->keputusan, 'Autoklaf tidak divonis PASS/FAIL.');
        $this->assertEqualsWithDelta(
            0.4419439029528431,
            $sesi->hasil_autoclave['suhu']['u95'],
            1e-9,
        );
    }

    public function test_muncul_di_detail_sesi(): void
    {
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $this->payload())
            ->json('data.id');

        $kestabilan = $this->actingAs($this->teknisi, 'sanctum')
            ->getJson("/api/calibrations/{$id}")
            ->assertOk()
            ->json('data.hasil_autoclave.suhu.kestabilan');

        $this->assertEqualsWithDelta(0.045, $kestabilan, 1e-9);
    }

    public function test_set_point_wajib_kalau_bukan_draft(): void
    {
        $payload = $this->payload();
        unset($payload['set_point']);

        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('set_point');
    }

    public function test_replay_client_request_id_tidak_dobel(): void
    {
        $payload = $this->payload();
        $payload['client_request_id'] = '11111111-1111-4111-8111-111111111111';

        $a = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $payload)->json('data.id');
        $b = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations/autoclave', $payload)->json('data.id');

        $this->assertSame($a, $b, 'Retry dengan client_request_id sama tidak bikin sesi dobel.');
        $this->assertSame(1, CalibrationSession::count());
    }
}
