<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\BuretProfile;
use App\Services\Calibration\Profiles\GelasUkurProfile;
use App\Services\Calibration\Profiles\LabuUkurProfile;
use App\Services\Calibration\Profiles\PicnometerProfile;
use App\Services\Calibration\Profiles\PipetUkurProfile;
use App\Services\Calibration\Profiles\PipetVolumeProfile;
use App\Services\Calibration\Profiles\VolumetricGlasswareProfile;
use App\Services\CalibrationValidator;
use App\Support\VolumetricGlasswareMentah as M;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §49 tahap 2 — enam bacaan suhu air Labu Ukur & Pipet Volume dari payload HP
 * sampai `raw_measurements`, `uncertainty_calculations`, `CalibrationValidator`,
 * dan `kalibrasi:hitung-ulang` — ketiganya lewat `VolumetricGlasswareMentah`
 * dan `hitungPerGrup()` yang sama.
 *
 * Rekonsiliasi angka ke replika workbook ada di `tests/Unit/VolumetrikEnamSuhuAirTest.php`;
 * yang dijaga di sini: jalurnya utuh, payload tiga bacaan tetap sama, enam
 * kembar = tiga bacaan, dan panjang yang salah ditolak 422.
 */
class VolumetrikEnamSuhuAirTest extends TestCase
{
    use RefreshDatabase;

    /** LU-250-1: `INPUT DATA!H33:L34`, lingkungan `E22:F25` (VolumetrikRev7WorkbookTest). */
    private const ISI_LU250 = [248.823, 248.833, 248.831];

    /** Awal ≠ akhir; akhir X3 (`M39`) sengaja yang tertinggi — `O35` melewatkannya. */
    private const SUHU_ENAM = [25.2, 25.3, 25.3, 25.4, 25.2, 26.0];

    /** @return array{Equipment, User} */
    private function siapkan(string $namaAlat, string $namaAlatKemampuan, array $pita, array $rentang): array
    {
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::factory()->create([
            'organization_id' => $org->id, 'kode' => 'volume', 'nama' => 'Volume',
        ]);

        foreach ($pita as [$maks, $cmc]) {
            CalibrationCapability::factory()->create([
                'organization_id' => $org->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $namaAlatKemampuan,
                'range_min' => null,
                'range_max' => $maks,
                'satuan' => 'mL',
                'ketidakpastian_terbaik' => $cmc,
                'satuan_ketidakpastian' => 'mL',
                'faktor_cakupan' => 2,
                'metode' => 'SIDIK-IK-CAL-0510_Rev.6',
            ]);
        }

        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $namaAlat,
            'nama_alat_kemampuan' => $namaAlatKemampuan,
            'satuan' => 'ml',
            'range_min' => $rentang[0],
            'range_max' => $rentang[1],
            'resolusi' => $rentang[2],
        ]);

        Standard::factory()->create([
            'organization_id' => $org->id,
            'nama' => 'Analytical Balance',
            'satuan_ketidakpastian' => 'g',
        ]);

        return [$alat, User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ])];
    }

    /** @return array{Equipment, User} */
    private function labuUkur(): array
    {
        return $this->siapkan('Labu Ukur 250 mL', 'Labu Ukur', [[200, 0.044], [250, 0.044], [500, 0.077]], [0, 250, null]);
    }

    /**
     * @param  list<float|null>  $suhu
     * @return array<string, mixed>
     */
    private function payloadLabu(Equipment $alat, array $suhu, array $isi = self::ISI_LU250, float $nominal = 250): array
    {
        return [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::where('organization_id', $alat->organization_id)->value('id'),
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-06',
            'suhu_awal' => 20.9, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 51, 'kelembaban_akhir' => 50,
            'tekanan_awal' => 1001.2, 'tekanan_akhir' => 1001.5,
            'measurements' => [[
                'titik_ukur' => $nominal,
                M::PERAN_KOSONG => [0, 0, 0],
                M::PERAN_ISI => $isi,
                M::PERAN_SUHU => $suhu,
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'A', 'toleransi_ml' => 0.15, 'kapasitas_ml' => $nominal,
                'neraca' => 'Electronic Balance Fujitsu',
            ]],
        ];
    }

    private function simpan(User $teknisi, array $payload): CalibrationSession
    {
        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    public function test_enam_suhu_tersimpan_urut_lalu_validator_dan_hitung_ulang_konsisten(): void
    {
        [$alat, $teknisi] = $this->labuUkur();
        $payload = $this->payloadLabu($alat, self::SUHU_ENAM);

        // Preview = simpan: satu fungsi penyusun (`susunPengukuran()`).
        $preview = $this->actingAs($teknisi)->postJson('/api/calibrations/preview', $payload)->assertOk();
        $this->assertSame([], $preview->json('data.belum_dihitung'));
        $this->assertCount(1, $preview->json('data.titik'));

        $sesi = $this->simpan($teknisi, $payload);

        $suhu = RawMeasurement::where('calibration_session_id', $sesi->id)
            ->where('peran_sensor', M::PERAN_SUHU)->orderBy('sensor_ke')->get();
        $this->assertSame([1, 2, 3, 4, 5, 6], $suhu->pluck('sensor_ke')->map(static fn ($k): int => (int) $k)->all());
        $this->assertEqualsWithDelta(self::SUHU_ENAM, $suhu->pluck('pembacaan')->map(static fn ($p): float => (float) $p)->all(), 1e-12);
        $this->assertSame(3, RawMeasurement::where('calibration_session_id', $sesi->id)->where('peran_sensor', M::PERAN_ISI)->count());

        $t = $sesi->uncertaintyCalculations()->sole();
        $catatan = collect((array) $t->type_b_components)->keyBy('sumber');
        $this->assertArrayHasKey('volumetric_rev7_rentang_suhu_o35_tanpa_m39', $catatan->all());
        $this->assertStringContainsString('MAX(H39:L39)', $catatan['volumetric_rev7_rentang_suhu_o35_tanpa_m39']['keterangan']);
        $this->assertStringContainsString('awal & akhir X1–X3', $catatan['jejak_titik']['keterangan']);

        // Angka tersimpan = hitungan langsung profil dari baris mentah yang sama.
        $profil = new LabuUkurProfile;
        $ulang = $profil->hitungPerGrup([[
            'titik_ke' => 1,
            'titik_ukur' => 250.0,
            'pembacaan' => [],
            'standard' => null,
            'konteks' => [
                ...M::dari($sesi->rawMeasurements()->get()),
                'spesifikasi_alat' => $sesi->spesifikasi_alat,
                'suhu_awal' => 20.9, 'suhu_akhir' => 20.6,
                'kelembaban_awal' => 51, 'kelembaban_akhir' => 50,
                'tekanan_awal' => 1001.2, 'tekanan_akhir' => 1001.5,
            ],
        ]], $alat)['hitungan'][0];
        $this->assertEqualsWithDelta($ulang['rata_rata'], (float) $t->rata_rata, 1e-8);
        $this->assertEqualsWithDelta($ulang['ketidakpastian_diperluas'], (float) $t->ketidakpastian_diperluas, 1e-8);
        $this->assertEqualsWithDelta($ulang['ketidakpastian_gabungan'], (float) $t->ketidakpastian_gabungan, 1e-8);

        // Validator menghitung ulang dari keenam baris mentah — tidak ada selisih.
        $temuan = app(CalibrationValidator::class)->periksa($sesi)['temuan'];
        $kode = array_column($temuan, 'kode');
        $pesan = implode(' | ', array_column($temuan, 'pesan'));
        $this->assertNotContains('hitung_ulang_gagal', $kode, $pesan);
        $this->assertNotContains('hitung_ulang_beda', $kode, $pesan);
        $this->assertNotContains('volumetric_titik_belum_dihitung', $kode, $pesan);
        $this->assertNotContains('pembacaan_di_luar_rentang', $kode, $pesan);

        // Perintah hitung ulang: tidak dilewati, tidak ada angka yang berubah.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->id], '--dry-run' => true])
            ->doesntExpectOutputToContain('dilewat')
            ->doesntExpectOutputToContain('→')
            ->assertSuccessful();
    }

    /** Enam kembar dari HP = tiga bacaan, sampai kolom tersimpan. */
    public function test_enam_kembar_lewat_hp_sama_dengan_tiga_bacaan(): void
    {
        [$alat, $teknisi] = $this->labuUkur();
        $sesiTiga = $this->simpan($teknisi, $this->payloadLabu($alat, [25.2, 25.3, 25.2]));
        $sesiEnam = $this->simpan($teknisi, $this->payloadLabu($alat, [25.2, 25.2, 25.3, 25.3, 25.2, 25.2]));
        $tiga = $sesiTiga->uncertaintyCalculations()->sole();
        $enam = $sesiEnam->uncertaintyCalculations()->sole();

        // Validator tidak memunculkan temuan baru hanya karena barisnya enam
        // (mis. pemeriksaan jumlah pembacaan) — kode temuan kedua sesi sama.
        $kodeTemuan = static fn (CalibrationSession $s): array => collect(app(CalibrationValidator::class)->periksa($s)['temuan'])
            ->pluck('kode')->unique()->sort()->values()->all();
        $this->assertSame($kodeTemuan($sesiTiga), $kodeTemuan($sesiEnam));

        foreach ([
            'rata_rata', 'error', 'koreksi', 'standar_deviasi', 'type_a', 'type_b', 'ketidakpastian_gabungan',
            'faktor_cakupan_k', 'derajat_kebebasan_efektif', 'ketidakpastian_diperluas',
        ] as $kolom) {
            $this->assertSame((string) $tiga->{$kolom}, (string) $enam->{$kolom}, $kolom);
        }

        // Cache workbook LU-250 `PERHITUNGAN!H60` (sakelar `master`).
        $this->assertEqualsWithDelta(249.85825034623147, (float) $enam->rata_rata, 1e-8);

        $sumberTiga = array_column((array) $tiga->type_b_components, 'sumber');
        $this->assertNotContains('volumetric_rev7_rentang_suhu_o35_tanpa_m39', $sumberTiga, 'catatan tiga bacaan tidak berubah');
        $this->assertContains('volumetric_rev7_rentang_suhu_o35_tanpa_m39', array_column((array) $enam->type_b_components, 'sumber'));
    }

    public function test_pipet_volume_menerima_enam_suhu(): void
    {
        [$alat, $teknisi] = $this->siapkan('Pipet Volume 2 mL', 'Pipet Volume', [[0.5, 0.002], [2, 0.0034], [3, 0.0034]], [0, 2, null]);
        $payload = $this->payloadLabu($alat, self::SUHU_ENAM, [1.9816, 1.9827, 1.9815], 2);
        $payload['spesifikasi_alat'][M::KUNCI_SESI] = [
            'kelas' => 'A', 'toleransi_ml' => 0.01, 'kapasitas_ml' => 2, 'neraca' => 'Analytical Balance',
        ];

        $sesi = $this->simpan($teknisi, $payload);

        $this->assertSame(6, RawMeasurement::where('calibration_session_id', $sesi->id)->where('peran_sensor', M::PERAN_SUHU)->count());
        $this->assertSame(1, $sesi->uncertaintyCalculations()->count());
    }

    public function test_labu_ukur_panjang_suhu_selain_tiga_atau_enam_ditolak_422(): void
    {
        [$alat, $teknisi] = $this->labuUkur();

        foreach ([[25.2, 25.3], [25.2, 25.3, 25.2, 25.3], [25.2, 25.3, 25.2, 25.3, 25.2], array_fill(0, 7, 25.2)] as $suhu) {
            $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $this->payloadLabu($alat, $suhu))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['measurements.0.vol_suhu'])
                ->assertJsonPath('errors', fn (array $e): bool => str_contains(
                    $e['measurements.0.vol_suhu'][0], 'tiga (satu per ulangan) atau enam (awal & akhir tiap ulangan',
                ));
        }

        $this->assertSame(0, CalibrationSession::count());
    }

    /** Profil Rev.6 (keluarga Fixed & Graduated) tetap tepat tiga — enam ditolak. */
    public function test_profil_lain_menolak_enam_suhu(): void
    {
        foreach ([
            ['Picnometer 25 mL', 'Picnometer', [[25, 0.002]], [0, 25, null]],
            ['Gelas Ukur 100 mL', 'Gelas Ukur', [[100, 0.34]], [0, 100, 1]],
        ] as [$nama, $kemampuan, $pita, $rentang]) {
            [$alat, $teknisi] = $this->siapkan($nama, $kemampuan, $pita, $rentang);

            $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $this->payloadLabu($alat, self::SUHU_ENAM))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['measurements.0.vol_suhu']);
        }

        $this->assertSame(0, CalibrationSession::count());
    }

    /**
     * Enam kotak yang terisi SEBAGIAN tidak dirapatkan jadi "tiga bacaan":
     * [X1 awal, X1 akhir, X2 awal] bukan tiga ulangan.
     */
    public function test_enam_kotak_terisi_sebagian_tidak_disimpan(): void
    {
        [$alat, $teknisi] = $this->labuUkur();

        $balasan = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payloadLabu($alat, [25.2, 25.3, 25.3, null, null, null]))
            ->assertSuccessful();

        $sesi = CalibrationSession::findOrFail($balasan->json('data.id'));
        $this->assertSame(0, RawMeasurement::where('calibration_session_id', $sesi->id)->count());
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count());

        $alasan = implode(' | ', array_column($balasan->json('meta.belum_dihitung'), 'alasan'));
        $this->assertStringContainsString('3 atau 6', $alasan);
        $this->assertStringContainsString('semua kotak terisi', $alasan);
    }

    /** Bentuk lembar: enam kotak berlabel hanya di tabel suhu Labu Ukur & PV. */
    public function test_bentuk_lembar_enam_kotak_suhu_berlabel(): void
    {
        $tabelHasil = static function (VolumetricGlasswareProfile $p): array {
            $bagian = collect($p->bentukLembarKerja()['bagian'])->firstWhere('kode', 'hasil');

            return collect($bagian['tabel'])->keyBy('grup')->all();
        };

        foreach ([new LabuUkurProfile, new PipetVolumeProfile] as $p) {
            $tabel = $tabelHasil($p);
            $this->assertSame([1, 2, 3, 4, 5, 6], $tabel[M::PERAN_SUHU]['pengulangan'], $p::class);
            $this->assertSame([
                ['ke' => 1, 'label' => 'X1 Awal'], ['ke' => 2, 'label' => 'X1 Akhir'],
                ['ke' => 3, 'label' => 'X2 Awal'], ['ke' => 4, 'label' => 'X2 Akhir'],
                ['ke' => 5, 'label' => 'X3 Awal'], ['ke' => 6, 'label' => 'X3 Akhir'],
            ], $tabel[M::PERAN_SUHU]['pengulangan_arah']);
            $this->assertSame('measurements[].'.M::PERAN_SUHU, $tabel[M::PERAN_SUHU]['simpan_ke']);
            $this->assertSame(3000, $tabel[M::PERAN_SUHU]['offset_kunci']);
            // Berat tetap tiga ulangan.
            $this->assertSame([1, 2, 3], $tabel[M::PERAN_KOSONG]['pengulangan']);
            $this->assertSame([1, 2, 3], $tabel[M::PERAN_ISI]['pengulangan']);
            $this->assertStringContainsString('enam kali suhu air', $p->bentukLembarKerja()['catatan_pengisian']);
        }

        foreach ([new PicnometerProfile, new BuretProfile, new GelasUkurProfile, new PipetUkurProfile] as $p) {
            $tabel = $tabelHasil($p);
            $this->assertSame([1, 2, 3], $tabel[M::PERAN_SUHU]['pengulangan'], $p::class);
            $this->assertArrayNotHasKey('pengulangan_arah', $tabel[M::PERAN_SUHU], $p::class);
            $this->assertStringContainsString('tiga kali suhu air', $p->bentukLembarKerja()['catatan_pengisian']);
        }
    }
}
