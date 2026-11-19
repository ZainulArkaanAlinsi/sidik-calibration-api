<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji kontrak (S-7): 404 dari route model binding membawa pesan mentah Laravel
 * `No query results for model [App\Models\CalibrationSession] 25` — nama kelas
 * internal yang tampil apa adanya di antrean draf teknisi (dan keluar ke klien di
 * produksi, karena pesan HttpException tidak disembunyikan). Pesannya diganti
 * kalimat generik berbahasa Indonesia; STATUS (404) dan bentuk `{message}` tetap.
 */
class PesanTidakDitemukanGenerikTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_binding_tidak_membocorkan_nama_kelas_model_dan_tetap_404(): void
    {
        $lab = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $lab->id]);

        foreach (['/api/calibrations/99999999', '/api/customers/99999999', '/api/equipments/99999999', '/api/rooms/99999999'] as $uri) {
            $respons = $this->actingAs($admin)->getJson($uri)->assertNotFound();
            $isi = (string) $respons->getContent();

            $this->assertStringNotContainsString('No query results', $isi, $uri);
            $this->assertStringNotContainsString('App\\Models', $isi, $uri);
            $this->assertStringNotContainsString('App\Models', $isi, $uri);
            $this->assertIsString($respons->json('message'));
            $this->assertNotSame('', $respons->json('message'), "{$uri}: pesan 404 tidak boleh kosong.");
        }
    }

    public function test_404_rute_yang_tidak_ada_tidak_berubah(): void
    {
        $lab = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $lab->id]);

        $this->actingAs($admin)->getJson('/api/rute-yang-tidak-ada')->assertNotFound();
    }
}
