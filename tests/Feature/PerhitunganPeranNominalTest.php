<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\Calibration\Profiles\JangkaSorongProfile;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /calibrations/{id}/perhitungan` (layar approval admin) merata-ratakan
 * SEMUA baris mentah satu titik. Micrometer (`mikro_balok`) dan Jangka Sorong
 * (`js_*_nominal`) menyimpan nominal keping balok ukur / slot standar sebagai
 * baris di sumbu yang sama dengan pembacaan, jadi baris itu ikut terbaca sebagai
 * pembacaan UUT: titik Micrometer 25 mm menampilkan rata-rata 21,43 dan koreksi
 * -3,57 walau sertifikatnya (dari hasil tersimpan) benar. Ketahuan dari lembar
 * nyata pusat (input data, 20 Nov 2026).
 */
class PerhitunganPeranNominalTest extends TestCase
{
    use RefreshDatabase;

    private function teknisi(): User
    {
        return User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();
    }

    /**
     * Tiap titik di layar perhitungan HARUS sama dengan baris pembacaan UUT di
     * database (peran berakhiran `_pembacaan`): jumlah, rata-rata, stdev; dan
     * nilai standarnya = standar terkoreksi tersimpan, bukan nominal cetak.
     */
    private function periksaLayar(int $sesiId, string $akhiranPeran): void
    {
        $admin = User::where('role', User::ROLE_ADMIN)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $tabel = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesiId}/perhitungan")
            ->assertOk()
            ->json('data.hasil.1.titik');

        $this->assertNotEmpty($tabel);
        $tersimpan = CalibrationSession::findOrFail($sesiId)->uncertaintyCalculations->keyBy('titik_ke');
        $mentah = RawMeasurement::where('calibration_session_id', $sesiId)->orderBy('titik_ke')->orderBy('sensor_ke')->get()->groupBy('titik_ke');

        foreach ($tabel as $t) {
            $uut = $mentah[$t['titik_ke']]
                ->filter(fn (RawMeasurement $m): bool => str_ends_with((string) $m->peran_sensor, $akhiranPeran))
                ->map(fn (RawMeasurement $m): float => (float) $m->pembacaan)
                ->values();

            $this->assertCount($uut->count(), $t['pembacaan'], "titik {$t['titik_ke']}: baris nominal standar ikut terbaca sebagai pembacaan");
            $this->assertEqualsWithDelta($uut->avg(), $t['average'], 1e-9, "titik {$t['titik_ke']}: average");

            $n = $uut->count();
            $sd = $n > 1 ? sqrt($uut->map(fn (float $x): float => ($x - $uut->avg()) ** 2)->sum() / ($n - 1)) : null;
            $this->assertEqualsWithDelta($sd, $t['stdev'], 1e-9, "titik {$t['titik_ke']}: stdev");

            $this->assertEqualsWithDelta((float) $tersimpan[$t['titik_ke']]->titik_ukur, $t['standard'], 1e-9, "titik {$t['titik_ke']}: standar terkoreksi tersimpan");
            $this->assertEqualsWithDelta($t['average'] - $t['standard'], $t['correction'], 1e-9, "titik {$t['titik_ke']}: Correction lembar ini = Average - Standard");
        }
    }

    public function test_layar_perhitungan_micrometer_tidak_mencampur_keping_balok_ke_pembacaan(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'ZQ-100')->firstOrFail();

        $id = $this->actingAs($this->teknisi())->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            'measurements' => [
                ['titik_ukur' => 25.0, 'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001]],
                ['titik_ukur' => 27.5, 'pembacaan' => [27.502, 27.501, 27.502, 27.501, 27.502]],
                ['titik_ukur' => 31.0, 'pembacaan' => [31.003, 31.002, 31.003, 31.002, 31.003]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm', 'kapasitas_mm' => 50.0, 'resolusi_mm' => 0.001,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                ],
            ],
        ])->assertSuccessful()->json('data.id');

        $this->periksaLayar($id, 'pembacaan');
    }

    public function test_layar_perhitungan_jangka_sorong_tidak_mencampur_nominal_ke_pembacaan(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'JS-042')->firstOrFail();
        $f = json_decode((string) file_get_contents(database_path('data/sesi-master-jangka-sorong.json')), true);
        $profil = new JangkaSorongProfile;

        // Baris ke-i ketiga tabel digabung per POSISI (lihat JangkaSorongSieveSesiTest).
        $baris = [];
        foreach (['outside', 'inside'] as $grup) {
            foreach ($f[$grup] as $t) {
                foreach ($profil->barisPraCetak($grup) as $i => $p) {
                    if (abs(array_sum($p['nominal']) - array_sum($t['nominal_mm'])) < 1e-9) {
                        $baris[$i]['js_'.$grup] = $t['pembacaan_mm'];
                    }
                }
            }
        }
        foreach ($f['depth'] as $i => $t) {
            $baris[$i]['js_depth'] = array_slice($t['pembacaan_mm'], 0, 5);
        }
        $outside = $profil->barisPraCetak('outside');
        $measurements = [];
        for ($i = 0; $i <= max(array_keys($baris)); $i++) {
            $measurements[] = ['titik_ukur' => array_sum($outside[$i]['nominal'] ?? [0.0])] + ($baris[$i] ?? []);
        }

        $id = $this->actingAs($this->teknisi())->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => $f['_sesi']['tanggal'],
            'suhu_awal' => $f['_sesi']['suhu_awal'], 'suhu_akhir' => $f['_sesi']['suhu_akhir'],
            'kelembaban_awal' => 44, 'kelembaban_akhir' => 55,
            'measurements' => $measurements,
            'spesifikasi_alat' => [
                'jangka_sorong' => [
                    'satuan' => 'mm', 'kapasitas_mm' => 600.0, 'resolusi_mm' => $f['_sesi']['resolusi_mm'],
                    'kerataan_muka_ukur' => 'baik',
                    'pra_evaluasi_outside' => ['baris' => [['titik_ukur' => null, 'pembacaan' => $f['pra_evaluasi_outside_mm']]]],
                    'pra_evaluasi_inside' => ['baris' => [['titik_ukur' => null, 'pembacaan' => $f['pra_evaluasi_inside_mm']]]],
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->periksaLayar($id, '_pembacaan');
    }
}
