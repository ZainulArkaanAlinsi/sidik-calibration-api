<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2-3 (QA ronde 2): `GET /users?search=` membaca `%` dan `_` secara HARFIAH —
 * kelas BUG-14 yang sudah ditutup untuk `/customers` dan `/equipments`. Dulu
 * `?search=%` mengembalikan semua pengguna karena wildcard LIKE tidak di-escape.
 */
class CariPenggunaLiteralTest extends TestCase
{
    use RefreshDatabase;

    public function test_persen_dan_garis_bawah_dibaca_harfiah(): void
    {
        $lab = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $lab->id, 'name' => 'Admin Pencari']);
        $biasa = User::factory()->create(['organization_id' => $lab->id, 'name' => 'Budi Santoso', 'email' => 'budi@contoh.test', 'employee_id' => 'EMP-100']);
        $persen = User::factory()->create(['organization_id' => $lab->id, 'name' => 'Diskon 50% Teknisi', 'email' => 'diskon@contoh.test', 'employee_id' => 'EMP-101']);
        $garis = User::factory()->create(['organization_id' => $lab->id, 'name' => 'Nama_Dengan_Garis', 'email' => 'garis@contoh.test', 'employee_id' => 'EMP-102']);

        $ids = fn (string $q): array => collect($this->actingAs($admin)->getJson('/api/users?search='.urlencode($q))->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();

        // `%` dulu = cocok dengan semua; sekarang hanya yang memang memuat tanda persen.
        $this->assertSame([$persen->id], $ids('%'));
        // `_` dulu = cocok dengan satu karakter apa pun (semua nama); sekarang hanya yang memuat garis bawah.
        $this->assertSame([$garis->id], $ids('_'));
        $this->assertSame([$garis->id], $ids('a_D'));

        // Pencarian biasa tidak berubah: nama, email, dan ID pegawai.
        $this->assertSame([$biasa->id], $ids('Budi'));
        $this->assertSame([$biasa->id], $ids('budi@contoh'));
        $this->assertSame([$biasa->id], $ids('EMP-100'));
    }

    public function test_pencarian_tetap_tidak_menyeret_lab_lain(): void
    {
        $lab = Organization::factory()->create();
        $labLain = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $lab->id]);
        User::factory()->create(['organization_id' => $labLain->id, 'name' => 'Orang Lab Lain 100%']);

        $this->assertSame([], $this->actingAs($admin)->getJson('/api/users?search='.urlencode('%'))->assertOk()->json('data'));
    }
}
