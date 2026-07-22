<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
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
 * Alur kalibrasi pH LENGKAP lewat API (jalur yang dipakai mobile), reproduksi
 * sertifikat asli 012-CAL-524: budget ketidakpastian penuh + max(U,CMC),
 * before/after adjustment, kondisi lingkungan awal/akhir + U95%.
 *
 * Beda dari GumCalculatorTest (unit, panggil kalkulator langsung) & seeder
 * (bikin record histori): ini nembak endpoint POST /api/calibrations persis
 * kayak yang bakal dikirim frontend, terus ngecek angka tersimpannya bener.
 */
class PhCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Equipment $alat;

    private Standard $buffer4;

    private Standard $buffer7;

    private Standard $buffer10;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();

        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);

        // CMC + konstanta budget pH 4/7/10 (sama kayak PhMeterCapabilitySeeder).
        $uTemp = sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2);
        foreach ([
            [4, 0.023, 0.00077, 0.01, 1.0, 0.00003, -0.0023, 4.0455],
            [7, 0.021, 0.00352, 0.02, 0.00304, 0.00008, -0.0076, 7.1182],
            [10, 0.031, 0.01021, 0.05, 0.00949, 0.00009, -0.0148, 10.262],
        ] as [$titik, $cmc, $ciSuhu, $uBeda, $ciBeda, $ka, $kb, $kc]) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'pH Meter', 'parameter' => 'pH',
                'range_min' => $titik, 'range_max' => $titik, 'satuan' => 'pH',
                'ketidakpastian_terbaik' => $cmc, 'satuan_ketidakpastian' => 'pH', 'faktor_cakupan' => 2,
                'u_temperature' => $uTemp, 'ci_suhu' => $ciSuhu,
                'u_perbedaan_suhu' => $uBeda, 'ci_perbedaan_suhu' => $ciBeda,
                // Kurva nilai buffer vs suhu — ngidupin pemeriksaan
                // "titik_ukur udah dikoreksi suhu belum".
                'koef_suhu_a' => $ka, 'koef_suhu_b' => $kb, 'koef_suhu_c' => $kc,
            ]);
        }

        $this->alat = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'customer_id' => Customer::factory()->create()->id,
            'nama_alat' => 'pH Meter', 'nama_alat_kemampuan' => 'pH Meter',
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        $buffer = fn (string $nama, float $u) => Standard::factory()->create([
            'nama' => $nama, 'ketidakpastian' => $u, 'satuan_ketidakpastian' => 'pH',
            'faktor_cakupan' => 2, 'berlaku_sampai' => now()->addYear(),
        ]);
        $this->buffer4 = $buffer('pH Buffer Solution 4', 0.02);
        $this->buffer7 = $buffer('pH Buffer Solution 7', 0.02);
        $this->buffer10 = $buffer('pH Buffer Solution 10', 0.03);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->buffer7->id,
            'tanggal_kalibrasi' => '2024-05-26T00:00:00Z',
            // Kondisi lingkungan awal/akhir + U95% thermohygro (server yang hitung U95%).
            'suhu_ruang_awal' => 21.3, 'suhu_ruang_akhir' => 21.5,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 56,
            'suhu_ruang_koreksi' => -0.43, 'kelembaban_koreksi' => -2.55,
            'suhu_ruang_u_std' => 1.7, 'kelembaban_u_std' => 4.8,
            'thermohygro' => 'TH-3',
            'measurements' => [
                [
                    'titik_ukur' => 4.009244572, 'satuan' => 'pH', 'standard_id' => $this->buffer4->id,
                    'pembacaan' => [4, 4, 4, 4, 4], 'suhu' => [22.2, 22.2, 22.1, 22.2, 22.2],
                    'titik_ukur_sebelum' => 4.0092252,
                    'pembacaan_sebelum' => [4.04, 4.04, 4.04, 5.0, 4.04],
                    'suhu_sebelum' => [22.2, 22.2, 22.2, 22.2, 22.2],
                ],
                [
                    'titik_ukur' => 6.9889072, 'satuan' => 'pH', 'standard_id' => $this->buffer7->id,
                    'pembacaan' => [7.01, 7.01, 7, 7, 7], 'suhu' => [22.2, 22.2, 22.2, 22.2, 22.2],
                    'titik_ukur_sebelum' => 6.9885032,
                    'pembacaan_sebelum' => [7.02, 7.04, 7.05, 7.02, 7.02],
                    'suhu_sebelum' => [22.3, 22.3, 22.3, 22.3, 22.3],
                ],
                [
                    'titik_ukur' => 9.978876900, 'satuan' => 'pH', 'standard_id' => $this->buffer10->id,
                    'pembacaan' => [10.11, 10.11, 10.11, 10.11, 10.11], 'suhu' => [22.1, 22.1, 22.1, 22.1, 22.1],
                    'titik_ukur_sebelum' => 9.9777956,
                    'pembacaan_sebelum' => [9.61, 9.94, 9.66, 9.61, 9.61],
                    'suhu_sebelum' => [22.2, 22.2, 22.2, 22.2, 22.2],
                ],
            ],
        ];
    }

    public function test_submit_ph_reproduksi_u95_sertifikat_dan_kondisi_lingkungan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();

        $sesi = CalibrationSession::latest('id')->with('uncertaintyCalculations')->firstOrFail();

        // U95% per titik = angka sertifikat (6 desimal): 0.023432 / 0.021109 / 0.031000.
        $u = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();
        $this->assertSame('0.023432', number_format($u[0]->ketidakpastian_diperluas, 6, '.', ''));
        $this->assertSame('0.021109', number_format($u[1]->ketidakpastian_diperluas, 6, '.', ''));
        // Titik 10: hitung < CMC → dilaporkan CMC 0.031.
        $this->assertEqualsWithDelta(0.031, $u[2]->ketidakpastian_diperluas, 1e-9);

        // Koreksi (nilai − standar) ikut sertifikat.
        $this->assertEqualsWithDelta(0.009244572, $u[0]->koreksi, 1e-6);
        $this->assertEqualsWithDelta(-0.131123100, $u[2]->koreksi, 1e-6);
        $this->assertSame('PASS', $sesi->keputusan);

        // Rata-rata & U95% lingkungan DIHITUNG server.
        $this->assertEqualsWithDelta(21.4, $sesi->suhu_ruang, 1e-9);
        $this->assertEqualsWithDelta(54.5, $sesi->kelembaban, 1e-9);
        $this->assertEqualsWithDelta(1.7117, $sesi->suhu_ruang_u95, 1e-4);
        $this->assertEqualsWithDelta(5.6604, $sesi->kelembaban_u95, 1e-4);
    }

    /**
     * Penjagaan nominal-vs-terkoreksi.
     *
     * Nilai buffer bergerak ngikutin suhu larutan: pH 4 pada 22.2 °C itu
     * 4.0092, BUKAN 3.99 yang tertulis di botol. Kalau yang dikirim nominal,
     * pencocokan CMC tetap berhasil dan nggak ada error sama sekali — cuma
     * angka KOREKSI di sertifikat yang salah. Makanya ditolak di depan.
     */
    public function test_titik_ukur_nominal_ditolak_karena_belum_dikoreksi_suhu(): void
    {
        $payload = $this->payload();
        // 3.99 = label botol. Yang bener 4.0092 pada suhu larutan yang dicatat.
        $payload['measurements'][0]['titik_ukur'] = 3.99;

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements.0.titik_ukur');

        // Nggak boleh nyisain sesi setengah jadi.
        $this->assertDatabaseCount('calibration_sessions', 0);
    }

    public function test_titik_ukur_terkoreksi_diterima(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();
    }

    public function test_pemeriksaan_dilewat_kalau_suhu_larutan_nggak_dicatat(): void
    {
        $payload = $this->payload();
        $payload['measurements'][0]['titik_ukur'] = 3.99;
        unset($payload['measurements'][0]['suhu']);

        // Tanpa suhu larutan, nilai seharusnya nggak bisa dihitung — jadi
        // nggak ada dasar buat nolak. Lebih baik lolos daripada nolak
        // berdasarkan tebakan.
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated();
    }

    public function test_pesan_error_nyebutin_nilai_yang_seharusnya(): void
    {
        $payload = $this->payload();
        $payload['measurements'][0]['titik_ukur'] = 3.99;

        // Key error-nya `measurements.0.titik_ukur` — ada titiknya, jadi nggak
        // bisa diambil pakai dot-notation `json()`. Ambil arraynya langsung.
        $errors = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->json('errors');

        $pesan = $errors['measurements.0.titik_ukur'][0] ?? '';

        // Teknisi harus tahu angka bener­nya berapa, bukan cuma "salah".
        $this->assertStringContainsString('4.009', (string) $pesan);
        $this->assertStringContainsString('3.99', (string) $pesan);
    }

    public function test_detail_sesi_ph_bawa_before_after_dan_kondisi_lingkungan(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->payload())->assertCreated();
        $sesi = CalibrationSession::latest('id')->firstOrFail();

        $resp = $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$sesi->id}")->assertOk();

        // Kondisi lingkungan terkoreksi (rata-rata + koreksi) muncul.
        $resp->assertJsonPath('data.kondisi_lingkungan.suhu.nilai_terkoreksi', 20.97)
            ->assertJsonPath('data.kondisi_lingkungan.kelembaban.nilai_terkoreksi', 51.95)
            ->assertJsonPath('data.kondisi_lingkungan.thermohygro', 'TH-3');

        // Ringkasan sebelum adjustment (as-found) ada, 3 titik.
        $resp->assertJsonCount(3, 'data.titik_sebelum');
        // Titik 1 sebelum: rata-rata (4.04×4 + 5.00)/5 = 4.232.
        $resp->assertJsonPath('data.titik_sebelum.0.rata_rata', 4.232);

        // Pembacaan mentah nyimpen suhu per baca (pasangan pH/°C).
        $suhuAda = collect($resp->json('data.pembacaan_mentah'))->contains(fn ($m) => $m['suhu'] !== null);
        $this->assertTrue($suhuAda, 'pembacaan_mentah harus bawa suhu per pembacaan');
    }
}
