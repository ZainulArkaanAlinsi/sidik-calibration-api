<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua perintah kesiapan: `kalibrasi:sapu-sesi` & `kalibrasi:uji-profil`.
 *
 * Keduanya dipakai SEBELUM sesi uji lapangan, jadi kalau perintahnya sendiri
 * yang rusak, yang hilang bukan cuma satu laporan — hilangnya kepercayaan
 * bahwa "sudah dicek" itu berarti apa-apa.
 */
class PerintahUjiKesiapanTest extends TestCase
{
    use RefreshDatabase;

    public function test_sapu_sesi_bersih_di_database_ber_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('kalibrasi:sapu-sesi')
            ->expectsOutputToContain('Bersih')
            ->assertSuccessful();
    }

    /**
     * Seluruh profil harus jalan di database ber-seed — termasuk Autoklaf,
     * yang diperiksa lewat `hasil_autoclave`, bukan endpoint preview.
     */
    public function test_uji_profil_semua_jalan_di_database_ber_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('kalibrasi:uji-profil')
            ->expectsOutputToContain('Semua profil jalan')
            ->assertSuccessful();
    }

    public function test_uji_profil_bisa_dibatesin_ke_satu_profil(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('kalibrasi:uji-profil', ['--profil' => 'gas_detector'])
            ->expectsOutputToContain('gas_detector')
            ->assertSuccessful();
    }

    /**
     * `uji-profil` TIDAK boleh menulis apa pun.
     *
     * Perintah ini dijalankan di database KERJA, kadang beberapa menit sebelum
     * teknisi mulai. Begitu dia mulai menyimpan sesi, dia berhenti aman —
     * dan yang paling mungkin bikin itu kejadian adalah seseorang mengganti
     * `preview` jadi jalur simpan karena "sekalian saja".
     */
    public function test_uji_profil_tidak_menulis_apa_pun_ke_database(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sebelum = [
            'sesi' => CalibrationSession::count(),
            'mentah' => RawMeasurement::count(),
            'hitungan' => UncertaintyCalculation::count(),
            'sertifikat' => Certificate::count(),
        ];

        $this->artisan('kalibrasi:uji-profil')->assertSuccessful();

        $this->assertSame($sebelum, [
            'sesi' => CalibrationSession::count(),
            'mentah' => RawMeasurement::count(),
            'hitungan' => UncertaintyCalculation::count(),
            'sertifikat' => Certificate::count(),
        ], 'kalibrasi:uji-profil menulis ke database — dia harus read-only.');
    }
}
