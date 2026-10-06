<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Support\PistonVolumeMentah as M;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur HP → server keluarga PISTON VOLUME: M0..M10 tersimpan berurutan, koma
 * desimal, penjaga yang MEMBLOKIR, angka tersimpan = master Fixed.
 */
class PistonSesiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Equipment, 1: User, 2: array<string, mixed>} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);
        $sesi = json_decode((string) file_get_contents(database_path('data/sesi-master-piston-volume.json')), true)['sesi']['fixed'];

        return [
            Equipment::where('serial_number', 'DEMO-PP-FIX-001')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
            $sesi,
        ];
    }

    /**
     * @param  array<string, mixed>  $sesi
     * @param  array<string, mixed>  $gantiBlok
     * @param  list<array<string, mixed>>|null  $titik
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $sesi, array $gantiBlok = [], ?array $titik = null, array $ganti = []): array
    {
        $m = $sesi['masukan'];
        // Koma desimal persis ketikan HP.
        $koma = static fn (float $v): string => str_replace('.', ',', (string) $v);

        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-02-13',
            'suhu_awal' => $koma($m['suhu_ruang'][0]), 'suhu_akhir' => $m['suhu_ruang'][1],
            'kelembaban_awal' => $m['kelembaban'][0], 'kelembaban_akhir' => $m['kelembaban'][1],
            'tekanan_awal' => $koma($m['tekanan_udara'][0]), 'tekanan_akhir' => $m['tekanan_udara'][1],
            'measurements' => $titik ?? [[
                'titik_ukur' => '10',
                M::PERAN_KUMULATIF => array_map($koma, $m['titik'][0]['kumulatif']),
                M::PERAN_SUHU_AIR => array_map($koma, $m['titik'][0]['suhu_air']),
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'keluarga' => 'fixed',
                'satuan' => 'ml',
                'kapasitas' => '10',
                'timbangan' => $m['timbangan'],
                ...$gantiBlok,
            ]],
            ...$ganti,
        ];
    }

    public function test_m0_sampai_m10_tersimpan_berurutan_dengan_koma(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $sesi))
            ->assertCreated()
            ->json('data.id');

        $kum = RawMeasurement::where('calibration_session_id', $id)
            ->where('peran_sensor', M::PERAN_KUMULATIF)
            ->orderBy('sensor_ke')
            ->get();

        $this->assertSame(range(0, 10), $kum->pluck('sensor_ke')->map(fn ($v): int => (int) $v)->all(),
            'sensor_ke kumulatif harus 0..10 (= M0..M10) — M0 tersimpan sebagai 1 menggeser semua selisih.');
        $this->assertEqualsWithDelta(10.0768, (float) $kum[1]->pembacaan, 1e-12, '"10,0768" bukan 100768 / 10.');
    }

    public function test_u95_tersimpan_sama_dengan_master_fixed(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, $sesi))
            ->assertCreated()
            ->json('data.id');

        $h = $sesi['harapan_benar'];
        $b = UncertaintyCalculation::where('calibration_session_id', $id)->firstOrFail();

        $this->assertEqualsWithDelta($h['u95'], $b->type_b_components['piston_budget']['u95'], 1e-12);
        $this->assertEqualsWithDelta($h['k'], $b->type_b_components['piston_budget']['k'], 1e-12);
        $this->assertEqualsWithDelta($h['per_titik'][0]['V20'], $b->type_b_components['piston_rantai']['V20_ml'], 1e-12);
        $this->assertEqualsWithDelta($h['per_titik'][0]['V20'], (float) $b->rata_rata, 1e-8, 'Actual Volume');
        $this->assertNull($b->keputusan, 'Master Fixed tidak memvonis (G-5).');
    }

    /**
     * Dropdown "Timbangan" dicabut (UI dobel dengan centang Standard Used,
     * laporan lapangan 5 Okt 2026). Timbangan lahir dari baris yang dicentang,
     * dan angkanya tetap sama dengan master Fixed.
     */
    public function test_timbangan_lahir_dari_centang_angka_tetap_master(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();
        $payload = $this->payload($alat, $sesi);
        unset($payload['spesifikasi_alat'][M::KUNCI_SESI]['timbangan']);
        $neraca = Standard::where('organization_id', $alat->organization_id)
            ->where('serial_number', '1129063525')->firstOrFail();
        $termometer = Standard::where('organization_id', $alat->organization_id)
            ->where('serial_number', 'SH1/20')->first();
        $payload['standar_dicek'] = array_values(array_filter([
            ['standard_id' => $neraca->id, 'dipakai' => true],
            // Standar non-timbangan ikut tercentang: tidak boleh mengganggu.
            $termometer ? ['standard_id' => $termometer->id, 'dipakai' => true] : null,
        ]));

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            $sesi['masukan']['timbangan'],
            CalibrationSession::findOrFail($id)->spesifikasi_alat[M::KUNCI_SESI]['timbangan'],
        );
        $b = UncertaintyCalculation::where('calibration_session_id', $id)->firstOrFail();
        $this->assertEqualsWithDelta($sesi['harapan_benar']['u95'], $b->type_b_components['piston_budget']['u95'], 1e-12);

        $bentuk = $this->actingAs($teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data.bagian');
        $kode = collect($bentuk)->flatMap(fn (array $b): array => array_column($b['field'] ?? [], 'kode'))->all();
        $this->assertNotEmpty($kode);
        $this->assertNotContains('spesifikasi_alat.'.M::KUNCI_SESI.'.timbangan', $kode, 'Dropdown timbangan dobel muncul lagi.');
    }

    public function test_dua_timbangan_dicentang_kiriman_ditolak(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();
        $payload = $this->payload($alat, $sesi);
        unset($payload['spesifikasi_alat'][M::KUNCI_SESI]['timbangan']);
        $payload['standar_dicek'] = Standard::where('organization_id', $alat->organization_id)
            ->whereIn('serial_number', ['1129063525', 'HSEX1403752'])
            ->pluck('id')
            ->map(fn (int $id): array => ['standard_id' => $id, 'dipakai' => true])
            ->all();
        $this->assertCount(2, $payload['standar_dicek']);

        $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('standar_dicek');
    }

    public function test_kumulatif_turun_diblokir(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();
        $kum = $sesi['masukan']['titik'][0]['kumulatif'];
        $kum[5] = $kum[4] - 0.001;

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, $sesi, titik: [[
                'titik_ukur' => '10', M::PERAN_KUMULATIF => $kum, M::PERAN_SUHU_AIR => $sesi['masukan']['titik'][0]['suhu_air'],
            ]]))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('lebih kecil dari M4', $belum[0]['alasan']);
    }

    public function test_tekanan_udara_kosong_diblokir(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, $sesi, ganti: ['tekanan_awal' => null]))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('tekanan_awal', $belum[0]['alasan']);
    }

    public function test_kurang_dari_sebelas_kumulatif_diblokir(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();
        $kum = array_slice($sesi['masukan']['titik'][0]['kumulatif'], 0, 10);

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, $sesi, titik: [[
                'titik_ukur' => '10', M::PERAN_KUMULATIF => $kum, M::PERAN_SUHU_AIR => $sesi['masukan']['titik'][0]['suhu_air'],
            ]]))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('TEPAT 11', $belum[0]['alasan']);
    }

    /**
     * Errata E-5: kapasitas di luar SEMUA pita CMC lampiran ditolak. Kalau
     * dihitung, U95-nya lahir tanpa lantai CMC — angka di luar ruang lingkup
     * akreditasi yang tetap tercetak dengan logo KAN.
     */
    public function test_kapasitas_di_luar_semua_pita_cmc_diblokir(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();

        // Pita CMC Piston Pipette terbesar 10 ml.
        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, $sesi, ['kapasitas' => '20']))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('di luar semua pita CMC', $belum[0]['alasan']);
        $this->assertStringContainsString('10 ml', $belum[0]['alasan']);
    }

    public function test_kapasitas_mikroliter_dikonversi_sebelum_memilih_pita(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();

        // 10000 µl = 10 ml — masih di pita terbesar, tidak boleh ditolak.
        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, $sesi, ['satuan' => 'µl', 'kapasitas' => '10000']))
            ->assertOk()
            ->json('data.belum_dihitung');

        foreach ($belum as $b) {
            $this->assertStringNotContainsString('pita CMC', $b['alasan']);
        }
    }

    public function test_graduated_wajib_tiga_titik(): void
    {
        [$alat, $teknisi, $sesi] = $this->siapkan();

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payload($alat, $sesi, ['keluarga' => 'graduated']))
            ->assertOk()
            ->json('data.belum_dihitung');

        $this->assertNotEmpty($belum);
        $this->assertStringContainsString('TIGA titik', $belum[0]['alasan']);
    }

    public function test_sesi_contoh_buret_digital_menang_cmc_tanpa_vonis_terbit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = Equipment::where('serial_number', 'DEMO-BD-GRD-001')->firstOrFail();
        $sesiId = CalibrationSession::where('equipment_id', $alat->id)->value('id');
        $baris = UncertaintyCalculation::where('calibration_session_id', $sesiId)->orderBy('titik_ke')->get();

        $this->assertCount(3, $baris, 'MIN, MID, MAX');

        foreach ($baris as $b) {
            $this->assertEqualsWithDelta(0.023, (float) $b->ketidakpastian_diperluas, 1e-12, 'CMC 10–50 ml menang');
        }

        // Vonis TIDAK terbit (aturan keputusan menunggu V-6). MPE buret
        // hand-driven ada untuk 2 & 10 ml, TIDAK untuk 4 ml — titik MID tanpa
        // vonis usulan dan alasannya terbaca, bukan `#N/A` (G-4).
        foreach ($baris as $b) {
            $this->assertNull($b->keputusan);
            $this->assertNull($b->toleransi);
            $this->assertFalse($b->type_b_components['kesesuaian']['diterbitkan']);
        }
        $this->assertNotNull($baris[0]->type_b_components['kesesuaian']['mpe']);
        $this->assertNull($baris[1]->type_b_components['kesesuaian']['mpe']);
        $this->assertStringContainsString('MPE tidak tersedia', $baris[1]->type_b_components['kesesuaian']['alasan_tanpa_vonis']);
        $this->assertNotNull($baris[2]->type_b_components['kesesuaian']['vonis_usulan_guarded']);
    }
}
