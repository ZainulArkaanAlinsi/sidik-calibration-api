<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentitasKlien;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ST-T-013 (FR-STU-003): `X-Klien`/`X-Versi-Klien` masuk konteks log; Sidik
 * Studio di bawah `studio.versi_minimum` dijawab 426 `klien_kedaluwarsa`;
 * request tanpa header (aplikasi lapangan) tidak pernah tersentuh.
 */
class IdentitasKlienTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $lab = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $lab->id]);
    }

    public function test_bawaan_tidak_pernah_menolak(): void
    {
        $this->assertSame('0.0.0', config('studio.versi_minimum'));

        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/health', ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => '0.0.1'])->assertOk();
        $this->getJson('/api/health', ['X-Klien' => 'sidik-studio'])->assertOk();
    }

    public function test_studio_lama_426_hanya_untuk_x_klien_studio(): void
    {
        config(['studio.versi_minimum' => '1.2.0']);

        // Tanpa header → tetap dilayani (aplikasi lapangan tidak mengirimnya).
        $this->getJson('/api/health')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/me')->assertOk();

        // Klien lain dengan versi rendah → bukan urusan gerbang ini.
        $this->getJson('/api/health', ['X-Klien' => 'sidik-lapangan', 'X-Versi-Klien' => '0.1.0'])->assertOk();

        foreach (['1.1.9', '0.9.0', '1.1.99+300', '', 'bukan-versi'] as $versi) {
            $this->sebagaiStudio($this->admin)
                ->getJson('/api/studio/workbook', ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => $versi])
                ->assertStatus(426)
                ->assertJsonPath('kode', 'klien_kedaluwarsa')
                ->assertJsonPath('versi_minimum', '1.2.0');
        }

        // Login pun ditolak — Studio lama harus diperbarui sebelum masuk.
        $this->postJson('/api/login', ['identifier' => 'x', 'password' => 'y'], ['X-Klien' => 'Sidik-Studio', 'X-Versi-Klien' => '1.0.0'])
            ->assertStatus(426);

        foreach (['1.2.0', '1.2.1', '1.10.0', '2.0.0-beta', 'v1.2.0+45'] as $versi) {
            $this->sebagaiStudio($this->admin)
                ->getJson('/api/studio/workbook', ['X-Klien' => 'sidik-studio', 'X-Versi-Klien' => $versi])
                ->assertOk();
        }
    }

    public function test_header_masuk_konteks_log_dalam_bentuk_bersih(): void
    {
        Route::middleware('api')->get('/api/_uji/konteks-klien', fn () => response()->json([
            'klien' => Context::get('klien'),
            'versi' => Context::get('versi_klien'),
        ]));

        $this->getJson('/api/_uji/konteks-klien', [
            'X-Klien' => "sidik-studio\r\nX-Sisip: jahat",
            'X-Versi-Klien' => '1.4.2+77 <script>',
        ])->assertOk()
            ->assertJsonPath('klien', 'sidik-studioX-Sisipjahat')
            ->assertJsonPath('versi', '1.4.2+77script');

        Context::flush();

        $this->getJson('/api/_uji/konteks-klien')->assertOk()
            ->assertJsonPath('klien', null)
            ->assertJsonPath('versi', null);
    }

    public function test_banding_versi(): void
    {
        $this->assertSame(0, IdentitasKlien::bandingVersi('1.2', '1.2.0'));
        $this->assertSame(-1, IdentitasKlien::bandingVersi('1.9.9', '1.10.0'));
        $this->assertSame(1, IdentitasKlien::bandingVersi('2', '1.99.99'));
        $this->assertSame(-1, IdentitasKlien::bandingVersi('', '0.0.1'));
    }
}
