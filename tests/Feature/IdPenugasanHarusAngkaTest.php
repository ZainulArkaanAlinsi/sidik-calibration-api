<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Penugasan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R2-10 (QA ronde 2): id penugasan di URL harus angka. `GET /penugasan/1abc`
 * dulu 200 (MySQL/TiDB membaca `id = '1abc'` sebagai `id = 1`); semua rute ber-id
 * lain sudah menjawab 404 sejak BUG-13 (`AppServiceProvider::boot`).
 */
class IdPenugasanHarusAngkaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pengunci yang tidak bergantung pada database: SQLite membandingkan `id = '1abc'`
     * sebagai teks (tidak cocok → 404 sendiri), jadi cacatnya hanya terlihat di
     * MySQL/TiDB. Yang dijaga di sini batasan di tingkat RUTE: tiap parameter
     * `{penugasan}` / `{penugasanItem}` wajib `[0-9]+`.
     */
    public function test_semua_rute_penugasan_membatasi_parameter_id_ke_angka(): void
    {
        $diperiksa = 0;

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            foreach (['penugasan', 'penugasanItem'] as $parameter) {
                if (! in_array($parameter, $rute->parameterNames(), true)) {
                    continue;
                }

                $this->assertSame('[0-9]+', $rute->wheres[$parameter] ?? null, "{$rute->uri()}: {{$parameter}} belum dibatasi ke angka.");
                $diperiksa++;
            }
        }

        $this->assertGreaterThanOrEqual(5, $diperiksa, 'Daftar rute penugasan menyusut.');
    }

    public function test_id_penugasan_bukan_angka_dijawab_404_dan_yang_angka_tetap_jalan(): void
    {
        $lab = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $lab->id]);

        $penugasan = Penugasan::create([
            'organization_id' => $lab->id,
            'judul' => 'Kalibrasi minggu ini',
            'tipe' => Penugasan::TIPE_PERSONAL,
            'status' => Penugasan::STATUS_AKTIF,
            'tanggal_target' => now()->addDays(7)->toDateString(),
            'dibuat_oleh' => $admin->id,
        ]);

        $this->actingAs($admin)->getJson("/api/penugasan/{$penugasan->id}")->assertOk();

        foreach (["{$penugasan->id}abc", 'abc', '1e3', '1.5'] as $salah) {
            $this->actingAs($admin)->getJson("/api/penugasan/{$salah}")->assertNotFound();
            $this->actingAs($admin)->putJson("/api/penugasan/{$salah}", ['judul' => 'x'])->assertNotFound();
            $this->actingAs($admin)->postJson("/api/penugasan/{$salah}/batalkan", ['alasan' => 'uji alasan'])->assertNotFound();
            $this->actingAs($admin)->patchJson("/api/penugasan/item/{$salah}", ['jumlah_tuntas' => 1])->assertNotFound();
        }
    }
}
