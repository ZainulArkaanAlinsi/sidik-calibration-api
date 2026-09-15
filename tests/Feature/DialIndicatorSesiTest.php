<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Support\DialIndicatorMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Dial Indicator**: payload HP → `raw_measurements` →
 * hitung ulang, dan angkanya sama di ketiga titik itu.
 *
 * Ditulis bareng profilnya — pola "alat baru tidak bisa dikirim dari HP"
 * (Anak Timbangan) dan "hitung ulang gagal" (dua belas alat) sama-sama tidak
 * pernah menerbitkan error. Seeder menulis ke DB langsung dan tidak pernah
 * memperlihatkan keduanya; test ini lewat API.
 */
class DialIndicatorSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /**
     * Tiga titik pertama sesi contoh master. Pembacaan dan tumpukan dikirim
     * sebagai TEKS berkoma desimal — bentuk terburuk yang bisa datang dari
     * keyboard HP Indonesia.
     *
     * @param  array<string, mixed>  $gantiBlok
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $gantiBlok = []): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-06',
            'suhu_awal' => '20,8', 'suhu_akhir' => 20.7,
            'kelembaban_awal' => 56, 'kelembaban_akhir' => 59,
            'measurements' => [
                ['titik_ukur' => null, 'nominal' => '1,1', 'pembacaan' => ['1,10', '1,10', 1.1, 1.1, 1.1, null]],
                ['titik_ukur' => 0, 'nominal' => [2.5], 'pembacaan' => [2.5, 2.5, 2.5, 2.5, 2.5]],
                ['titik_ukur' => null, 'nominal' => '2,5+1,3+1,2', 'pembacaan' => [5, 5, 5, 5, 5]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '0-25',
                DialIndicatorMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 25,
                    'resolusi_mm' => '0,01',
                    // Bentuk cerminan tabel `simpan_ke` dari HP.
                    'pra_evaluasi' => ['baris' => [['titik_ukur' => null, 'pembacaan' => array_fill(0, 10, 25.01)]]],
                    'balok_pra_evaluasi' => '14+11',
                    ...$gantiBlok,
                ],
            ],
        ];
    }

    /** @return array{Equipment, User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'DC-36')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    public function test_lembar_kerja_enam_penunjukan_dan_tumpukan_per_baris(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $bentuk = $this->actingAs($teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('SIDIK-FM-CAL-0526_Rev.3', $bentuk['kode_dokumen']);
        $tabel = collect($bentuk['bagian'])->firstWhere('kode', 'hasil')['tabel'][0];
        $this->assertCount(6, $tabel['pengulangan']);
        $this->assertSame('daftar_angka', $tabel['kolom_baris'][0]['tipe']);
        $this->assertTrue($tabel['titik_bisa_diubah']);
    }

    public function test_koma_desimal_dan_tumpukan_teks_tersimpan_sebagai_angka_yang_benar(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $titik1 = RawMeasurement::where('calibration_session_id', $id)->where('titik_ke', 1)->get();
        $pembacaan = $titik1->where('peran_sensor', DialIndicatorMentah::PERAN_PEMBACAAN);

        // "1,10" → 1.1, bukan 110 (koma dibaca ribuan) dan bukan 1 (terpotong).
        $this->assertCount(5, $pembacaan);
        foreach ($pembacaan as $b) {
            $this->assertEqualsWithDelta(1.1, (float) $b->pembacaan, 1e-12);
        }

        $keping = RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 3)
            ->where('peran_sensor', DialIndicatorMentah::PERAN_BALOK)
            ->orderBy('sensor_ke')
            ->pluck('pembacaan')
            ->map(static fn ($v): float => (float) $v)
            ->all();

        // "2,5+1,3+1,2" → tiga keping, BUKAN [2, 5, 1, 3, 1, 2].
        $this->assertEqualsWithDelta([2.5, 1.3, 1.2], $keping, 1e-12);

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertEqualsWithDelta(20.8, (float) $sesi->suhu_awal, 1e-9);
        // `assertEquals`: kolom JSON memulangkan 14 (int) untuk 14.0 — nilainya
        // yang dijaga, bukan tipe hasil `json_encode`.
        $this->assertEquals([14.0, 11.0], $sesi->spesifikasi_alat['dial_indicator']['balok_pra_evaluasi']);
    }

    public function test_koreksi_cocok_master_dan_hitung_ulang_konsisten(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(3, $hitungan);

        // `SERTIFIKAT!L18:L20` master.
        $master = [-0.0001100000000000545, 0.000140000000000029, 0.0003500000000000725];

        foreach ($hitungan as $i => $h) {
            $this->assertEqualsWithDelta($master[$i], (float) $h->koreksi, self::TOLERANSI, "koreksi titik {$h->titik_ke}");
            // Lantai CMC pita 0-25 mm = 0,0065 mm.
            $this->assertGreaterThanOrEqual(0.0065, (float) $h->ketidakpastian_diperluas);
        }

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])->assertSuccessful();

        foreach ($sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get() as $i => $h) {
            $this->assertEqualsWithDelta($master[$i], (float) $h->koreksi, self::TOLERANSI, 'hitung ulang menggeser koreksi');
            $this->assertEqualsWithDelta(
                (float) $hitungan[$i]->ketidakpastian_diperluas,
                (float) $h->ketidakpastian_diperluas,
                // Kolom `decimal(20,8)` — dua jalur boleh beda di digit ke-9.
                1e-8,
                'hitung ulang menggeser U95',
            );
        }
    }

    public function test_keping_tidak_terdaftar_ditolak_dengan_alasan_yang_menyebut_kepingnya(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][2]['nominal'] = '2,5+1,3+1,25';

        $alasan = collect(
            $this->actingAs($teknisi)
                ->postJson('/api/calibrations/preview', $payload)
                ->assertSuccessful()
                ->json('data.belum_dihitung') ?? []
        )->firstWhere('titik_ke', 3)['alasan'] ?? '';

        $this->assertStringContainsString('1,25', $alasan);

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)->assertCreated()->json('data.id');

        $this->assertSame(
            [1, 2],
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->orderBy('titik_ke')->pluck('titik_ke')->all(),
        );
    }

    public function test_blok_evaluation_kurang_atau_balok_kosong_tidak_menerbitkan_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        foreach ([['pra_evaluasi' => [25.01]], ['balok_pra_evaluasi' => ''], ['resolusi_mm' => null], ['kapasitas_mm' => 400]] as $ganti) {
            $id = $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $this->payload($alat, $ganti))
                ->assertCreated()
                ->json('data.id');

            $this->assertSame(
                0,
                CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
                'Budget tidak utuh harus menahan terbit: '.json_encode($ganti),
            );
        }
    }

    public function test_satuan_inch_dikonversi_saat_dihitung_bukan_saat_disimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat, [
            'satuan' => 'inch',
            'kapasitas_mm' => 1,
            'resolusi_mm' => 0.0005,
            'pra_evaluasi' => [0.9846, 0.9847, 0.9846, 0.9846, 0.9847, 0.9846, 0.9846, 0.9847, 0.9846, 0.9846],
        ]);
        // Keping 2,5 mm; dial inch membaca 0,0984 inch = 2,49936 mm.
        $payload['measurements'] = [['titik_ukur' => null, 'nominal' => [2.5], 'pembacaan' => [0.0984, 0.0984, 0.0984]]];

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)->assertCreated()->json('data.id');

        $mentah = RawMeasurement::where('calibration_session_id', $id)
            ->where('peran_sensor', DialIndicatorMentah::PERAN_PEMBACAAN)->first();
        $this->assertEqualsWithDelta(0.0984, (float) $mentah->pembacaan, 1e-12, 'yang disimpan angka MENTAH');
        $this->assertSame('inch', $mentah->satuan);

        $h = CalibrationSession::findOrFail($id)->uncertaintyCalculations()->firstOrFail();
        $this->assertEqualsWithDelta(2.50014 - 0.0984 * 25.4, (float) $h->koreksi, self::TOLERANSI);
    }
}
