<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `kesehatan.db` mengukur sambungan SUNGGUHAN, jadi tes yang menyetel ulang
 * konfigurasi koneksi bawaan harus tahu kapan itu berpengaruh.
 *
 * ## Kejadian yang melahirkan berkas ini (PR #287, CI MySQL merah)
 *
 * Dua tes lama (`HealthDeployTest::test_nggak_ada_rahasia_yang_ikut_keluar` dan
 * `HealthLaporinAcuanTest::test_blok_acuan_hanya_memuat_sumber...`) menaruh
 * "kenari" rahasia di konfigurasi `database.connections.mysql` (host dan sandi
 * palsu) lalu menuntut `GET /api/health` menjawab 200. Di profil SQLite itu
 * tidak berarti apa-apa (koneksi bawaannya `sqlite`). Di profil MySQL koneksi
 * bawaan ADALAH `mysql`, dan sebelum blok `kesehatan` tidak ada yang menyentuh
 * database di jalur itu — sekarang `select 1` adalah yang PERTAMA membuka
 * sambungan, jadi host/sandi palsu itu sungguhan dipakai: `db_gagal`, 503.
 *
 * Itu BUKAN cacat health. Database yang tidak bisa disambungi memang harus
 * dijawab 503, dan itulah gunanya blok ini. Yang salah asumsi tesnya: kenari
 * rahasia tidak boleh merusak sambungan yang dipakai aplikasi. Perbaikannya di
 * tes: buka koneksi dulu, baru tanam kenari.
 *
 * ## Yang dikunci di sini
 *
 *  1. Konfigurasi koneksi diubah SESUDAH tersambung: health tidak peduli
 *     (sambungan yang sudah terbuka dipakai apa adanya) -> 200.
 *  2. Konfigurasi koneksi rusak SEBELUM tersambung: `db_gagal` -> 503. Kalau
 *     ini berubah jadi 200, health berhenti mengukur apa pun.
 *  3. (Penjaga sumber, jalan di SQLite juga) tes mana pun yang meminta
 *     `/api/health` sambil menyetel `database.connections.<driver nyata>` wajib
 *     membuka koneksi lebih dulu (`getPdo()`); kalau tidak, dia hijau di SQLite
 *     dan merah di MySQL, dan baru ketahuan di CI.
 */
class HealthKoneksiKonfigurasiTest extends TestCase
{
    /**
     * @return array{0: string, 1: string} nama koneksi bawaan dan kunci config-nya
     */
    private function koneksiBawaan(): array
    {
        $nama = (string) config('database.default');

        return [$nama, "database.connections.{$nama}"];
    }

    public function test_konfigurasi_diubah_sesudah_tersambung_tidak_memutus_health(): void
    {
        [, $kunci] = $this->koneksiBawaan();

        // Buka dulu: sesudah ini koneksi tidak membaca konfigurasinya lagi.
        DB::connection()->getPdo();

        config()->set("{$kunci}.host", 'host-database-rahasia');
        config()->set("{$kunci}.password", 'PENANDA-SANDI-UJI-DB');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kesehatan.db', 'ok');
    }

    public function test_konfigurasi_rusak_sebelum_tersambung_membuat_db_gagal(): void
    {
        [$nama, $kunci] = $this->koneksiBawaan();

        // Host tidak ada (MySQL), dan berkas tidak ada (SQLite — yang tidak
        // membaca `host`). `purge` supaya tidak ada PDO yang sudah terbuka.
        config()->set("{$kunci}.host", 'host-database-rahasia.invalid');
        config()->set("{$kunci}.password", 'PENANDA-SANDI-UJI-DB');
        config()->set("{$kunci}.database", sys_get_temp_dir().DIRECTORY_SEPARATOR.'kes-tidak-ada-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR.'x.sqlite');
        DB::purge($nama);

        $respons = $this->getJson('/api/health');

        $respons->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.alasan', ['db_gagal']);

        // Dan kenarinya tidak ikut keluar walau justru dialah yang dipakai.
        foreach (['host-database-rahasia', 'PENANDA-SANDI-UJI-DB'] as $kenari) {
            $this->assertStringNotContainsString($kenari, (string) $respons->getContent());
        }
    }

    /**
     * Penjaga sumber. Mencari tes yang meminta `/api/health` dan menyetel
     * `database.connections.<mysql|sqlite|mariadb|pgsql|sqlsrv>` tanpa membuka
     * koneksi (`getPdo()`) di berkas yang sama.
     */
    public function test_tes_health_yang_mengubah_koneksi_nyata_membuka_koneksi_dulu(): void
    {
        $pelanggar = [];

        foreach (glob(__DIR__.DIRECTORY_SEPARATOR.'*.php') ?: [] as $berkas) {
            if (basename($berkas) === basename(__FILE__)) {
                continue;
            }

            $isi = (string) file_get_contents($berkas);

            $mintaHealth = str_contains($isi, "'/api/health'") || str_contains($isi, '"/api/health"');
            $ubahKoneksi = (bool) preg_match(
                '/database\\.connections\\.(mysql|sqlite|mariadb|pgsql|sqlsrv)\\./',
                $isi,
            );

            if ($mintaHealth && $ubahKoneksi && ! str_contains($isi, 'getPdo()')) {
                $pelanggar[] = basename($berkas);
            }
        }

        $this->assertSame(
            [],
            $pelanggar,
            'Tes ini meminta /api/health sambil menyetel konfigurasi koneksi database nyata tanpa '
            .'`DB::connection()->getPdo()` lebih dulu. Di profil MySQL koneksi itu adalah koneksi '
            .'bawaan, kesehatan.db gagal, dan health menjawab 503 (lihat docblock kelas ini).',
        );
    }
}
