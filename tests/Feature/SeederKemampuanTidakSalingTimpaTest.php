<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\Organization;
use Database\Seeders\CalibrationCapabilitySeeder;
use Database\Seeders\ChlorineCapabilitySeeder;
use Database\Seeders\ConductivityCapabilitySeeder;
use Database\Seeders\DoMeterCapabilitySeeder;
use Database\Seeders\PhMeterCapabilitySeeder;
use Database\Seeders\RefractometerCapabilitySeeder;
use Database\Seeders\TurbidimeterCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeder lampiran akreditasi nggak boleh nabrak seeder CMC per-alat.
 *
 * ## Kenapa test ini ada
 *
 * `CalibrationCapabilitySeeder` mulai dengan ngehapus baris kemampuan
 * se-KATEGORI, terus nulis ulang dari `database/data/kemampuan-kalibrasi.json`.
 * Baris JSON nggak punya `u_temperature`, dan `u_temperature` itu SAKELAR:
 * `CalibrationProfile::komponenBudget()` balikin null tanpa dia, dan tiap sesi
 * baru diam-diam turun dari budget master (5-6 komponen) ke jalur lama satu
 * komponen (CMC/2). Sertifikatnya tetap terbit, angkanya beda, nggak ada error
 * di mana pun.
 *
 * Dua cara dulu ini kejadian:
 *
 *  1. `db:seed --class=CalibrationCapabilitySeeder` dijalanin sendirian —
 *     ngosongin `u_temperature` sembilan alat sekaligus. Persis kondisi DB
 *     lokal yang ketemu 20 Agu 2026: delapan dari sembilan alat NULL.
 *  2. Dijalanin berurutan pun barisnya DOBEL, karena kunci `updateOrCreate`
 *     seeder per-alat (`range_min = range_max = titik`) nggak pernah ketemu
 *     baris JSON (`range_min = NULL`). Yang satu punya `u_temperature`, yang
 *     satu NULL, dan `kemampuanUntukTitik()` milih salah satunya tanpa aturan
 *     yang bisa ditebak.
 */
class SeederKemampuanTidakSalingTimpaTest extends TestCase
{
    use RefreshDatabase;

    /** Alat yang baris CMC-nya dipegang seeder sendiri, berikut seeder-nya. */
    private const SEEDER_PER_ALAT = [
        'pH Meter' => PhMeterCapabilitySeeder::class,
        'Turbidimeter' => TurbidimeterCapabilitySeeder::class,
        'Chlorin Meter' => ChlorineCapabilitySeeder::class,
        'Refractometer' => RefractometerCapabilitySeeder::class,
        'Conductivitymeter' => ConductivityCapabilitySeeder::class,
        'DO Meter' => DoMeterCapabilitySeeder::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['id' => 1]);
    }

    private function seedSemua(): void
    {
        $this->seed(CalibrationCapabilitySeeder::class);

        foreach (self::SEEDER_PER_ALAT as $seeder) {
            $this->seed($seeder);
        }
    }

    public function test_tiap_titik_cuma_punya_satu_baris(): void
    {
        $this->seedSemua();

        foreach (array_keys(self::SEEDER_PER_ALAT) as $alat) {
            $baris = CalibrationCapability::where('nama_alat', $alat)->get();

            $this->assertGreaterThan(0, $baris->count(), "Baris CMC {$alat} kok nggak ada sama sekali.");

            $perTitik = $baris->groupBy(fn (CalibrationCapability $c): string => (string) $c->range_max);

            foreach ($perTitik as $titik => $kembar) {
                $this->assertCount(
                    1,
                    $kembar,
                    "{$alat} titik {$titik} punya {$kembar->count()} baris CMC. Kalau ada yang `u_temperature`-nya ".
                    'NULL, sesi bisa diam-diam jatuh ke budget satu komponen tergantung baris mana yang kepilih.',
                );
            }
        }
    }

    public function test_seeder_lampiran_nggak_ngosongin_konstanta_budget(): void
    {
        $this->seedSemua();

        $sebelum = CalibrationCapability::whereIn('nama_alat', array_keys(self::SEEDER_PER_ALAT))
            ->pluck('u_temperature', 'id');

        // Yang paling gampang kejadian: seeder lampirannya dijalanin lagi
        // sendirian, tanpa seeder per-alat menyusul.
        $this->seed(CalibrationCapabilitySeeder::class);

        $sesudah = CalibrationCapability::whereIn('nama_alat', array_keys(self::SEEDER_PER_ALAT))
            ->pluck('u_temperature', 'id');

        $this->assertEquals(
            $sebelum->all(),
            $sesudah->all(),
            'Jalanin CalibrationCapabilitySeeder sendirian nggak boleh nyentuh baris milik seeder per-alat. '.
            'Sekali `u_temperature` kehapus, semua sertifikat berikutnya pakai budget yang beda tanpa satu pun error.',
        );
    }

    public function test_alat_tanpa_seeder_sendiri_tetap_diseed_dari_lampiran(): void
    {
        $this->seedSemua();

        // Autoklaf sengaja nggak punya seeder kemampuan sendiri —
        // `AutoclaveCalculator` nggak lewat `komponenBudget()`. Baris JSON-nya
        // harus tetap ada, kalau nggak lantai CMC-nya ilang.
        $this->assertSame(
            2,
            CalibrationCapability::where('nama_alat', 'Autoklaf')->count(),
            'Autoklaf butuh dua baris CMC (Suhu & Tekanan) dari lampiran akreditasi.',
        );
    }
}
