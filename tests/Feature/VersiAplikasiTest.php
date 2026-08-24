<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\VersiAplikasiController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /api/app/versi-terbaru` — sumber pemberitahuan "ada versi baru" di HP.
 *
 * Yang diuji bukan cuma jalur senangnya. Endpoint ini dipanggil **saat
 * aplikasi dibuka**, jadi tiap cara dia gagal harus berujung pada aplikasi
 * yang tetap bisa dipakai — bukan layar login yang menggantung atau error
 * yang bikin teknisi mengira aplikasinya rusak.
 */
class VersiAplikasiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(VersiAplikasiController::KUNCI_CACHE);
    }

    /** @param  array<int, array<string, mixed>>  $assets */
    private function rilis(string $tag = 'v1.4.0+57', ?array $assets = null): array
    {
        return [
            'tag_name' => $tag,
            'body' => 'build 57 · feat(enclosure): layar grid',
            'published_at' => '2026-08-24T04:00:00Z',
            'assets' => $assets ?? [[
                'name' => 'sidik-kalibrasi-1.4.0.apk',
                'size' => 52428800,
                'browser_download_url' => 'https://github.com/x/y/releases/download/v1.4.0+57/app.apk',
            ]],
        ];
    }

    public function test_memulangkan_versi_url_dan_ukuran_dari_rilis_github(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson([
                'tersedia' => true,
                'versi' => '1.4.0',
                'build' => 57,
                'tag' => 'v1.4.0+57',
                'ukuran' => 52428800,
                'wajib' => false,
            ])
            ->assertJsonPath(
                'url_unduh',
                'https://github.com/x/y/releases/download/v1.4.0+57/app.apk',
            );
    }

    public function test_tanpa_auth(): void
    {
        // Layar login justru yang paling butuh tahu aplikasinya ketinggalan.
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru')->assertOk();
    }

    public function test_github_mati_tetap_200_supaya_aplikasi_bisa_dibuka(): void
    {
        Http::fake(['api.github.com/*' => Http::response('', 500)]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => false])
            ->assertJsonMissingPath('url_unduh');
    }

    public function test_koneksi_gagal_tetap_200(): void
    {
        Http::fake(fn () => throw new \RuntimeException('jaringan putus'));

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => false]);
    }

    public function test_repo_belum_punya_rilis_dijawab_tidak_tersedia(): void
    {
        // Repo tanpa rilis menjawab 404, bukan daftar kosong.
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => false]);
    }

    public function test_rilis_tanpa_apk_dianggap_tidak_tersedia(): void
    {
        // Kalau ini dipulangkan `tersedia: true`, mobile menampilkan tombol
        // unduh yang nggak menuju ke mana-mana.
        Http::fake(['api.github.com/*' => Http::response($this->rilis(assets: [[
            'name' => 'catatan-rilis.txt',
            'size' => 120,
            'browser_download_url' => 'https://github.com/x/y/releases/download/v1.4.0/catatan.txt',
        ]]), 200)]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => false]);
    }

    public function test_apk_dipilih_walau_bukan_aset_pertama(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilis(assets: [
            [
                'name' => 'catatan.txt',
                'size' => 10,
                'browser_download_url' => 'https://x/catatan.txt',
            ],
            [
                'name' => 'app-release.APK',
                'size' => 999,
                'browser_download_url' => 'https://x/app.apk',
            ],
        ]), 200)]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJsonPath('url_unduh', 'https://x/app.apk')
            ->assertJsonPath('ukuran', 999);
    }

    public function test_tag_tanpa_nomor_build_tidak_bikin_gagal(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilis(tag: 'v2.0.0'), 200)]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['versi' => '2.0.0', 'build' => null]);
    }

    public function test_hasil_di_cache_supaya_jatah_github_tidak_habis(): void
    {
        // 60 permintaan per jam per IP, dan seluruh trafik API keluar dari SATU
        // IP. Tanpa cache, 60 teknisi membuka aplikasi berbarengan sudah
        // menghabiskan jatah sejam.
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru')->assertOk();
        $this->getJson('/api/app/versi-terbaru')->assertOk();
        $this->getJson('/api/app/versi-terbaru')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_kegagalan_tidak_ikut_di_cache(): void
    {
        // Kalau gagalnya ikut tersimpan, satu kali GitHub ngadat bikin 15 menit
        // berikutnya ikut buta walau GitHub sudah pulih.
        //
        // `fakeSequence`, bukan dua kali `Http::fake()`: panggilan `fake()`
        // kedua MENAMBAH stub, bukan mengganti — yang pertama tetap menang dan
        // testnya mengukur hal yang salah.
        Http::fakeSequence('api.github.com/*')
            ->push('', 500)
            ->push($this->rilis(), 200);

        $this->getJson('/api/app/versi-terbaru')->assertJson(['tersedia' => false]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => true, 'versi' => '1.4.0']);
    }
}
