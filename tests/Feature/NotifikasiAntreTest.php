<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Channels\SaluranPush;
use App\Notifications\NotifikasiSistem;
use App\Notifications\SesiDisetujui;
use App\Services\Push\FcmPengirimPush;
use App\Services\Push\PengirimPush;
use App\Services\Push\PengirimPushMati;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** G5 (K-50-13): notifikasi diantre, payload push punya tujuan + kategori + ikon Android. */
class NotifikasiAntreTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'teknisi',
            'organization_id' => Organization::factory()->create()->id,
        ]);
    }

    public function test_notifikasi_sistem_diantre_setelah_commit_dengan_backoff(): void
    {
        $n = new SesiDisetujui(1, 'CAL/1', 'pH Meter', 'lulus');

        $this->assertInstanceOf(ShouldQueue::class, $n);
        $this->assertInstanceOf(NotifikasiSistem::class, $n);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $n);
        $this->assertSame([10, 60, 300], $n->backoff());
    }

    public function test_mengirim_notifikasi_hanya_mengantre_job_tidak_memanggil_fcm(): void
    {
        Queue::fake();
        $user = $this->user();

        $user->notify(new SesiDisetujui(1, 'CAL/1', 'pH Meter', 'lulus'));

        Queue::assertPushed(SendQueuedNotifications::class);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_notification_fake_melihat_notifikasi_terkirim(): void
    {
        Notification::fake();
        $user = $this->user();

        $user->notify(new SesiDisetujui(1, 'CAL/1', 'pH Meter', 'lulus'));

        Notification::assertSentTo($user, SesiDisetujui::class);
    }

    public function test_payload_push_membawa_tujuan_dan_kategori(): void
    {
        $data = (new SesiDisetujui(12, 'CAL/1', 'pH Meter', 'lulus'))
            ->toPush($this->user())['data'];

        $this->assertSame('calibration/12', $data['tujuan']);
        $this->assertNotSame('', $data['kategori']);
    }

    public function test_pesan_fcm_membawa_ikon_dan_warna_android(): void
    {
        $user = $this->user();
        $perangkat = DeviceToken::catat($user, 'fcm-abc', 'android');
        $http = new HttpFactory;
        $http->fake(['*' => $http->response([])]);
        Cache::put('fcm.token_akses', 'tok', 60);

        (new FcmPengirimPush($http, 'proyek-uji', '/tidak/ada.json'))
            ->kirim($perangkat, 'j', 'i', ['tujuan' => 'calibration/1']);

        $http->assertSent(function ($request) {
            $n = $request->data()['message']['android']['notification'];
            $this->assertSame('ic_stat_sidik', $n['icon']);
            $this->assertSame('#043EA1', $n['color']);

            return true;
        });
    }

    public function test_tanpa_kredensial_fcm_saluran_diam_tanpa_exception(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.credentials' => null]);
        $user = $this->user();
        DeviceToken::catat($user, 'fcm-abc', 'android');

        $this->assertInstanceOf(PengirimPushMati::class, app(PengirimPush::class));

        app(SaluranPush::class)->send($user, new SesiDisetujui(1, 'CAL/1', 'pH Meter', 'lulus'));

        $this->assertDatabaseHas('device_tokens', ['token' => 'fcm-abc']);
    }
}
