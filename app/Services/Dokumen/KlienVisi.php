<?php

namespace App\Services\Dokumen;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Transport ke penyedia AI visi — Anthropic atau Gemini — tanpa tahu isi
 * promptnya.
 *
 * ## Kenapa transportnya dipisah dari yang menyusun prompt
 *
 * Yang ada di kelas ini bukan kode boilerplate: hampir tiap cabangnya lahir
 * dari kejadian nyata di lapangan.
 *
 *  - timeout HTTP dipotong biar nggak lebih panjang dari umur proses PHP-nya,
 *    karena kalau lewat, `catch (ConnectionException)` yang tugasnya bilang
 *    "coba lagi sebentar" nggak pernah kejalan pas paling dibutuhkan;
 *  - 429/503 dijawab "layanan lagi sibuk, fotonya nggak perlu diulang",
 *    karena pesan yang nyuruh foto ulang bikin teknisi motret berkali-kali
 *    buat sesuatu yang mustahil berhasil sampai bebannya turun;
 *  - kuota habis dibedain dari sibuk, karena nunggu nggak bakal nolong;
 *  - `stop_reason: refusal` dicek DULUAN, karena itu HTTP 200 yang isinya
 *    penolakan;
 *  - JSON di-parse toleran, karena Gemini sering balikin array telanjang yang
 *    isinya SUDAH benar — dan dulu itu kebuang sebagai "tidak bisa dibaca".
 *
 * Pelajaran semahal itu nggak boleh hidup di dua tempat. Begitu disalin, satu
 * salinan bakal ketinggalan waktu yang lain diperbaiki, dan bedanya cuma
 * kelihatan waktu penyedianya lagi bermasalah — persis waktu yang paling nggak
 * enak buat nemuin bug.
 *
 * > CATATAN: `WorksheetVisionExtractor` masih punya salinan transportnya
 * > sendiri. Dia SENGAJA belum dipindah ke sini — dia jalur produksi yang
 * > sudah terbukti, dan memindahkannya barengan menambah fitur baru berarti
 * > dua perubahan berisiko dalam satu langkah. Pemindahannya kerjaan tersendiri.
 */
class KlienVisi
{
    private const MIME_DIDUKUNG = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Detik yang disisakan buat kerja sesudah HTTP: decode, log, susun response. */
    private const CADANGAN_DETIK = 5;

    /**
     * @return 'anthropic'|'gemini'
     */
    public static function penyediaAktif(): string
    {
        return strtolower((string) config('services.vision.driver', 'anthropic')) === 'gemini'
            ? 'gemini'
            : 'anthropic';
    }

    public static function model(): string
    {
        return (string) config('services.'.self::penyediaAktif().'.model');
    }

    /**
     * Kirim satu gambar + prompt, balikin JSON yang sudah di-decode.
     *
     * @param  array<string, mixed>|null  $skema  JSON Schema kalau penyedianya sanggup
     * @return array{ok: bool, status: string, data: array<string, mixed>|null, raw: mixed, usage: array<string, int|null>, error: string|null, model: string}
     */
    public function minta(
        string $isiGambar,
        string $mimeType,
        string $systemPrompt,
        string $instruksi,
        ?array $skema = null,
    ): array {
        $mimeType = strtolower($mimeType);

        if (! in_array($mimeType, self::MIME_DIDUKUNG, true)) {
            $mimeType = 'image/jpeg';
        }

        return self::penyediaAktif() === 'gemini'
            ? $this->lewatGemini($isiGambar, $mimeType, $systemPrompt, $instruksi, $skema)
            : $this->lewatAnthropic($isiGambar, $mimeType, $systemPrompt, $instruksi, $skema);
    }

    /**
     * @param  array<string, mixed>|null  $skema
     * @return array<string, mixed>
     */
    private function lewatAnthropic(
        string $isiGambar,
        string $mimeType,
        string $systemPrompt,
        string $instruksi,
        ?array $skema,
    ): array {
        $apiKey = (string) config('services.anthropic.api_key');
        $model = (string) config('services.anthropic.model');

        if ($apiKey === '') {
            // Salah SETUP, bukan salah data — controller nerjemahin jadi 503.
            // Penyedianya ikut disebut karena yang salah biasanya
            // `VISION_DRIVER`-nya, bukan kuncinya.
            throw new RuntimeException(
                'ANTHROPIC_API_KEY belum diisi di server, padahal VISION_DRIVER-nya `anthropic`. '
                .'Kalau maunya Gemini, setel VISION_DRIVER=gemini di `.env`.',
            );
        }

        $body = [
            'model' => $model,
            'max_tokens' => (int) config('services.anthropic.max_tokens', 4096),
            // Di-cache: isinya stabil antar panggilan.
            'system' => [[
                'type' => 'text',
                'text' => $systemPrompt,
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ]],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => [
                        'type' => 'base64', 'media_type' => $mimeType, 'data' => $isiGambar,
                    ]],
                    ['type' => 'text', 'text' => $instruksi],
                ],
            ]],
        ];

        if ($skema !== null && (bool) config('services.anthropic.structured_output', true)) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $skema]];
        }

        try {
            $resp = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
                ->timeout($this->batasWaktu((int) config('services.anthropic.timeout', 60)))
                ->baseUrl((string) config('services.anthropic.base_url', 'https://api.anthropic.com'))
                ->post('/v1/messages', $body);
        } catch (ConnectionException $e) {
            Log::warning('KlienVisi: koneksi ke Anthropic gagal', ['pesan' => $e->getMessage()]);

            return $this->gagal($model, 'Gagal menghubungi layanan AI. Coba lagi sebentar.', null);
        }

        if ($resp->failed()) {
            Log::warning('KlienVisi: Anthropic balik error', [
                'status' => $resp->status(),
                'pesan' => $resp->json('error.message') ?? $resp->body(),
            ]);

            return $this->gagal(
                $model,
                $this->pesanGagalHttp($resp->status(), $resp->json('error.message')),
                $resp->json() ?? $resp->body(),
            );
        }

        $json = $resp->json();

        // HTTP 200 yang isinya penolakan — dicek sebelum teksnya dibaca.
        if (($json['stop_reason'] ?? null) === 'refusal') {
            return [
                'ok' => false,
                'status' => 'ditolak',
                'data' => null,
                'raw' => $json,
                'usage' => $this->usageAnthropic($json),
                'error' => 'AI menolak memproses gambar ini. Gunakan input manual.',
                'model' => $model,
            ];
        }

        $teks = '';

        foreach ((array) ($json['content'] ?? []) as $blok) {
            if (($blok['type'] ?? null) === 'text') {
                $teks .= (string) ($blok['text'] ?? '');
            }
        }

        return $this->hasil($teks, $json, $this->usageAnthropic($json), $model);
    }

    /**
     * @param  array<string, mixed>|null  $skema
     * @return array<string, mixed>
     */
    private function lewatGemini(
        string $isiGambar,
        string $mimeType,
        string $systemPrompt,
        string $instruksi,
        ?array $skema,
    ): array {
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.model');

        if ($apiKey === '') {
            throw new RuntimeException(
                'GEMINI_API_KEY belum diisi di server, padahal VISION_DRIVER-nya `gemini`.',
            );
        }

        $body = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $isiGambar]],
                    ['text' => $instruksi],
                ],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => (int) config('services.gemini.max_tokens', 8192),
                'responseMimeType' => 'application/json',
            ],
        ];

        try {
            $resp = Http::withHeaders(['content-type' => 'application/json'])
                ->timeout($this->batasWaktu((int) config('services.gemini.timeout', 60)))
                ->baseUrl((string) config(
                    'services.gemini.base_url',
                    'https://generativelanguage.googleapis.com',
                ))
                ->post('/v1beta/models/'.$model.':generateContent?key='.$apiKey, $body);
        } catch (ConnectionException $e) {
            Log::warning('KlienVisi: koneksi ke Gemini gagal', ['pesan' => $e->getMessage()]);

            return $this->gagal($model, 'Gagal menghubungi layanan AI. Coba lagi sebentar.', null);
        }

        if ($resp->failed()) {
            Log::warning('KlienVisi: Gemini balik error', [
                'status' => $resp->status(),
                'pesan' => $resp->json('error.message') ?? $resp->body(),
            ]);

            return $this->gagal(
                $model,
                $this->pesanGagalHttp($resp->status(), $resp->json('error.message')),
                $resp->json() ?? $resp->body(),
            );
        }

        $json = $resp->json();
        $teks = '';

        foreach ((array) ($json['candidates'][0]['content']['parts'] ?? []) as $bagian) {
            $teks .= (string) ($bagian['text'] ?? '');
        }

        return $this->hasil($teks, $json, $this->usageGemini($json), $model);
    }

    /**
     * @param  array<string, int|null>  $usage
     * @return array<string, mixed>
     */
    private function hasil(string $teks, mixed $raw, array $usage, string $model): array
    {
        $data = $this->parseJson($teks);

        if ($data === null) {
            return [
                'ok' => false,
                'status' => 'tak_terbaca',
                'data' => null,
                'raw' => $raw,
                'usage' => $usage,
                'error' => 'Jawaban AI nggak bisa dibaca sebagai JSON.',
                'model' => $model,
            ];
        }

        return [
            'ok' => true,
            'status' => 'ok',
            'data' => $data,
            'raw' => $raw,
            'usage' => $usage,
            'error' => null,
            'model' => $model,
        ];
    }

    /**
     * Parse toleran: buang pagar markdown, ambil dari kurung pertama sampai
     * pasangannya yang terakhir.
     *
     * Array telanjang ikut diterima — Gemini dengan `responseMimeType:
     * application/json` sering balikin array langsung tanpa pembungkus, dan
     * dulu jawaban yang SUDAH benar isinya kebuang sebagai "tidak bisa dibaca".
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $teks): ?array
    {
        $teks = trim($teks);
        $teks = preg_replace('/^```(?:json)?|```$/mi', '', $teks) ?? $teks;

        $kandidat = [];

        foreach ([['{', '}'], ['[', ']']] as [$buka, $tutup]) {
            $awal = strpos($teks, $buka);
            $akhir = strrpos($teks, $tutup);

            if ($awal !== false && $akhir !== false && $akhir > $awal) {
                $kandidat[$awal] = substr($teks, $awal, $akhir - $awal + 1);
            }
        }

        if ($kandidat === []) {
            return null;
        }

        ksort($kandidat);
        $data = json_decode(reset($kandidat), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Timeout HTTP yang NGGAK boleh lebih panjang dari umur prosesnya sendiri.
     * 0 = nggak dibatasi (CLI, queue worker) → pakai angka config apa adanya.
     */
    private function batasWaktu(int $dariConfig): int
    {
        $batasProses = (int) ini_get('max_execution_time');

        if ($batasProses <= 0) {
            return $dariConfig;
        }

        return max(5, min($dariConfig, $batasProses - self::CADANGAN_DETIK));
    }

    private function pesanGagalHttp(int $status, mixed $pesanApi): string
    {
        $teks = mb_strtolower(is_string($pesanApi) ? $pesanApi : '');

        $habis = str_contains($teks, 'credits are depleted')
            || str_contains($teks, 'insufficient')
            || str_contains($teks, 'billing')
            || str_contains($teks, 'exceeded your current quota')
            || str_contains($teks, 'quota_exceeded');

        if ($habis) {
            return 'Kuota layanan AI habis, jadi lembarnya belum bisa dibaca. '
                .'Kabarin admin buat isi ulang — nunggu nggak bakal bikin ini jalan lagi. '
                .'Sementara ini isi manual.';
        }

        if (in_array($status, [429, 503], true)) {
            return 'Layanan AI lagi sibuk. Tunggu beberapa menit lalu coba lagi — fotonya nggak perlu diulang.';
        }

        return 'Layanan AI menolak permintaan.';
    }

    /**
     * @return array<string, int|null>
     */
    private function usageAnthropic(mixed $json): array
    {
        $u = is_array($json) ? ($json['usage'] ?? []) : [];

        return [
            'input_tokens' => isset($u['input_tokens']) ? (int) $u['input_tokens'] : null,
            'output_tokens' => isset($u['output_tokens']) ? (int) $u['output_tokens'] : null,
            'cache_read_input_tokens' => isset($u['cache_read_input_tokens'])
                ? (int) $u['cache_read_input_tokens']
                : null,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function usageGemini(mixed $json): array
    {
        $u = is_array($json) ? ($json['usageMetadata'] ?? []) : [];

        return [
            'input_tokens' => isset($u['promptTokenCount']) ? (int) $u['promptTokenCount'] : null,
            'output_tokens' => isset($u['candidatesTokenCount']) ? (int) $u['candidatesTokenCount'] : null,
            'cache_read_input_tokens' => isset($u['cachedContentTokenCount'])
                ? (int) $u['cachedContentTokenCount']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gagal(string $model, string $error, mixed $raw): array
    {
        return [
            'ok' => false,
            'status' => 'gagal',
            'data' => null,
            'raw' => $raw,
            'usage' => ['input_tokens' => null, 'output_tokens' => null, 'cache_read_input_tokens' => null],
            'error' => $error,
            'model' => $model,
        ];
    }
}
