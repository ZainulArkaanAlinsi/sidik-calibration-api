<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pengirim push lewat Firebase Cloud Messaging HTTP v1.
 *
 * Cuma dipakai buat SATU keadaan: HP dengan aplikasi ketutup total. Selama
 * aplikasinya jalan, kabarnya sudah sampai lewat Reverb — jalur yang nggak
 * butuh layanan pihak ketiga sama sekali. Firebase di proyek ini statusnya
 * sementara, dan kelas ini sengaja jadi satu-satunya tempat namanya disebut:
 * menggantinya nanti berarti membuang satu berkas, bukan menelusuri seluruh
 * aplikasi.
 */
class FcmPengirimPush implements PengirimPush
{
    private const CACHE_TOKEN = 'fcm.token_akses';

    /** Ditolak PERMANEN di pengiriman terakhir — lihat [tokenMati]. */
    private bool $tokenMati = false;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $projectId,
        private readonly string $berkasKredensial,
        private readonly int $timeout = 10,
    ) {}

    public function kirim(
        DeviceToken $perangkat,
        string $judul,
        string $isi,
        array $data = [],
    ): bool {
        $this->tokenMati = false;

        $akses = $this->tokenAkses();

        if ($akses === null) {
            // Kredensial salah/tidak terbaca itu masalah SERVER, bukan salah
            // perangkatnya. Jangan sampai satu berkas kredensial yang keliru
            // menghapus seluruh isi `device_tokens`.
            return false;
        }

        try {
            $respons = $this->http
                ->timeout($this->timeout)
                ->withToken($akses)
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                    ['message' => $this->pesan($perangkat, $judul, $isi, $data)],
                );
        } catch (Throwable $e) {
            // Jaringan ngadat — sesaat, tokennya masih sehat.
            Log::warning('Push gagal: '.$e->getMessage(), [
                'user_id' => $perangkat->user_id,
            ]);

            return false;
        }

        if ($respons->successful()) {
            return true;
        }

        $this->tokenMati = $this->ditolakPermanen($respons->status(), $respons->json());

        Log::warning('Push ditolak FCM.', [
            'user_id' => $perangkat->user_id,
            'status' => $respons->status(),
            'token_mati' => $this->tokenMati,
        ]);

        return false;
    }

    public function tokenMati(): bool
    {
        return $this->tokenMati;
    }

    /**
     * Perangkatnya yang salah, atau layanannya yang lagi bermasalah?
     *
     * Bedanya menentukan tokennya dihapus atau dipertahankan, dan dua-duanya
     * salah kalau kebalik:
     *
     *  - Menghapus token sehat gara-gara FCM sempat 503 → orangnya berhenti
     *    dapat notifikasi dan nggak ada yang tahu sebabnya.
     *  - Mempertahankan token mati → tabelnya menumpuk selamanya, karena FCM
     *    nggak pernah mengabarkan kalau aplikasi dicopot.
     *
     * Yang dianggap permanen cuma dua, sesuai dokumen FCM: 404 `UNREGISTERED`
     * (aplikasi dicopot / token dirotasi) dan 400 `INVALID_ARGUMENT` (bentuk
     * tokennya memang bukan token). 401/403 SENGAJA nggak masuk — itu
     * kredensial server yang salah, dan satu salah setel bakal mengosongkan
     * seluruh tabel.
     *
     * @param  array<string, mixed>|null  $badan
     */
    private function ditolakPermanen(int $status, ?array $badan): bool
    {
        if ($status === 404) {
            return true;
        }

        if ($status !== 400) {
            return false;
        }

        $kode = $badan['error']['details'][0]['errorCode']
            ?? $badan['error']['status']
            ?? null;

        return in_array($kode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true);
    }

    /**
     * Bentuk pesan FCM HTTP v1.
     *
     * Blok `notification` ikut supaya sistem operasinya yang menampilkan waktu
     * aplikasi ketutup — kalau cuma `data`, Android nggak menampilkan apa pun
     * sampai aplikasinya dibuka, dan itu persis keadaan yang mau ditutup.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function pesan(
        DeviceToken $perangkat,
        string $judul,
        string $isi,
        array $data,
    ): array {
        return [
            'token' => $perangkat->token,
            'notification' => ['title' => $judul, 'body' => $isi],
            // Semua nilai WAJIB string di HTTP v1; angka mentah ditolak 400.
            'data' => array_map(static fn ($v): string => (string) $v, $data),
            'android' => [
                'priority' => 'high',
                'notification' => ['channel_id' => 'sidik_kerja'],
            ],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];
    }

    /**
     * Token OAuth2 buat FCM, di-cache.
     *
     * Tiap token butuh tanda tangan RS256 — mahal kalau diulang tiap notifikasi,
     * dan satu sesi approval bisa memicu banyak. Umurnya sejam; disimpan 55
     * menit supaya nggak pernah dipakai persis di detik kedaluwarsanya.
     */
    private function tokenAkses(): ?string
    {
        $tersimpan = Cache::get(self::CACHE_TOKEN);

        if (is_string($tersimpan)) {
            return $tersimpan;
        }

        try {
            $kredensial = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                $this->berkasKredensial,
            );

            $token = $kredensial->fetchAuthToken()['access_token'] ?? null;
        } catch (Throwable $e) {
            Log::error('Kredensial FCM nggak kebaca: '.$e->getMessage());

            return null;
        }

        if (! is_string($token) || $token === '') {
            return null;
        }

        // Ditulis ke cache CUMA kalau berhasil. `Cache::remember` bakal
        // menyimpan `null` juga — dan itu artinya satu kegagalan sekejap
        // (berkas lagi ditulis ulang, jam sistem meleset) mematikan push
        // selama 55 menit penuh, jauh sesudah sebabnya hilang.
        Cache::put(self::CACHE_TOKEN, $token, now()->addMinutes(55));

        return $token;
    }
}
