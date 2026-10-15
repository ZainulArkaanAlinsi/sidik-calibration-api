<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** K-50-09: rute permintaan/koreksi/foto pelanggan (lab & pelanggan) sudah tidak terdaftar. */
class PermintaanKoreksiFotoDicabutTest extends TestCase
{
    use RefreshDatabase;

    public function test_tidak_ada_rute_permintaan_koreksi_foto_pelanggan(): void
    {
        $pola = '#(permintaan|koreksi-pelanggan|foto-pelanggan|minta-koreksi)|pelanggan/v1/(koreksi|foto)|/alat/\{alat\}/foto#';
        $sisa = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/') && preg_match($pola, $uri))
            // `dokumen/bacaan/.../koreksi` & `worksheet-scans/.../koreksi` = koreksi OCR, bukan pelanggan.
            ->values()->all();

        $this->assertSame([], $sisa);
    }

    public function test_izin_permintaan_hilang_dari_matriks(): void
    {
        $this->assertSame([], array_values(preg_grep('/^permintaan\./', array_keys(MatriksIzin::PETA))));
    }

    public function test_rute_lab_lama_menjawab_404(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/api/permintaan-pelanggan', '/api/koreksi-pelanggan', '/api/foto-pelanggan/1'] as $uri) {
            $this->actingAs($admin)->getJson($uri)->assertNotFound();
        }
    }
}
