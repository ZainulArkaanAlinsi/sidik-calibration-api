<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Channel realtime `organisasi.{id}` cuma buat orang lab.
 *
 * ## Kenapa kepemilikan organisasi saja nggak cukup
 *
 * Akun pelanggan nanti duduk di `organization_id` PT Sidik juga — itu memang
 * rancangannya (01-PRD §8: "Pelanggan selalu berada di `organization_id` PT
 * Sidik"). Jadi penjagaan lama, `$user->organization_id === $organizationId`,
 * MELOLOSKAN mereka. Yang bocor lewat channel ini tiap sesi kalibrasi dan
 * sertifikat yang lewat, milik semua pelanggan lab, realtime.
 *
 * ## Kenapa closure-nya dipanggil lewat refleksi, bukan lewat HTTP
 *
 * `BROADCAST_CONNECTION=null` di kedua phpunit.xml, dan `NullBroadcaster::auth()`
 * itu no-op yang membalas 200 badan kosong — closure di `routes/channels.php`
 * nggak pernah dieksekusi. Test lewat HTTP bakal hijau apa pun isi closure-nya.
 * Jebakan yang sama sudah pernah menggigit repo ini; lihat docblock panjang di
 * `RealtimeSyncTest::test_endpoint_auth_channel_nerima_http_request_asli`.
 *
 * `verifyUserCanAccessChannel()` yang dipanggil di sini adalah method yang
 * SAMA yang dipakai broadcaster sungguhan (Reverb/Pusher) waktu klien
 * subscribe. Jadi yang diuji closure-nya sendiri, bukan tiruannya.
 */
class GerbangChannelRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['nama' => 'PT Contoh Satu']);
    }

    /** Jalankan closure otorisasi channel yang beneran terdaftar. */
    private function boleh(User $user, string $channel): bool
    {
        $request = Request::create('/api/broadcasting/auth', 'POST', [
            'channel_name' => 'private-'.$channel,
        ]);
        $request->setUserResolver(fn (): User => $user);

        $metode = new ReflectionMethod(Broadcaster::class, 'verifyUserCanAccessChannel');

        try {
            $metode->invoke(Broadcast::driver(), $request, $channel);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    private function orangLab(string $role): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => $role,
        ]);
    }

    /**
     * Role di luar tiga role lab ditolak, WALAU organisasinya cocok.
     *
     * Usernya nggak disimpan: `users.role` masih enum(admin,teknisi,viewer)
     * sampai migrasi Fase 3.
     */
    public function test_channel_organisasi_menolak_role_tak_dikenal(): void
    {
        $pelanggan = User::factory()->make([
            'organization_id' => $this->org->id,
            'role' => 'pelanggan',
            'status' => User::STATUS_AKTIF,
        ]);
        $pelanggan->id = 999_999;

        $this->assertFalse(
            $this->boleh($pelanggan, 'organisasi.'.$this->org->id),
            'Role tak dikenal bisa dengerin channel organisasi — tiap sesi & sertifikat '.
            'yang lewat bocor realtime ke pelanggan.'
        );
    }

    /** Tiga role lab tetap diterima — gerbang barunya nggak boleh memutus teknisi. */
    public function test_channel_organisasi_menerima_admin_teknisi_viewer(): void
    {
        foreach (User::roles() as $role) {
            $this->assertTrue(
                $this->boleh($this->orangLab($role), 'organisasi.'.$this->org->id),
                "Role {$role} ditolak di channel organisasinya sendiri — sinkron realtime mati."
            );
        }
    }

    /** Penjagaan lama tetap berlaku: organisasi lain tetap ditolak. */
    public function test_channel_organisasi_tetap_menolak_organisasi_lain(): void
    {
        $lain = Organization::factory()->create(['nama' => 'PT Contoh Tiga']);

        $this->assertFalse(
            $this->boleh($this->orangLab(User::ROLE_ADMIN), 'organisasi.'.$lain->id),
            'Admin satu lab bisa dengerin channel lab lain.'
        );
    }

    /**
     * `App.Models.User.{id}` SENGAJA nggak diberi gerbang role — cuma
     * kepemilikan. Notifikasi milik seseorang tetap miliknya apa pun rolenya,
     * dan pelanggan nanti tetap butuh lonceng sendiri.
     */
    public function test_channel_user_tetap_memeriksa_kepemilikan_saja(): void
    {
        $teknisi = $this->orangLab(User::ROLE_TEKNISI);
        $admin = $this->orangLab(User::ROLE_ADMIN);

        $this->assertTrue($this->boleh($teknisi, 'App.Models.User.'.$teknisi->id));
        $this->assertFalse(
            $this->boleh($teknisi, 'App.Models.User.'.$admin->id),
            'Teknisi bisa dengerin lonceng notifikasi orang lain.'
        );
    }
}
