<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keadaan saklar modul pelanggan kelihatan dari luar, tanpa masuk dashboard.
 *
 * ## Kenapa berkas ini ada
 *
 * `render.yaml` menulis `FITUR_PELANGGAN` dengan `value: "false"`, dan itu
 * gampang disalahbaca sebagai KUNCI. Bukan. `value:` cuma berarti blueprint
 * menyinkronkan nilainya tiap kali dia dibaca — yaitu tiap deploy. Di ANTARA
 * dua deploy, nilainya bisa digeser dari dashboard Render dan **langsung
 * berlaku**; deploy berikutnya menimpanya balik tanpa satu pun peringatan.
 *
 * Dua arah, dua-duanya senyap:
 *
 *  1. dinyalakan buat uji coba lalu lupa → seluruh `/api/pelanggan/v1` terbuka
 *     ke internet sampai deploy berikutnya, padahal modulnya belum lewat
 *     tinjauan keamanan M6-01;
 *  2. dinyalakan waktu rilis M7 lalu ketimpa deploy → modulnya mati diam-diam,
 *     dan yang ketahuan cuma dari keluhan pengguna.
 *
 * Bukan kekhawatiran teoretis. `ARSIP_DRIVER` kena persis pola (2) pada
 * 1 Sep 2026: digeser ke `s3` di dashboard, deploy berikutnya menimpanya balik
 * ke `local`, produksi diam-diam menulis ke disk yang kehapus tiap deploy — dan
 * yang menemukan justru satu huruf berubah di `/api/health`. Lihat render.yaml
 * §ARSIP_DRIVER.
 *
 * ## Kenapa BUKAN guard keras
 *
 * Pilihan yang ditolak: menolak `FITUR_PELANGGAN=true` waktu
 * `APP_ENV=production`. Masalahnya bukan "ada yang sengaja menyalakan" — itu
 * memang perlu waktu M7 tiba — melainkan "nilainya berubah tanpa ada yang
 * tahu". Yang mengobati itu kekelihatan, bukan kunci tambahan; dan kunci
 * tambahan justru bikin rilis M7 butuh dua tempat yang harus sejalan, dengan
 * kegagalan yang lebih senyap lagi kalau salah satunya terlewat.
 *
 * ## Yang berkas ini TIDAK lakukan
 *
 * Tidak menyalakan, mematikan, atau memvalidasi modulnya. Yang dijaga cuma:
 * keadaannya berhenti tak terlihat.
 */
class HealthLaporinPelangganTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitur_mati_dilaporin_mati(): void
    {
        config(['pelanggan.fitur' => false, 'pelanggan.maintenance' => false]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('pelanggan.fitur', false)
            ->assertJsonPath('pelanggan.maintenance', false);
    }

    public function test_fitur_nyala_dilaporin_nyala(): void
    {
        config(['pelanggan.fitur' => true]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('pelanggan.fitur', true);
    }

    /**
     * `maintenance` dilaporkan TERPISAH dari `fitur`.
     *
     * Keduanya bisa salah sendiri-sendiri, dan gabungannya punya arti sendiri:
     * fitur nyala + maintenance nyala = modulnya hidup tapi seluruh pelanggan
     * dapat 503. Dilebur jadi satu boolean, keadaan itu tidak bisa dibedakan
     * dari "modulnya memang mati".
     */
    public function test_maintenance_dilaporin_terpisah_dari_fitur(): void
    {
        config(['pelanggan.fitur' => true, 'pelanggan.maintenance' => true]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('pelanggan.fitur', true)
            ->assertJsonPath('pelanggan.maintenance', true);
    }

    /**
     * Endpoint ini PUBLIK tanpa auth — nol nilai rahasia boleh ikut.
     *
     * Batas yang sama dengan tiga blok di atasnya (`direktori_perusahaan`,
     * `deploy`, `realtime`): yang dilaporkan STATUS, bukan nilai.
     */
    public function test_nggak_ada_rahasia_yang_ikut_kebawa(): void
    {
        config([
            'pelanggan.fitur' => true,
            'pelanggan.pesan_maintenance' => 'RAHASIA-JANGAN-BOCOR',
            'pelanggan.cutoff_token_lama' => '2026-12-31',
        ]);

        $badan = (string) $this->getJson('/api/health')->assertOk()->getContent();

        $this->assertStringNotContainsString('RAHASIA-JANGAN-BOCOR', $badan);
        $this->assertStringNotContainsString('2026-12-31', $badan);
        $this->assertSame(
            ['fitur', 'maintenance'],
            array_keys((array) $this->getJson('/api/health')->json('pelanggan')),
            'Blok pelanggan cuma boleh memuat dua status — bukan isi setelannya.',
        );
    }

    /** Blok lama nggak boleh ilang gara-gara nambah blok baru. */
    public function test_isi_health_yang_lama_nggak_ilang(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'app',
                'time',
                'direktori_perusahaan' => ['disetel', 'driver', 'bisa_ditagih'],
                'deploy' => ['versi', 'arsip', 'seed_saat_boot', 'bangun_ulang_saat_boot'],
                'realtime' => ['driver', 'nyala', 'paket_terpasang'],
                'pelanggan' => ['fitur', 'maintenance'],
            ]);
    }

    /**
     * Pasangan lapis kedua: peringatan keras di boot.
     *
     * Health menjawab "sekarang keadaannya apa"; entrypoint menjawab "kenapa
     * nilainya begitu" di detik dia dipakai. Dua-duanya perlu, karena log
     * deploy jarang dibaca kalau deploy-nya sukses, sementara health butuh ada
     * yang ingat mengetuknya.
     *
     * Polanya sama persis dengan `SEED_ON_BOOT` & `BANGUN_ULANG_ON_BOOT` yang
     * sudah lebih dulu berteriak di berkas yang sama.
     */
    public function test_entrypoint_berteriak_kalau_fitur_nyala(): void
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString(
            '[ "${FITUR_PELANGGAN}" = "true" ]',
            $entrypoint,
            'Gerbang peringatan FITUR_PELANGGAN hilang dari entrypoint.',
        );

        $this->assertStringContainsString(
            '/api/pelanggan/v1 kebuka',
            $entrypoint,
            'Peringatannya wajib menyebut APA yang terbuka, bukan cuma nama variabelnya.',
        );

        $this->assertStringContainsString(
            'ketimpa balik diam-diam di deploy berikutnya',
            $entrypoint,
            'Peringatannya wajib menyebut bahwa nilainya nggak bertahan.',
        );

        // SENGAJA peringatan, bukan `exit 1`. Kalau suatu saat ini berubah jadi
        // gerbang keras, yang hilang bukan cuma satu saklar — seluruh server
        // yang dipakai teknisi di lokasi ikut mati.
        $blok = substr($entrypoint, (int) strpos($entrypoint, '[ "${FITUR_PELANGGAN}" = "true" ]'));
        $blok = substr($blok, 0, (int) strpos($blok, "\nfi"));

        $this->assertStringNotContainsString('exit 1', $blok, 'Blok ini nggak boleh matiin boot.');
    }
}
