<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\JangkaSorongProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Jangka Sorong** dan **Sieve Mesh** lewat API: payload
 * bentuk HP → `raw_measurements` → hitung ulang, angkanya sama.
 *
 * Kedua profil dibangun terpisah dari pengkabelannya ke berkas bersama, dan
 * seeder mereka menulis langsung ke DB — jadi test ini satu-satunya yang
 * membuktikan sesi dari HP benar-benar sampai (preseden Anak Timbangan, yang
 * lembarnya lengkap tapi tidak pernah bisa dikirim).
 */
class JangkaSorongSieveSesiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private static function fixture(string $alat): array
    {
        return json_decode((string) file_get_contents(database_path("data/sesi-master-{$alat}.json")), true);
    }

    private function teknisi(): User
    {
        return User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();
    }

    /**
     * Susun `measurements[]` Jangka Sorong seperti HP: baris ke-i ketiga tabel
     * digabung per POSISI, nominal diambil dari baris pra-cetak lembar.
     *
     * @return array<string, mixed>
     */
    private function payloadJangkaSorong(Equipment $alat, float $kapasitas): array
    {
        $f = self::fixture('jangka-sorong');
        $profil = new JangkaSorongProfile;
        $baris = [];

        foreach (['outside', 'inside'] as $grup) {
            $praCetak = $profil->barisPraCetak($grup);

            foreach ($f[$grup] as $t) {
                foreach ($praCetak as $i => $p) {
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

        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => $f['_sesi']['tanggal'],
            'suhu_awal' => $f['_sesi']['suhu_awal'], 'suhu_akhir' => $f['_sesi']['suhu_akhir'],
            'kelembaban_awal' => 44, 'kelembaban_akhir' => 55,
            'measurements' => $measurements,
            'spesifikasi_alat' => [
                'jangka_sorong' => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => $kapasitas,
                    'resolusi_mm' => $f['_sesi']['resolusi_mm'],
                    'kerataan_muka_ukur' => 'baik',
                    'pra_evaluasi_outside' => ['baris' => [['titik_ukur' => null, 'pembacaan' => $f['pra_evaluasi_outside_mm']]]],
                    'pra_evaluasi_inside' => ['baris' => [['titik_ukur' => null, 'pembacaan' => $f['pra_evaluasi_inside_mm']]]],
                ],
            ],
        ];
    }

    public function test_jangka_sorong_tiga_tabel_dari_hp_terhitung_dan_hitung_ulang_konsisten(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'JS-042')->firstOrFail();

        $id = $this->actingAs($this->teknisi())
            ->postJson('/api/calibrations', $this->payloadJangkaSorong($alat, 600.0))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $titikKe = $hitungan->pluck('titik_ke');

        $this->assertTrue($titikKe->contains(fn ($t) => $t >= 1 && $t < 100), 'tabel Outside hilang');
        $this->assertTrue($titikKe->contains(fn ($t) => $t > 100 && $t < 200), 'tabel Inside hilang');
        $this->assertTrue($titikKe->contains(fn ($t) => $t > 200), 'tabel Depth hilang');

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])->assertSuccessful();

        $sesudah = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get()->keyBy('titik_ke');

        foreach ($hitungan as $h) {
            $this->assertEqualsWithDelta((float) $h->koreksi, (float) $sesudah[$h->titik_ke]->koreksi, 1e-8, "koreksi titik {$h->titik_ke}");
            $this->assertEqualsWithDelta(
                (float) $h->ketidakpastian_diperluas,
                (float) $sesudah[$h->titik_ke]->ketidakpastian_diperluas,
                1e-8,
                "U95 titik {$h->titik_ke}",
            );
        }
    }

    public function test_jangka_sorong_di_atas_300_mm_terbit_tanpa_klaim_akreditasi(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'JS-042')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($alat);

        $besar = CalibrationSession::findOrFail($this->actingAs($this->teknisi())
            ->postJson('/api/calibrations', $this->payloadJangkaSorong($alat, 600.0))
            ->assertCreated()->json('data.id'));
        $kecil = CalibrationSession::findOrFail($this->actingAs($this->teknisi())
            ->postJson('/api/calibrations', $this->payloadJangkaSorong($alat, 300.0))
            ->assertCreated()->json('data.id'));

        $this->assertFalse($profil->dalamLingkupAkreditasiSesi($besar), '600 mm di luar lampiran 0-300 mm');
        $this->assertTrue($profil->dalamLingkupAkreditasiSesi($kecil));
        $this->assertGreaterThan(0, $besar->uncertaintyCalculations()->count());

        // ≤ 300 mm: lantai CMC 0,015 mm berlaku di setiap titik.
        foreach ($kecil->uncertaintyCalculations as $h) {
            $this->assertGreaterThanOrEqual(0.015 - 1e-9, (float) $h->ketidakpastian_diperluas);
        }
    }

    /** @return array<string, mixed> */
    private function payloadSieve(Equipment $alat, ?int $jumlahOpening = null): array
    {
        $f = self::fixture('sieve');
        $m = $f['_sesi'];
        $opening = $jumlahOpening === null ? $f['opening'] : array_slice($f['opening'], 0, $jumlahOpening);

        $baris = array_map(static fn (array $o): array => [
            'titik_ukur' => $o['no'],
            'warp' => [$o['warp']],
            'weft' => [$o['weft']],
            'kawat' => [$o['kawat']],
        ], $opening);
        // Satu nilai dikirim sebagai TEKS berkoma — bentuk terburuk dari keyboard HP.
        $baris[0]['warp'] = [str_replace('.', ',', (string) $opening[0]['warp'])];

        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => $m['tanggal'],
            'suhu_awal' => $m['suhu_awal'], 'suhu_akhir' => $m['suhu_akhir'],
            'kelembaban_awal' => $m['rh_awal'], 'kelembaban_akhir' => $m['rh_akhir'],
            'measurements' => [],
            'spesifikasi_alat' => [
                'sieve' => [
                    'tipe' => $m['tipe'],
                    'satuan' => $m['satuan'],
                    'nominal' => $m['nominal'],
                    'standar_dipakai' => $m['standar_dipakai'],
                    'opening' => ['baris' => $baris],
                ],
            ],
        ];
    }

    public function test_sieve_dari_hp_tiga_grup_terhitung_koma_diterima_dan_hitung_ulang_konsisten(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'SK 19')->firstOrFail();

        $id = $this->actingAs($this->teknisi())
            ->postJson('/api/calibrations', $this->payloadSieve($alat))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertSame([1, 2, 3], $hitungan->pluck('titik_ke')->all());

        $warp1 = $sesi->rawMeasurements()->where('peran_sensor', 'sieve_warp')->where('sensor_ke', 1)->firstOrFail();
        $this->assertEqualsWithDelta(self::fixture('sieve')['opening'][0]['warp'], (float) $warp1->pembacaan, 1e-12);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])->assertSuccessful();

        foreach ($sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get() as $i => $h) {
            $this->assertEqualsWithDelta((float) $hitungan[$i]->ketidakpastian_diperluas, (float) $h->ketidakpastian_diperluas, 1e-8);
            $this->assertSame($hitungan[$i]->keputusan, $h->keputusan);
        }
    }

    public function test_sieve_opening_di_bawah_minimum_tidak_terbit_dan_alasannya_kebaca(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'SK 19')->firstOrFail();
        $payload = $this->payloadSieve($alat, 8);

        $alasan = collect(
            $this->actingAs($this->teknisi())->postJson('/api/calibrations/preview', $payload)
                ->assertSuccessful()->json('data.belum_dihitung') ?? []
        )->pluck('alasan')->implode(' ');

        $this->assertStringContainsString('minimum', strtolower($alasan));

        $id = $this->actingAs($this->teknisi())->postJson('/api/calibrations', $payload)->assertCreated()->json('data.id');
        $this->assertSame(0, CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count());
    }
}
