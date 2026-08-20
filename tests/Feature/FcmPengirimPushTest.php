<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Push\FcmPengirimPush;
use App\Services\Push\PengirimPush;
use App\Services\Push\PengirimPushMati;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pengirim FCM — HTTP-nya dipalsukan, kredensialnya tidak.
 *
 * Yang diuji: BENTUK pesan yang berangkat, dan keputusan "token ini mati atau
 * cuma lagi apes". Yang kedua paling menentukan, karena salahnya senyap ke dua
 * arah — token sehat kehapus gara-gara FCM sempat 503, atau token mati numpuk
 * selamanya karena FCM memang tidak pernah mengabarkan aplikasi dicopot.
 */
class FcmPengirimPushTest extends TestCase
{
    use RefreshDatabase;

    private DeviceToken $perangkat;

    protected function setUp(): void
    {
        parent::setUp();

        $teknisi = User::factory()->create([
            'role' => 'teknisi',
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $this->perangkat = DeviceToken::catat($teknisi, 'fcm-abc', 'android');

        // Token akses dipasang di cache supaya tesnya nggak menyentuh berkas
        // service account sama sekali — yang diuji pengirimannya, bukan
        // penandatanganan JWT punya Google.
        Cache::put('fcm.token_akses', 'akses-palsu', now()->addHour());
    }

    private function pengirim(HttpFactory $http): FcmPengirimPush
    {
        return new FcmPengirimPush($http, 'proyek-uji', '/tidak/dipakai.json');
    }

    public function test_terkirim_dan_bentuk_pesannya_benar(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response(['name' => 'projects/proyek-uji/messages/1']),
        ]);

        $hasil = $this->pengirim($http)->kirim(
            $this->perangkat,
            'Lembar kerja masuk',
            'CAL/2026/08/0001 — pH Meter',
            ['tautan_tipe' => 'calibration', 'tautan_id' => '7'],
        );

        $this->assertTrue($hasil);

        $http->assertSent(function ($request) {
            $pesan = $request->data()['message'];

            // `notification` WAJIB ikut: kalau cuma `data`, Android nggak
            // menampilkan apa pun sampai aplikasinya dibuka — persis keadaan
            // yang mau ditutup push ini.
            $this->assertSame('Lembar kerja masuk', $pesan['notification']['title']);
            $this->assertSame('fcm-abc', $pesan['token']);
            $this->assertSame('sidik_kerja', $pesan['android']['notification']['channel_id']);
            $this->assertSame('calibration', $pesan['data']['tautan_tipe']);
            $this->assertStringContainsString('proyek-uji', $request->url());

            return true;
        });
    }

    /** Semua nilai `data` wajib string di HTTP v1; angka mentah ditolak 400. */
    public function test_muatan_data_selalu_string(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response([])]);

        $this->pengirim($http)->kirim($this->perangkat, 'j', 'i', ['tautan_id' => 7]);

        $http->assertSent(function ($request) {
            $this->assertSame('7', $request->data()['message']['data']['tautan_id']);

            return true;
        });
    }

    public function test_404_berarti_token_mati(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['error' => ['status' => 'NOT_FOUND']], 404)]);

        $pengirim = $this->pengirim($http);

        $this->assertFalse($pengirim->kirim($this->perangkat, 'j', 'i'));
        $this->assertTrue($pengirim->tokenMati());
    }

    public function test_400_unregistered_berarti_token_mati(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response([
                'error' => ['details' => [['errorCode' => 'UNREGISTERED']]],
            ], 400),
        ]);

        $pengirim = $this->pengirim($http);
        $pengirim->kirim($this->perangkat, 'j', 'i');

        $this->assertTrue($pengirim->tokenMati());
    }

    /**
     * 503 itu layanannya yang lagi bermasalah. Menghapus token di sini berarti
     * satu gangguan sesaat di pihak Google mencabut notifikasi seluruh karyawan
     * — dan tidak ada yang tahu sebabnya.
     */
    public function test_503_tidak_mencabut_token(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response([], 503)]);

        $pengirim = $this->pengirim($http);

        $this->assertFalse($pengirim->kirim($this->perangkat, 'j', 'i'));
        $this->assertFalse($pengirim->tokenMati());
    }

    /**
     * 401/403 = kredensial SERVER yang salah, bukan salah perangkatnya. Satu
     * salah setel tidak boleh mengosongkan seluruh tabel `device_tokens`.
     */
    public function test_401_dan_403_tidak_mencabut_token(): void
    {
        foreach ([401, 403] as $status) {
            $http = new HttpFactory;
            $http->fake(['*' => $http->response([], $status)]);

            $pengirim = $this->pengirim($http);
            $pengirim->kirim($this->perangkat, 'j', 'i');

            $this->assertFalse($pengirim->tokenMati(), "status {$status} nggak boleh mencabut token");
        }
    }

    /** Keadaan "mati" tidak boleh nempel dari pengiriman sebelumnya. */
    public function test_status_token_mati_direset_tiap_kirim(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response([], 404)]);

        $pengirim = $this->pengirim($http);
        $pengirim->kirim($this->perangkat, 'j', 'i');
        $this->assertTrue($pengirim->tokenMati());

        $http2 = new HttpFactory;
        $http2->fake(['*' => $http2->response(['name' => 'ok'])]);
        $pengirim2 = $this->pengirim($http2);
        $pengirim2->kirim($this->perangkat, 'j', 'i');
        $this->assertFalse($pengirim2->tokenMati());
    }

    /**
     * Kredensial tidak terbaca itu masalah server. Jangan sampai satu berkas
     * keliru menghapus seluruh perangkat terdaftar.
     */
    public function test_tanpa_token_akses_gagal_tanpa_mencabut(): void
    {
        Cache::forget('fcm.token_akses');

        $http = new HttpFactory;
        $http->fake(['*' => $http->response([])]);

        $pengirim = new FcmPengirimPush($http, 'proyek-uji', '/berkas/tidak/ada.json');

        $this->assertFalse($pengirim->kirim($this->perangkat, 'j', 'i'));
        $this->assertFalse($pengirim->tokenMati());
        $http->assertNothingSent();
    }

    /** Kegagalan baca kredensial TIDAK boleh ikut ke-cache. */
    public function test_kegagalan_kredensial_tidak_dicache(): void
    {
        Cache::forget('fcm.token_akses');

        $http = new HttpFactory;
        $http->fake(['*' => $http->response([])]);

        (new FcmPengirimPush($http, 'proyek-uji', '/berkas/tidak/ada.json'))
            ->kirim($this->perangkat, 'j', 'i');

        // Kalau `null` ikut tersimpan, push mati 55 menit penuh jauh sesudah
        // sebabnya hilang.
        $this->assertNull(Cache::get('fcm.token_akses'));
    }

    /** Setengah disetel = pengirim yang diam, bukan pengirim yang selalu gagal. */
    public function test_konfigurasi_setengah_jadi_tetap_memakai_pengirim_diam(): void
    {
        config()->set('services.fcm.project_id', 'proyek-uji');
        config()->set('services.fcm.credentials', null);
        $this->app->forgetInstance(PengirimPush::class);

        $this->assertInstanceOf(PengirimPushMati::class, app(PengirimPush::class));
    }

    /** Berkas kredensial yang ditunjuk tapi tidak ada juga dianggap belum disetel. */
    public function test_berkas_kredensial_hilang_tetap_memakai_pengirim_diam(): void
    {
        config()->set('services.fcm.project_id', 'proyek-uji');
        config()->set('services.fcm.credentials', '/berkas/tidak/ada.json');
        $this->app->forgetInstance(PengirimPush::class);

        $this->assertInstanceOf(PengirimPushMati::class, app(PengirimPush::class));
    }
}
