<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Validasi & hitung ulang sebelum sertifikat terbit (spesifikasi poin 11).
 */
class CalibrationValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();

        $this->alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->standar = Standard::factory()->create();
    }

    private function buatSesi(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_sesi_yang_sehat_lolos_pemeriksaan(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.boleh_terbit', true)
            ->assertJsonPath('data.ringkasan.error', 0)
            ->assertJsonPath('data.ringkasan.peringatan', 0);
    }

    public function test_angka_yang_diutak_atik_langsung_ketahuan_waktu_dihitung_ulang(): void
    {
        Queue::fake();
        $sesi = $this->buatSesi();

        // Ketidakpastian dikecilin langsung di DB — persis skenario yang bikin
        // alat FAIL kelihatan PASS di sertifikat.
        $sesi->uncertaintyCalculations()->update(['ketidakpastian_diperluas' => 0.0001]);

        $respons = $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('butuh_konfirmasi', true)
            ->assertJsonPath('validasi.valid', false);

        $this->assertContains(
            'hitung_ulang_beda',
            array_column($respons->json('validasi.temuan'), 'kode'),
        );

        // Ketahan beneran: statusnya nggak pindah & sertifikat nggak diantre.
        // (Sinyal broadcast realtime boleh jalan — yang dijaga cuma jangan sampai
        // sertifikatnya kegenerate.)
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->fresh()->status);
        Queue::assertNotPushed(GenerateCertificate::class);
    }

    public function test_admin_tetap_bisa_lanjut_kalau_peringatannya_disadari(): void
    {
        $sesi = $this->buatSesi();
        $sesi->uncertaintyCalculations()->update(['ketidakpastian_diperluas' => 0.0001]);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk()
            ->assertJsonPath('data.status', CalibrationSession::STATUS_DISETUJUI);
    }

    public function test_koreksi_yang_nggak_konsisten_nahan_penerbitan_tanpa_bisa_dilewatin(): void
    {
        $sesi = $this->buatSesi();

        // Correction wajib kebalikan error — ini rumus mati, bukan selisih
        // pembulatan. Nggak boleh bisa di-abaikan.
        $sesi->uncertaintyCalculations()->update(['koreksi' => 999]);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422)
            ->assertJsonPath('validasi.boleh_terbit', false);
    }

    public function test_keputusan_sesi_yang_nggak_cocok_sama_titiknya_ditolak(): void
    {
        $sesi = $this->buatSesi();

        // Satu titik FAIL harusnya bikin seluruh sesi FAIL.
        $sesi->uncertaintyCalculations()->update(['keputusan' => 'FAIL']);

        $respons = $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertStatus(422);

        $this->assertContains(
            'keputusan_sesi_salah',
            array_column($respons->json('validasi.temuan'), 'kode'),
        );
    }

    public function test_kolom_sertifikat_yang_kosong_cuma_jadi_info_dan_nggak_nahan_approve(): void
    {
        $sesi = $this->buatSesi();

        $respons = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk();

        $info = array_filter(
            $respons->json('data.temuan'),
            fn (array $t): bool => $t['tingkat'] === 'info',
        );

        // Order Number & Calibration Method emang belum diisi admin di sini.
        $this->assertNotEmpty($info);
        $this->assertTrue($respons->json('data.boleh_terbit'));

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertOk();
    }

    public function test_hasil_pemeriksaan_ikut_kesimpen_di_sertifikat(): void
    {
        $sesi = $this->buatSesi();

        $this->actingAs($this->admin)->postJson("/api/calibrations/{$sesi->id}/approve")->assertOk();

        $validasi = $sesi->fresh()->certificate()->firstOrFail()->validasi;

        $this->assertTrue($validasi['boleh_terbit']);
        $this->assertSame(0, $validasi['ringkasan']['error']);
    }

    // ------------------------------------------------ koreksi suhu larutan

    /** @param array<string, mixed> $titik */
    private function periksaSesiDengan(Standard $standar, array $titik, ?Equipment $alat = null): array
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => ($alat ?? $this->alat)->id,
            'standard_id' => $standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [$titik],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        return $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data');
    }

    /** @param array<string, mixed> $hasil */
    private function kodeTemuan(array $hasil): array
    {
        return array_column($hasil['temuan'], 'kode');
    }

    /**
     * Standar punya kurva suhu tapi suhu larutannya nggak dicatat.
     *
     * Nilai acuan kepaksa pakai nominal, dan Correction meleset sebesar koreksi
     * suhu yang nggak kepakai — di titik pH 10 itu 0,065 pH pada alat
     * bertoleransi 0,2. Sebelum ini nggak ada satu pun yang ngasih tau: nggak
     * ada error, nggak ada peringatan, sertifikatnya terbit rapi.
     */
    public function test_suhu_larutan_yang_nggak_dicatat_jadi_peringatan(): void
    {
        $berkurva = Standard::factory()->create([
            'koefisien_suhu' => ['a' => 3e-5, 'b' => -0.0023, 'c' => 4.0455],
        ]);

        $hasil = $this->periksaSesiDengan($berkurva, [
            'titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03],
        ]);

        $this->assertContains('suhu_larutan_tidak_dicatat', $this->kodeTemuan($hasil));

        // PERINGATAN, bukan error: teknisi di lapangan nggak boleh keblokir, dan
        // admin tetap boleh lanjut kalau dia sadar risikonya.
        $this->assertTrue($hasil['boleh_terbit']);
    }

    /**
     * Kebalikannya: teknisi nyatat suhu, tapi standarnya belum punya kurva.
     *
     * Angka yang dia catat kebuang percuma dan nilai acuan tetap nominal. Yang
     * perlu dibenerin data master standarnya, bukan lembar kerjanya — makanya
     * pesannya nunjuk ke situ.
     */
    public function test_standar_tanpa_kurva_suhu_jadi_peringatan_kalau_suhunya_dicatat(): void
    {
        $hasil = $this->periksaSesiDengan($this->standar, [
            'titik_ukur' => 50.0, 'satuan' => 'mm',
            'pembacaan' => [50.02, 50.01, 50.03],
            'suhu' => [25.3, 25.3, 25.3],
        ]);

        $this->assertContains('standar_tanpa_kurva_suhu', $this->kodeTemuan($hasil));
        $this->assertTrue($hasil['boleh_terbit']);
    }

    /**
     * Alat yang suhunya emang nggak ngaruh JANGAN dikasih peringatan.
     *
     * Standar panjang/massa/tekanan nggak punya kurva suhu dan nggak butuh.
     * Kalau tiap sesi non-pH kebanjiran temuan yang nggak ada tindak lanjutnya,
     * temuannya berhenti dibaca orang — dan yang beneran penting ikut kelewat.
     */
    public function test_sesi_tanpa_suhu_dan_tanpa_kurva_nggak_dikasih_peringatan(): void
    {
        $hasil = $this->periksaSesiDengan($this->standar, [
            'titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03],
        ]);

        $kode = $this->kodeTemuan($hasil);

        $this->assertNotContains('suhu_larutan_tidak_dicatat', $kode);
        $this->assertNotContains('standar_tanpa_kurva_suhu', $kode);
    }

    /**
     * Alat yang standarnya MEMANG dibaca nominal (chlorine, turbidimeter) tetap
     * nyatat suhu larutan — suhunya masuk budget ketidakpastian, bukan buat
     * ngoreksi nilai acuan. `koefisien_suhu` NULL di situ jawaban yang benar.
     *
     * Sebelum ini kombinasi "suhu kecatat + standar tanpa kurva" langsung jadi
     * peringatan, jadi TIAP sertifikat chlorine & turbidimeter ke-flag
     * `valid: false` gara-gara perilaku yang justru diharapkan — kejadian di
     * sesi 7 & 11–13 di DB lab. Yang mbedain sekarang profil alatnya.
     */
    public function test_alat_yang_standarnya_nominal_nggak_dikasih_peringatan_kurva_suhu(): void
    {
        $chlorine = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'analitik'])->id,
            'nama_alat' => 'Chlorine Meter',
            // Kunci yang bikin registry milih ChlorineProfile.
            'nama_alat_kemampuan' => 'Chlorin Meter',
            'satuan' => 'mg/L', 'resolusi' => 0.01, 'toleransi' => 0.15,
        ]);

        $hasil = $this->periksaSesiDengan($this->standar, [
            'titik_ukur' => 1.74, 'satuan' => 'mg/L',
            'pembacaan' => [1.76, 1.76, 1.75],
            'suhu' => [25.8, 25.8, 25.8],
        ], $chlorine);

        $this->assertNotContains('standar_tanpa_kurva_suhu', $this->kodeTemuan($hasil));

        // Dan alat generik dengan standar & suhu yang sama persis TETAP diperingatin
        // — yang berubah cuma jenis alatnya, bukan saringannya dilonggarin.
        $this->assertContains('standar_tanpa_kurva_suhu', $this->kodeTemuan(
            $this->periksaSesiDengan($this->standar, [
                'titik_ukur' => 50.0, 'satuan' => 'mm',
                'pembacaan' => [50.02, 50.01, 50.03],
                'suhu' => [25.8, 25.8, 25.8],
            ]),
        ));
    }
}
