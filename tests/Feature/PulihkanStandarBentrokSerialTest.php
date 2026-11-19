<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-1 (QA ronde 2): `POST /standards/{S}/pulihkan` tidak boleh melahirkan dua
 * standar AKTIF berserial sama.
 *
 * Aturan unik serial standar hanya antar baris yang belum terhapus
 * (`StandardRequest`: `unique ... whereNull('deleted_at')`) dan tidak ada indeks
 * unik di database, jadi serial milik standar yang dihapus bisa dipakai standar
 * baru. Memulihkan yang lama sesudahnya sebelumnya menghasilkan dua standar
 * aktif berserial sama (id 20 dan 22 di QA). Alat, ruangan, IK, dan PT
 * menolak buat ulang lewat indeks unik; standar tidak punya indeks itu, jadi
 * pemeriksaannya di sini: 422 `serial_dipakai`, standar tetap terhapus, tanpa
 * baris audit `dipulihkan`.
 */
class PulihkanStandarBentrokSerialTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
    }

    private function standar(?string $serial, ?Organization $lab = null): Standard
    {
        return Standard::factory()->create([
            'organization_id' => ($lab ?? $this->lab)->id,
            'serial_number' => $serial,
        ]);
    }

    public function test_pulihkan_ditolak_422_bila_serialnya_sudah_dipakai_standar_aktif_lain(): void
    {
        $lama = $this->standar('SN-DUP-001');
        $this->actingAs($this->admin)->deleteJson("/api/standards/{$lama->id}")->assertOk();

        // Serial yang dilepas baris terhapus boleh dipakai standar baru (perilaku lama, tetap).
        $baru = $this->actingAs($this->admin)->postJson('/api/standards', [
            'nama' => 'Standar Pengganti', 'serial_number' => 'SN-DUP-001',
        ])->assertCreated()->json('data.id');

        $respons = $this->actingAs($this->admin)->postJson("/api/standards/{$lama->id}/pulihkan");

        $respons->assertStatus(422)
            ->assertJsonPath('kode', 'serial_dipakai')
            ->assertJsonPath('id', $baru)
            ->assertJsonPath('entitas', 'standards');
        $this->assertStringContainsString('SN-DUP-001', (string) $respons->json('message'));

        $this->assertSoftDeleted('standards', ['id' => $lama->id]);
        $this->assertSame(1, Standard::query()->where('serial_number', 'SN-DUP-001')->count(), 'Dua standar aktif berserial sama.');
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_DIPULIHKAN)->where('entity_id', $lama->id)->count());
    }

    public function test_pulihkan_berhasil_setelah_standar_pengganti_dihapus(): void
    {
        $lama = $this->standar('SN-DUP-002');
        $this->actingAs($this->admin)->deleteJson("/api/standards/{$lama->id}")->assertOk();
        $baru = $this->actingAs($this->admin)->postJson('/api/standards', [
            'nama' => 'Pengganti', 'serial_number' => 'SN-DUP-002',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/standards/{$lama->id}/pulihkan")->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson("/api/standards/{$baru}")->assertOk();

        $this->actingAs($this->admin)->postJson("/api/standards/{$lama->id}/pulihkan")->assertOk();
        $this->assertNotSoftDeleted('standards', ['id' => $lama->id]);
    }

    public function test_serial_kosong_dan_serial_lab_lain_tidak_menghalangi_pulihkan(): void
    {
        $labLain = Organization::factory()->create();

        $tanpaSerial = $this->standar(null);
        $this->standar(null);
        $this->actingAs($this->admin)->deleteJson("/api/standards/{$tanpaSerial->id}")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/standards/{$tanpaSerial->id}/pulihkan")->assertOk();

        $milik = $this->standar('SN-LINTAS-1');
        $this->standar('SN-LINTAS-1', $labLain);
        $this->actingAs($this->admin)->deleteJson("/api/standards/{$milik->id}")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/standards/{$milik->id}/pulihkan")->assertOk();
    }

    public function test_standar_yang_tidak_terhapus_tetap_menjawab_tidak_terhapus(): void
    {
        $aktif = $this->standar('SN-AKTIF-1');

        $this->actingAs($this->admin)->postJson("/api/standards/{$aktif->id}/pulihkan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'tidak_terhapus');
    }
}
