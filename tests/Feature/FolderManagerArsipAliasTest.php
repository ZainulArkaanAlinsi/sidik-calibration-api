<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alias `/arsip/*` yang dipanggil mobile (docs/permintaan-backend-2026-07-24.md §2)
 * harus resolve ke handler Folder Manager yang sama — bukan 404 yang bikin layar
 * arsip kosong. Shape-nya sama persis dengan `/folders`.
 */
class FolderManagerArsipAliasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        // Folder akar (per-PT) tipe manual → boleh direname/hapus.
        $this->folder = Folder::factory()->create(['nama' => 'PT Contoh', 'parent_id' => null]);
    }

    public function test_arsip_perusahaan_alias_daftar_folder_akar(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->folder->id)
            ->assertJsonPath('data.0.nama', 'PT Contoh');
    }

    public function test_arsip_folders_detail_alias(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/arsip/folders/{$this->folder->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->folder->id)
            ->assertJsonPath('data.nama', 'PT Contoh');
    }

    public function test_arsip_folders_admin_bisa_rename_dan_hapus(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/arsip/folders/{$this->folder->id}", ['nama' => 'PT Contoh 2026'])
            ->assertOk()
            ->assertJsonPath('data.nama', 'PT Contoh 2026');

        $this->actingAs($this->admin)
            ->deleteJson("/api/arsip/folders/{$this->folder->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('folders', ['id' => $this->folder->id]);
    }

    public function test_teknisi_tidak_bisa_tulis_arsip(): void
    {
        $this->actingAs($this->teknisi)
            ->putJson("/api/arsip/folders/{$this->folder->id}", ['nama' => 'Ubah'])
            ->assertForbidden();
    }
}
