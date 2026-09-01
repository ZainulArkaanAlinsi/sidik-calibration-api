<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dua sumbu kiriman yang panjangnya dulu ditentukan PENGIRIM, bukan server.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `measurements` dibatasi `max:60`, dan docblock-nya menyebut alasannya
 * gamblang: satu proses 512 MB dipakai semua organisasi. Tapi dua sumbu di
 * bawahnya lolos tanpa batas sama sekali:
 *
 *  - **`measurements.*.pembacaan`** — sumbu yang paling banyak menulis
 *    `raw_measurements` di jalur datar: satu elemen = satu baris. Batas 60 di
 *    atasnya jadi nggak ada artinya kalau tiap titik boleh membawa deret
 *    sepanjang apa pun. Semua sumbu sebelahnya sudah dibatasi
 *    (`sensor_grid.*.pembacaan` 20, `standar`/`uut` 20, `indikator` 20,
 *    `nominal` 6) — yang ini satu-satunya yang kelewat.
 *  - **`standar_dicek`** — tiap elemennya memicu satu query `exists`, jadi
 *    jumlah query dan waktu validasi satu request ditentukan pengirim.
 *
 * Batasnya bukan angka karangan: `pembacaan` memakai
 * `CalibrationProfile::MAKS_KOLOM_PENGULANGAN`, jumlah kolom pengulangan
 * terbanyak yang boleh digambar lembar mana pun, jadi kiriman yang SAH nggak
 * mungkin melebihinya.
 */
class BatasUkuranKirimanTest extends TestCase
{
    use RefreshDatabase;

    public function test_deret_pembacaan_kepanjangan_ditolak_422(): void
    {
        $this->seed(DatabaseSeeder::class);

        $respons = $this->pratinjau([
            'measurements' => [[
                'titik_ukur' => 4.01,
                'pembacaan' => array_fill(0, CalibrationProfile::MAKS_KOLOM_PENGULANGAN + 1, 4.01),
            ]],
        ]);

        $respons->assertStatus(422);
        $respons->assertJsonValidationErrors('measurements.0.pembacaan');
    }

    /** Dan panjang yang WAJAR tetap diterima — batasnya bukan buat menutup jalan. */
    public function test_deret_pembacaan_sepanjang_batas_tetap_diterima(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->pratinjau([
            'measurements' => [[
                'titik_ukur' => 4.01,
                'pembacaan' => array_fill(0, CalibrationProfile::MAKS_KOLOM_PENGULANGAN, 4.01),
            ]],
        ])->assertOk();
    }

    public function test_standar_dicek_kepanjangan_ditolak_422(): void
    {
        $this->seed(DatabaseSeeder::class);

        $idStandar = Standard::query()->value('id');

        $respons = $this->pratinjau([
            'measurements' => [['titik_ukur' => 4.01, 'pembacaan' => [4.01, 4.02, 4.03]]],
            'standar_dicek' => array_fill(0, 41, ['standard_id' => $idStandar, 'dipakai' => true]),
        ]);

        $respons->assertStatus(422);
        $respons->assertJsonValidationErrors('standar_dicek');
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function pratinjau(array $tambahan): TestResponse
    {
        $sesi = CalibrationSession::where('nomor_sesi', '2405.13.A')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        return $this->actingAs($teknisi)->postJson('/api/calibrations/preview', [
            'equipment_id' => $sesi->equipment_id,
            'standard_id' => $sesi->standard_id,
            'tanggal_kalibrasi' => '2026-09-01',
            ...$tambahan,
        ]);
    }
}
