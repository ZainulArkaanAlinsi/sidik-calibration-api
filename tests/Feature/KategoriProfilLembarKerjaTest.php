<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CategoryController;
use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/categories/{kode}` sekarang ikut ngirim `profil` — kode lembar
 * kerja tiap jenis alat, atau null kalau alatnya pakai form generik.
 *
 * Kenapa ini penting sampai dapat berkas test sendiri: sebelum ini pemetaan
 * nama alat → lembar kerja HARDCODED di APK (map `_profilKhusus`, 26 ejaan).
 * Selama tabelnya di HP, alat yang baru ditambah admin MUSTAHIL dapat lembar
 * yang bener — server tahu alatnya, HP yang nggak tahu terjemahannya, dan
 * nggak ada satu pun error yang muncul: teknisi cuma dapat form generik.
 *
 * @see CategoryController::show()
 * @see CalibrationProfileRegistry::kodeProfilDariNama()
 */
class KategoriProfilLembarKerjaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['nama' => 'PT Sidik']);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_kirim_kode_profil_per_jenis_alat(): void
    {
        $kategori = EquipmentCategory::factory()->create([
            'kode' => 'instrumen-analitik',
            'nama' => 'Instrumen Analitik',
        ]);

        // Ejaan baris kemampuannya sengaja diambil apa adanya dari data nyata:
        // "Chlorin Meter" tanpa 'e' (lampiran akreditasi no. 42) dan
        // "Spectrophotometer" versi Inggris (yang diseed
        // `SpectrophotometerCapabilitySeeder`, beda dari "Spektrofotometer" di
        // lampiran). Dua ejaan itu yang paling gampang meleset.
        $this->buatKemampuan($kategori, 'pH Meter');
        $this->buatKemampuan($kategori, 'Chlorin Meter');
        $this->buatKemampuan($kategori, 'Spectrophotometer');

        $data = $this->actingAs($this->admin)
            ->getJson('/api/categories/instrumen-analitik')
            ->assertOk()
            ->json('data.kemampuan');

        $this->assertSame(
            ['ph_meter', 'chlorine_meter', 'spectrophotometer'],
            array_column($data, 'profil'),
        );
    }

    /**
     * Alat tanpa lembar khusus dapat `profil: null` — BUKAN `ph_meter`.
     *
     * `CalibrationProfileRegistry::untukNamaAlat()` yang lama jatuh ke pH kalau
     * nggak ketemu, dan kalau jalur ini ikut begitu, Buret bakal muncul di HP
     * sebagai alat berlembar pH: teknisi ngisi buffer 4/7/10 buat buret, sesi
     * kesimpen, dan nggak ada satu pun error di sepanjang jalur itu.
     */
    public function test_alat_generik_dapat_profil_null(): void
    {
        $kategori = EquipmentCategory::factory()->create([
            'kode' => 'volume',
            'nama' => 'Volume',
        ]);

        $this->buatKemampuan($kategori, 'Buret');

        $this->actingAs($this->admin)
            ->getJson('/api/categories/volume')
            ->assertOk()
            ->assertJsonPath('data.kemampuan.0.profil', null);
    }

    /**
     * Field lama HARUS tetap ada. APK yang udah kepasang di HP orang masih baca
     * `nama_alat`/`range_*`/`metode` dari respons ini, dan mereka nggak ikut
     * ke-update waktu server dideploy — nambah field itu aman, ngilangin field
     * bikin picker alat di HP lama kosong.
     */
    public function test_field_lama_nggak_ada_yang_ilang(): void
    {
        $kategori = EquipmentCategory::factory()->create([
            'kode' => 'suhu-dan-kelembapan',
            'nama' => 'Suhu dan Kelembapan',
        ]);

        $this->buatKemampuan($kategori, 'Oven', [
            'parameter' => 'Suhu',
            'range_min' => null,
            'range_note' => 'ambient',
            'range_max' => 300,
            'satuan' => '°C',
            'ketidakpastian_terbaik' => 1.5,
            'satuan_ketidakpastian' => '°C',
            'faktor_cakupan' => 2,
            'metode' => 'SIDIK-IK-CAL-0517',
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/categories/suhu-dan-kelembapan')
            ->assertOk()
            ->assertJsonStructure(['data' => ['kode', 'nama', 'kemampuan' => [[
                'nama_alat', 'parameter', 'range_min', 'range_max', 'range_note', 'satuan',
                'ketidakpastian_terbaik', 'satuan_ketidakpastian', 'faktor_cakupan', 'metode', 'profil',
            ]]]])
            ->assertJsonPath('data.kemampuan.0.nama_alat', 'Oven')
            ->assertJsonPath('data.kemampuan.0.range_note', 'ambient')
            ->assertJsonPath('data.kemampuan.0.metode', 'SIDIK-IK-CAL-0517')
            ->assertJsonPath('data.kemampuan.0.profil', 'oven');
    }

    /** @param  array<string, mixed>  $ubah */
    private function buatKemampuan(EquipmentCategory $kategori, string $namaAlat, array $ubah = []): void
    {
        CalibrationCapability::create([
            // Pemiliknya diturunkan dari kategorinya, sama kayak yang dilakukan
            // migrasi `..._bikin_calibration_capabilities_bisa_ditambah_orang`.
            'organization_id' => $kategori->organization_id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $namaAlat,
            'range_min' => 0,
            'range_max' => 14,
            'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.02,
            'satuan_ketidakpastian' => 'pH',
            ...$ubah,
        ]);
    }
}
