<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
    }

    public function test_daftar_standar_bentuknya_sesuai_yang_dibutuhin_dropdown_mobile(): void
    {
        Standard::factory()->create(['nama' => 'Gauge Block Set Grade 0']);

        $response = $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Gauge Block Set Grade 0')
            ->assertJsonPath('data.0.masih_berlaku', true)
            ->assertJsonStructure(['data' => ['*' => [
                'id', 'nama', 'no_sertifikat', 'tertelusur_ke', 'berlaku_sampai',
                'masih_berlaku', 'ketidakpastian', 'satuan_ketidakpastian', 'faktor_cakupan',
            ]]]);

        // Angkanya dikirim sebagai number, bukan string — kontrak minta gitu biar
        // Dart nggak perlu parsing. Float bulat (2.0) kekirim jadi `2`, itu tetap
        // number yang sah, jadi bandinginnya jangan pakai tipe yang kaku.
        $this->assertIsNumeric($response->json('data.0.ketidakpastian'));
        $this->assertEqualsWithDelta(0.0004, $response->json('data.0.ketidakpastian'), 1e-9);
        $this->assertEqualsWithDelta(2, $response->json('data.0.faktor_cakupan'), 1e-9);
    }

    public function test_standar_kadaluarsa_tetap_muncul_tapi_ditandain(): void
    {
        Standard::factory()->kadaluarsa()->create(['nama' => 'Anak Timbangan F1']);

        // Sengaja nggak disembunyiin: kalau ilang dari daftar, teknisi ngira
        // datanya kehapus — padahal cuma perlu dikalibrasi ulang.
        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.masih_berlaku', false);
    }

    public function test_bisa_disaring_cuma_yang_masih_berlaku(): void
    {
        Standard::factory()->create(['nama' => 'Gauge Block Set Grade 0']);
        Standard::factory()->kadaluarsa()->create(['nama' => 'Anak Timbangan F1']);

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards?berlaku_saja=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Gauge Block Set Grade 0');
    }

    public function test_viewer_boleh_baca_standar(): void
    {
        Standard::factory()->create();
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->getJson('/api/standards')->assertOk();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/standards')->assertUnauthorized();
    }

    public function test_standar_punya_pt_lain_nggak_kelihatan(): void
    {
        $ptLain = Organization::factory()->create();
        $standarPtLain = Standard::factory()->create(['organization_id' => $ptLain->id]);

        $this->actingAs($this->teknisi)
            ->getJson('/api/standards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->teknisi)
            ->getJson("/api/standards/{$standarPtLain->id}")
            ->assertNotFound();
    }
}
