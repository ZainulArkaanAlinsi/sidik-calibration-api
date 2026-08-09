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
 * Lembar PERHITUNGAN sisi admin, diadu langsung sama lembar olah data ASLI
 * PT Sidik (`Master Olah Data_pH for trial.xlsm`, sertifikat 012-CAL-524).
 *
 * Angka pembanding di test ini bukan karangan — semuanya disalin dari
 * `PERHITUNGAN.csv`. Kalau ada rumus yang digeser diam-diam, test ini yang
 * kejeblos duluan, bukan pelanggan yang nemu di sertifikat.
 */
class PerhitunganTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $buffer4;

    private Standard $buffer7;

    private Standard $buffer10;

    private Standard $thermohygro;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create(['kode_teknisi' => 'DR']);

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create([
                'nama' => 'PT TIRTA GRACIA SEMESTA MANDIRI',
                'alamat' => 'Jl. Arteri Primer A-10 RT. 01 RW.12 Nyalindung Kec. Cicalengka, Kab. Bandung, Jawa Barat',
            ])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter',
            'merk' => 'Mettler Toledo',
            'model' => 'Five Easy',
            'serial_number' => 'B628755900',
            'range_min' => 0, 'range_max' => 14,
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        // Persamaan suhu dari sertifikat buffer Merck/Supelco.
        $this->buffer4 = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 4',
            'koefisien_suhu' => ['a' => 3e-5, 'b' => -0.0023, 'c' => 4.0455],
        ]);
        $this->buffer7 = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 7',
            'koefisien_suhu' => ['a' => 8e-5, 'b' => -0.0076, 'c' => 7.1182],
        ]);
        $this->buffer10 = Standard::factory()->create([
            'nama' => 'pH Buffer Solution 10',
            'koefisien_suhu' => ['a' => 9e-5, 'b' => -0.0148, 'c' => 10.262],
        ]);

        // TH-3, dari DATABASE.csv lembar olah data.
        $this->thermohygro = Standard::factory()->create([
            'nama' => 'TH-3',
            'parameter_kondisi' => [
                'suhu' => ['indexed_value' => 19.83, 'correction' => -0.43, 'u95' => 1.7],
                'kelembaban' => ['indexed_value' => 47.05, 'correction' => -2.55, 'u95' => 4.8],
            ],
        ]);
    }

    /** Data persis sertifikat 012-CAL-524. */
    private function kirimLembarKerja(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->buffer7->id,
            'tanggal_kalibrasi' => '2024-05-26',
            'tanggal_terima' => '2024-05-26',
            'suhu_awal' => 21.3,
            'suhu_akhir' => 21.5,
            'kelembaban_awal' => 53,
            'kelembaban_akhir' => 56,
            'measurements' => [
                [
                    'titik_ukur' => 4.00,
                    'standard_id' => $this->buffer4->id,
                    'pembacaan_sebelum' => [4.04, 4.04, 4.04, 5.00, 4.04],
                    'suhu_sebelum' => [22.2, 22.2, 22.2, 22.2, 22.2],
                    'pembacaan' => [4.00, 4.00, 4.00, 4.00, 4.00],
                    'suhu' => [22.2, 22.2, 22.1, 22.2, 22.2],
                ],
                [
                    'titik_ukur' => 7.00,
                    'standard_id' => $this->buffer7->id,
                    'pembacaan_sebelum' => [7.02, 7.04, 7.05, 7.02, 7.02],
                    'suhu_sebelum' => [22.3, 22.3, 22.3, 22.3, 22.3],
                    'pembacaan' => [7.01, 7.01, 7.00, 7.00, 7.00],
                    'suhu' => [22.2, 22.2, 22.2, 22.2, 22.2],
                ],
                [
                    'titik_ukur' => 10.01,
                    'standard_id' => $this->buffer10->id,
                    'pembacaan_sebelum' => [9.61, 9.94, 9.66, 9.61, 9.61],
                    'suhu_sebelum' => [22.2, 22.2, 22.2, 22.2, 22.2],
                    'pembacaan' => [10.11, 10.11, 10.11, 10.11, 10.11],
                    'suhu' => [22.1, 22.1, 22.1, 22.1, 22.1],
                ],
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // Thermohygro itu field administratif — diisi admin, bukan teknisi.
        $this->actingAs($this->admin)->patchJson("/api/calibrations/{$sesi->id}/admin", [
            'thermohygro_standard_id' => $this->thermohygro->id,
        ])->assertOk();

        return $sesi->fresh();
    }

    public function test_kondisi_lingkungan_sama_persis_sama_lembar_olah_data_asli(): void
    {
        $sesi = $this->kirimLembarKerja();

        $data = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/perhitungan")
            ->assertOk()
            ->json('data.kondisi_lingkungan');

        $suhu = $data['suhu'];
        $this->assertEqualsWithDelta(21.4, $suhu['average'], 1e-9);
        $this->assertEqualsWithDelta(0.2, $suhu['delta'], 1e-9);
        $this->assertEqualsWithDelta(19.83, $suhu['indexed_value'], 1e-9);
        $this->assertEqualsWithDelta(-0.43, $suhu['correction'], 1e-9);
        // U95% = akar(U95%_stdTH² + Δ²) — angka dari lembar aslinya.
        $this->assertEqualsWithDelta(1.7117242768623688, $suhu['u95_sertifikat'], 1e-9);

        $rh = $data['kelembaban'];
        $this->assertEqualsWithDelta(54.5, $rh['average'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $rh['delta'], 1e-9);
        $this->assertEqualsWithDelta(5.660388679233963, $rh['u95_sertifikat'], 1e-9);

        $this->assertSame('TH-3', $data['thermohygro']);
    }

    public function test_nilai_standar_dihitung_dari_suhu_larutan_bukan_nilai_nominal(): void
    {
        $sesi = $this->kirimLembarKerja();

        $hasil = collect(
            $this->actingAs($this->admin)
                ->getJson("/api/calibrations/{$sesi->id}/perhitungan")
                ->json('data.hasil'),
        );

        $sebelum = $hasil->firstWhere('tahap', 'sebelum_adjustment')['titik'];
        $sesudah = $hasil->firstWhere('tahap', 'sesudah_adjustment')['titik'];

        // Buffer pH 4 di 22,2 °C → 4,0092252 (bukan 4,00 nominalnya).
        $this->assertEqualsWithDelta(4.0092252, $sebelum[0]['standard'], 1e-9);
        $this->assertEqualsWithDelta(6.9885032, $sebelum[1]['standard'], 1e-9);
        $this->assertEqualsWithDelta(9.9777956, $sebelum[2]['standard'], 1e-9);

        // Sesudah adjustment suhunya beda dikit (22,18 °C rata-rata di titik 1),
        // jadi nilai standarnya juga geser — persis kayak di lembar aslinya.
        $this->assertEqualsWithDelta(4.009244572, $sesudah[0]['standard'], 1e-9);
        $this->assertEqualsWithDelta(6.9889072, $sesudah[1]['standard'], 1e-9);
        $this->assertEqualsWithDelta(9.9788769, $sesudah[2]['standard'], 1e-7);
    }

    public function test_baris_average_correction_dan_stdev_cocok_sama_lembar_aslinya(): void
    {
        $sesi = $this->kirimLembarKerja();

        $hasil = collect(
            $this->actingAs($this->admin)
                ->getJson("/api/calibrations/{$sesi->id}/perhitungan")
                ->json('data.hasil'),
        );

        $sebelum = $hasil->firstWhere('tahap', 'sebelum_adjustment');
        $titik = $sebelum['titik'];

        // Titik pH 4 sebelum adjustment: ada outlier 5,00 di repeat ke-4.
        $this->assertEqualsWithDelta(4.232, $titik[0]['average'], 1e-9);
        $this->assertEqualsWithDelta(0.2227748, $titik[0]['correction'], 1e-9);
        $this->assertEqualsWithDelta(0.4293250516799596, $titik[0]['stdev'], 1e-9);

        $this->assertEqualsWithDelta(7.03, $titik[1]['average'], 1e-9);
        $this->assertEqualsWithDelta(0.0414968, $titik[1]['correction'], 1e-9);

        $this->assertEqualsWithDelta(9.686, $titik[2]['average'], 1e-9);
        $this->assertEqualsWithDelta(-0.2917956, $titik[2]['correction'], 1e-9);

        // MAX STDEV = yang terbesar dari ketiga titik.
        $this->assertEqualsWithDelta(0.4293250516799596, $sebelum['max_stdev'], 1e-9);

        $sesudah = $hasil->firstWhere('tahap', 'sesudah_adjustment');
        $titikSesudah = $sesudah['titik'];

        $this->assertEqualsWithDelta(-0.009244572, $titikSesudah[0]['correction'], 1e-9);
        $this->assertEqualsWithDelta(0.0150928, $titikSesudah[1]['correction'], 1e-9);
        $this->assertEqualsWithDelta(0.1311231, $titikSesudah[2]['correction'], 1e-7);
        $this->assertEqualsWithDelta(0.005477225575051544, $sesudah['max_stdev'], 1e-9);
    }

    public function test_suhu_larutan_tiap_pengulangan_ikut_kecatat(): void
    {
        $sesi = $this->kirimLembarKerja();

        $titik = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/perhitungan")
            ->json('data.hasil.1.titik.0');

        $this->assertCount(5, $titik['pembacaan']);
        $this->assertSame([22.2, 22.2, 22.1, 22.2, 22.2], array_column($titik['pembacaan'], 'suhu'));
        $this->assertEqualsWithDelta(22.18, $titik['average_suhu'], 1e-9);
    }

    public function test_env_condition_di_sertifikat_ikut_hasil_hitungan_kondisi_lingkungan(): void
    {
        $sesi = $this->kirimLembarKerja();

        // Average 21,4 + koreksi (−0,43) = 20,97 → dibulatkan 21,0 di sertifikat.
        $this->assertEqualsWithDelta(20.97, $sesi->fresh()->suhu_ruang, 1e-9);
        // Average 54,5 + koreksi (−2,55) = 51,95 — persis contoh di spesifikasi.
        $this->assertEqualsWithDelta(51.95, $sesi->fresh()->kelembaban, 1e-9);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $this->assertSame(
            // Suhu 2 desimal, kelembaban bulat — ngikut Excel master lab. Perhatiin
            // `20,97`: format lama (1 desimal) nampilinnya `21,0`, jadi dua digit
            // yang dicatat teknisi ilang dari dokumen resmi tanpa ada yang tau.
            // 20,97 disimpen utuh di DB (dicek di atas) tapi DICETAK 1 desimal,
            // ngikut sertifikat asli lab. Yang diuji test ini tetap kejaga:
            // angkanya beda dari average mentah 21,4, jadi koreksi thermohygro
            // kebukti ikut kepakai.
            // 51,95 & 5,6604 itu ANGKA MASTER pH apa adanya — kalau di sini
            // kecetak `52%` lagi, artinya kelembabannya kepangkas lagi.
            'T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,66%',
            $sesi->fresh()->certificate->snapshot['header']['env_condition'],
        );
    }

    public function test_identitas_alat_dan_customer_ikut_di_lembar_perhitungan(): void
    {
        $sesi = $this->kirimLembarKerja();

        $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/perhitungan")
            ->assertOk()
            ->assertJsonPath('data.identitas_alat.nama_alat', 'pH Meter')
            ->assertJsonPath('data.identitas_alat.merk', 'Mettler Toledo')
            ->assertJsonPath('data.identitas_alat.type', 'Five Easy')
            ->assertJsonPath('data.identitas_alat.no_seri', 'B628755900')
            ->assertJsonPath('data.identitas_alat.rentang_ukur', '0-14')
            ->assertJsonPath('data.identitas_customer.nama', 'PT TIRTA GRACIA SEMESTA MANDIRI')
            ->assertJsonPath('data.identitas_customer.tanggal_kalibrasi', '2024-05-26');
    }

    public function test_lembar_perhitungan_cuma_buat_admin(): void
    {
        $sesi = $this->kirimLembarKerja();

        // Teknisi ngisi lembar kerja; yang ngolah admin.
        $this->actingAs($this->teknisi)
            ->getJson("/api/calibrations/{$sesi->id}/perhitungan")
            ->assertForbidden();
    }

    public function test_bentuk_lembar_kerja_teknisi_nggak_bawa_kolom_admin(): void
    {
        $kodeTeknisi = $this->kodeField($this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja')
            ->assertOk()
            ->assertJsonPath('data.untuk', 'teknisi')
            ->json('data.bagian'));

        // Metode kalibrasi & nomor order tetap hak admin (spesifikasi poin 1) —
        // dua-duanya udah TERCETAK/ditentukan kantor, bukan dibaca di lapangan.
        $this->assertNotContains('calibration_method_id', $kodeTeknisi);
        $this->assertNotContains('nomor_order', $kodeTeknisi);

        // Thermohygro SEBALIKNYA: pindah jadi hak teknisi 29 Juli 2026 — dia
        // yang tau unit mana yang kebawa. Lihat AdminFieldSeparationTest.
        $this->assertContains('thermohygro_standard_id', $kodeTeknisi);

        // Identitas alat & pemilik diketik teknisi dari badan alat, bukan
        // disalin master — kolomnya wajib ada di lembar kerjanya.
        foreach (['alat_model', 'alat_serial_number', 'alat_merk', 'pemilik_nama', 'pemilik_alamat'] as $kode) {
            $this->assertContains($kode, $kodeTeknisi);
        }

        // Kolom lembar kerjanya sendiri tetap ada.
        $this->assertContains('suhu_awal', $kodeTeknisi);
        $this->assertContains('catatan_teknisi', $kodeTeknisi);

        $kodeAdmin = $this->kodeField($this->actingAs($this->admin)
            ->getJson('/api/calibrations/lembar-kerja')
            ->assertOk()
            ->assertJsonPath('data.untuk', 'admin')
            ->json('data.bagian'));

        // Admin dapat semuanya.
        $this->assertContains('thermohygro_standard_id', $kodeAdmin);
        $this->assertContains('nomor_order', $kodeAdmin);
        $this->assertContains('suhu_awal', $kodeAdmin);
    }

    /**
     * @param  list<array<string, mixed>>  $bagian
     * @return list<string>
     */
    private function kodeField(array $bagian): array
    {
        return collect($bagian)->flatMap(fn (array $b): array => array_column($b['field'] ?? [], 'kode'))->all();
    }
}
