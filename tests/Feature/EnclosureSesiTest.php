<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\Enclosure\InkubatorProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua sesi Enclosure dari ujung ke ujung: seeder → `EnclosureProfileBase::
 * hitungPerGrup()` → baris `uncertainty_calculations`, diadu ke kedua master.
 *
 * Beda dari `Tests\Unit\EnclosureBudgetTest` (murni, cuma kalkulator): yang diuji
 * di sini rantai lengkapnya lewat database — pencocokan alat ke profil per JENIS
 * enclosure, baris CMC per jenis dari lampiran akreditasi, penyimpanan grid ke
 * `raw_measurements` (kolom `sensor_ke`/`peran_sensor`), dan `keputusan` NULL.
 *
 * Jalankan juga di MySQL (`php artisan test -c phpunit.mysql.xml`): kolom
 * `decimal(20,8)` dibaca float oleh SQLite tapi string oleh MySQL, dan grid
 * enclosure nulis JAUH lebih banyak kolom desimal per sesi dari alat mana pun.
 */
class EnclosureSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-7;

    private function seedDanSesi(string $nomorSesi): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
    }

    public function test_inkubator_ketemu_profilnya(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $this->assertInstanceOf(InkubatorProfile::class, $profil);
        $this->assertSame('inkubator', $profil->kode());
        $this->assertSame('Inkubator', $sesi->equipment->nama_alat_kemampuan);
    }

    public function test_oven_ketemu_profilnya(): void
    {
        $sesi = $this->seedDanSesi('2406.25.AI');
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $this->assertInstanceOf(OvenProfile::class, $profil);
        $this->assertSame('oven', $profil->kode());
    }

    /**
     * Yokogawa (Inkubator, Type N): 4 set point, semua U95 dilaporkan 1,4 (lantai
     * CMC). Uc diadu untuk set point yang master-nya benar (SP1/SP2/SP4).
     */
    public function test_sesi_yokogawa_cocok_master(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');
        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(4, $baris);

        // Set point (titik_ukur) dan U95 dilaporkan.
        $harapUc = [0 => 0.62381769, 1 => 0.62309591, 3 => 0.62650074];
        $setpoints = [15.0, 35.0, 75.0, 100.0];

        foreach ($baris as $i => $b) {
            $this->assertEqualsWithDelta($setpoints[$i], (float) $b->titik_ukur, self::TOLERANSI, "setpoint ke-{$i}");
            $this->assertEqualsWithDelta(1.4, (float) $b->ketidakpastian_diperluas, self::TOLERANSI, "U95 ke-{$i}");
            $this->assertNull($b->keputusan);
            $this->assertNull($b->toleransi);

            if (isset($harapUc[$i])) {
                $this->assertEqualsWithDelta($harapUc[$i], (float) $b->ketidakpastian_gabungan, self::TOLERANSI, "Uc ke-{$i}");
            }
        }

        // SP3 (index 2): kalkulator menghitung Uc BENAR (0,6346), bukan bug sel
        // master (0,6234). Yang tercetak tetap 1,4.
        $this->assertEqualsWithDelta(0.63463317, (float) $baris[2]->ketidakpastian_gabungan, self::TOLERANSI);

        // Jejak audit membawa konteks enclosure + lantai CMC.
        $konteks = collect($baris[0]->type_b_components)->firstWhere('sumber', 'konteks_sesi');
        $this->assertSame('cmc', $konteks['sumber_u95']);
        $this->assertSame('Inkubator', $konteks['enclosure']);
        $this->assertSame('yokogawa', $konteks['merk_kalibrator']);
        $this->assertSame('Type N', $konteks['tipe_sensor']);
        $this->assertEqualsWithDelta(1.4, (float) $konteks['cmc'], self::TOLERANSI);

        // 11 komponen budget (termasuk konduksi_panas).
        $sumber = collect($baris[0]->type_b_components)->pluck('sumber');
        $this->assertTrue($sumber->contains('konduksi_panas'));
        $this->assertTrue($sumber->contains('efek_radiasi'));
    }

    /**
     * Recorder (Oven, Type K): 3 set point @ 67 °C, semua U95 dilaporkan 1,5
     * (lantai CMC Oven), Uc identik 0,5954.
     */
    public function test_sesi_recorder_cocok_master(): void
    {
        $sesi = $this->seedDanSesi('2406.25.AI');
        $baris = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(3, $baris);

        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(67.0, (float) $b->titik_ukur, self::TOLERANSI);
            $this->assertEqualsWithDelta(1.5, (float) $b->ketidakpastian_diperluas, self::TOLERANSI);
            $this->assertEqualsWithDelta(0.59543500, (float) $b->ketidakpastian_gabungan, self::TOLERANSI);
            $this->assertNull($b->keputusan);
        }

        $konteks = collect($baris[0]->type_b_components)->firstWhere('sumber', 'konteks_sesi');
        $this->assertSame('Oven', $konteks['enclosure']);
        $this->assertSame('recorder', $konteks['merk_kalibrator']);
        $this->assertSame('Type K', $konteks['tipe_sensor']);

        // 10 komponen — Recorder TIDAK punya konduksi_panas.
        $sumber = collect($baris[0]->type_b_components)->pluck('sumber');
        $this->assertFalse($sumber->contains('konduksi_panas'));
    }

    /** Grid tersimpan di raw_measurements dengan sumbu sensor (9 termokopel × 5). */
    public function test_grid_tersimpan_dengan_sumbu_sensor(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');

        $sp1 = $sesi->rawMeasurements()->where('titik_ke', 1);

        // 9 termokopel × 5 pengulangan = 45 baris termokopel.
        $this->assertSame(45, (clone $sp1)->where('peran_sensor', 'termokopel')->count());
        // 5 baris Indikator.
        $this->assertSame(5, (clone $sp1)->where('peran_sensor', 'indikator')->count());
        // Sensor acuan = No.3 (master Type N mulai dari 3).
        $this->assertSame(3, (int) (clone $sp1)->where('peran_sensor', 'termokopel')->orderBy('sensor_ke')->first()->sensor_ke);
    }

    /**
     * Jalur API sungguhan: POST grid ke `/api/calibrations/preview` → controller
     * `susunGridEnclosure` → `hitungPerGrup`. Ini yang membuktikan ingest grid
     * dari request beneran jalan, bukan cuma lewat seeder.
     */
    public function test_api_preview_grid_enclosure(): void
    {
        $this->seed(DatabaseSeeder::class);

        $equipment = Equipment::where('serial_number', 'D132469')->firstOrFail(); // Incubator-02
        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $fix = self::fixtureGolden()['yokogawa']['setpoints'][0]; // SP1 (15 °C)

        $sensorGrid = array_map(
            static fn (array $s): array => ['no' => $s['no'], 'pembacaan' => $s['pembacaan']],
            $fix['sensors'],
        );

        $respons = $this->actingAs($teknisi)->postJson('/api/calibrations/preview', [
            'equipment_id' => $equipment->id,
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-02',
            'tipe_sensor' => 'Type N',
            'suhu_awal' => 23.7, 'suhu_akhir' => 23.7, 'kelembaban_awal' => 47, 'kelembaban_akhir' => 46,
            'measurements' => [[
                'titik_ukur' => 15.0,
                'satuan' => '°C',
                'sensor_grid' => $sensorGrid,
                'indikator' => $fix['indikator'],
            ]],
        ])->assertOk();

        $titik = $respons->json('data.titik');
        $this->assertCount(1, $titik);
        $this->assertEqualsWithDelta(15.0, (float) $titik[0]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(1.4, (float) $titik[0]['ketidakpastian_diperluas'], self::TOLERANSI);
    }

    /** @return array<string, mixed> */
    private static function fixtureGolden(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enclosure-golden.json')), true);
    }

    /** Enclosure nggak divonis PASS/FAIL. */
    public function test_tidak_divonis(): void
    {
        $sesi = $this->seedDanSesi('2405.03.AV');

        $this->assertNull($sesi->fresh()->keputusan);
        foreach ($sesi->uncertaintyCalculations as $b) {
            $this->assertNull($b->keputusan);
            $this->assertNull($b->toleransi);
        }
    }
}
