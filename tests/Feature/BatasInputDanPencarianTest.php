<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kelompok SARAN uji E2E-QA 18 Nov 2026 — input yang dulu menjawab 500 atau
 * diterima tanpa batas, sekarang 422 / 404 / hasil yang benar:
 *
 *  - BUG-9   `catatan` alat tanpa batas panjang → "Data too long" 500;
 *  - BUG-10  angka di luar rentang `decimal(20,8)` → "Out of range" 500;
 *  - BUG-11  `POST /calibrations` dengan puluhan ribu titik menggantung > 2 menit;
 *  - BUG-12  suhu 999 / -999 diterima dan masuk koreksi lingkungan sertifikat;
 *  - BUG-13  id rute '1abc' dibaca sebagai 1 oleh MySQL → 200 untuk alat 1;
 *  - BUG-14  `/customers?search=%` mengembalikan SEMUA pelanggan.
 */
class BatasInputDanPencarianTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private User $admin;

    private User $teknisi;

    private Customer $pelanggan;

    private Equipment $alat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
        $this->pelanggan = Customer::factory()->create(['organization_id' => $this->lab->id]);
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $this->lab->id, 'kode' => 'panjang']);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $this->lab->id,
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $kategori->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
    }

    /** @return array<string, mixed> */
    private function alatBaru(array $ganti = []): array
    {
        return [
            'nama_alat' => 'Jangka Sorong Uji',
            'serial_number' => 'BATAS-'.random_int(1000, 9999),
            'kategori' => 'panjang',
            'pelanggan_id' => $this->pelanggan->id,
            ...$ganti,
        ];
    }

    /** @return array<string, mixed> */
    private function sesiBaru(array $ganti = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => Standard::factory()->create(['organization_id' => $this->lab->id])->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
            ...$ganti,
        ];
    }

    // ------------------------------------------------------------- BUG-9

    public function test_catatan_alat_dibatasi_2000_karakter(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', $this->alatBaru(['catatan' => str_repeat('c', 70000)]))
            ->assertStatus(422)->assertJsonValidationErrors(['catatan']);

        $this->actingAs($this->admin)->postJson('/api/equipments', $this->alatBaru(['catatan' => str_repeat('c', 2001)]))
            ->assertStatus(422)->assertJsonValidationErrors(['catatan']);

        $this->actingAs($this->admin)->postJson('/api/equipments', $this->alatBaru(['catatan' => str_repeat('c', 2000)]))
            ->assertCreated();
    }

    // ------------------------------------------------------------ BUG-10

    public function test_angka_alat_di_luar_rentang_kolom_dijawab_422_bukan_500(): void
    {
        foreach ([
            ['resolusi' => 1e308],
            ['range_min' => 1e15],
            ['range_min' => 0, 'range_max' => 1e16],
            ['range_min' => -1e15],
            ['toleransi' => 1e13],
        ] as $ngawur) {
            $this->actingAs($this->admin)->postJson('/api/equipments', $this->alatBaru($ngawur))
                ->assertStatus(422)
                ->assertJsonValidationErrors([array_key_last($ngawur)]);
        }
    }

    public function test_angka_alat_tepat_di_batas_kolom_masih_diterima(): void
    {
        $this->actingAs($this->admin)->postJson('/api/equipments', $this->alatBaru([
            'range_min' => -999999999999, 'range_max' => 999999999999, 'resolusi' => 0.00000001,
        ]))->assertCreated();
    }

    public function test_pembacaan_dan_titik_ukur_di_luar_rentang_kolom_dijawab_422_bukan_500(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru([
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [1e308, 50.0]]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['measurements.0.pembacaan.0']);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru([
            'measurements' => [['titik_ukur' => 1e20, 'satuan' => 'mm', 'pembacaan' => [50.0]]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['measurements.0.titik_ukur']);
    }

    // ------------------------------------------------------------ BUG-11

    public function test_puluhan_ribu_titik_ditolak_422_dengan_cepat(): void
    {
        $titik = array_fill(0, 10000, ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.0]]);

        $mulai = microtime(true);
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru(['measurements' => $titik]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements']);

        // Dulu menggantung > 120 detik (perluasan aturan wildcard ke 10.000
        // elemen). Batas longgar supaya tidak rapuh di mesin lambat.
        $this->assertLessThan(20, microtime(true) - $mulai, 'ukuran harus ditolak SEBELUM aturan diperluas');
    }

    public function test_enam_puluh_satu_titik_dan_terlalu_banyak_pembacaan_ditolak(): void
    {
        $titik = array_fill(0, 61, ['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.0]]);
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru(['measurements' => $titik]))
            ->assertStatus(422)->assertJsonValidationErrors(['measurements']);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru([
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => array_fill(0, 5000, 50.0)]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['measurements.0.pembacaan']);
    }

    public function test_sesi_wajar_tetap_diterima(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru())->assertCreated();
    }

    // ------------------------------------------------------------ BUG-12

    public function test_suhu_di_luar_rentang_wajar_ditolak_dan_kelembaban_tetap_0_sampai_100(): void
    {
        foreach ([
            ['suhu_awal' => 999], ['suhu_akhir' => -999], ['suhu_ruang' => 999],
            ['suhu_awal' => 150.1], ['suhu_akhir' => -50.1],
            ['kelembaban_awal' => 101], ['kelembaban_akhir' => -1], ['kelembaban' => 100.5],
        ] as $ngawur) {
            $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru($ngawur))
                ->assertStatus(422)
                ->assertJsonValidationErrors([array_key_first($ngawur)]);
        }

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru([
            'suhu_awal' => -50, 'suhu_akhir' => 150, 'kelembaban_awal' => 0, 'kelembaban_akhir' => 100,
        ]))->assertCreated();
    }

    public function test_autoclave_memakai_batas_suhu_dan_kelembaban_yang_sama(): void
    {
        $respons = $this->actingAs($this->teknisi)->postJson('/api/calibrations/autoclave', [
            'suhu_awal' => 999, 'suhu_akhir' => -999, 'kelembaban_awal' => 101, 'kelembaban_akhir' => -1,
        ]);

        $respons->assertStatus(422)->assertJsonValidationErrors([
            'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir',
        ]);
    }

    // ------------------------------------------------------------ BUG-13

    public function test_id_rute_yang_bukan_angka_dijawab_404(): void
    {
        $sesi = $this->actingAs($this->teknisi)->postJson('/api/calibrations', $this->sesiBaru())
            ->assertCreated()->json('data.id');

        foreach ([
            "/api/equipments/{$this->alat->id}abc",
            "/api/equipments/{$this->alat->id};DROP",
            '/api/equipments/'.rawurlencode("{$this->alat->id} OR 1=1"),
            "/api/calibrations/{$sesi}x",
            "/api/customers/{$this->pelanggan->id}abc",
            '/api/standards/1abc',
            '/api/rooms/1abc',
            '/api/orders/1abc',
        ] as $url) {
            $this->actingAs($this->admin)->getJson($url)->assertNotFound();
        }

        // Id yang benar tetap berfungsi.
        $this->actingAs($this->admin)->getJson("/api/equipments/{$this->alat->id}")->assertOk();
        $this->actingAs($this->admin)->getJson("/api/calibrations/{$sesi}")->assertOk();
        $this->actingAs($this->admin)->getJson("/api/customers/{$this->pelanggan->id}")->assertOk();
    }

    public function test_rute_statis_di_samping_rute_ber_id_tetap_berfungsi(): void
    {
        $this->actingAs($this->admin)->getJson('/api/customers/lookup?q=zzz')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/calibrations?per_page=1')->assertOk();
    }

    // ------------------------------------------------------------ BUG-14

    public function test_pencarian_pelanggan_membaca_persen_dan_garis_bawah_harfiah(): void
    {
        Customer::factory()->create(['organization_id' => $this->lab->id, 'nama' => 'PT Alfa Makmur']);
        Customer::factory()->create(['organization_id' => $this->lab->id, 'nama' => 'PT Diskon 100% Murni']);
        Customer::factory()->create(['organization_id' => $this->lab->id, 'nama' => 'PT Under_Score']);

        $cari = fn (string $kata): array => $this->actingAs($this->admin)
            ->getJson('/api/customers?search='.rawurlencode($kata))
            ->assertOk()
            ->json('data.*.nama');

        $this->assertSame(['PT Diskon 100% Murni'], $cari('%'), '% harfiah, bukan "cocokkan semuanya"');
        $this->assertSame(['PT Under_Score'], $cari('_'), '_ harfiah, bukan "satu karakter apa pun"');
        $this->assertSame(['PT Under_Score'], $cari('Under_S'));
        $this->assertSame(['PT Alfa Makmur'], $cari('alfa'), 'pencarian biasa tidak berubah');
        $this->assertSame([], $cari('%%%'));
    }

    public function test_pencarian_pelanggan_tetap_hanya_lab_sendiri(): void
    {
        $labLain = Organization::factory()->create();
        Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Milik 100% Lab Lain']);

        $this->actingAs($this->admin)->getJson('/api/customers?search='.rawurlencode('%'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
