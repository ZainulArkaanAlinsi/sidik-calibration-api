<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Akun lab yang tidak aktif tidak boleh memakai API lewat token lamanya.
 *
 * Temuan B06 (paket 30 Sep). `PUT /api/users/{id}` memang mencabut token saat
 * status diubah ke `nonaktif`, tapi form Edit di panel `/admin` tidak — dia
 * cuma menyimpan kolom `status`. Karyawan yang keluar tetap bisa masuk dari HP
 * lamanya, dan tidak ada yang memunculkan error. Penjagaannya ditaruh di
 * `EnsureUserHasRole`, satu-satunya pintu yang dilewati semua rute internal
 * ber-token (termasuk `/broadcasting/auth`), jadi jalur penulisan status mana
 * pun yang lupa mencabut token tetap tertutup.
 */
class AkunNonaktifDitolakTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_lama_akun_nonaktif_ditolak_dan_semua_tokennya_dicabut(): void
    {
        $teknisi = $this->teknisiDenganDuaToken($token);

        // Jalur panel: kolom status diubah lewat model, tanpa mencabut token.
        $teknisi->update(['status' => User::STATUS_NONAKTIF]);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('kode', 'akun_nonaktif');

        $this->assertSame(0, $teknisi->tokens()->count());
    }

    public function test_akun_pending_juga_ditolak(): void
    {
        $teknisi = $this->teknisiDenganDuaToken($token);

        $teknisi->update(['status' => User::STATUS_PENDING]);

        $this->withToken($token)
            ->getJson('/api/calibrations')
            ->assertUnauthorized()
            ->assertJsonPath('kode', 'akun_nonaktif');
    }

    public function test_akun_aktif_tetap_lolos(): void
    {
        $this->teknisiDenganDuaToken($token);

        $this->withToken($token)->getJson('/api/me')->assertOk();
    }

    private function teknisiDenganDuaToken(?string &$token): User
    {
        $teknisi = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        // Dua token = dua perangkat. Yang dicabut harus SEMUANYA, bukan cuma
        // token yang kebetulan dipakai mengetuk.
        $teknisi->createToken('hp-lama', ['internal']);
        $token = $teknisi->createToken('hp', ['internal'])->plainTextToken;

        return $teknisi;
    }
}
