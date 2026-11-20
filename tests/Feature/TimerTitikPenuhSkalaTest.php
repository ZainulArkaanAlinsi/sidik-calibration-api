<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timer/Stopwatch yang dikalibrasi di titik PENUH SKALA tidak boleh memuntahkan
 * peringatan `pembacaan_di_luar_rentang` palsu.
 *
 * Ditemukan 20 Nov 2026 saat memasukkan lembar nyata sebuah Timer
 * (rentang 10 menit, titik 5 dan 10 menit): stopwatch standar membaca
 * 600,087 s di titik 600 s — wajar, karena standar dan alat ditekan
 * berbarengan — dan sesinya ditahan peringatan "pembacaan 600087 s jauh di luar
 * rentang ukur alat (0–600 s)".
 *
 * Akarnya: `CalibrationValidator` memakai `nilaiDalamSatuanAlat()` untuk DUA
 * angka — pembacaan (milidetik) dan titik ukur. Titik ukur lembar ini sudah
 * DETIK (`WaktuMentah`), jadi dibagi 1000 lagi dan "pembacaan dekat titik
 * standar" (pengecualian 10%) tidak pernah berlaku: 600,087 s dibandingkan
 * dengan 0,6 s.
 */
class TimerTitikPenuhSkalaTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $admin;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        // Rentang berkas "10 min" ditulis dalam satuan hasil alat (detik), 0–600.
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'waktu-dan-frekuensi'])->id,
            'nama_alat_kemampuan' => 'Timer/Stopwatch',
            'satuan' => 's', 'range_min' => 0, 'range_max' => 600,
            'resolusi' => 1, 'toleransi' => null,
        ]);

        $this->standar = Standard::factory()->create(['nama' => 'Stopwatch SW-1', 'serial_number' => 'SW-1']);
    }

    public function test_titik_penuh_skala_dengan_bacaan_standar_sedikit_melewati_rentang_tidak_ditandai(): void
    {
        // Standar 600,083 / 600,083 / 600,082 s; alat pelanggan 600,000 s.
        $kode = $this->periksa(600, [83, 83, 82]);

        $this->assertNotContains('pembacaan_di_luar_rentang', $kode);
    }

    /**
     * Penjaga TIDAK dimatikan: koma kegeser sungguhan (6000,083 s di titik 600 s)
     * tetap tertangkap.
     */
    public function test_koma_kegeser_sungguhan_tetap_tertangkap(): void
    {
        $kode = $this->periksa(600, [83, 83, 82], menitStandar: 100);

        $this->assertContains('pembacaan_di_luar_rentang', $kode);
    }

    /**
     * @param  list<int>  $msStandar  milidetik tiap ulangan standar (menit = set point / 60 kecuali `$menitStandar`)
     * @return list<string>
     */
    private function periksa(int $setPointDetik, array $msStandar, ?int $menitStandar = null): array
    {
        $menit = intdiv($setPointDetik, 60);
        $kotak = static fn (int $m, int $ms): array => ['jam' => 0, 'menit' => $m, 'detik' => 0, 'milidetik' => $ms];

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [[
                'titik_ukur' => $setPointDetik,
                'standar' => array_map(static fn (int $ms): array => $kotak($menitStandar ?? $menit, $ms), $msStandar),
                'uut' => array_map(static fn (int $ms): array => $kotak($menit, 0), $msStandar),
            ]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        return array_column(
            $this->actingAs($this->admin)->getJson("/api/calibrations/{$sesi->id}/validasi")->assertOk()->json('data.temuan'),
            'kode',
        );
    }
}
