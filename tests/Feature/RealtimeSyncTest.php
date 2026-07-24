<?php

namespace Tests\Feature;

use App\Events\PerubahanDataOrganisasi;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Notifications\AlatJatuhTempo;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Realtime sync mobile ↔ desktop (spec poin 12D): perubahan data & notifikasi
 * di-broadcast biar HP & panel desktop nunjukin data yang sama, barengan.
 */
class RealtimeSyncTest extends TestCase
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
            'equipment_category_id' => EquipmentCategory::factory()->create()->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create();
    }

    public function test_store_kalibrasi_menyiarkan_sinyal_perubahan(): void
    {
        Event::fake([PerubahanDataOrganisasi::class]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01]]],
        ])->assertCreated();

        Event::assertDispatched(
            PerubahanDataOrganisasi::class,
            fn (PerubahanDataOrganisasi $e): bool => $e->jenis === 'kalibrasi'
                && $e->aksi === 'dibuat'
                && $e->organizationId === $this->teknisi->organization_id,
        );
    }

    public function test_reject_menyiarkan_sinyal_ditolak(): void
    {
        $sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $this->alat->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        Event::fake([PerubahanDataOrganisasi::class]);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => 'Tolong perbaiki.'])
            ->assertOk();

        Event::assertDispatched(
            PerubahanDataOrganisasi::class,
            fn (PerubahanDataOrganisasi $e): bool => $e->aksi === 'ditolak' && $e->id === $sesi->id,
        );
    }

    public function test_event_broadcast_ke_channel_privat_organisasi(): void
    {
        $event = new PerubahanDataOrganisasi(7, 'sertifikat', 'diterbitkan', 42);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);

        $channel = $event->broadcastOn()[0];
        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-organisasi.7', $channel->name);
        $this->assertSame('data.berubah', $event->broadcastAs());
        $this->assertSame(
            ['jenis' => 'sertifikat', 'aksi' => 'diterbitkan', 'id' => 42],
            $event->broadcastWith(),
        );
    }

    public function test_notifikasi_sistem_lewat_database_dan_broadcast(): void
    {
        $notif = new AlatJatuhTempo(1, 2, []);

        $this->assertEqualsCanonicalizing(['database', 'broadcast'], $notif->via($this->admin));
        $this->assertInstanceOf(BroadcastMessage::class, $notif->toBroadcast($this->admin));
    }

    public function test_endpoint_auth_channel_butuh_login(): void
    {
        // Tanpa token → 401. Otorisasi per-channel (org membership) ada di
        // routes/channels.php, dipakai Echo waktu subscribe.
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-organisasi.'.$this->teknisi->organization_id,
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }
}
