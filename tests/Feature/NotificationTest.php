<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifikasi kejadian yang butuh tindakan (Permintaan Backend Fase 2 §2).
 *
 * Yang diuji bukan cuma "notifikasinya kebikin", tapi juga **nyampe ke orang
 * yang bener**: admin nggak boleh kebanjiran notifikasi PT lain, dan teknisi
 * nggak boleh lihat notifikasi punya teknisi lain.
 */
class NotificationTest extends TestCase
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

    private function submitSesi(): CalibrationSession
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_sesi_masuk_antrean_approval_ngabarin_admin(): void
    {
        $this->submitSesi();

        $this->assertSame(1, $this->admin->unreadNotifications()->count());
        // Teknisi yang ngirim nggak perlu dikabarin soal kerjaannya sendiri.
        $this->assertSame(0, $this->teknisi->unreadNotifications()->count());

        $this->actingAs($this->admin)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.tipe', 'kalibrasi.menunggu_approval')
            ->assertJsonPath('data.0.tautan.jenis', 'kalibrasi');
    }

    public function test_draft_nggak_ngabarin_siapa_pun(): void
    {
        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'status' => CalibrationSession::STATUS_DRAFT,
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $this->assertSame(0, $this->admin->unreadNotifications()->count());
    }

    public function test_sesi_ditolak_ngabarin_teknisi_pembuatnya(): void
    {
        $sesi = $this->submitSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Titik ukur kurang'])
            ->assertOk();

        $this->actingAs($this->teknisi)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.tipe', 'kalibrasi.perlu_revisi')
            ->assertJsonPath('data.0.isi', 'Titik ukur kurang');
    }

    public function test_pendaftaran_akun_baru_ngabarin_admin(): void
    {
        $this->postJson('/api/register', [
            'nama' => 'Budi Baru',
            'employee_id' => 'SDK-1234',
            'department' => 'Kalibrasi',
            'email' => 'budi@sidik.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.tipe', 'pengguna.menunggu_persetujuan');
    }

    public function test_notifikasi_nggak_bocor_antar_organisasi(): void
    {
        $this->submitSesi();

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);

        $this->assertSame(0, $adminLain->unreadNotifications()->count());
        $this->actingAs($adminLain)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filter_belum_dibaca_dan_tandai_baca(): void
    {
        $this->submitSesi();

        $id = $this->actingAs($this->admin)->getJson('/api/notifications?belum_dibaca=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta_tambahan.belum_dibaca', 1)
            ->json('data.0.id');

        $this->actingAs($this->admin)->postJson("/api/notifications/{$id}/baca")
            ->assertOk()
            ->assertJsonPath('data.dibaca_pada', fn ($v) => $v !== null);

        // Udah kebaca: ilang dari filter, badge balik nol, tapi barisnya tetap ada.
        $this->actingAs($this->admin)->getJson('/api/notifications?belum_dibaca=1')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta_tambahan.belum_dibaca', 0);

        $this->actingAs($this->admin)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_nggak_bisa_nandain_baca_notifikasi_orang_lain(): void
    {
        $this->submitSesi();
        $id = $this->admin->notifications()->firstOrFail()->id;

        $this->actingAs($this->teknisi)
            ->postJson("/api/notifications/{$id}/baca")
            ->assertNotFound();
    }

    public function test_baca_semua(): void
    {
        $this->submitSesi();
        $this->submitSesi();

        $this->actingAs($this->admin)->postJson('/api/notifications/baca-semua')
            ->assertOk()
            ->assertJsonPath('data.ditandai_dibaca', 2);

        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_butuh_login(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}
