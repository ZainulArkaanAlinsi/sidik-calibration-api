<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        Customer::factory()->create();
        EquipmentCategory::factory()->create(['kode' => 'panjang']);
    }

    public function test_dashboard_ngitung_alat_dan_yang_overdue(): void
    {
        Equipment::factory()->count(3)->create();
        Equipment::factory()->overdue()->count(2)->create();

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'total_alat', 'alat_overdue', 'kalibrasi_draft', 'menunggu_approval',
                'kalibrasi_selesai', 'menunggu_proses', 'sertifikat_bulan_ini',
                'grafik_pekerjaan' => [['bulan', 'label', 'masuk', 'selesai']],
            ]])
            ->assertJsonPath('data.total_alat', 5)
            ->assertJsonPath('data.alat_overdue', 2);
    }

    /**
     * Kartu "Kalibrasi selesai" & "Menunggu proses". Yang selesai itu sesi
     * DISETUJUI; sisanya — draft, nunggu approval, minta revisi — semuanya masih
     * butuh sentuhan orang, jadi kehitung menunggu proses.
     */
    public function test_dashboard_misahin_kalibrasi_selesai_dan_menunggu_proses(): void
    {
        $admin = User::factory()->admin()->create();

        $this->bikinSesi($admin, CalibrationSession::STATUS_DISETUJUI);
        $this->bikinSesi($admin, CalibrationSession::STATUS_DISETUJUI);
        $this->bikinSesi($admin, CalibrationSession::STATUS_DRAFT);
        $this->bikinSesi($admin, CalibrationSession::STATUS_MENUNGGU_APPROVAL);
        $this->bikinSesi($admin, CalibrationSession::STATUS_PERLU_REVISI);

        $this->actingAs($admin)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kalibrasi_selesai', 2)
            // Termasuk `perlu_revisi` — sesi yang ditolak balik ke meja teknisi,
            // bukan ilang dari hitungan.
            ->assertJsonPath('data.menunggu_proses', 3);
    }

    public function test_grafik_pekerjaan_keluar_6_bulan_termasuk_bulan_kosong(): void
    {
        $admin = User::factory()->admin()->create();

        $this->bikinSesi($admin, CalibrationSession::STATUS_DISETUJUI, now(), now());
        $this->bikinSesi($admin, CalibrationSession::STATUS_DRAFT, now()->subMonths(2));
        // Di luar jendela 6 bulan — nggak boleh nempel ke bulan mana pun.
        $this->bikinSesi($admin, CalibrationSession::STATUS_DRAFT, now()->subMonths(9));

        $response = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk();

        $grafik = collect($response->json('data.grafik_pekerjaan'));

        $this->assertCount(6, $grafik);
        // Urutannya lama → baru, biar mobile bisa langsung gambar tanpa nyortir.
        $this->assertSame(now()->startOfMonth()->subMonths(5)->format('Y-m'), $grafik->first()['bulan']);
        $this->assertSame(now()->format('Y-m'), $grafik->last()['bulan']);

        $this->assertSame(1, $grafik->last()['masuk']);
        $this->assertSame(1, $grafik->last()['selesai']);

        $duaBulanLalu = $grafik->firstWhere('bulan', now()->subMonths(2)->format('Y-m'));
        $this->assertSame(1, $duaBulanLalu['masuk']);
        // Masih draft — masuk iya, selesai belum.
        $this->assertSame(0, $duaBulanLalu['selesai']);

        // Bulan tanpa kerjaan tetap keluar dengan nol, bukan dilewat. Kalau
        // dilewat, jeda kosongnya ketutup dan trennya kelihatan bohong.
        $limaBulanLalu = $grafik->firstWhere('bulan', now()->subMonths(5)->format('Y-m'));
        $this->assertSame(0, $limaBulanLalu['masuk']);
        $this->assertSame(0, $limaBulanLalu['selesai']);
    }

    /** Teknisi cuma lihat grafik kerjaannya sendiri, sama kayak kartu angkanya. */
    public function test_grafik_pekerjaan_ikut_kesaring_per_teknisi(): void
    {
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->bikinSesi($teknisi, CalibrationSession::STATUS_DRAFT);
        $this->bikinSesi($teknisiLain, CalibrationSession::STATUS_DRAFT);
        $this->bikinSesi($teknisiLain, CalibrationSession::STATUS_DRAFT);

        $bulanIni = fn (array $data): array => collect($data)->last();

        $this->actingAs($teknisi)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.grafik_pekerjaan', fn (array $g) => $bulanIni($g)['masuk'] === 1);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.grafik_pekerjaan', fn (array $g) => $bulanIni($g)['masuk'] === 3);
    }

    private function bikinSesi(
        User $teknisi,
        string $status,
        ?Carbon $tanggalKalibrasi = null,
        ?Carbon $reviewedAt = null,
    ): CalibrationSession {
        return CalibrationSession::create([
            'organization_id' => $teknisi->organization_id,
            'equipment_id' => Equipment::query()->value('id') ?? Equipment::factory()->create()->id,
            'teknisi_id' => $teknisi->id,
            'status' => $status,
            'tanggal_kalibrasi' => $tanggalKalibrasi ?? now(),
            'reviewed_at' => $reviewedAt,
        ]);
    }

    /**
     * Teknisi cuma lihat kalibrasi miliknya sendiri, admin lihat semua — dan
     * role-nya diambil dari token, bukan dari yang dikirim mobile.
     */
    public function test_teknisi_cuma_ngitung_kalibrasi_miliknya_sendiri(): void
    {
        $alat = Equipment::factory()->create();
        $teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $bikinDraft = fn (User $u) => CalibrationSession::create([
            'organization_id' => $u->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $u->id,
            'status' => CalibrationSession::STATUS_DRAFT,
            'tanggal_kalibrasi' => now(),
        ]);

        $bikinDraft($teknisi);
        $bikinDraft($teknisiLain);
        $bikinDraft($teknisiLain);

        $this->actingAs($teknisi)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kalibrasi_draft', 1);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kalibrasi_draft', 3);
    }
}
