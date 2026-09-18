<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/pelanggan/v1/app/status` — dibaca dari config, NOL query database.
 *
 * Endpoint ini dipanggil tiap kali aplikasi dibuka dan tiap kali kembali dari
 * latar belakang. Dia harus tetap menjawab justru waktu keadaan sedang buruk —
 * database penuh, koneksi habis, migrasi sedang jalan — karena jawabannyalah
 * yang menyuruh aplikasi menampilkan layar maintenance alih-alih galat teknis.
 *
 * Jumlah query-nya DIHITUNG, bukan cuma dijanjikan di komentar: satu `->load()`
 * atau satu middleware yang menyentuh database bakal lolos review tapi ketahuan
 * di sini.
 */
class AppStatusPelangganTest extends TestCase
{
    use RefreshDatabase;

    public function test_bentuk_responsnya_sesuai_kontrak(): void
    {
        config([
            'pelanggan.versi_minimum' => '1.2.0',
            'pelanggan.versi_terbaru' => '1.4.1',
            'pelanggan.maintenance' => true,
            'pelanggan.pesan_maintenance' => 'Sedang perbaikan sampai 21.00 WIB.',
        ]);

        $this->getJson('/api/pelanggan/v1/app/status')
            ->assertOk()
            ->assertJsonStructure(['data' => ['versi_minimum', 'versi_terbaru', 'maintenance', 'pesan_maintenance']])
            ->assertJsonPath('data.versi_minimum', '1.2.0')
            ->assertJsonPath('data.versi_terbaru', '1.4.1')
            ->assertJsonPath('data.maintenance', true)
            ->assertJsonPath('data.pesan_maintenance', 'Sedang perbaikan sampai 21.00 WIB.');
    }

    public function test_tidak_menyentuh_database_sama_sekali(): void
    {
        DB::enableQueryLog();

        $this->getJson('/api/pelanggan/v1/app/status')->assertOk();

        $query = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $query, sprintf(
            'app/status menembak %d query database. Endpoint ini harus tetap menjawab '.
            "justru waktu database bermasalah — itu seluruh gunanya.\n  %s",
            count($query),
            implode("\n  ", array_column($query, 'query')),
        ));
    }

    /** `maintenance` wajib boolean sungguhan, bukan "1"/"0" — klien Dart mem-parsing tipenya. */
    public function test_maintenance_bertipe_boolean(): void
    {
        config(['pelanggan.maintenance' => false]);

        $isi = $this->getJson('/api/pelanggan/v1/app/status')->assertOk()->json('data');

        $this->assertIsBool($isi['maintenance']);
        $this->assertIsString($isi['versi_minimum']);
    }
}
