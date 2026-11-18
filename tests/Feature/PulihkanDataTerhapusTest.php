<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationMethod;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-5 + BUG-7 uji E2E-QA 18 Nov 2026.
 *
 * Indeks unik di database (`rooms`, `calibration_methods`, `customers`,
 * `equipments`) tidak mengecualikan baris soft-delete, jadi kode/nama/seri yang
 * pernah dipakai tetap TERKUNCI sesudah barisnya dihapus. Dulu:
 *  - ruangan & Instruksi Kerja: validasi (`whereNull('deleted_at')`) lebih
 *    longgar dari indeks → INSERT gagal → 500 tanpa pesan yang bisa ditindak;
 *  - nama PT: 422 generik "sudah ada" untuk PT yang bahkan tidak tampil di
 *    daftar — dan tidak ada jalan memulihkannya.
 *
 * Sekarang: validasi ikut melihat baris terhapus dan menjawab 422 yang
 * menyebut `kode: 'terhapus'` + `id` baris lamanya, balapan (indeks yang
 * memutuskan) juga 422, dan admin bisa `POST /{entitas}/{id}/pulihkan`.
 */
class PulihkanDataTerhapusTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    private Organization $labLain;

    private User $admin;

    private User $adminLain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab = Organization::factory()->create();
        $this->labLain = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->lab->id]);
        $this->adminLain = User::factory()->admin()->create(['organization_id' => $this->labLain->id]);
    }

    /** slug rute → [kelas model, kolom kunci kasat mata]. */
    private const ENTITAS = [
        'rooms' => [Room::class, 'kode'],
        'calibration-methods' => [CalibrationMethod::class, 'kode'],
        'standards' => [Standard::class, 'nama'],
        'equipments' => [Equipment::class, 'nama_alat'],
        'customers' => [Customer::class, 'nama'],
    ];

    /** @return array<string, array{0: string}> */
    public static function slugEntitas(): array
    {
        return array_map(static fn (string $slug): array => [$slug], array_combine(
            array_keys(self::ENTITAS),
            array_keys(self::ENTITAS),
        ));
    }

    private function buat(string $slug, ?Organization $lab = null): Model
    {
        $lab ??= $this->lab;
        $kelas = self::ENTITAS[$slug][0];

        if ($slug === 'equipments') {
            return Equipment::factory()->create([
                'organization_id' => $lab->id,
                'customer_id' => Customer::factory()->create(['organization_id' => $lab->id])->id,
                'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $lab->id])->id,
            ]);
        }

        return $kelas::factory()->create(['organization_id' => $lab->id]);
    }

    private function buatTerhapus(string $slug, ?Organization $lab = null): Model
    {
        $baris = $this->buat($slug, $lab);
        $baris->delete();

        return $baris;
    }

    // ------------------------------------------------------ BUG-5: ruangan

    public function test_ruangan_terhapus_tidak_bisa_dibuat_ulang_dan_jawabannya_422_bukan_500(): void
    {
        $lama = $this->buatTerhapus('rooms');

        $this->actingAs($this->admin)->postJson('/api/rooms', ['kode' => $lama->kode, 'nama' => 'Ruang Baru'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'terhapus')
            ->assertJsonPath('id', $lama->id)
            ->assertJsonValidationErrors(['kode']);
    }

    public function test_pesan_ruangan_terhapus_menyuruh_pulihkan_atau_pakai_kode_lain(): void
    {
        $lama = $this->buatTerhapus('rooms');

        $pesan = $this->actingAs($this->admin)
            ->postJson('/api/rooms', ['kode' => $lama->kode, 'nama' => 'x'])
            ->json('errors.kode.0');

        $this->assertStringContainsString('terhapus', $pesan);
        $this->assertStringContainsString('pulihkan', $pesan);
        $this->assertStringContainsString('kode lain', $pesan);
    }

    public function test_kode_ruangan_yang_masih_hidup_tetap_jawaban_lama_tanpa_kode_terhapus(): void
    {
        $hidup = $this->buat('rooms');

        $respons = $this->actingAs($this->admin)->postJson('/api/rooms', ['kode' => $hidup->kode, 'nama' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kode']);

        $this->assertNull($respons->json('kode'));
    }

    public function test_ubah_kode_ruangan_ke_kode_terhapus_juga_422_terhapus(): void
    {
        $lama = $this->buatTerhapus('rooms');
        $lain = $this->buat('rooms');

        $this->actingAs($this->admin)->putJson('/api/rooms/'.$lain->id, ['kode' => $lama->kode])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'terhapus')
            ->assertJsonPath('id', $lama->id);

        // Simpan ulang dirinya sendiri dengan kode yang sama tetap boleh.
        $this->actingAs($this->admin)->putJson('/api/rooms/'.$lain->id, ['kode' => $lain->kode, 'nama' => 'Ganti'])
            ->assertOk();
    }

    public function test_kode_ruangan_terhapus_milik_lab_lain_tidak_terlihat_dan_tidak_bentrok(): void
    {
        $lama = $this->buatTerhapus('rooms', $this->labLain);

        $this->actingAs($this->admin)->postJson('/api/rooms', ['kode' => $lama->kode, 'nama' => 'Punya Kita'])
            ->assertCreated();
    }

    public function test_balapan_dua_post_ruangan_kode_sama_dijawab_422_bukan_500(): void
    {
        // Request B lolos validasi, lalu request A menyelip INSERT sebelum B
        // menulis — indeks unik yang memutuskan. Disimulasikan lewat event.
        $kode = 'R-BALAP';
        $sudahMenyelip = false;

        Room::creating(function (Room $room) use (&$sudahMenyelip, $kode): void {
            if ($sudahMenyelip) {
                return;
            }
            $sudahMenyelip = true;

            DB::table('rooms')->insert([
                'organization_id' => $this->lab->id,
                'kode' => $kode,
                'nama' => 'Pemenang Balapan',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($this->admin)->postJson('/api/rooms', ['kode' => $kode, 'nama' => 'Kalah Balapan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kode']);

        $this->assertSame(1, Room::where('kode', $kode)->count());
    }

    // ---------------------------------------------------- BUG-5: metode IK

    public function test_metode_ik_terhapus_tidak_bisa_dibuat_ulang_dengan_kode_dan_revisi_sama(): void
    {
        $lama = CalibrationMethod::factory()->create([
            'organization_id' => $this->lab->id, 'kode' => 'IK-UJI-1', 'revisi' => '01',
        ]);
        $lama->delete();

        $this->actingAs($this->admin)->postJson('/api/calibration-methods', [
            'kode' => 'IK-UJI-1', 'revisi' => '01', 'nama' => 'Lagi',
        ])->assertStatus(422)
            ->assertJsonPath('kode', 'terhapus')
            ->assertJsonPath('id', $lama->id)
            ->assertJsonValidationErrors(['kode']);

        // Revisi BARU dengan kode yang sama memang cara kerja resmi — tetap sah.
        $this->actingAs($this->admin)->postJson('/api/calibration-methods', [
            'kode' => 'IK-UJI-1', 'revisi' => '02', 'nama' => 'Revisi baru',
        ])->assertCreated();
    }

    public function test_balapan_dua_post_metode_ik_dijawab_422_bukan_500(): void
    {
        $sudahMenyelip = false;

        CalibrationMethod::creating(function (CalibrationMethod $metode) use (&$sudahMenyelip): void {
            if ($sudahMenyelip) {
                return;
            }
            $sudahMenyelip = true;

            DB::table('calibration_methods')->insert([
                'organization_id' => $this->lab->id,
                'kode' => 'IK-BALAP', 'revisi' => '01', 'nama' => 'Pemenang',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->actingAs($this->admin)->postJson('/api/calibration-methods', [
            'kode' => 'IK-BALAP', 'revisi' => '01', 'nama' => 'Kalah',
        ])->assertStatus(422)->assertJsonValidationErrors(['kode']);
    }

    // ---------------------------------------------------------- BUG-7: PT

    public function test_nama_pt_terhapus_dijawab_422_dengan_kode_terhapus_dan_id(): void
    {
        $lama = $this->buatTerhapus('customers');

        $this->actingAs($this->admin)->postJson('/api/customers', ['nama' => $lama->nama])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'terhapus')
            ->assertJsonPath('id', $lama->id)
            ->assertJsonValidationErrors(['nama']);
    }

    public function test_nama_pt_yang_masih_hidup_tetap_pesan_lama(): void
    {
        $hidup = $this->buat('customers');

        $respons = $this->actingAs($this->admin)->postJson('/api/customers', ['nama' => $hidup->nama])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nama']);

        $this->assertNull($respons->json('kode'));
        $this->assertStringContainsString('sudah ada', $respons->json('errors.nama.0'));
    }

    public function test_ganti_nama_pt_ke_nama_pt_terhapus_juga_422_terhapus(): void
    {
        $lama = $this->buatTerhapus('customers');
        $hidup = $this->buat('customers');

        $this->actingAs($this->admin)->putJson('/api/customers/'.$hidup->id, ['nama' => $lama->nama])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'terhapus')
            ->assertJsonPath('id', $lama->id);
    }

    // ------------------------------------------------------------ pulihkan

    #[DataProvider('slugEntitas')]
    public function test_admin_memulihkan_baris_terhapus_dengan_jejak_audit(string $slug): void
    {
        $baris = $this->buatTerhapus($slug);
        $tabel = $baris->getTable();

        $respons = $this->actingAs($this->admin)
            ->postJson("/api/{$slug}/{$baris->id}/pulihkan", ['alasan' => 'Salah hapus, diminta lab'])
            ->assertOk()
            ->assertJsonPath('data.id', $baris->id);

        $this->assertNotNull($respons->json('data'));
        $this->assertNull($baris::withTrashed()->find($baris->id)->deleted_at, 'barisnya hidup lagi');

        // Satu baris audit `dipulihkan`: pelaku, alasan, sebelum → sesudah.
        $audit = AuditLog::where('entity_type', $tabel)
            ->where('entity_id', $baris->id)
            ->where('action', AuditLog::ACTION_DIPULIHKAN)
            ->get();

        $this->assertCount(1, $audit, 'tepat satu catatan pemulihan, bukan dua');
        $this->assertSame($this->admin->id, (int) $audit[0]->changed_by);
        $this->assertSame($this->lab->id, (int) $audit[0]->organization_id);
        $this->assertStringContainsString('Salah hapus, diminta lab', (string) $audit[0]->note);
        $this->assertNotNull($audit[0]->old_data['deleted_at'] ?? null, 'nilai sebelum: waktu hapus');
        $this->assertArrayHasKey('deleted_at', $audit[0]->new_data);
        $this->assertNull($audit[0]->new_data['deleted_at'], 'nilai sesudah: deleted_at null');
    }

    #[DataProvider('slugEntitas')]
    public function test_pulihkan_tanpa_alasan_tetap_boleh_dan_tercatat(string $slug): void
    {
        $baris = $this->buatTerhapus($slug);

        $this->actingAs($this->admin)->postJson("/api/{$slug}/{$baris->id}/pulihkan")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => $baris->getTable(),
            'entity_id' => $baris->id,
            'action' => AuditLog::ACTION_DIPULIHKAN,
            'changed_by' => $this->admin->id,
        ]);
    }

    #[DataProvider('slugEntitas')]
    public function test_super_admin_juga_boleh_memulihkan(string $slug): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->lab->id, 'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $baris = $this->buatTerhapus($slug);

        $this->actingAs($superAdmin)->postJson("/api/{$slug}/{$baris->id}/pulihkan")->assertOk();

        $this->assertNull($baris::withTrashed()->find($baris->id)->deleted_at);
    }

    #[DataProvider('slugEntitas')]
    public function test_teknisi_viewer_dan_tamu_tidak_boleh_memulihkan(string $slug): void
    {
        $baris = $this->buatTerhapus($slug);
        $url = "/api/{$slug}/{$baris->id}/pulihkan";

        $this->postJson($url)->assertStatus(401);

        $teknisi = User::factory()->create(['organization_id' => $this->lab->id]);
        $this->actingAs($teknisi)->postJson($url)->assertStatus(403);

        $viewer = User::factory()->create(['organization_id' => $this->lab->id, 'role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer)->postJson($url)->assertStatus(403);

        $this->assertNotNull($baris::withTrashed()->find($baris->id)->deleted_at, 'tetap terhapus');
    }

    #[DataProvider('slugEntitas')]
    public function test_baris_lab_lain_dijawab_404_dan_tetap_terhapus(string $slug): void
    {
        $milikLain = $this->buatTerhapus($slug, $this->labLain);

        $this->actingAs($this->admin)->postJson("/api/{$slug}/{$milikLain->id}/pulihkan")->assertNotFound();

        $this->assertNotNull($milikLain::withTrashed()->find($milikLain->id)->deleted_at);
        $this->assertDatabaseMissing('audit_logs', [
            'entity_type' => $milikLain->getTable(),
            'entity_id' => $milikLain->id,
            'action' => AuditLog::ACTION_DIPULIHKAN,
        ]);
    }

    #[DataProvider('slugEntitas')]
    public function test_baris_yang_tidak_terhapus_atau_tidak_ada_ditolak(string $slug): void
    {
        $hidup = $this->buat($slug);

        $this->actingAs($this->admin)->postJson("/api/{$slug}/{$hidup->id}/pulihkan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'tidak_terhapus');

        $this->actingAs($this->admin)->postJson("/api/{$slug}/999999/pulihkan")->assertNotFound();
        $this->actingAs($this->admin)->postJson("/api/{$slug}/1abc/pulihkan")->assertNotFound();
    }

    public function test_alasan_terlalu_panjang_ditolak_dan_tidak_memulihkan(): void
    {
        $baris = $this->buatTerhapus('rooms');

        $this->actingAs($this->admin)
            ->postJson("/api/rooms/{$baris->id}/pulihkan", ['alasan' => str_repeat('a', 301)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alasan']);

        $this->assertNotNull(Room::withTrashed()->find($baris->id)->deleted_at);
    }

    public function test_alat_tidak_dipulihkan_selama_pt_pemiliknya_masih_terhapus(): void
    {
        $alat = $this->buat('equipments');
        $pt = $alat->customer;
        $alat->delete();
        $pt->delete();

        $this->actingAs($this->admin)->postJson("/api/equipments/{$alat->id}/pulihkan")
            ->assertStatus(422)
            ->assertJsonPath('kode', 'pt_terhapus')
            ->assertJsonPath('id', $pt->id);

        $this->assertNotNull(Equipment::withTrashed()->find($alat->id)->deleted_at);

        // Urutan yang benar: PT dulu, lalu alatnya.
        $this->actingAs($this->admin)->postJson("/api/customers/{$pt->id}/pulihkan")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/equipments/{$alat->id}/pulihkan")->assertOk();
    }

    public function test_sesudah_dipulihkan_barisnya_terlihat_lagi_dan_kodenya_terpakai_lagi(): void
    {
        $lama = $this->buatTerhapus('rooms');

        $this->actingAs($this->admin)->getJson('/api/rooms/'.$lama->id)->assertNotFound();

        $this->actingAs($this->admin)->postJson("/api/rooms/{$lama->id}/pulihkan")->assertOk();

        $this->actingAs($this->admin)->getJson('/api/rooms/'.$lama->id)->assertOk();

        // Sekarang kodenya dipegang baris HIDUP: pesan lama, bukan "terhapus".
        $respons = $this->actingAs($this->admin)->postJson('/api/rooms', ['kode' => $lama->kode, 'nama' => 'x'])
            ->assertStatus(422);
        $this->assertNull($respons->json('kode'));
    }

    public function test_pulihkan_dua_kali_kedua_kalinya_422_dan_audit_tetap_satu(): void
    {
        $baris = $this->buatTerhapus('standards');

        $this->actingAs($this->admin)->postJson("/api/standards/{$baris->id}/pulihkan")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/standards/{$baris->id}/pulihkan")->assertStatus(422);

        $this->assertSame(1, AuditLog::where('entity_type', 'standards')
            ->where('entity_id', $baris->id)
            ->where('action', AuditLog::ACTION_DIPULIHKAN)->count());
    }
}
