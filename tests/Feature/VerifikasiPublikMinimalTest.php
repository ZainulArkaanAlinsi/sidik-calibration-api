<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifikasi QR publik: MINIMAL. Nama pelanggan, alamat pelanggan, dan nomor
 * order adalah data rahasia klien (ISO/IEC 17025 klausul 4.2) dan yang memindai
 * QR orang luar tanpa akun.
 *
 * Yang HARUS tetap ada supaya QR berfungsi: nomor sertifikat, status, keputusan,
 * tanggal, nama alat, `serial_number` (dicocokkan dengan label alat), dan
 * penerbit.
 *
 * Cakupan: JSON `GET /api/verify/{token}` (seluruh isi) dan halaman web
 * `GET /verify/{token}` -- kartu ringkas (bagian di atas "Tampilkan lembar
 * lengkap") serta kartu lama untuk sertifikat tanpa snapshot (seluruh isi).
 * LEMBAR LENGKAP di balik "Tampilkan lembar lengkap" dan unduhan PDF/Excel
 * adalah salinan sah sertifikat yang memang memuat identitas pelanggan
 * (spesifikasi poin 13); itu keputusan produk tersendiri, TIDAK diuji di sini.
 */
class VerifikasiPublikMinimalTest extends TestCase
{
    use RefreshDatabase;

    private const NAMA_PELANGGAN = 'PT Klien Rahasiabanget';

    private const ALAMAT_PELANGGAN = 'Jl. Alamat Rahasia 99 Kota Uji';

    private const NOMOR_ORDER = 'ORD-RAHASIA-7731';

    private const SERIAL = 'SN-VERIF-5521';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('arsip');

        $this->org = Organization::factory()->create(['nama' => 'PT Lab Uji', 'no_akreditasi' => 'LK-285-IDN']);
    }

    /** Sertifikat terbit lewat jalur sungguhan, jadi snapshot-nya penuh. */
    private function sertifikatDenganSnapshot(): Certificate
    {
        $pelanggan = Customer::factory()->create([
            'organization_id' => $this->org->id,
            'nama' => self::NAMA_PELANGGAN,
            'alamat' => self::ALAMAT_PELANGGAN,
        ]);
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pelanggan->id,
            'nama_alat' => 'Jangka Sorong Verif',
            'serial_number' => self::SERIAL,
            'equipment_category_id' => EquipmentCategory::query()->firstOrCreate(
                ['organization_id' => $this->org->id, 'kode' => 'panjang'],
                ['nama' => 'Panjang'],
            )->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $teknisi = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::factory()->create(['organization_id' => $this->org->id])->id,
            'tanggal_kalibrasi' => '2026-07-20',
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update([
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'reviewed_by' => $admin->id,
            'nomor_order' => self::NOMOR_ORDER,
        ]);

        (new GenerateCertificate($sesi->id, $admin->id))->handle();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    /** Bagian halaman SEBELUM lembar lengkap: kartu ringkas + bilah status. */
    private function kartuRingkas(string $html): string
    {
        $batas = strpos($html, 'class="lembar-lengkap"');
        $this->assertNotFalse($batas, 'Halaman verifikasi tidak lagi punya blok lembar lengkap; sesuaikan tes ini.');

        return substr($html, 0, $batas);
    }

    private function assertTanpaDataKlien(string $teks, string $konteks): void
    {
        foreach ([self::NAMA_PELANGGAN, self::ALAMAT_PELANGGAN, self::NOMOR_ORDER, 'Rahasiabanget', 'Pemilik'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $teks, "{$konteks} memuat data klien: {$terlarang}");
        }
        // Bentuk yang disamarkan lama (`PT Klie••`) juga tidak boleh kembali.
        $this->assertStringNotContainsString('Disamarkan sebagian', $teks, "{$konteks} masih memuat nama pemilik yang disamarkan.");
    }

    public function test_json_publik_tidak_memuat_nama_alamat_atau_order_tapi_tetap_memuat_yang_dicocokkan(): void
    {
        $sertifikat = $this->sertifikatDenganSnapshot();

        $respons = $this->getJson("/api/verify/{$sertifikat->qr_token}")->assertOk();

        $this->assertTanpaDataKlien($respons->getContent(), 'JSON /api/verify');
        $respons->assertJsonMissingPath('data.alat.pemilik')
            ->assertJsonMissingPath('data.pemilik')
            ->assertJsonPath('data.alat.serial_number', self::SERIAL)
            ->assertJsonPath('data.alat.nama_alat', 'Jangka Sorong Verif')
            ->assertJsonPath('data.nomor', $sertifikat->nomor)
            ->assertJsonPath('data.status', 'terbit')
            ->assertJsonPath('data.diterbitkan_oleh.nama', 'PT Lab Uji')
            ->assertJsonPath('data.diterbitkan_oleh.no_akreditasi', 'LK-285-IDN')
            ->assertJsonStructure(['data' => [
                'nomor', 'status', 'status_dokumen', 'digantikan_oleh', 'keputusan',
                'diterbitkan_pada', 'berlaku_sampai', 'kadaluarsa',
                'alat' => ['nama_alat', 'serial_number'],
                'tanggal_kalibrasi', 'diterbitkan_oleh' => ['nama', 'no_akreditasi'],
            ]]);

        // Kunci tepat: field baru di masa depan tidak masuk diam-diam.
        $this->assertSame(['nama_alat', 'serial_number'], array_keys($respons->json('data.alat')));
    }

    public function test_kartu_ringkas_halaman_web_tidak_memuat_nama_alamat_atau_order(): void
    {
        $sertifikat = $this->sertifikatDenganSnapshot();

        $html = $this->get("/verify/{$sertifikat->qr_token}")->assertOk()->getContent();
        $ringkas = $this->kartuRingkas($html);

        $this->assertTanpaDataKlien($ringkas, 'Kartu ringkas /verify');
        $this->assertStringContainsString($sertifikat->nomor, $ringkas);
        $this->assertStringContainsString(self::SERIAL, $ringkas);
        $this->assertStringContainsString('Jangka Sorong Verif', $ringkas);
    }

    public function test_kartu_lama_tanpa_snapshot_tidak_memuat_nama_alamat_atau_order(): void
    {
        $sertifikat = $this->sertifikatDenganSnapshot();
        $sertifikat->forceFill(['snapshot' => null])->save();

        $html = $this->get("/verify/{$sertifikat->qr_token}")->assertOk()->getContent();

        $this->assertTanpaDataKlien($html, 'Kartu lama /verify');
        $this->assertStringContainsString(self::SERIAL, $html);
        $this->assertStringContainsString($sertifikat->nomor, $html);
        $this->assertStringContainsString('LK-285-IDN', $html);
    }
}
