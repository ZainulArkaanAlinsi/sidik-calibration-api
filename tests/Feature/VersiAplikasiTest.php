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
        Cache::forget(VersiAplikasiController::KUNCI_CACHE_STUDIO);
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

    // ------------------------------------------ jatah API habis → redirect releases/latest

    /**
     * Log Render 15 Sep 2026 04:36: `GitHub menolak permintaan versi aplikasi.
     * {"status":403}` — jatah API tanpa token (per IP, dan IP keluar Render
     * gratis dipakai bersama) habis, dan selama itu tidak satu HP pun ditawari
     * pemutakhiran.
     */
    public function test_jatah_api_habis_jatuh_ke_redirect_releases_latest(): void
    {
        Http::preventStrayRequests();
        // Urutan penting: pola `github.com/*` juga cocok untuk `api.github.com`.
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403),
            'github.com/*' => Http::response('', 302, [
                'Location' => 'https://github.com/'.VersiAplikasiController::REPO.'/releases/tag/v1.0.566+566',
            ]),
        ]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson([
                'tersedia' => true,
                'versi' => '1.0.566',
                'build' => 566,
                'tag' => 'v1.0.566+566',
                'url_unduh' => 'https://github.com/'.VersiAplikasiController::REPO
                    .'/releases/download/v1.0.566+566/sidik-kalibrasi-1.0.566.apk',
                'wajib' => false,
            ]);
    }

    public function test_429_juga_jatuh_ke_redirect(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/*' => Http::response('', 429),
            'github.com/*' => Http::response('', 302, [
                'Location' => 'https://github.com/x/y/releases/tag/v2.1.0+700',
            ]),
        ]);

        $this->getJson('/api/app/versi-terbaru')->assertJson(['tersedia' => true, 'versi' => '2.1.0']);
    }

    public function test_redirect_tanpa_tag_tetap_tidak_tersedia(): void
    {
        // Repo tanpa rilis diarahkan ke `/releases`, bukan ke sebuah tag.
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/*' => Http::response('', 403),
            'github.com/*' => Http::response('', 302, [
                'Location' => 'https://github.com/x/y/releases',
            ]),
        ]);

        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => false]);
    }

    public function test_token_dipakai_kalau_disetel(): void
    {
        config(['services.github.token' => 'ghp_contoh']);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru')->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer ghp_contoh'));
    }

    public function test_tanpa_token_tidak_mengirim_authorization(): void
    {
        config(['services.github.token' => null]);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru')->assertOk();

        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    // ------------------------------------------ Sidik Studio (?aplikasi=studio)

    /** Rilis yang memuat APK lab DAN APK Studio, urutan asetnya bisa diatur. */
    private function rilisDenganStudio(bool $studioDulu = false): array
    {
        $dasar = 'https://github.com/x/y/releases/download/v1.0.600+600/';
        $aset = fn (string $nama, int $ukuran): array => [
            'name' => $nama,
            'size' => $ukuran,
            'browser_download_url' => $dasar.$nama,
        ];

        $lab = $aset('sidik-kalibrasi-1.0.600.apk', 70000000);
        $labTetap = $aset('sidik-kalibrasi.apk', 70000000);
        $studio = $aset('sidik-studio-0.1.600.apk', 55000000);
        $studioTetap = $aset('sidik-studio.apk', 55000000);

        $assets = $studioDulu
            ? [$studioTetap, $studio, $labTetap, $lab]
            : [$lab, $labTetap, $studio, $studioTetap];

        return $this->rilis('v1.0.600+600', $assets);
    }

    public function test_studio_memakai_versi_dari_nama_aset_bukan_dari_tag(): void
    {
        // Tag memakai skema app lab (1.0.600); Studio ber-versi 0.1.600.
        // Kalau yang dipulangkan 1.0.600, Studio 0.1.x selalu merasa tertinggal.
        Http::fake(['api.github.com/*' => Http::response($this->rilisDenganStudio(), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertOk()
            ->assertJson([
                'tersedia' => true,
                'versi' => '0.1.600',
                'build' => 600,
                'tag' => 'v1.0.600+600',
                'ukuran' => 55000000,
                'wajib' => false,
            ])
            ->assertJsonPath(
                'url_unduh',
                'https://github.com/x/y/releases/download/v1.0.600+600/sidik-studio-0.1.600.apk',
            );
    }

    public function test_studio_memilih_aset_studio_walau_aset_lab_lebih_dulu(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilisDenganStudio(studioDulu: false), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertJsonPath('url_unduh', 'https://github.com/x/y/releases/download/v1.0.600+600/sidik-studio-0.1.600.apk');
    }

    public function test_lab_tanpa_parameter_tidak_pernah_dapat_apk_studio_walau_asetnya_duluan(): void
    {
        // Release yang sama kini memuat APK Studio. Urutan aset di GitHub tidak
        // dijamin, dan app lab tidak boleh ditawari APK Studio.
        Http::fake(['api.github.com/*' => Http::response($this->rilisDenganStudio(studioDulu: true), 200)]);

        $url = $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => true, 'versi' => '1.0.600', 'build' => 600])
            ->json('url_unduh');

        // Aset lab pertama (urutan lama: aset .apk pertama yang bukan Studio).
        $this->assertStringContainsString('/sidik-kalibrasi', $url);
        $this->assertStringNotContainsString('sidik-studio', $url);
    }

    public function test_rilis_tanpa_aset_studio_dijawab_tidak_tersedia_bukan_error(): void
    {
        // Rilis lab terbit lebih dulu daripada job Studio.
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertOk()
            ->assertJson(['tersedia' => false]);

        // Jalur lab pada rilis yang sama tetap jalan.
        $this->getJson('/api/app/versi-terbaru')
            ->assertOk()
            ->assertJson(['tersedia' => true, 'versi' => '1.4.0']);
    }

    public function test_aset_studio_tanpa_versi_di_nama_tidak_dipakai(): void
    {
        // `sidik-studio.apk` (tautan tetap) tidak memuat versi — tanpa versi
        // yang benar perbandingan di Studio tidak bisa dipercaya.
        Http::fake(['api.github.com/*' => Http::response($this->rilis('v1.0.600+600', [[
            'name' => 'sidik-studio.apk',
            'size' => 55000000,
            'browser_download_url' => 'https://x/sidik-studio.apk',
        ]]), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertOk()
            ->assertJson(['tersedia' => false]);
    }

    public function test_jawaban_tanpa_aset_studio_ikut_di_cache(): void
    {
        // Studio belum pernah terbit = tiap pembukaan Studio menanya GitHub lagi
        // kalau jawabannya tidak di-cache, memakan jatah 60/jam yang dibagi
        // dengan app lab.
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')->assertJson(['tersedia' => false]);
        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')->assertJson(['tersedia' => false]);

        Http::assertSentCount(1);
    }

    public function test_cache_studio_dan_lab_terpisah(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilisDenganStudio(), 200)]);

        $lab = $this->getJson('/api/app/versi-terbaru')->assertJson(['versi' => '1.0.600']);
        $studio = $this->getJson('/api/app/versi-terbaru?aplikasi=studio')->assertJson(['versi' => '0.1.600']);

        $this->assertNotSame($lab->json('url_unduh'), $studio->json('url_unduh'));
        $this->assertTrue(Cache::has(VersiAplikasiController::KUNCI_CACHE));
        $this->assertTrue(Cache::has(VersiAplikasiController::KUNCI_CACHE_STUDIO));

        // Dua kunci = dua pertanyaan ke GitHub, bukan satu jawaban dipakai bersama.
        Http::assertSentCount(2);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')->assertOk();
        $this->getJson('/api/app/versi-terbaru')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_studio_github_mati_tetap_200_dan_gagal_tidak_ikut_di_cache(): void
    {
        Http::fakeSequence('api.github.com/*')
            ->push('', 500)
            ->push($this->rilisDenganStudio(), 200);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertOk()
            ->assertJson(['tersedia' => false]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertOk()
            ->assertJson(['tersedia' => true, 'versi' => '0.1.600']);
    }

    public function test_studio_jatah_api_habis_tidak_jatuh_ke_redirect_lab(): void
    {
        // Redirect menyusun URL `sidik-kalibrasi-<versi>.apk` — itu APK lab.
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'rate limit'], 403),
            'github.com/*' => Http::response('', 302, [
                'Location' => 'https://github.com/x/y/releases/tag/v1.0.600%2B600',
            ]),
        ]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=studio')
            ->assertOk()
            ->assertJson(['tersedia' => false]);
    }

    public function test_parameter_aplikasi_tidak_dikenal_tidak_mendapat_apk_lab(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilisDenganStudio(), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=lainnya')
            ->assertOk()
            ->assertJson(['tersedia' => false]);

        $this->getJson('/api/app/versi-terbaru?aplikasi[]=studio')
            ->assertOk()
            ->assertJson(['tersedia' => false]);

        Http::assertNothingSent();
    }

    public function test_parameter_aplikasi_kosong_sama_dengan_tanpa_parameter(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->rilis(), 200)]);

        $this->getJson('/api/app/versi-terbaru?aplikasi=')
            ->assertOk()
            ->assertJson(['tersedia' => true, 'versi' => '1.4.0']);
    }
}
