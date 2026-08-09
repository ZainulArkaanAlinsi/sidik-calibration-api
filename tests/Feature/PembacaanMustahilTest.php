<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\CalibrationValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Peringatan buat pembacaan yang **alatnya sendiri nggak mungkin nampilin**.
 *
 * ## Kenapa cuma yang mustahil
 *
 * 6 Agt 2026 sesi chlorine kesimpen dengan pembacaan 1,90 buat standar 1,83
 * (kertas labnya 1,86) dan nembus sampai sertifikat terbit tanpa satu pun
 * peringatan. Percobaan pertama: nyalain peringatan kalau |error| + U mepet
 * toleransi. Diadu ke data nyata, ambangnya gugur — Turbidimeter master rasio
 * 0,958 dan pH master 0,811, sementara chlorine yang salah 1,000 dan yang benar
 * 0,733. Nggak ada ambang yang misahin salah ketik dari penyimpangan beneran,
 * karena 1,90 buat standar 1,83 emang angka yang wajar.
 *
 * Jadi yang dijaga cuma dua yang objektif salah: di luar rentang ukur (koma
 * kegeser) dan bukan kelipatan resolusi (digit kelebihan).
 *
 * ## Setengah tes ini soal DIAM
 *
 * Aturan yang kebanyakan bunyi lebih berbahaya daripada nggak ada aturan —
 * temuan yang selalu muncul berhenti dibaca orang, termasuk temuan yang
 * beneran penting. Makanya data master ketiga alat ikut diuji, dan yang
 * diharapkan NOL temuan. Itu bukan basa-basi: versi pertama aturan rentang
 * nyalain 4 peringatan tiap sesi Turbidimeter, karena titik teratasnya (1.000
 * NTU) emang kebaca 1001 di alat yang rentangnya 0–1000.
 */
class PembacaanMustahilTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private User $admin;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        // Angka alatnya niru Chlorine Meter Hanna HI97711 yang jadi asal
        // kejadiannya: rentang 0–4 mg/L, resolusi 0,01, toleransi 0,15.
        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'kimia'])->id,
            'satuan' => 'mg/L', 'range_min' => 0, 'range_max' => 4,
            'resolusi' => 0.01, 'toleransi' => 0.15,
        ]);

        $this->standar = Standard::factory()->create();
    }

    public function test_pembacaan_wajar_nggak_bikin_peringatan_apa_pun(): void
    {
        $kode = $this->periksa([1.76, 1.76, 1.76, 1.76, 1.75]);

        $this->assertNotContains('pembacaan_di_luar_rentang', $kode);
        $this->assertNotContains('pembacaan_bukan_kelipatan_resolusi', $kode);
    }

    /**
     * Koma kegeser — `1,86` diketik jadi `18,6`. Alat rentang 0–4 mg/L nggak
     * akan pernah nampilin angka segitu.
     */
    public function test_pembacaan_jauh_di_luar_rentang_ketangkep(): void
    {
        $kode = $this->periksa([1.76, 18.6, 1.76, 1.76, 1.75]);

        $this->assertContains('pembacaan_di_luar_rentang', $kode);
    }

    /**
     * Lewat dikit di batas atas TETAP DIAM. Titik kalibrasi teratas emang duduk
     * persis di batas rentang, jadi pembacaan normal pun bisa lewat sedikit —
     * master Turbidimeter nyatet 1001 NTU di alat 0–1000 NTU.
     */
    public function test_lewat_batas_sedikit_masih_dianggap_wajar(): void
    {
        // 4,1 mg/L di alat 0–4 dengan toleransi 0,15 — masih dalam pita jaga.
        $kode = $this->periksa([4.1, 4.1, 4.1, 4.1, 4.1]);

        $this->assertNotContains('pembacaan_di_luar_rentang', $kode);
    }

    /**
     * Digit kelebihan — `1,86` jadi `1,867` di alat yang layarnya cuma dua
     * desimal. Angkanya masih masuk rentang, error-nya juga wajar, jadi nggak
     * ada pemeriksaan lain yang bakal nyadar.
     */
    public function test_pembacaan_bukan_kelipatan_resolusi_ketangkep(): void
    {
        $kode = $this->periksa([1.76, 1.867, 1.76, 1.76, 1.75]);

        $this->assertContains('pembacaan_bukan_kelipatan_resolusi', $kode);
    }

    /**
     * Peringatan, bukan error: sertifikatnya tetap boleh terbit kalau admin
     * yakin (alat rusak yang beneran nampilin angka aneh), asal dia sadar.
     */
    public function test_cuma_nahan_sekali_bukan_ngunci_penerbitan(): void
    {
        $hasil = $this->periksaLengkap([1.76, 1.867, 1.76, 1.76, 1.75]);

        $this->assertFalse($hasil['valid']);
        $this->assertTrue($hasil['boleh_terbit']);
    }

    /**
     * Yang paling gampang bikin salah ketik lolos: pembacaan yang salahnya
     * masuk akal. 1,90 buat standar 1,83 NGGAK ketangkep di sini, dan itu
     * disengaja — lihat docblock kelas. Tes ini yang jaga supaya nggak ada yang
     * "mbenerin" dengan nambah ambang tebak-tebakan.
     */
    public function test_salah_ketik_yang_angkanya_wajar_memang_nggak_ketangkep(): void
    {
        $kode = $this->periksa([1.90, 1.90, 1.90, 1.90, 1.90]);

        $this->assertNotContains('pembacaan_di_luar_rentang', $kode);
        $this->assertNotContains('pembacaan_bukan_kelipatan_resolusi', $kode);
    }

    /**
     * Data master ketiga alat: NOL peringatan pembacaan. Ini setengah nilai
     * aturannya — kalau di sini bunyi, aturannya yang salah, bukan datanya.
     */
    public function test_data_master_tiga_alat_nggak_kena_satu_pun(): void
    {
        $this->seed(DatabaseSeeder::class);

        $validator = app(CalibrationValidator::class);

        foreach (['2405.13.A', '2406.32.A', '2406.32.C'] as $nomorSesi) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

            $kode = array_column($validator->periksa($sesi)['temuan'], 'kode');

            $this->assertNotContains(
                'pembacaan_di_luar_rentang',
                $kode,
                "sesi master {$nomorSesi} kena peringatan rentang — aturannya kebanyakan bunyi",
            );
            $this->assertNotContains(
                'pembacaan_bukan_kelipatan_resolusi',
                $kode,
                "sesi master {$nomorSesi} kena peringatan resolusi — aturannya kebanyakan bunyi",
            );
        }
    }

    /**
     * @param  list<float>  $pembacaan
     * @return list<string>
     */
    private function periksa(array $pembacaan): array
    {
        return array_column($this->periksaLengkap($pembacaan)['temuan'], 'kode');
    }

    /**
     * @param  list<float>  $pembacaan
     * @return array<string, mixed>
     */
    private function periksaLengkap(array $pembacaan): array
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [
                ['titik_ukur' => 1.83, 'satuan' => 'mg/L', 'pembacaan' => $pembacaan],
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        return $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data');
    }
}
