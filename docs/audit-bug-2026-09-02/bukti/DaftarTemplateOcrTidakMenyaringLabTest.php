<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEMENTARA — pembuktian, bukan buat di-commit apa adanya.
 *
 * `GET /api/worksheet-templates` (JAMAK) memanggil `TemplateLembarKerja::daftar()`,
 * yang membangun tiap profil dengan `bentukLembarKerja(false, null)`. `null` itu
 * yang bikin `masterStandarTertaut()` menjalankan `when(false, ...)` — TANPA
 * `where organization_id`. Dua pintu lain dengan bentuk sama sudah ditutup
 * (`lembar-kerja` & `worksheet-templates/{kode}`); yang ini pintu ketiga.
 */
class DaftarTemplateOcrTidakMenyaringLabTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_template_tidak_pernah_menyentuh_standar_lab_lain(): void
    {
        $labSaya = Organization::factory()->create();
        $labLain = Organization::factory()->create();

        Standard::factory()->create(['organization_id' => $labLain->id]);

        $teknisi = User::factory()->create([
            'organization_id' => $labSaya->id,
            'role' => User::ROLE_TEKNISI,
            'status' => 'aktif',
        ]);

        $tanpaSaringan = [];
        DB::listen(function ($q) use (&$tanpaSaringan): void {
            if (str_contains($q->sql, 'from "standards"') || str_contains($q->sql, 'from `standards`')) {
                if (! str_contains($q->sql, 'organization_id')) {
                    $tanpaSaringan[] = $q->sql;
                }
            }
        });

        $this->actingAs($teknisi)->getJson('/api/worksheet-templates')->assertOk();

        $this->assertSame(
            [],
            $tanpaSaringan,
            count($tanpaSaringan).' query ke tabel `standards` jalan TANPA saringan '
            .'`organization_id` waktu GET /api/worksheet-templates dipanggil. '
            .'Contoh: '.($tanpaSaringan[0] ?? '-'),
        );
    }
}
