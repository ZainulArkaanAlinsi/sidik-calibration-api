<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AlatJatuhTempo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pengingat jatuh tempo (spec poin 6): tanggal di-set admin per alat, ambang H-
 * diatur admin per org (default 30 hari), jalan otomatis (scheduler) ATAU manual
 * (admin mencet endpoint).
 */
class ReminderJatuhTempoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Customer $pelanggan;

    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
        $this->pelanggan = Customer::factory()->create();
        $this->kategori = EquipmentCategory::factory()->create();
    }

    private function alat(string $jatuhTempo): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'status' => Equipment::STATUS_AKTIF,
            'tanggal_jatuh_tempo' => $jatuhTempo,
        ]);
    }

    public function test_manual_kirim_pengingat_alat_mendekati_dan_overdue(): void
    {
        Notification::fake();

        $this->alat(now()->addDays(20)->toDateString());  // mendekati (dalam 30 hari)
        $this->alat(now()->subDays(5)->toDateString());    // sudah lewat (overdue)
        $this->alat(now()->addDays(60)->toDateString());   // jauh — di luar default 30

        $this->actingAs($this->admin)
            ->postJson('/api/reminders/jatuh-tempo')
            ->assertOk()
            ->assertJsonPath('data.ambang_hari', 30)
            ->assertJsonPath('data.mendekati', 1)
            ->assertJsonPath('data.overdue', 1)
            ->assertJsonPath('data.admin_dikabarin', 1);

        Notification::assertSentTo($this->admin, AlatJatuhTempo::class);
    }

    public function test_lead_time_custom_dari_setting_org(): void
    {
        Notification::fake();
        // Admin set ambang jadi 90 hari (± 3 bulan sebelum jatuh tempo).
        $this->admin->organization->update(['settings' => ['reminder_hari_sebelum' => 90]]);

        $this->alat(now()->addDays(60)->toDateString()); // masuk kalau ambang 90

        $this->actingAs($this->admin)
            ->postJson('/api/reminders/jatuh-tempo')
            ->assertOk()
            ->assertJsonPath('data.ambang_hari', 90)
            ->assertJsonPath('data.mendekati', 1);
    }

    public function test_override_hari_lewat_request(): void
    {
        Notification::fake();
        $this->alat(now()->addDays(60)->toDateString());

        $this->actingAs($this->admin)
            ->postJson('/api/reminders/jatuh-tempo', ['hari' => 90])
            ->assertOk()
            ->assertJsonPath('data.ambang_hari', 90)
            ->assertJsonPath('data.mendekati', 1);
    }

    public function test_tidak_ada_yang_jatuh_tempo(): void
    {
        Notification::fake();
        $this->alat(now()->addDays(200)->toDateString()); // masih jauh

        $this->actingAs($this->admin)
            ->postJson('/api/reminders/jatuh-tempo')
            ->assertOk()
            ->assertJsonPath('data.admin_dikabarin', 0);

        Notification::assertNothingSent();
    }

    public function test_teknisi_tidak_boleh_memicu(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/reminders/jatuh-tempo')
            ->assertForbidden();
    }

    public function test_command_scheduler_pakai_setting_org(): void
    {
        Notification::fake();
        $this->admin->organization->update(['settings' => ['reminder_hari_sebelum' => 90]]);
        $this->alat(now()->addDays(60)->toDateString());

        $this->artisan('alat:cek-jatuh-tempo')->assertSuccessful();

        Notification::assertSentTo($this->admin, AlatJatuhTempo::class);
    }
}
