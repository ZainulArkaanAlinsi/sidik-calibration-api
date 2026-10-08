<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sembunyikan sesi dari layar Riwayat — §47 `docs/permintaan-user-7.md`,
 * keputusan pemilik 8 Okt 2026.
 *
 * Yang dijaga di sini dua hal yang sama-sama TIDAK memunculkan error kalau
 * dilanggar:
 *
 * 1. Ini preferensi tampilan PER AKUN. Sesi, pembacaan, sertifikat, dan jejak
 *    audit tidak boleh berubah satu baris pun, dan akun lain tetap melihat
 *    sesinya tanpa tanda tersembunyi. Versi yang "menyembunyikan" dengan
 *    mengubah sesi (kolom di `calibration_sessions`, soft delete) tetap
 *    menjawab 200 — cuma diam-diam menghilangkan sesi dari layar admin.
 * 2. Yang boleh menyembunyikan = yang bisa melihat sesi itu di `index`.
 *    Teknisi lain dan lab lain dijawab 404 (bukan 403 — 403 mengakui sesinya
 *    ada).
 */
class RiwayatSembunyikanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private User $viewer;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->admin = $this->akun($this->org, User::ROLE_ADMIN);
        $this->teknisi = $this->akun($this->org, User::ROLE_TEKNISI);
        $this->viewer = $this->akun($this->org, User::ROLE_VIEWER);

        $this->sesi = $this->bikinSesi($this->org, $this->teknisi);

        RawMeasurement::create([
            'calibration_session_id' => $this->sesi->id,
            'titik_ke' => 1,
            'pembacaan_ke' => 1,
            'titik_ukur' => 10.0,
            'pembacaan' => 10.01,
            'satuan' => 'mm',
            'input_source' => 'manual',
            'is_verified' => true,
        ]);

        Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $this->sesi->id,
        ]);
    }

    public function test_sembunyikan_cuma_berlaku_untuk_akun_yang_menyembunyikan(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson($this->url())
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->sesi->id, 'tersembunyi' => true]]);

        // Daftarnya TIDAK menyaring — sesinya tetap ada, cuma bertanda.
        $this->assertSame(true, $this->tersembunyiDiIndex($this->teknisi));

        // Akun lain di lab yang sama tetap melihatnya tanpa tanda.
        $this->assertSame(false, $this->tersembunyiDiIndex($this->admin));
        $this->assertSame(false, $this->tersembunyiDiIndex($this->viewer));
    }

    public function test_tampilkan_lagi_mengembalikan_false(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')->postJson($this->url())->assertOk();

        $this->actingAs($this->teknisi, 'sanctum')
            ->deleteJson($this->url())
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->sesi->id, 'tersembunyi' => false]]);

        $this->assertSame(false, $this->tersembunyiDiIndex($this->teknisi));
        $this->assertDatabaseCount('riwayat_tersembunyi', 0);
    }

    public function test_idempoten_dua_kali_post_dan_dua_kali_delete(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')->postJson($this->url())->assertOk();
        $this->actingAs($this->teknisi, 'sanctum')
            ->postJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.tersembunyi', true);

        $this->assertDatabaseCount('riwayat_tersembunyi', 1);

        $this->actingAs($this->teknisi, 'sanctum')->deleteJson($this->url())->assertOk();
        $this->actingAs($this->teknisi, 'sanctum')
            ->deleteJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.tersembunyi', false);

        $this->assertDatabaseCount('riwayat_tersembunyi', 0);
    }

    /**
     * Inti keputusan pemilik: tidak ada data lab yang berubah. Dihitung dari
     * baris, bukan dari respons — respons 200 tidak membuktikan apa-apa soal
     * tabel lain.
     */
    public function test_sesi_pembacaan_sertifikat_dan_audit_tetap_utuh(): void
    {
        $sebelum = $this->hitungBaris();
        $sesiSebelum = $this->sesi->fresh()->getAttributes();

        $this->actingAs($this->teknisi, 'sanctum')->postJson($this->url())->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url())->assertOk();

        $this->assertSame($sebelum, $this->hitungBaris(), 'Menyembunyikan mengubah jumlah baris data lab.');
        $this->assertSame($sesiSebelum, $this->sesi->fresh()->getAttributes(), 'Menyembunyikan mengubah baris sesi.');
        $this->assertDatabaseCount('riwayat_tersembunyi', 2);

        $this->actingAs($this->teknisi, 'sanctum')->deleteJson($this->url())->assertOk();

        $this->assertSame($sebelum, $this->hitungBaris(), 'Menampilkan lagi mengubah jumlah baris data lab.');
        $this->assertSame($sesiSebelum, $this->sesi->fresh()->getAttributes(), 'Menampilkan lagi mengubah baris sesi.');
        // Penyembunyian admin tidak ikut terhapus oleh teknisi.
        $this->assertDatabaseHas('riwayat_tersembunyi', [
            'user_id' => $this->admin->id,
            'calibration_session_id' => $this->sesi->id,
        ]);
    }

    public function test_teknisi_tidak_bisa_menyembunyikan_sesi_teknisi_lain(): void
    {
        $teknisiLain = $this->akun($this->org, User::ROLE_TEKNISI);

        $this->actingAs($teknisiLain, 'sanctum')->postJson($this->url())->assertNotFound();
        $this->actingAs($teknisiLain, 'sanctum')->deleteJson($this->url())->assertNotFound();

        $this->assertDatabaseCount('riwayat_tersembunyi', 0);
    }

    public function test_admin_lab_lain_dijawab_404(): void
    {
        $labLain = Organization::factory()->create();
        $adminLain = $this->akun($labLain, User::ROLE_ADMIN);

        $this->actingAs($adminLain, 'sanctum')->postJson($this->url())->assertNotFound();
        $this->actingAs($adminLain, 'sanctum')->deleteJson($this->url())->assertNotFound();

        $this->assertDatabaseCount('riwayat_tersembunyi', 0);
    }

    /** Viewer ikut membaca `GET /calibrations`, jadi dia juga boleh merapikan Riwayat-nya. */
    public function test_viewer_boleh_menyembunyikan(): void
    {
        $this->actingAs($this->viewer, 'sanctum')
            ->postJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.tersembunyi', true);

        $this->assertSame(true, $this->tersembunyiDiIndex($this->viewer));
        $this->assertSame(false, $this->tersembunyiDiIndex($this->teknisi));
    }

    /** Admin melihat semua sesi lab di `index`, jadi boleh menyembunyikan sesi teknisi mana pun. */
    public function test_admin_boleh_menyembunyikan_sesi_teknisi_mana_pun(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url())->assertOk();

        $this->assertSame(true, $this->tersembunyiDiIndex($this->admin));
        $this->assertSame(false, $this->tersembunyiDiIndex($this->teknisi));
    }

    /** `lolosBacaSuperAdmin` cuma meloloskan GET/HEAD — rute ini tidak dilonggarkan. */
    public function test_super_admin_ditolak_menulis(): void
    {
        $super = $this->akun($this->org, User::ROLE_SUPER_ADMIN);

        $this->actingAs($super, 'sanctum')->postJson($this->url())->assertForbidden();
        $this->actingAs($super, 'sanctum')->deleteJson($this->url())->assertForbidden();

        // Membaca daftarnya tetap boleh, dan tandanya tetap terisi (false).
        $this->assertSame(false, $this->tersembunyiDiIndex($super));
    }

    /**
     * Tanpa N+1: tandanya datang dari SATU subquery EXISTS di query daftar,
     * berapa pun jumlah barisnya.
     */
    public function test_index_membaca_tanda_dengan_satu_query(): void
    {
        foreach (range(1, 4) as $_) {
            $lain = $this->bikinSesi($this->org, $this->teknisi);
            $this->actingAs($this->teknisi, 'sanctum')
                ->postJson("/api/calibrations/{$lain->id}/sembunyikan")
                ->assertOk();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->getJson('/api/calibrations')
            ->assertOk()
            ->json('data');

        $kueri = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'riwayat_tersembunyi'));
        DB::disableQueryLog();

        $this->assertCount(5, $data);
        $this->assertCount(1, $kueri, 'Tanda `tersembunyi` dibaca lebih dari satu query.');
        $this->assertSame(4, collect($data)->where('tersembunyi', true)->count());
        $this->assertSame(1, collect($data)->where('tersembunyi', false)->count());
    }

    /** Di luar `index` atributnya tidak dimuat — kuncinya tetap ada dan bertipe bool. */
    public function test_detail_sesi_tetap_membawa_kunci_tersembunyi(): void
    {
        $this->actingAs($this->teknisi, 'sanctum')
            ->getJson("/api/calibrations/{$this->sesi->id}")
            ->assertOk()
            ->assertJsonPath('data.tersembunyi', false);
    }

    public function test_rute_ber_throttle_dengan_limiter_terdaftar(): void
    {
        foreach (['POST', 'DELETE'] as $metode) {
            $rute = collect(Route::getRoutes()->getRoutes())->first(
                fn ($r) => $r->uri() === 'api/calibrations/{calibration}/sembunyikan'
                    && in_array($metode, $r->methods(), true),
            );

            $this->assertNotNull($rute, "{$metode} sembunyikan tidak terdaftar.");
            $this->assertContains('throttle:riwayat-sembunyikan', $rute->gatherMiddleware());
            // Sama grupnya dengan `GET /calibrations`.
            $this->assertContains('role:admin,teknisi,viewer', $rute->gatherMiddleware());
        }

        // Limiter yang namanya tidak terdaftar dianggap "tanpa batas" oleh
        // Laravel — throttle-nya hilang tanpa error.
        $this->assertNotNull(app(RateLimiter::class)->limiter('riwayat-sembunyikan'));
    }

    // ------------------------------------------------------------- pembantu

    private function url(): string
    {
        return "/api/calibrations/{$this->sesi->id}/sembunyikan";
    }

    private function tersembunyiDiIndex(User $user): ?bool
    {
        $baris = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/calibrations')->assertOk()->json('data'),
        )->firstWhere('id', $this->sesi->id);

        $this->assertNotNull($baris, 'Sesi hilang dari daftar — daftar tidak boleh menyaring yang tersembunyi.');

        return $baris['tersembunyi'];
    }

    /** @return array<string, int> */
    private function hitungBaris(): array
    {
        return collect(['calibration_sessions', 'raw_measurements', 'certificates', 'audit_logs'])
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])
            ->all();
    }

    private function akun(Organization $org, string $role): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'role' => $role,
            'status' => User::STATUS_AKTIF,
        ]);
    }

    private function bikinSesi(Organization $org, User $teknisi): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $org->id])->id,
        ]);

        return CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
        ]);
    }
}
