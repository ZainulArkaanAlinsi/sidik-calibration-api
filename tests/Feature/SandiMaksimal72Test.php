<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R2-11 (QA ronde 2): bcrypt hanya memakai 72 byte pertama, jadi dua sandi panjang
 * yang berbeda sesudah byte ke-72 dianggap SAMA dan `POST /users` menerima 70.000
 * karakter (201). Sandi BARU dibatasi 72 byte dengan pesan jelas; sandi yang sudah
 * ada tidak disentuh (tidak ada pemeriksaan saat login).
 */
class SandiMaksimal72Test extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $superAdmin;

    private User $admin;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->superAdmin = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_AKTIF]);
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
    }

    private function akunBaru(string $sandi, string $nomor = '1')
    {
        return $this->actingAs($this->superAdmin)->postJson('/api/users', [
            'role' => User::ROLE_ADMIN,
            'nama' => 'Admin Baru '.$nomor,
            'email' => "admin-baru-{$nomor}@contoh.test",
            'employee_id' => "EMP-BARU-{$nomor}",
            'password' => $sandi,
        ]);
    }

    public function test_buat_akun_admin_menolak_sandi_lebih_dari_72_byte_dan_menerima_tepat_72(): void
    {
        $respons = $this->akunBaru(str_repeat('a', 73), '1');
        $respons->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('72', (string) $respons->json('errors.password.0'));

        $this->akunBaru(str_repeat('a', 70000), '2')->assertUnprocessable();

        $this->akunBaru(str_repeat('a', 72), '3')->assertCreated();
    }

    public function test_batasnya_dihitung_dalam_byte_bukan_karakter(): void
    {
        // 37 karakter x 2 byte = 74 byte: lolos `max:72` karakter, tetapi bcrypt memotongnya.
        $this->akunBaru(str_repeat('é', 37), '4')->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->akunBaru(str_repeat('é', 36), '5')->assertCreated();
    }

    public function test_reset_sandi_oleh_admin_menolak_lebih_dari_72_byte(): void
    {
        $this->actingAs($this->admin)->postJson("/api/users/{$this->teknisi->id}/reset-password", ['password' => str_repeat('b', 73)])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->actingAs($this->admin)->postJson("/api/users/{$this->teknisi->id}/reset-password", ['password' => str_repeat('b', 72)])
            ->assertOk();

        $this->assertTrue(Hash::check(str_repeat('b', 72), $this->teknisi->fresh()->password));
    }

    public function test_sandi_pendek_dan_normal_tetap_diterima_dan_minimal_8_tetap_berlaku(): void
    {
        $this->akunBaru('sandiAman123', '6')->assertCreated();
        $this->akunBaru('pendek', '7')->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }
}
