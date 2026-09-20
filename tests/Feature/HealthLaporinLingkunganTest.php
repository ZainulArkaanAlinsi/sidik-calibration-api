<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `deploy.lingkungan` — mana yang staging, mana yang produksi, dari LUAR.
 *
 * ## Kenapa berkas ini ada, dan kenapa SEKARANG
 *
 * Ini prasyarat M0-04 (staging terpisah), dan sengaja mendarat **sebelum**
 * service kedua dibuat: penjaganya harus ada sebelum yang dijaga.
 *
 * Begitu ada dua service Render, keduanya melayani kode yang sama, memakai
 * blueprint yang sama, dan membalas dengan bentuk JSON yang sama. Yang
 * membedakan cuma isi `.env`-nya — dan itu tidak kelihatan dari luar sama
 * sekali. Service staging yang `DB_HOST`-nya masih menunjuk Aiven produksi
 * **tidak memunculkan error apa pun**: dia menyambung, membaca, dan menulis
 * dengan senang hati. Yang menemukannya bukan alarm, tapi data yang telanjur
 * salah tempat.
 *
 * Bentuk kegagalan itu sudah ada presedennya di repo ini: `ARSIP_DRIVER` yang
 * ketimpa balik diam-diam (1 Sep 2026) ketahuan justru karena satu huruf
 * berubah di `/api/health`, bukan karena ada yang gagal.
 *
 * ## Batasnya
 *
 * Yang dilaporkan cuma NAMA lingkungannya. `APP_KEY`, `APP_DEBUG`, dan sisa
 * `config/app.php` tidak pernah ikut — endpoint ini publik tanpa auth, dan
 * `APP_DEBUG` di sini justru memberi tahu penyerang kapan halaman errornya
 * bakal memuntahkan isi konfigurasi.
 */
class HealthLaporinLingkunganTest extends TestCase
{
    use RefreshDatabase;

    public function test_produksi_dilaporin_produksi(): void
    {
        config(['app.env' => 'production']);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.lingkungan', 'production');
    }

    public function test_staging_dilaporin_staging(): void
    {
        config(['app.env' => 'staging']);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.lingkungan', 'staging');
    }

    /**
     * Nilai yang tak terduga pun dilaporkan APA ADANYA.
     *
     * Bukan kelonggaran: yang dicari justru salah ketik. `stagingg` yang
     * dilaporkan apa adanya langsung kelihatan salah; yang dipetakan ke daftar
     * nilai "sah" bakal jatuh ke `production` atau kosong, dan menyembunyikan
     * persis kesalahan yang endpoint ini ada untuk menangkapnya.
     */
    public function test_nilai_tak_terduga_tetap_dilaporin_apa_adanya(): void
    {
        config(['app.env' => 'stagingg']);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.lingkungan', 'stagingg');
    }

    /**
     * Endpoint ini PUBLIK tanpa auth — nol nilai rahasia boleh ikut.
     *
     * Batas yang sama dengan `HealthLaporinPelangganTest`: yang dilaporkan
     * STATUS, bukan isi konfigurasi.
     */
    public function test_nggak_ada_rahasia_yang_ikut_kebawa(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:RAHASIA-KUNCI-JANGAN-BOCOR=',
            'app.debug' => true,
        ]);

        $badan = (string) $this->getJson('/api/health')->assertOk()->getContent();

        $this->assertStringNotContainsString('RAHASIA-KUNCI-JANGAN-BOCOR', $badan);
        $this->assertStringNotContainsString('app_key', $badan);
        $this->assertStringNotContainsString('debug', $badan);

        // `deploy` cuma boleh memuat kelima kunci ini. Kunci keenam yang muncul
        // tanpa sengaja — misalnya seluruh `config('app')` ikut ter-spread —
        // langsung memerahkan test ini.
        $this->assertSame(
            ['lingkungan', 'versi', 'arsip', 'seed_saat_boot', 'bangun_ulang_saat_boot'],
            array_keys((array) $this->getJson('/api/health')->json('deploy')),
        );
    }

    /** Blok lama nggak boleh ilang gara-gara nambah field baru. */
    public function test_isi_health_yang_lama_nggak_ilang(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'app',
                'time',
                'direktori_perusahaan' => ['disetel', 'driver', 'bisa_ditagih'],
                'deploy' => ['lingkungan', 'versi', 'arsip', 'seed_saat_boot', 'bangun_ulang_saat_boot'],
                'realtime' => ['driver', 'nyala', 'paket_terpasang'],
                'pelanggan' => ['fitur', 'maintenance'],
            ]);
    }
}
