<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/health` ketika DATABASE-nya sendiri mati (paket OPS-KODE, butir 1).
 *
 * Berkas terpisah dari `HealthKesehatanTest` karena butuh koneksi yang benar-
 * benar rusak — `RefreshDatabase` membungkus tiap test dalam transaksi pada
 * koneksi yang akan kita putuskan di sini.
 *
 * Kasus yang paling mahal: produksi memakai `CACHE_STORE=database`, jadi
 * database mati juga mematikan cache. Sebelum blok `kesehatan`, itu berarti
 * `/api/health` meledak 500 (cache dibaca di luar try/catch) — pemantau
 * melihat "error" tapi tanpa kata kunci yang bisa dibedakan dari PHP mati.
 * Yang dijamin sekarang: tetap 503 JSON terkendali dengan `db_gagal`.
 */
class HealthKesehatanDbGagalTest extends TestCase
{
    private function putuskanDatabase(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kes-db-tidak-ada-'.bin2hex(random_bytes(5));

        // Koneksi BARU yang dijadikan bawaan, bukan merusak koneksi `sqlite`:
        // suite MySQL (phpunit.mysql.xml) memakai koneksi `mysql` sebagai
        // bawaan, dan merusak `sqlite` di sana tidak mengubah apa pun —
        // testnya akan hijau di SQLite dan merah di MySQL.
        //
        // SQLite menolak berkas yang tidak ada (kecuali `:memory:`), jadi ping
        // gagal dengan galat sungguhan dari driver — bukan tiruan. pdo_sqlite
        // ada di kedua profil CI.
        config()->set('database.connections.putus', [
            'driver' => 'sqlite',
            'database' => $dir.DIRECTORY_SEPARATOR.'x.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('database.default', 'putus');
        DB::purge('putus');
    }

    public function test_database_mati_503_db_gagal_dan_status_bukan_ok(): void
    {
        $this->putuskanDatabase();

        $respons = $this->getJson('/api/health');

        $respons->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('kesehatan.db', 'gagal')
            ->assertJsonPath('kesehatan.alasan', ['db_gagal'])
            ->assertJsonPath('kesehatan.antrean.menunggu', null)
            ->assertJsonPath('kesehatan.sertifikat_tertunda', null);
        $this->assertStringNotContainsString('"status":"ok"', $respons->getContent());
    }

    /** Cache berbasis database + database mati: tetap 503 terkendali, bukan 500. */
    public function test_database_mati_dengan_cache_database_tetap_503_bukan_500(): void
    {
        config()->set('cache.default', 'database');
        config()->set('queue.default', 'database');
        $this->putuskanDatabase();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('kesehatan.alasan', ['db_gagal'])
            // Blok-blok lama tetap terjawab dari config, tidak ikut runtuh.
            ->assertJsonPath('append_only_db', false)
            ->assertJsonStructure(['app', 'time', 'deploy' => ['versi'], 'php_versi']);
    }

    public function test_pesan_galat_database_tidak_bocor(): void
    {
        $this->putuskanDatabase();

        $isi = $this->getJson('/api/health')->assertStatus(503)->getContent();

        foreach (['SQLSTATE', 'Exception', 'kes-db-tidak-ada', '.sqlite', 'does not exist', 'select 1'] as $rincian) {
            $this->assertStringNotContainsString($rincian, $isi, "Bocor: {$rincian}");
        }
    }
}
