<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * QA ronde 2-B, saran peninjau #283 butir 2: `IdentitasKlien` tepat sesudah
 * autentikasi.
 *
 * Sesudah R2-7 `aplikasi:`/`role:` (dan penjaga Studio) berjalan sebelum route
 * model binding, tetapi `IdentitasKlien` (middleware grup, tanpa prioritas) tetap
 * di belakang mereka. Akibatnya Studio yang kedaluwarsa menerima 403 (bukan 426
 * `klien_kedaluwarsa`) di rute yang menolaknya, dan `X-Klien`/`X-Versi-Klien`
 * tidak masuk konteks log untuk 403 dari gerbang-gerbang itu.
 *
 * Aman dari oracle: `IdentitasKlien` hanya membaca header, tidak pernah ID atau
 * data, jadi jawabannya seragam untuk ID apa pun. Tamu tetap 401: autentikasi
 * tetap paling depan (konteks log klien untuk 401 memang belum ada).
 */
class IdentitasKlienSesudahAutentikasiTest extends TestCase
{
    use RefreshDatabase;

    private const STUDIO_LAMA = ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '1.0.0'];

    private Organization $lab;

    private User $viewer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['studio.versi_minimum' => '9.0.0']);

        $this->lab = Organization::factory()->create();
        $this->viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        Context::flush();
    }

    public function test_studio_kedaluwarsa_menerima_426_bukan_403_dari_gerbang_peran(): void
    {
        // Viewer di rute khusus admin: dulu 403 dari `role:`.
        $this->actingAs($this->viewer)->getJson('/api/users', self::STUDIO_LAMA)
            ->assertStatus(426)->assertJsonPath('kode', 'klien_kedaluwarsa');

        // Akun lab di rute Studio: dulu 403 `bukan_token_studio`.
        $this->actingAs($this->admin)->getJson('/api/studio/workbook', self::STUDIO_LAMA)
            ->assertStatus(426)->assertJsonPath('kode', 'klien_kedaluwarsa');
    }

    public function test_studio_kedaluwarsa_menerima_426_bukan_403_dari_gerbang_aplikasi(): void
    {
        $token = $this->admin->createToken('uji', ['pelanggan'])->plainTextToken;

        $this->withToken($token)->getJson('/api/customers', self::STUDIO_LAMA)
            ->assertStatus(426)->assertJsonPath('kode', 'klien_kedaluwarsa');
    }

    public function test_jawaban_426_seragam_untuk_id_apa_pun_tidak_menjadi_oracle(): void
    {
        $milik = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $lain = Customer::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $badan = [];

        foreach ([$milik->id, $lain->id, 99999999] as $id) {
            $r = $this->actingAs($this->viewer)->deleteJson("/api/customers/{$id}", [], self::STUDIO_LAMA)->assertStatus(426);
            $badan[] = $r->getContent();
        }

        $this->assertCount(1, array_unique($badan));
    }

    public function test_tanpa_header_studio_atau_versi_cukup_jawaban_gerbang_tidak_berubah(): void
    {
        // Tanpa header: 403 dari `role:` seperti biasa.
        $this->actingAs($this->viewer)->getJson('/api/users')->assertForbidden();

        // Versi cukup baru: lolos IdentitasKlien, lalu 403 dari `role:`.
        config(['studio.versi_minimum' => '1.0.0']);
        $this->actingAs($this->viewer)->getJson('/api/users', self::STUDIO_LAMA)->assertForbidden();

        // Klien lain (bukan Studio) tidak pernah kena 426, versinya berapa pun.
        $this->actingAs($this->viewer)->getJson('/api/users', ['X-Klien' => 'sidik-lab', 'X-Versi-Klien' => '0.0.1'])->assertForbidden();
    }

    public function test_tamu_tetap_401_walau_header_studio_kedaluwarsa(): void
    {
        $this->getJson('/api/users', self::STUDIO_LAMA)->assertUnauthorized();
        $this->getJson('/api/studio/workbook', self::STUDIO_LAMA)->assertUnauthorized();
    }

    public function test_konteks_log_klien_terisi_untuk_403_dari_gerbang_peran(): void
    {
        config(['studio.versi_minimum' => '1.0.0']);
        Context::flush();

        $this->actingAs($this->viewer)->getJson('/api/users', self::STUDIO_LAMA)->assertForbidden();

        $this->assertSame('sidik-studio', Context::get('klien'));
        $this->assertSame('1.0.0', Context::get('versi_klien'));
    }
}
