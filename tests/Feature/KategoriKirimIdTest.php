<?php

namespace Tests\Feature;

use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/categories` mengirim `id` numerik tiap kategori.
 *
 * Layar "Terima permintaan" di aplikasi lab (1 Okt 2026) mengirim
 * `equipment_category_id` untuk alat baru yang diajukan pelanggan. Daftar
 * kategorinya diambil dari endpoint ini — dan endpoint ini dulu cuma
 * mengirim `kode`. Tanpa `id`, tombol Terima mati untuk setiap permintaan yang
 * memuat alat baru, tanpa satu pun error di server.
 */
class KategoriKirimIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_kategori_memuat_id_numerik_yang_cocok(): void
    {
        Organization::factory()->create(['nama' => 'PT Sidik']);
        $admin = User::factory()->admin()->create();
        $kategori = EquipmentCategory::factory()->create([
            'kode' => 'suhu-dan-kelembapan',
            'nama' => 'Suhu dan Kelembapan',
        ]);

        $data = $this->actingAs($admin)
            ->getJson('/api/categories')
            ->assertOk()
            ->json('data');

        $baris = collect($data)->firstWhere('kode', 'suhu-dan-kelembapan');

        $this->assertNotNull($baris, 'Kategori yang dibuat tidak muncul di daftar.');
        $this->assertSame($kategori->id, $baris['id']);
    }
}
