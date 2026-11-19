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
 * R2-12 (QA ronde 2): verifikasi publik TIDAK BOLEH patah untuk sertifikat
 * terbit, apa pun yang terjadi pada alatnya sesudahnya.
 *
 * `DELETE /api/equipments/{alat}` adalah soft delete dan TIDAK menolak alat yang
 * sudah bersertifikat (menolaknya = keputusan produk, bukan bagian perbaikan ini).
 * Sebelumnya relasi `session.equipment` mengecualikan baris terhapus, jadi
 * `GET /api/verify/{token}` membaca `nama_alat` dari null dan menjawab 500 --
 * padahal QR yang tercetak di kertas dipindai orang luar bertahun-tahun kemudian.
 * Halaman web `/verify/{token}` dan unduhan lewat QR memakai relasi yang sama.
 *
 * Yang dijaga: jawabannya TETAP 200 dan isinya SAMA PERSIS dengan sebelum alat
 * dihapus (nama alat, nomor seri, keputusan, tanggal, penerbit).
 */
class VerifikasiAlatDihapusTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('arsip');

        $this->org = Organization::factory()->create(['nama' => 'PT Lab Uji', 'no_akreditasi' => 'LK-285-IDN']);
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
    }

    /** Sertifikat terbit lewat jalur sungguhan, jadi snapshot-nya penuh. */
    private function sertifikatTerbit(string $serial = 'SN-HAPUS-0042'): Certificate
    {
        $pelanggan = Customer::factory()->create(['organization_id' => $this->org->id]);
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pelanggan->id,
            'nama_alat' => 'Jangka Sorong Hapus',
            'serial_number' => $serial,
            'equipment_category_id' => EquipmentCategory::query()->firstOrCreate(
                ['organization_id' => $this->org->id, 'kode' => 'panjang'],
                ['nama' => 'Panjang'],
            )->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
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
            'reviewed_by' => $this->admin->id,
            'nomor_order' => 'ORD-HAPUS-1',
        ]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    public function test_json_verifikasi_tetap_200_dan_isi_sama_setelah_alat_dihapus_lunak(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sebelum = $this->getJson("/api/verify/{$sertifikat->qr_token}")->assertOk()->json();

        // Lewat rutenya sendiri, seperti yang terjadi di lapangan (admin menghapus alat).
        $this->actingAs($this->admin)->deleteJson('/api/equipments/'.$sertifikat->session->equipment_id)->assertOk();
        $this->assertSoftDeleted('equipments', ['id' => $sertifikat->session->equipment_id]);

        $sesudah = $this->getJson("/api/verify/{$sertifikat->qr_token}")->assertOk()->json();

        $this->assertSame($sebelum, $sesudah);
        $this->assertSame('Jangka Sorong Hapus', $sesudah['data']['alat']['nama_alat']);
        $this->assertSame('SN-HAPUS-0042', $sesudah['data']['alat']['serial_number']);
    }

    public function test_halaman_web_verifikasi_tetap_200_setelah_alat_dan_pelanggannya_dihapus(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sebelum = $this->get("/verify/{$sertifikat->qr_token}")->assertOk()->getContent();

        $alat = $sertifikat->session->equipment;
        $alat->customer->delete();
        $alat->delete();

        $sesudah = $this->get("/verify/{$sertifikat->qr_token}")->assertOk()->getContent();

        $this->assertStringContainsString($sertifikat->nomor, $sesudah);
        $this->assertStringContainsString('SN-HAPUS-0042', $sesudah);
        $this->assertSame($this->tanpaTokenCsrf($sebelum), $this->tanpaTokenCsrf($sesudah));
    }

    public function test_kartu_lama_tanpa_snapshot_tetap_200_setelah_alat_dan_pelanggannya_dihapus(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->forceFill(['snapshot' => null])->save();

        $alat = $sertifikat->session->equipment;
        $alat->customer->delete();
        $alat->delete();

        $html = $this->get("/verify/{$sertifikat->qr_token}")->assertOk()->getContent();

        $this->assertStringContainsString($sertifikat->nomor, $html);
        $this->assertStringContainsString('SN-HAPUS-0042', $html);
    }

    public function test_unduhan_lewat_qr_tetap_jalan_setelah_alat_dihapus(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->session->equipment->delete();

        $this->get("/verify/{$sertifikat->qr_token}/download?format=pdf")->assertOk();
        $this->get("/verify/{$sertifikat->qr_token}/download?format=xlsx")->assertOk();
    }

    public function test_json_verifikasi_sertifikat_dibatalkan_dan_ditarik_tetap_200_setelah_alat_dihapus(): void
    {
        foreach ([Certificate::STATUS_DIBATALKAN => 'SN-HAPUS-B', Certificate::STATUS_DITARIK => 'SN-HAPUS-T'] as $status => $serial) {
            $sertifikat = $this->sertifikatTerbit($serial);
            $sertifikat->forceFill(['status' => $status])->save();
            $sertifikat->session->equipment->delete();

            $this->getJson("/api/verify/{$sertifikat->qr_token}")
                ->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.alat.serial_number', $serial);
        }
    }

    public function test_token_tak_dikenal_tetap_404_bukan_500(): void
    {
        $this->getJson('/api/verify/tidak-ada-xyz')->assertNotFound();
        $this->get('/verify/tidak-ada-xyz')->assertNotFound();
    }

    private function tanpaTokenCsrf(string $html): string
    {
        return (string) preg_replace('/name="_token"\s+value="[^"]*"/', 'name="_token" value=""', $html);
    }
}
