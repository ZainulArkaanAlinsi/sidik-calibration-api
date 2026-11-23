<?php

namespace App\Services\Vision;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Token akses OAuth2 untuk service account Google (alur JWT bearer), tanpa
 * dependency Composer: tanda tangan RS256 lewat `openssl_sign` dan pertukaran
 * token lewat `Http` yang sudah dipakai repo ini.
 *
 * Kredensial hanya dibaca dari `services.google_vision.kredensial` (env server).
 * Isi kunci privat TIDAK PERNAH masuk pesan galat, log, atau cache; yang di-cache
 * hanya token akses berumur pendek, dengan kunci yang diturunkan dari hash
 * alamat surel service account.
 */
final class GoogleVisionToken
{
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-vision';

    private const TOKEN_URI_BAWAAN = 'https://oauth2.googleapis.com/token';

    /**
     * @throws GagalMenghubungiGoogle jaringan putus, waktu habis, atau Google sementara tidak
     *                                bisa melayani (5xx/429): kegagalan biasa, bukan salah setup
     * @throws RuntimeException kredensial kosong/rusak atau Google menolak tokennya (4xx): salah setup
     */
    public function ambil(): string
    {
        $kred = $this->kredensial();
        $kunciCache = $this->kunciCache($kred['client_email']);

        $tersimpan = Cache::get($kunciCache);
        if (is_string($tersimpan) && $tersimpan !== '') {
            return $tersimpan;
        }

        $sekarang = time();
        $isi = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'
            .$this->b64(json_encode([
                'iss' => $kred['client_email'],
                'scope' => self::SCOPE,
                'aud' => $kred['token_uri'],
                'iat' => $sekarang,
                'exp' => $sekarang + 3600,
            ], JSON_THROW_ON_ERROR));

        $kunciPrivat = openssl_pkey_get_private($kred['private_key']);
        if ($kunciPrivat === false) {
            throw new RuntimeException('Kunci privat service account Google tidak valid.');
        }

        $tanda = '';
        if (! openssl_sign($isi, $tanda, $kunciPrivat, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal menandatangani permintaan token Google.');
        }

        try {
            $resp = Http::asForm()
                ->timeout((int) config('services.google_vision.timeout', 30))
                ->post($kred['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $isi.'.'.$this->b64($tanda),
                ]);
        } catch (ConnectionException) {
            throw new GagalMenghubungiGoogle('Tidak bisa menghubungi Google untuk meminta token.');
        }

        $token = (string) $resp->json('access_token', '');
        if (! $resp->successful() || $token === '') {
            // 5xx dan 429 = gangguan sementara di Google, bukan salah setup kita.
            if ($resp->status() >= 500 || $resp->status() === 429) {
                throw new GagalMenghubungiGoogle('Google sementara tidak bisa melayani permintaan token (HTTP '.$resp->status().').');
            }

            throw new RuntimeException('Google menolak permintaan token (HTTP '.$resp->status().').');
        }

        $umur = (int) $resp->json('expires_in', 3600);
        Cache::put($kunciCache, $token, max(60, $umur - 300));

        return $token;
    }

    /**
     * Apakah `GOOGLE_VISION_KREDENSIAL` bisa dipakai menandatangani permintaan token: berbentuk
     * JSON service account (`client_email` dan `private_key` terisi) DAN kunci privatnya terbaca
     * OpenSSL. Pemeriksaan lokal saja, tanpa permintaan ke Google, jadi tidak membuktikan Google
     * menerimanya. Kunci privat yang rusak (mis. `\n` salah escape saat ditempel ke env) adalah
     * salah setel yang paling umum. Dipakai status admin (`GET /api/admin/ocr-cloud`) supaya
     * konfigurasi salah cepat terlihat; tidak pernah mengembalikan isi kredensial.
     */
    public function kredensialTerbaca(): bool
    {
        try {
            $kred = $this->kredensial();
        } catch (RuntimeException) {
            return false;
        }

        return openssl_pkey_get_private($kred['private_key']) !== false;
    }

    /**
     * Buang token yang ditolak Vision (HTTP 401) supaya permintaan berikutnya meminta
     * yang baru, bukan mengulang token mati sampai kedaluwarsa (±55 menit). Kredensial
     * rusak dibiarkan: `ambil()` yang akan melaporkannya.
     */
    public function lupakan(): void
    {
        try {
            Cache::forget($this->kunciCache($this->kredensial()['client_email']));
        } catch (RuntimeException) {
        }
    }

    private function kunciCache(string $clientEmail): string
    {
        return 'google_vision.token.'.substr(hash('sha256', $clientEmail), 0, 16);
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     *
     * @throws RuntimeException
     */
    private function kredensial(): array
    {
        $mentah = trim((string) config('services.google_vision.kredensial', ''));
        if ($mentah === '') {
            throw new RuntimeException('GOOGLE_VISION_KREDENSIAL belum diisi di server.');
        }

        if (! str_starts_with($mentah, '{')) {
            $dekode = base64_decode($mentah, true);
            $mentah = $dekode === false ? '' : $dekode;
        }

        $data = json_decode($mentah, true);
        if (! is_array($data)
            || ! is_string($data['client_email'] ?? null) || $data['client_email'] === ''
            || ! is_string($data['private_key'] ?? null) || $data['private_key'] === '') {
            throw new RuntimeException('GOOGLE_VISION_KREDENSIAL bukan JSON service account yang valid.');
        }

        $tokenUri = is_string($data['token_uri'] ?? null) && $data['token_uri'] !== ''
            ? $data['token_uri']
            : self::TOKEN_URI_BAWAAN;

        // Kredensial datang dari env server (tepercaya), tetapi alamat token
        // tetap wajib HTTPS supaya salah tempel tidak mengirim tanda tangan
        // lewat jaringan terbuka.
        if (! str_starts_with($tokenUri, 'https://')) {
            throw new RuntimeException('token_uri pada kredensial Google harus HTTPS.');
        }

        return [
            'client_email' => $data['client_email'],
            'private_key' => $data['private_key'],
            'token_uri' => $tokenUri,
        ];
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
