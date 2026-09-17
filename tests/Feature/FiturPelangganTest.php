<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sakelar `FITUR_PELANGGAN` menutup seluruh modul pelanggan (03-SDD §10).
 *
 * Rute ujinya didaftarkan DI DALAM test, bukan menumpang rute pelanggan yang
 * sudah ada. Sebabnya: yang diuji middleware-nya, dan menumpang rute nyata
 * bikin test ini ikut merah tiap kali rute itu berubah karena alasan lain.
 */
class FiturPelangganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'fitur.pelanggan'])
            ->get('/uji/fitur-pelanggan', fn () => response()->json(['data' => ['ok' => true]]));
    }

    /** Flag mati → 503 dengan `kode` yang stabil, bukan 404. */
    public function test_flag_mati_menutup_rute_pelanggan(): void
    {
        config(['pelanggan.fitur' => false]);

        $this->getJson('/uji/fitur-pelanggan')
            ->assertStatus(503)
            ->assertJsonPath('kode', 'belum_tersedia')
            ->assertJsonStructure(['kode', 'message']);
    }

    /** Flag nyala → lewat. */
    public function test_flag_nyala_membuka_rute_pelanggan(): void
    {
        config(['pelanggan.fitur' => true]);

        $this->getJson('/uji/fitur-pelanggan')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    /**
     * `/app/status` TETAP jalan waktu flag mati.
     *
     * Ini yang membedakan "fitur dimatikan dengan benar" dari "aplikasi
     * pelanggan buta": tanpa endpoint ini, aplikasi nggak punya cara tahu
     * dirinya usang atau server sedang maintenance.
     */
    public function test_app_status_tetap_jalan_walau_flag_mati(): void
    {
        config(['pelanggan.fitur' => false]);

        $this->getJson('/api/pelanggan/v1/app/status')->assertOk();
    }

    /** Default konfigurasinya MATI — produksi nggak boleh nyala tanpa dinyatakan. */
    public function test_default_config_mati(): void
    {
        $this->assertFalse(
            (bool) config('pelanggan.fitur'),
            'Default FITUR_PELANGGAN nyala. 03-SDD §10 minta produksi mati sampai M7.'
        );
    }
}
