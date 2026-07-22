<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Worksheet itu FORMULIR yang diisi manual (atau dibaca dari foto), bukan
 * tampilan yang isinya diturunkan sistem.
 *
 * Tiga kolom "langkah awal" sebelumnya dipaksa backend, jadi hasil input nggak
 * bisa sama persis sama kertasnya: Certificate Number, Calculated by, dan
 * Calibration Method. Test ini ngunci ketiganya beneran bisa diisi orang, dan
 * beneran nyampe ke sertifikat.
 */
class WorksheetLangkahAwalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Alex Misramto', 'department' => 'Technical Manager',
        ]);
        $this->teknisi = User::factory()->create(['name' => 'Dwi Rahayu', 'employee_id' => 'DR']);

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create(['nama' => 'PT TIRTA GRACIA'])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    /** @param  array<string, mixed>  $tambahan */
    private function submit(array $tambahan = []): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
            ...$tambahan,
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_tiga_field_langkah_awal_kesimpen_dan_kebalikin(): void
    {
        $sesi = $this->submit([
            'nomor_sertifikat' => '012-CAL-524',
            'dihitung_oleh' => 'NR',
            'metode_kalibrasi' => 'SIDIK-IK-CAL-0506_Rev.6',
        ]);

        $this->actingAs($this->teknisi)->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.nomor_sertifikat', '012-CAL-524')
            ->assertJsonPath('data.dihitung_oleh', 'NR')
            ->assertJsonPath('data.metode_kalibrasi', 'SIDIK-IK-CAL-0506_Rev.6');
    }

    /**
     * Nomor sertifikat dari worksheet harus MENANG atas penomoran otomatis.
     * Lab udah punya penomoran sendiri dan itu yang tercetak di arsip mereka.
     */
    public function test_nomor_sertifikat_dari_worksheet_dipakai_bukan_nomor_otomatis(): void
    {
        $sesi = $this->submit(['nomor_sertifikat' => '012-CAL-524']);
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $this->assertSame('012-CAL-524', $sesi->certificate()->firstOrFail()->nomor);
    }

    public function test_tanpa_nomor_manual_backend_tetap_ngasih_nomor_otomatis(): void
    {
        $sesi = $this->submit();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        // Alur lama nggak berubah buat yang nggak ngisi.
        $this->assertMatchesRegularExpression(
            '#^CAL/\d{4}/\d{2}/\d{4}$#',
            $sesi->certificate()->firstOrFail()->nomor,
        );
    }

    /**
     * Regresi end-to-end: API → approve → isi sertifikat.
     *
     * Yang diuji bukan cuma "PDF-nya kebikin", tapi angka & nama yang beneran
     * nyampe ke kertas. Dulu pernah kejadian view dikasih variabel baru dan
     * jalur seeder-nya ketinggalan — ini nangkep kelas kesalahan itu.
     */
    public function test_regresi_isi_sertifikat_dari_input_sampai_cetak(): void
    {
        $sesi = $this->submit([
            'nomor_sertifikat' => '012-CAL-524',
            'dihitung_oleh' => 'NR',
            'metode_kalibrasi' => 'SIDIK-IK-CAL-0506_Rev.6',
            'suhu_ruang_awal' => 21.3, 'suhu_ruang_akhir' => 21.5,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 56,
            'suhu_ruang_koreksi' => -0.43, 'kelembaban_koreksi' => -2.55,
            'suhu_ruang_u_std' => 1.7, 'kelembaban_u_std' => 4.8,
            'thermohygro' => 'TH-3',
        ]);

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
        $sesiLengkap = CalibrationSession::with([
            'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard',
            'uncertaintyCalculations.standard', 'rawMeasurements',
        ])->findOrFail($sesi->id);

        $html = view('sertifikat.pdf', GenerateCertificate::dataView($sertifikat, $sesiLengkap, null))->render();

        foreach ([
            '012-CAL-524',              // Certificate Number dari worksheet
            'SIDIK-IK-CAL-0506_Rev.6',  // Calibration Method manual, bukan dari CMC
            'NR',                       // Calculated by (inisial)
            'Alex Misramto',            // Signed by (nama lengkap)
            'Technical Manager',
            'PT TIRTA GRACIA',          // Owner
            'Dwi Rahayu',               // Teknisi
            '20.97',                    // Suhu terkoreksi (21.4 + -0.43)
            '51.95',                    // Kelembaban terkoreksi (54.5 + -2.55)
            '1.7117',                   // U95% suhu
            '5.6604',                   // U95% kelembaban
            'TH-3',
        ] as $harusAda) {
            $this->assertStringContainsString(
                $harusAda,
                $html,
                "Sertifikat harus nyantumin '{$harusAda}' — kalau ilang, ada mata rantai input→cetak yang putus.",
            );
        }
    }

    /**
     * Alat yang belum di-link ke jenis kemampuan jatuh ke jalur hitung GENERIK,
     * dan itu ngasih U yang jauh beda dari CMC. Diam-diam jatuh ke situ padahal
     * kategorinya PUNYA CMC hampir selalu salah setup — sertifikatnya terbit
     * kelihatan normal, tapi ketidakpastiannya bukan yang diklaim lab.
     */
    public function test_alat_belum_dilink_tapi_kategorinya_punya_cmc_bikin_peringatan(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter', 'range_min' => 4, 'range_max' => 4, 'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.023, 'satuan_ketidakpastian' => 'pH', 'faktor_cakupan' => 2,
        ]);

        // nama_alat_kemampuan SENGAJA kosong — inilah salah setup-nya.
        $alatBelumLink = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'customer_id' => Customer::factory()->create()->id,
            'nama_alat' => 'pH Meter Bench',
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $pesan, array $ctx): bool => str_contains($pesan, 'nama_alat_kemampuan')
                && $ctx['equipment_id'] === $alatBelumLink->id);

        app(\App\Services\GumCalculator::class)
            ->hitungTitik(1, 4.0, [4.0, 4.0, 4.0], $alatBelumLink, $this->standar);
    }

    public function test_alat_yang_udah_dilink_nggak_bikin_peringatan(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter', 'range_min' => 4, 'range_max' => 4, 'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.023, 'satuan_ketidakpastian' => 'pH', 'faktor_cakupan' => 2,
        ]);

        $alatTerlink = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'customer_id' => Customer::factory()->create()->id,
            'nama_alat_kemampuan' => 'pH Meter',
            'satuan' => 'pH', 'resolusi' => 0.01, 'toleransi' => 0.2,
        ]);

        Log::shouldReceive('warning')->never();

        $hasil = app(\App\Services\GumCalculator::class)
            ->hitungTitik(1, 4.0, [4.0, 4.0, 4.0], $alatTerlink, $this->standar);

        $this->assertEqualsWithDelta(0.023, $hasil['ketidakpastian_diperluas'], 1e-9);
    }
}
