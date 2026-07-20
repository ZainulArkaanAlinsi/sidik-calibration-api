<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tiga hal yang diputusin 14 Jul, setelah kelihatan lubangnya waktu auth kelar:
 * logout semua perangkat, organisasi otomatis buat pendaftar, dan admin yang
 * bisa nyetel ulang password + benerin email salah ketik.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    /**
     * Token Sanctum nggak kadaluarsa sendiri. Tanpa endpoint ini, sesi di HP
     * yang ilang bakal hidup selamanya.
     */
    public function test_logout_all_mencabut_sesi_di_semua_perangkat(): void
    {
        $user = User::factory()->create();

        $hpLama = $user->createToken('hp-lama')->plainTextToken;
        $hpBaru = $user->createToken('hp-baru')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($hpBaru)->postJson('/api/logout-all')
            ->assertOk()
            ->assertJsonPath('data.sesi_dicabut', 2);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // HP yang ilang (token lama) nggak bisa dipakai lagi.
        $this->app['auth']->forgetGuards();
        $this->withToken($hpLama)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_pendaftar_baru_langsung_nempel_ke_organisasi(): void
    {
        $this->postJson('/api/register', [
            'nama' => 'Eko Prasetyo',
            'employee_id' => 'ASM-0099',
            'department' => 'Kalibrasi',
            'email' => 'eko@ptasmo.com',
            'password' => 'rahasia123',
        ])->assertCreated();

        // Kalau organization_id-nya null, layar profil di mobile nampilin PT kosong.
        $this->assertDatabaseHas('users', [
            'email' => 'eko@ptasmo.com',
            'organization_id' => Organization::first()->id,
        ]);
    }

    /**
     * Reset password jalannya lewat email, tapi login pakai ID pegawai — jadi
     * orang yang salah ketik email waktu daftar kekunci selamanya kalau nggak
     * ada yang bisa benerin.
     */
    public function test_admin_bisa_benerin_email_yang_salah_ketik(): void
    {
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create(['email' => 'eko@gmial.com']);

        $this->actingAs($admin)->putJson("/api/users/{$teknisi->id}", ['email' => 'eko@gmail.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'eko@gmail.com');

        $this->assertDatabaseHas('users', ['id' => $teknisi->id, 'email' => 'eko@gmail.com']);
    }

    public function test_admin_nggak_bisa_nyetel_email_yang_udah_dipakai_orang_lain(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'kepakai@asmo.test']);
        $teknisi = User::factory()->create();

        $this->actingAs($admin)->putJson("/api/users/{$teknisi->id}", ['email' => 'kepakai@asmo.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar.');
    }

    public function test_admin_bisa_nyetel_ulang_password_user_dan_sesi_lamanya_mati(): void
    {
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create([
            'employee_id' => 'ASM-0042',
            'password' => 'passwordlama',
        ]);
        $teknisi->createToken('hp-teknisi');

        $this->actingAs($admin)
            ->postJson("/api/users/{$teknisi->id}/reset-password", ['password' => 'passwordbaru123'])
            ->assertOk();

        $this->assertTrue(Hash::check('passwordbaru123', $teknisi->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['identifier' => 'ASM-0042', 'password' => 'passwordbaru123'])
            ->assertOk();
    }

    public function test_teknisi_nggak_bisa_nyetel_ulang_password_orang_lain(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        $korban = User::factory()->create(['password' => 'passwordlama']);

        $this->actingAs($teknisi)
            ->postJson("/api/users/{$korban->id}/reset-password", ['password' => 'dibajak123'])
            ->assertForbidden();

        $this->actingAs($teknisi)
            ->putJson("/api/users/{$korban->id}", ['role' => User::ROLE_ADMIN])
            ->assertForbidden();

        // Password & role korban nggak berubah gara-gara percobaan barusan.
        $this->assertTrue(Hash::check('passwordlama', $korban->fresh()->password));
        $this->assertSame(User::ROLE_TEKNISI, $korban->fresh()->role);
    }

    /**
     * `role:admin` di routes cuma mastiin yang manggil itu admin — dia nggak
     * peduli admin MANA. Tanpa penjaga per-organisasi, daftar user bocor lintas
     * PT dan admin PT A bisa nyetel ulang password admin PT B modal nebak ID.
     */
    public function test_daftar_user_nggak_bocor_ke_organisasi_lain(): void
    {
        $admin = User::factory()->admin()->create();
        $ptLain = Organization::factory()->create();
        User::factory()->count(3)->create(['organization_id' => $ptLain->id]);

        $this->actingAs($admin)->getJson('/api/users')
            ->assertOk()
            // Cuma dirinya sendiri. Tiga user PT sebelah nggak ikut kebawa.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $admin->id);
    }

    public function test_admin_nggak_bisa_ngutak_atik_akun_organisasi_lain(): void
    {
        $admin = User::factory()->admin()->create();
        $ptLain = Organization::factory()->create();
        $korban = User::factory()->create([
            'organization_id' => $ptLain->id,
            'password' => 'passwordlama',
            'status' => User::STATUS_PENDING,
        ]);

        // 404, bukan 403 — 403 ngonfirmasi akunnya ada, itu udah bocoran sendiri.
        $this->actingAs($admin)->putJson("/api/users/{$korban->id}", ['email' => 'dibajak@a.test'])
            ->assertNotFound();
        $this->actingAs($admin)->postJson("/api/users/{$korban->id}/reset-password", ['password' => 'dibajak123'])
            ->assertNotFound();
        $this->actingAs($admin)->postJson("/api/users/{$korban->id}/approve", ['role' => User::ROLE_ADMIN])
            ->assertNotFound();
        $this->actingAs($admin)->postJson("/api/users/{$korban->id}/reject")
            ->assertNotFound();

        $korban->refresh();
        $this->assertTrue(Hash::check('passwordlama', $korban->password));
        $this->assertSame(User::STATUS_PENDING, $korban->status);
        $this->assertSame(User::ROLE_TEKNISI, $korban->role);
    }

    public function test_admin_menonaktifkan_akun_langsung_mutusin_sesinya(): void
    {
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create();
        $teknisi->createToken('hp-teknisi');

        $this->actingAs($admin)
            ->putJson("/api/users/{$teknisi->id}", ['status' => User::STATUS_NONAKTIF])
            ->assertOk();

        // Nonaktif tapi sesinya masih hidup itu setengah matang.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
