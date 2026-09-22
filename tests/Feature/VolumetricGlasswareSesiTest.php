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
use App\Services\CalibrationValidator;
use App\Support\VolumetricGlasswareMentah as M;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Volumetric Glassware**, kedua keluarga: payload HP →
 * `raw_measurements` → `uncertainty_calculations` → validator & hitung ulang.
 *
 * Masukannya data mentah `INPUT_DATA` kedua workbook master, dan angka yang
 * harus keluar adalah yang TERCETAK di sheet `SERTIFIKAT` master — V20,
 * Correction (V20 − Nominal), dan U95% sesudah lantai CMC.
 *
 * Ditulis bareng profilnya: pola "satu titik bukan satu deret datar" sudah
 * menggigit enam belas kali, selalu tanpa error.
 */
class VolumetricGlasswareSesiTest extends TestCase
{
    use RefreshDatabase;

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

    /** Fixed — Pipet Volume 1 mL, Class B ± 0,008 (`Fixed_…/INPUT_DATA`). */
    private function fixed(): array
    {
        [$alat, $teknisi] = $this->siapkan('Pipet Volume 1 mL', 'Pipet Volume', [[0.5, 0.002], [1, 0.003], [2, 0.0034]], [0, 1, null]);

        return [$alat, $teknisi, [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::where('organization_id', $alat->organization_id)->value('id'),
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2022-07-11',
            'suhu_awal' => 21, 'suhu_akhir' => 20.5,
            'kelembaban_awal' => 48, 'kelembaban_akhir' => 47,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            'measurements' => [[
                'titik_ukur' => 1,
                M::PERAN_KOSONG => [0, 0, 0],
                M::PERAN_ISI => [0.9998, 0.9997, 0.9996],
                M::PERAN_SUHU => [27, 27, 27],
            ]],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'B', 'toleransi_ml' => 0.008, 'kapasitas_ml' => 1,
                'neraca' => 'Analytical Balance',
            ]],
        ]];
    }

    /** Graduated — Gelas Ukur 100 mL, titik 10/50/100 (`Graduated_…/INPUT_DATA`). */
    private function graduated(): array
    {
        [$alat, $teknisi] = $this->siapkan('Gelas Ukur 100 mL', 'Gelas Ukur', [[10, 0.067], [50, 0.17], [100, 0.34]], [0, 100, 1]);

        return [$alat, $teknisi, [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::where('organization_id', $alat->organization_id)->value('id'),
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2022-07-11',
            'suhu_awal' => 20.4, 'suhu_akhir' => 20.5,
            'kelembaban_awal' => 64, 'kelembaban_akhir' => 62,
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            'measurements' => [
                ['titik_ukur' => 10, M::PERAN_KOSONG => [60.234, 60.24, 60.243],
                    M::PERAN_ISI => [70.7791, 70.7965, 70.8854], M::PERAN_SUHU => [25.4, 25.3, 25.4]],
                ['titik_ukur' => 50, M::PERAN_KOSONG => [60.255, 60.258, 60.263],
                    M::PERAN_ISI => [110.944, 110.9023, 111.0863], M::PERAN_SUHU => [25.4, 25.3, 25.5]],
                ['titik_ukur' => 100, M::PERAN_KOSONG => [60.241, 60.247, 60.25],
                    M::PERAN_ISI => [159.4398, 159.5042, 159.4852], M::PERAN_SUHU => [25.3, 25.2, 25.4]],
            ],
            'spesifikasi_alat' => [M::KUNCI_SESI => [
                'kelas' => 'B', 'toleransi_ml' => 0.5, 'resolusi_ml' => 1, 'kapasitas_ml' => 100,
                'neraca' => 'Electronic Balance Precisa',
            ]],
        ]];
    }

    private function simpan(User $teknisi, array $payload): CalibrationSession
    {
        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    public function test_fixed_tersimpan_tiga_peran_dan_angka_cetaknya_sama_dengan_master(): void
    {
        [, $teknisi, $payload] = $this->fixed();
        $sesi = $this->simpan($teknisi, $payload);

        $peran = RawMeasurement::where('calibration_session_id', $sesi->id)
            ->pluck('peran_sensor')->countBy()->sortKeys()->all();
        $this->assertSame([M::PERAN_ISI => 3, M::PERAN_KOSONG => 3, M::PERAN_SUHU => 3], $peran);

        $t = $sesi->uncertaintyCalculations()->sole();

        // `SERTIFIKAT!N17` dan `S17` (= V20 − Nominal, dicetak lewat tanda −1).
        $this->assertEqualsWithDelta(1.0042575664928188, (float) $t->rata_rata, 1e-6);
        $this->assertEqualsWithDelta(0.004257566492818832, (float) $t->error, 1e-6);
        $this->assertEqualsWithDelta(-0.004257566492818832, (float) $t->koreksi, 1e-6);
        // `SERTIFIKAT!Q18` = 0,003 — lantai CMC Pipet Volume 1 mL.
        $this->assertEqualsWithDelta(0.003, (float) $t->ketidakpastian_diperluas, 1e-9);

        $sumber = array_column((array) $t->type_b_components, 'sumber');
        $this->assertContains('perbandingan_cmc', $sumber);
        $this->assertContains('volumetric_veff_dibagi_jumlah', $sumber);
    }

    public function test_graduated_satu_u95_dan_angka_cetaknya_sama_dengan_master(): void
    {
        [, $teknisi, $payload] = $this->graduated();
        $sesi = $this->simpan($teknisi, $payload);

        $titik = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(3, $titik);

        // `SERTIFIKAT!N17:S19`.
        $harap = [
            [10.62484944968544, 0.6248494496854402],
            [50.92790025937759, 0.9279002593775871],
            [99.63673552715461, -0.3632644728453869],
        ];
        foreach ($titik as $i => $t) {
            $this->assertEqualsWithDelta($harap[$i][0], (float) $t->rata_rata, 1e-6);
            $this->assertEqualsWithDelta($harap[$i][1], (float) $t->error, 1e-6);
            // Lantai CMC dari KAPASITAS (100 mL → 0,34), bukan dari nominal titik
            // (10 mL → 0,067). `SERTIFIKAT!Q22` = 0,34 untuk ketiga baris.
            $this->assertEqualsWithDelta(0.34, (float) $t->ketidakpastian_diperluas, 1e-9);
            $this->assertContains(
                'volumetric_keterulangan_tanpa_nol_hantu',
                array_column((array) $t->type_b_components, 'sumber'),
            );
        }
    }

    public function test_hitung_ulang_memberi_angka_yang_sama_dan_tanpa_peringatan_palsu(): void
    {
        foreach ([$this->fixed(), $this->graduated()] as [, $teknisi, $payload]) {
            $sesi = $this->simpan($teknisi, $payload);

            $temuan = app(CalibrationValidator::class)->periksa($sesi)['temuan'];
            $kode = array_column($temuan, 'kode');
            $pesan = implode(' | ', array_column($temuan, 'pesan'));

            $this->assertNotContains('hitung_ulang_gagal', $kode, $pesan);
            $this->assertNotContains('hitung_ulang_beda', $kode, $pesan);
            $this->assertNotContains('pembacaan_di_luar_rentang', $kode, 'gram & °C diadu ke rentang mL: '.$pesan);
        }
    }

    public function test_perintah_hitung_ulang_tidak_melewati_sesi_volumetric(): void
    {
        [, $teknisi, $payload] = $this->graduated();
        $sesi = $this->simpan($teknisi, $payload);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->id], '--dry-run' => true])
            ->doesntExpectOutputToContain('dilewat')
            ->assertSuccessful();
    }

    /**
     * Satu titik Graduated yang tabelnya tidak sinkron menahan SELURUH sesi:
     * budget-nya satu untuk semua titik, jadi menghitung dari titik sisanya
     * berarti U yang tercetak bergantung pada titik mana yang kebetulan benar.
     */
    public function test_graduated_tabel_tidak_sinkron_menahan_semua_titik(): void
    {
        [, $teknisi, $payload] = $this->graduated();
        $payload['measurements'][1][M::PERAN_SUHU] = [25.4, 25.3, null];

        $balasan = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)->assertSuccessful();
        $sesi = CalibrationSession::findOrFail($balasan->json('data.id'));

        $this->assertSame(0, RawMeasurement::where('calibration_session_id', $sesi->id)->where('titik_ke', 2)->count());
        // Titik 1 & 3 lengkap, tapi TIDAK terbit — alasannya pulang lewat
        // `belum_dihitung` jalur simpan (dijaga di `susunBlokVolumetric`).
        $this->assertSame(0, $sesi->uncertaintyCalculations()->count(), 'titik sehat Graduated ikut terbit dari budget separuh');
    }
}
