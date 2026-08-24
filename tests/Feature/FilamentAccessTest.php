<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel admin web (Filament) di /admin. Bentengnya cuma `canAccessPanel` di
 * User — jadi aksesnya dikunci di sini, terpisah dari izin API.
 */
class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    public function test_admin_aktif_bisa_buka_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_teknisi_ditolak_masuk_panel(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        // Punya akun yang sah, tapi bukan admin — panel admin harus nolak dia,
        // walaupun API tetap nerima dia buat endpoint yang boleh.
        $this->actingAs($teknisi)->get('/admin')->assertForbidden();
    }

    public function test_viewer_ditolak_masuk_panel(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->get('/admin')->assertForbidden();
    }

    public function test_admin_yang_dinonaktifin_langsung_kehilangan_akses_panel(): void
    {
        $admin = User::factory()->admin()->create(['status' => User::STATUS_NONAKTIF]);

        // Role masih admin, tapi status nonaktif — nggak boleh nyangkut ke panel.
        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_tanpa_login_dilempar_ke_halaman_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /**
     * Smoke test: tiap halaman daftar resource ke-render tanpa error. Nangkep
     * salah nama kolom / relasi di tabel Filament sebelum ketemu pas dibuka admin.
     */
    public function test_semua_halaman_resource_render_buat_admin(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            '/admin/equipment',
            '/admin/customers',
            '/admin/standards',
            '/admin/equipment-categories',
            // Master kemampuan kalibrasi (CMC). Kolom `Rentang` & `CMC (U95)`
            // di tabelnya dihitung dari method model, bukan kolom mentah — itu
            // jenis kesalahan yang cuma ketahuan waktu halamannya dibuka.
            '/admin/calibration-capabilities',
            '/admin/users',
            '/admin/calibration-sessions',
            '/admin/certificates',
            // Halaman singleton — mount-nya ngambil organisasi si admin & ngisi
            // form, jadi ini sekalian nguji organization_id-nya nyambung.
            '/admin/pengaturan-organisasi',
        ] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    /**
     * Halaman detail sertifikat harus render walau sertifikatnya revisi dari
     * sertifikat lain — nangkep salah relasi (session.equipment, revisionOf).
     */
    public function test_halaman_detail_sertifikat_render_termasuk_yang_revisi(): void
    {
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create();
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
        ]);
        $sesi = CalibrationSession::create([
            'organization_id' => $admin->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'tanggal_kalibrasi' => now()->subDay(),
        ]);
        $asli = Certificate::create([
            'organization_id' => $admin->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'CAL/2026/07/0001',
            'qr_token' => 'token-asli',
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => now()->subDay(),
        ]);
        $revisi = Certificate::create([
            'organization_id' => $admin->organization_id,
            'calibration_session_id' => $sesi->id,
            'revision_of' => $asli->id,
            'nomor' => 'CAL/2026/07/0001-R1',
            'qr_token' => 'token-revisi',
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => now(),
            'alasan_revisi' => 'Koreksi nama pelanggan',
        ]);

        $this->actingAs($admin)->get("/admin/certificates/{$asli->id}")->assertOk();
        $this->actingAs($admin)->get("/admin/certificates/{$revisi->id}")->assertOk();
    }
}
