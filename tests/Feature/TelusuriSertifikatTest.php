<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** K-50-05: `GET /api/sertifikat/telusuri?q=` (pengganti Pelacakan paket). */
class TelusuriSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
    }

    private function sertifikat(Organization $org, string $nomor, array $atribut = [], ?User $teknisi = null): Certificate
    {
        $pt = Customer::factory()->create(['organization_id' => $org->id, 'nama' => 'PT '.$nomor]);
        $alat = Equipment::factory()->create(['organization_id' => $org->id, 'customer_id' => $pt->id]);

        return Certificate::factory()->create([
            'organization_id' => $org->id,
            'nomor' => $nomor,
            'status' => Certificate::STATUS_TERBIT,
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $org->id,
                'equipment_id' => $alat->id,
                'teknisi_id' => $teknisi?->id ?? $this->admin->id,
            ])->id,
            ...$atribut,
        ]);
    }

    public function test_mencari_nomor_termasuk_revisi_dan_memuat_kolom_yang_diminta(): void
    {
        $asli = $this->sertifikat($this->org, 'KAL/2026/0100');
        $asli->update(['status' => Certificate::STATUS_DITARIK]);
        $this->sertifikat($this->org, 'KAL/2026/0100-R1', ['revision_of' => $asli->id, 'revisi_ke' => 1]);
        $this->sertifikat($this->org, 'KAL/2026/0200');

        $res = $this->actingAs($this->admin)->getJson('/api/sertifikat/telusuri?q=0100')->assertOk();

        $res->assertJsonPath('meta.total', 2);
        $nomor = collect($res->json('data'))->pluck('nomor')->all();
        $this->assertSame(['KAL/2026/0100', 'KAL/2026/0100-R1'], $nomor);

        $r1 = collect($res->json('data'))->firstWhere('nomor', 'KAL/2026/0100-R1');
        $this->assertSame(1, $r1['revisi_ke']);
        $this->assertSame('PT KAL/2026/0100-R1', $r1['perusahaan']);
        $this->assertNotEmpty($r1['pdf_url']);
        $this->assertSame('ditarik', collect($res->json('data'))->firstWhere('nomor', 'KAL/2026/0100')['status_dokumen']);
        foreach (['alat', 'serial_number', 'status', 'tahap_paket', 'berlaku_sampai'] as $kunci) {
            $this->assertArrayHasKey($kunci, $r1);
        }
    }

    public function test_tidak_menembus_organisasi_lain_dan_teknisi_cuma_miliknya(): void
    {
        $orgLain = Organization::factory()->create();
        $this->sertifikat($orgLain, 'KAL/LAIN/0001');
        $teknisi = User::factory()->create(['organization_id' => $this->org->id, 'role' => User::ROLE_TEKNISI]);
        $this->sertifikat($this->org, 'KAL/MILIK/0001', [], $teknisi);
        $this->sertifikat($this->org, 'KAL/MILIK/0002');

        $this->actingAs($this->admin)->getJson('/api/sertifikat/telusuri?q=KAL/LAIN')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->actingAs($teknisi)->getJson('/api/sertifikat/telusuri?q=KAL/MILIK')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nomor', 'KAL/MILIK/0001');
    }

    public function test_q_wajib_dan_wildcard_dianggap_huruf_biasa(): void
    {
        $this->sertifikat($this->org, 'KAL/0001');

        $this->actingAs($this->admin)->getJson('/api/sertifikat/telusuri')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/sertifikat/telusuri?q=%25%25')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/sertifikat/telusuri?q=KAL')->assertUnauthorized();
    }
}
