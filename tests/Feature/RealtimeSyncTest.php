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
use App\Notifications\Channels\SaluranPush;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Broadcast;
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

        // Dicek di level KELAS, bukan `assertInstanceOf` ke `$event`: yang
        // terakhir nyempitin tipe `$event` jadi cuma `ShouldBroadcast`, padahal
        // di bawah ini masih dipanggil `broadcastAs()`/`broadcastWith()` yang
        // punya kelas eventnya.
        $this->assertContains(
            ShouldBroadcast::class,
            class_implements($event),
            'Event wajib nge-implement ShouldBroadcast — kalau nggak, Laravel nggak nyiarin apa pun.',
        );

        $channel = $event->broadcastOn()[0];
        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-organisasi.7', $channel->name);
        $this->assertSame('data.berubah', $event->broadcastAs());
        $this->assertSame(
            ['jenis' => 'sertifikat', 'aksi' => 'diterbitkan', 'id' => 42],
            $event->broadcastWith(),
        );
    }

    /**
     * Tiga saluran, dan ketiganya menutup keadaan yang berbeda:
     *
     *  - `database` — jejak permanen; lonceng Filament & halaman notifikasi.
     *  - `broadcast` — aplikasi lagi JALAN, di HP maupun panel desktop.
     *  - `push` — HP dengan aplikasi KETUTUP TOTAL, satu-satunya celah yang
     *    websocket nggak bisa tutup.
     *
     * Kalau salah satu kecabut, yang hilang bukan notifikasinya melainkan satu
     * keadaan — dan itu ketahuannya cuma dari orang yang bilang "kok saya nggak
     * dikabarin", berbulan-bulan kemudian.
     */
    public function test_notifikasi_sistem_lewat_database_broadcast_dan_push(): void
    {
        $notif = new AlatJatuhTempo(1, 2, []);

        $this->assertEqualsCanonicalizing(
            ['database', 'broadcast', SaluranPush::class],
            $notif->via($this->admin),
        );
        $this->assertInstanceOf(BroadcastMessage::class, $notif->toBroadcast($this->admin));

        // Muatan push sengaja TIPIS — dia mendarat di layar kunci, kebaca siapa
        // pun yang megang HP-nya. Rinciannya ditarik lewat REST sesudah dibuka.
        $push = $notif->toPush($this->admin);
        $this->assertArrayHasKey('judul', $push);
        $this->assertArrayHasKey('isi', $push);
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

    /**
     * Test di atas cuma nyentuh jalur 401 — itu dicegat middleware SEBELUM closure
     * route-nya jalan, jadi isi closure-nya nggak pernah kepakai. Yang ini nembak
     * dengan token supaya closure-nya beneran dieksekusi.
     *
     * Kenapa perlu: driver `log` (dev) & `null` (test) bikin `auth()` jadi no-op
     * yang balikin 200 body kosong, jadi salah apa pun di dalam closure kelihatan
     * "lolos". Pernah kejadian: `Request` di routes/api.php nggak ke-import, jadi
     * type-hint-nya resolve ke alias global = FACADE, bukan HTTP request-nya.
     * Akibatnya `$request->channel_name` selalu null → begitu produksi pindah ke
     * reverb, semua subscribe kena 403 tanpa satu pun error di log.
     *
     * Makanya di sini dipasang driver penangkap yang beneran baca request-nya.
     */
    public function test_endpoint_auth_channel_nerima_http_request_asli(): void
    {
        $penangkap = new class implements Broadcaster
        {
            public mixed $diterima = null;

            public function auth($request)
            {
                $this->diterima = $request;

                return ['sukses' => true];
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = []): void {}
        };

        Broadcast::extend('penangkap', fn () => $penangkap);
        config([
            'broadcasting.default' => 'penangkap',
            'broadcasting.connections.penangkap' => ['driver' => 'penangkap'],
        ]);

        $channel = 'private-organisasi.'.$this->teknisi->organization_id;

        $this->actingAs($this->teknisi)
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id' => '1234.5678',
            ])
            ->assertOk()
            ->assertJson(['sukses' => true]);

        // Inti test-nya: yang keinjeksi harus HTTP request, bukan facade.
        $this->assertInstanceOf(Request::class, $penangkap->diterima);
        $this->assertSame($channel, $penangkap->diterima->input('channel_name'));
        // Broadcaster butuh user buat nyocokin ke callback di routes/channels.php.
        $this->assertSame($this->teknisi->id, $penangkap->diterima->user()?->id);
    }
}
