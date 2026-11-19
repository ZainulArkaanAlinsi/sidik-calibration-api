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
 *
 * ## Fixture WAJIB eksplisit (kegagalan acak 19 Nov 2026)
 *
 * Pencarian mencocokkan SUBSTRING di `name`, `email`, dan `employee_id` semua
 * akun lab. Akun penelepon (`$admin`) dulu hanya diberi `name`; `email`-nya
 * keluaran Faker. Dengan locale Faker `id_ID` (nama Indonesia), `safeEmail()`
 * memuat "budi" (Budiman, Budiyanto, ...) sekitar 0,8% dari panggilan — dan
 * `?search=Budi` ikut menyeret akun admin: `[admin, biasa]` lawan `[biasa]`,
 * hijau di rerun. Dengan `en_US` tidak pernah (0 dari 100.000). Dua kejadian CI
 * dalam ~7 jam: PR #286 MySQL shard 1/6 dan main 301d2cc9 SQLite shard 1/4.
 * Karena itu SEMUA akun di tes pencarian ini memakai isian eksplisit, tidak ada
 * yang diserahkan ke generator acak.
 */
class CariPenggunaLiteralTest extends TestCase
{
    use RefreshDatabase;

    public function test_persen_dan_garis_bawah_dibaca_harfiah(): void
    {
        $lab = Organization::factory()->create();
        $admin = $this->adminPencari($lab);
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

    /**
     * Penjelasan kegagalan acak di atas, secara deterministik: akun lain di lab
     * yang sama yang `email`-nya memuat kata cari IKUT cocok (kecocokan
     * substring, bukan kata utuh). Email di bawah adalah keluaran nyata Faker
     * `id_ID`. Kalau tes ini suatu saat merah, aturan pencariannya yang berubah
     * (mis. jadi kata utuh) — dan fixture eksplisit di tes utama boleh dilonggarkan.
     */
    public function test_akun_lain_yang_emailnya_memuat_kata_cari_ikut_cocok(): void
    {
        $lab = Organization::factory()->create();
        $admin = $this->adminPencari($lab, ['email' => 'budiyanto.diana@example.net']);
        $biasa = User::factory()->create(['organization_id' => $lab->id, 'name' => 'Budi Santoso', 'email' => 'budi@contoh.test', 'employee_id' => 'EMP-100']);

        $ids = collect($this->actingAs($admin)->getJson('/api/users?search=Budi')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$admin->id, $biasa->id], $ids);
    }

    /** Akun penelepon dengan SEMUA isian pencarian eksplisit (tidak memuat kata cari tes ini). */
    private function adminPencari(Organization $lab, array $timpa = []): User
    {
        return User::factory()->admin()->create(array_merge([
            'organization_id' => $lab->id,
            'name' => 'Admin Pencari',
            'email' => 'admin.pencari@contoh.test',
            'employee_id' => 'ADM-001',
        ], $timpa));
    }

    public function test_pencarian_tetap_tidak_menyeret_lab_lain(): void
    {
        $lab = Organization::factory()->create();
        $labLain = Organization::factory()->create();
        $admin = $this->adminPencari($lab);
        User::factory()->create(['organization_id' => $labLain->id, 'name' => 'Orang Lab Lain 100%']);

        $this->assertSame([], $this->actingAs($admin)->getJson('/api/users?search='.urlencode('%'))->assertOk()->json('data'));
    }
}
