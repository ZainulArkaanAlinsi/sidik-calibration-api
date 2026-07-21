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
 * Arsip = tampilan "file manager" atas data yang udah ada:
 * perusahaan (Customer) → alat (Equipment) → berkas (CalibrationSession + Certificate).
 * Nggak nyimpen apa-apa baru; cuma nurunin hierarki dari relasi yang udah ada.
 */
class ArsipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private User $teknisiLain;

    private Customer $perusahaan;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->teknisiLain = User::factory()->create();

        $this->perusahaan = Customer::factory()->create(['nama' => 'PT Contoh Sejahtera']);
        $this->alat = Equipment::factory()->create([
            'customer_id' => $this->perusahaan->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'nama_alat' => 'Jangka Sorong',
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    /** Bikin satu sesi disetujui + sertifikat terbit, atas nama teknisi tertentu. */
    private function terbitkanSertifikat(User $teknisi): Certificate
    {
        $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->certificate()->firstOrFail();
    }

    public function test_folder_perusahaan_muncul_dengan_ringkasan_isinya(): void
    {
        $this->terbitkanSertifikat($this->teknisi);

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipe', 'folder')
            ->assertJsonPath('data.0.nama', 'PT Contoh Sejahtera')
            ->assertJsonPath('data.0.jumlah_alat', 1)
            ->assertJsonPath('data.0.jumlah_sertifikat', 1)
            // Sesi tadi tanggal_kalibrasi kemarin — max-nya harus keisi, bukan null.
            ->assertJsonPath('data.0.terakhir_kalibrasi', fn ($v) => $v !== null);
    }

    public function test_perusahaan_tanpa_alat_tetap_muncul_sebagai_folder_kosong(): void
    {
        Customer::factory()->create(['nama' => 'PT Belum Ada Alat']);

        $this->actingAs($this->admin)
            ->getJson('/api/arsip/perusahaan?search=Belum Ada')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.jumlah_alat', 0)
            ->assertJsonPath('data.0.jumlah_sertifikat', 0)
            ->assertJsonPath('data.0.terakhir_kalibrasi', null);
    }

    public function test_folder_perusahaan_terisolasi_antar_organisasi(): void
    {
        $this->terbitkanSertifikat($this->teknisi);

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)
            ->getJson('/api/arsip/perusahaan')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_isi_folder_perusahaan_daftar_alat_plus_breadcrumb(): void
    {
        $this->terbitkanSertifikat($this->teknisi);

        $this->actingAs($this->admin)
            ->getJson("/api/arsip/perusahaan/{$this->perusahaan->id}")
            ->assertOk()
            // Breadcrumb folder induk nempel di root lewat additional().
            ->assertJsonPath('folder.tipe', 'perusahaan')
            ->assertJsonPath('folder.nama', 'PT Contoh Sejahtera')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipe', 'folder')
            ->assertJsonPath('data.0.nama_alat', 'Jangka Sorong')
            ->assertJsonPath('data.0.jumlah_sesi', 1)
            ->assertJsonPath('data.0.jumlah_sertifikat', 1);
    }

    public function test_isi_folder_alat_berkas_lengkap_dengan_sertifikat(): void
    {
        $sertifikat = $this->terbitkanSertifikat($this->teknisi);

        $this->actingAs($this->admin)
            ->getJson("/api/arsip/alat/{$this->alat->id}")
            ->assertOk()
            ->assertJsonPath('folder.tipe', 'alat')
            ->assertJsonPath('folder.perusahaan.nama', 'PT Contoh Sejahtera')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipe', 'berkas')
            ->assertJsonPath('data.0.keputusan', 'PASS')
            ->assertJsonPath('data.0.sertifikat.nomor', $sertifikat->nomor)
            ->assertJsonPath('data.0.sertifikat.pdf_url', route('certificates.download', $sertifikat));
    }

    public function test_teknisi_di_folder_alat_cuma_lihat_berkas_miliknya(): void
    {
        $this->terbitkanSertifikat($this->teknisi);
        $this->terbitkanSertifikat($this->teknisiLain);

        // Admin lihat dua-duanya...
        $this->actingAs($this->admin)
            ->getJson("/api/arsip/alat/{$this->alat->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // ...tapi teknisi cuma sesi miliknya sendiri.
        $this->actingAs($this->teknisi)
            ->getJson("/api/arsip/alat/{$this->alat->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.teknisi.id', $this->teknisi->id);
    }

    public function test_folder_perusahaan_organisasi_lain_404(): void
    {
        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)
            ->getJson("/api/arsip/perusahaan/{$this->perusahaan->id}")
            ->assertNotFound();
    }

    public function test_folder_alat_organisasi_lain_404(): void
    {
        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->actingAs($adminLain)
            ->getJson("/api/arsip/alat/{$this->alat->id}")
            ->assertNotFound();
    }

    public function test_arsip_wajib_login(): void
    {
        $this->getJson('/api/arsip/perusahaan')->assertUnauthorized();
    }
}
