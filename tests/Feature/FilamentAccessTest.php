<?php

namespace Tests\Feature;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
            '/admin/rooms',
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

    /**
     * Master ruangan lab harus bisa diurus dari komputer, bukan cuma lewat
     * `api/rooms` — yang mendaftarin nama ruangan itu pemilik lab, dan dia
     * nggak pakai aplikasi HP.
     */
    public function test_admin_bisa_nyimpen_ruangan_lab_baru_dari_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreateRoom::class)
            ->fillForm([
                'kode' => 'R-01',
                'nama' => 'Ruang Kalibrasi Massa',
                'lokasi' => 'Lantai 2',
                'suhu_min' => 18,
                'suhu_max' => 25,
                'kelembaban_min' => 40,
                'kelembaban_max' => 60,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // `organization_id` datang dari field Hidden, bukan dari isian admin.
        // Kalau default-nya lepas, ruangannya kesimpen tanpa organisasi dan
        // langsung ilang dari daftarnya sendiri — nggak ada error di mana pun.
        $this->assertDatabaseHas('rooms', [
            'organization_id' => $admin->organization_id,
            'kode' => 'R-01',
            'nama' => 'Ruang Kalibrasi Massa',
            'aktif' => true,
        ]);
    }

    /**
     * Kode kembar ditolak dengan kalimat yang kebaca orang lab, bukan pesan
     * bawaan Filament ("The kode has already been taken.") yang nyebut nama
     * kolom dan nggak ngasih tau bentroknya sama apa.
     */
    public function test_kode_ruangan_kembar_ditolak_dengan_pesan_yang_kebaca(): void
    {
        $admin = User::factory()->admin()->create();
        Room::factory()->create([
            'organization_id' => $admin->organization_id,
            'kode' => 'R-01',
        ]);

        $this->actingAs($admin);

        $komponen = Livewire::test(CreateRoom::class)
            ->fillForm(['kode' => 'R-01', 'nama' => 'Ruang Kalibrasi Suhu'])
            ->call('create')
            ->assertHasFormErrors(['kode']);

        $this->assertSame(
            'Kode ruangan ini sudah dipakai.',
            $komponen->instance()->getErrorBag()->first('data.kode'),
        );
    }

    /**
     * Uniknya PER organisasi, sama kayak indeks `['organization_id', 'kode']`
     * di tabelnya. Kalau `where()`-nya lepas dari aturan Filament, admin PT ini
     * nggak bisa bikin "R-01" cuma gara-gara lab organisasi lain udah punya —
     * dan pesan errornya bakal nyuruh dia ganti kode tanpa alasan yang masuk akal.
     */
    public function test_kode_ruangan_yang_sama_boleh_dipakai_organisasi_lain(): void
    {
        $admin = User::factory()->admin()->create();
        $tetangga = Organization::factory()->create();
        Room::factory()->create(['organization_id' => $tetangga->id, 'kode' => 'R-01']);

        $this->actingAs($admin);

        Livewire::test(CreateRoom::class)
            ->fillForm(['kode' => 'R-01', 'nama' => 'Ruang Kalibrasi Massa'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rooms', [
            'organization_id' => $admin->organization_id,
            'kode' => 'R-01',
        ]);
    }

    /**
     * Rentang kebalik itu syarat yang nggak mungkin dipenuhi siapa pun — tiap
     * sesi di ruangan itu bakal ketulis melanggar syarat selamanya. `RoomRequest`
     * udah nolaknya buat jalur API, tapi form panel nyimpen langsung ke model
     * tanpa lewat sana, jadi aturannya harus kembar di dua tempat.
     */
    public function test_rentang_suhu_kebalik_ditolak_di_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $komponen = Livewire::test(CreateRoom::class)
            ->fillForm([
                'kode' => 'R-09',
                'nama' => 'Ruang Uji',
                'suhu_min' => 25,
                'suhu_max' => 18,
            ])
            ->call('create')
            ->assertHasFormErrors(['suhu_max']);

        $this->assertSame(
            'Suhu maksimum nggak boleh lebih kecil dari minimum.',
            $komponen->instance()->getErrorBag()->first('data.suhu_max'),
        );

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_daftar_ruangan_cuma_nampilin_punya_organisasi_sendiri(): void
    {
        $admin = User::factory()->admin()->create();
        $punyaKita = Room::factory()->create([
            'organization_id' => $admin->organization_id,
            'kode' => 'R-01',
        ]);
        $tetangga = Organization::factory()->create();
        $punyaTetangga = Room::factory()->create([
            'organization_id' => $tetangga->id,
            'kode' => 'R-02',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$punyaKita])
            ->assertCanNotSeeTableRecords([$punyaTetangga]);
    }

    public function test_ruangan_organisasi_lain_nggak_bisa_dibuka_buat_disunting(): void
    {
        $admin = User::factory()->admin()->create();
        $tetangga = Organization::factory()->create();
        $punyaTetangga = Room::factory()->create([
            'organization_id' => $tetangga->id,
            'kode' => 'R-02',
        ]);

        // 404, bukan 403: route binding-nya ikut disaring `ScopesToOrganization`,
        // jadi barisnya emang nggak ketemu. Bedanya penting — 403 ngasih tau
        // bahwa ID itu ada dan cuma nggak boleh dibuka.
        $this->actingAs($admin)->get("/admin/rooms/{$punyaTetangga->id}/edit")->assertNotFound();
    }

    public function test_teknisi_dan_viewer_ditolak_buka_halaman_ruangan(): void
    {
        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/admin/rooms')->assertForbidden();
        }
    }

    /**
     * Nonaktifin ruangan itu NANDAIN, bukan ngapus. Baris `rooms`-nya harus
     * tetap utuh: `CalibrationSession::room()` itu `belongsTo` polos tanpa
     * `withTrashed()`, jadi baris yang kehapus bikin `$sesi->room` jadi null
     * dan sertifikat revisi buat sesi tahun lalu kecetak "Laboratorium"
     * gantiin nama ruangannya — tanpa error di mana pun.
     */
    public function test_nonaktifin_ruangan_nandain_bukan_ngapus(): void
    {
        $admin = User::factory()->admin()->create();
        $ruangan = Room::factory()->create([
            'organization_id' => $admin->organization_id,
            'kode' => 'R-01',
            'nama' => 'Ruang Kalibrasi Massa',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListRooms::class)
            ->callAction(TestAction::make('ubahStatus')->table($ruangan));

        $ruangan->refresh();

        $this->assertFalse($ruangan->aktif);
        $this->assertNull($ruangan->deleted_at);
    }
}
