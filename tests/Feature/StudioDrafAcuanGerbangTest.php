<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gerbang rute simpan/muat ulang DRAF data acuan Studio (rencana A1, bagian B1):
 * `POST /api/studio/workbook/{workbook}/draf`, `GET|PUT .../draf/{versi}`,
 * `GET .../draf-dasar` (B4).
 *
 * Yang dikunci: gerbang `studio` menang atas sakelar `STUDIO_DRAF_ACUAN_AKTIF`
 * (non-Studio 401/403 walau sakelar mati atau hidup, 503 hanya untuk akun
 * Studio); sakelar bawaan MATI; jatah `throttle:studio-draf` 30/menit; izin
 * `workbook.draf.sunting` tercermin di `/studio/me`. Urutan efektif middleware
 * ditentukan prioritas Laravel (`throttle` dan `SubstituteBindings` jalan lebih
 * awal dari `studio`, berlaku juga untuk rute Studio lama) -- tidak dikunci di sini.
 *
 * Keempat rute sudah nyata (B3/B4); perilakunya diuji `StudioDrafAcuanBuatTest`,
 * `...BuatLanjutTest`, `...PerbaruiTest`, `...MuatTest`, `...DasarTest`. Di sini
 * hanya gerbangnya, dan bahwa akun Studio dengan sakelar hidup melewatinya
 * (404 untuk id yang tak ada, bukan 501 kerangka).
 */
class StudioDrafAcuanGerbangTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function ruteDraf(): array
    {
        return [
            'POST simpan' => ['POST', '/api/studio/workbook/1/draf'],
            'GET muat ulang' => ['GET', '/api/studio/workbook/1/draf/1'],
            'PUT perbarui' => ['PUT', '/api/studio/workbook/1/draf/1'],
            'GET dasar' => ['GET', '/api/studio/workbook/1/draf-dasar'],
        ];
    }

    private function pemilik(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'email' => 'pemilik.draf@sidik.test',
        ]);
    }

    public function test_sakelar_bawaan_mati(): void
    {
        $this->assertFalse(config('studio.draf_acuan_aktif'));
    }

    #[DataProvider('ruteDraf')]
    public function test_tanpa_token_401_baik_sakelar_mati_maupun_hidup(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();

        config(['studio.draf_acuan_aktif' => true]);
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('ruteDraf')]
    public function test_token_lab_biasa_403_baik_sakelar_mati_maupun_hidup(string $method, string $uri): void
    {
        $user = $this->pemilik();
        config(['studio.akun' => 'pemilik.draf@sidik.test']);
        Sanctum::actingAs($user, ['internal']);

        $this->json($method, $uri)->assertForbidden();

        config(['studio.draf_acuan_aktif' => true]);
        $this->json($method, $uri)->assertForbidden();
    }

    #[DataProvider('ruteDraf')]
    public function test_token_studio_milik_akun_lain_403_walau_sakelar_hidup(string $method, string $uri): void
    {
        $lain = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_AKTIF,
            'email' => 'bukan.studio@sidik.test',
        ]);
        config(['studio.akun' => 'pemilik.draf@sidik.test', 'studio.draf_acuan_aktif' => true]);
        Sanctum::actingAs($lain, ['studio']);

        $this->json($method, $uri)
            ->assertForbidden()
            ->assertJsonPath('kode', 'bukan_akun_studio');
    }

    #[DataProvider('ruteDraf')]
    public function test_akun_studio_dengan_sakelar_mati_503_belum_tersedia(string $method, string $uri): void
    {
        $this->sebagaiStudio($this->pemilik());

        $this->json($method, $uri)
            ->assertStatus(503)
            ->assertJsonPath('kode', 'belum_tersedia');
    }

    #[DataProvider('ruteDraf')]
    public function test_akun_studio_dengan_sakelar_hidup_lolos_gerbang_dan_workbook_tak_ada_404_bukan_501(string $method, string $uri): void
    {
        config(['studio.draf_acuan_aktif' => true]);
        $this->sebagaiStudio($this->pemilik());

        // Workbook tidak ada -> 404 (organisasi dicek SEBELUM validasi bodi); bukan 501/503/422.
        $this->json($method, str_replace('/workbook/1/', '/workbook/999999/', $uri))
            ->assertNotFound();
    }

    public function test_sakelar_mati_tidak_pernah_menulis_walau_bodi_lengkap(): void
    {
        $this->sebagaiStudio($this->pemilik());

        foreach ([['POST', '/api/studio/workbook/1/draf'], ['PUT', '/api/studio/workbook/1/draf/1']] as [$method, $uri]) {
            $this->json($method, $uri, ['alasan' => 'tidak boleh sampai ke controller'])
                ->assertStatus(503)
                ->assertJsonPath('kode', 'belum_tersedia');
        }
    }

    public function test_sakelar_hidup_dibatasi_30_per_menit_dengan_header_throttle(): void
    {
        config(['studio.draf_acuan_aktif' => true]);
        $this->sebagaiStudio($this->pemilik());

        // Panggilan ke-31 dalam semenit baru 429 dan membawa `Retry-After`.
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/studio/workbook/999999/draf')->assertNotFound();
        }
        $this->postJson('/api/studio/workbook/1/draf')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_rute_lama_tidak_terganggu_sakelar_mati(): void
    {
        $this->sebagaiStudio($this->pemilik());

        // `/workbook/impor` tetap terbaca sebagai "impor" (bukan id), tanpa berkas -> 422, bukan 503.
        $this->postJson('/api/studio/workbook/impor')->assertStatus(422);
        $this->getJson('/api/studio/workbook')->assertOk();
    }

    public function test_izin_draf_tercermin_di_studio_me_hanya_saat_sakelar_hidup(): void
    {
        $this->sebagaiStudio($this->pemilik());

        // Sakelar mati: rutenya 503, jadi `/studio/me` tidak menawarkan izinnya.
        $this->assertNotContains(
            'workbook.draf.sunting',
            $this->getJson('/api/studio/me')->assertOk()->json('data.izin.boleh'),
        );

        config(['studio.draf_acuan_aktif' => true]);

        $this->assertContains(
            'workbook.draf.sunting',
            $this->getJson('/api/studio/me')->assertOk()->json('data.izin.boleh'),
        );
    }
}
