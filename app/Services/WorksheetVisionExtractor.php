<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Baca tabel lembar kerja pH dari FOTO pakai Claude Vision (ganti OCR di HP).
 *
 * Implementasi dari Project-PT-Sidik/SPEC-vision-prompt.md:
 * - SATU foto = SATU tabel (Before ATAU After adjustment).
 * - Output: { "baris": [...] } — tiap `baris` = satu Repeat, array `ph`/`suhu`/
 *   keyakinan sepanjang jumlah buffer standar (4/7/10), urut kiri→kanan.
 * - Structured Output (`output_config.format`) MENJAMIN bentuk JSON — ini
 *   pengganti `temperature: 0` yang udah dihapus di Opus 4.8. `temperature` &
 *   `budget_tokens` sengaja NGGAK dikirim (error 400 di 4.8).
 * - Few-shot dari worksheet Tirta Gracia asli (cert 012-CAL-524) + prompt
 *   caching biar system + contoh (foto besar) nggak diproses ulang tiap panggil.
 *
 * Model NGGAK menghitung apa pun — cuma nyalin angka apa adanya (termasuk
 * anomali). Perhitungan sertifikat tetap di mesin GUM deterministik (SPEC §9).
 * Pakai Http client (bukan SDK) biar nol dependency baru & aman di repo bareng;
 * bentuk request-nya identik dengan yang dijelasin SPEC §5.
 */
class WorksheetVisionExtractor
{
    private const MIME_DIDUKUNG = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Folder foto few-shot yang dilampirin ke prompt (diisi Arkaan, opsional). */
    private const FEW_SHOT_DIR = 'few_shot';

    /**
     * @return array{
     *     ok: bool, status: string,
     *     data: array{baris: array<int, mixed>}|null,
     *     raw: mixed,
     *     usage: array{input_tokens: int|null, output_tokens: int|null, cache_read_input_tokens: int|null},
     *     error: string|null, model: string
     * }
     */
    /**
     * @param  int|null  $jumlahTitik  jumlah larutan standar (kolom, mis. 3) — petunjuk buat model
     * @param  int|null  $jumlahPengulangan  jumlah Repeat (baris) yang diharapkan — petunjuk
     */
    public function extract(
        string $isiGambar,
        string $mimeType,
        ?int $jumlahTitik = null,
        ?int $jumlahPengulangan = null,
    ): array {
        $apiKey = (string) config('services.anthropic.api_key');
        $model = (string) config('services.anthropic.model');

        if ($apiKey === '') {
            // Bukan error data — ini salah setup. Controller nerjemahin jadi 503.
            throw new RuntimeException('ANTHROPIC_API_KEY belum diisi di server.');
        }

        $mimeType = strtolower($mimeType);
        if (! in_array($mimeType, self::MIME_DIDUKUNG, true)) {
            $mimeType = 'image/jpeg';
        }

        $body = [
            'model' => $model,
            'max_tokens' => (int) config('services.anthropic.max_tokens', 2048),
            // System di-cache: konten stabil, dipakai tiap panggilan.
            'system' => [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ]],
            'messages' => $this->bangunMessages($isiGambar, $mimeType, $jumlahTitik, $jumlahPengulangan),
        ];

        // Structured Output: jamin bentuk JSON. Bisa dimatiin lewat env kalau
        // API nolak skema-nya — prompt tetap minta JSON murni sebagai jaring.
        if ((bool) config('services.anthropic.structured_output', true)) {
            $body['output_config'] = [
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ];
        }

        try {
            $resp = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('services.anthropic.timeout', 60))
                ->baseUrl((string) config('services.anthropic.base_url', 'https://api.anthropic.com'))
                ->post('/v1/messages', $body);
        } catch (ConnectionException $e) {
            Log::warning('WorksheetVisionExtractor: koneksi ke Anthropic gagal', ['pesan' => $e->getMessage()]);

            return $this->gagal($model, 'Gagal menghubungi layanan AI. Coba lagi sebentar.', null);
        }

        if ($resp->failed()) {
            $pesanApi = $resp->json('error.message') ?? $resp->body();
            Log::warning('WorksheetVisionExtractor: Anthropic balik error', [
                'status' => $resp->status(),
                'pesan' => $pesanApi,
            ]);

            return $this->gagal($model, 'Layanan AI menolak permintaan.', $resp->json() ?? $resp->body());
        }

        $json = $resp->json();

        // Classifier bisa nolak (HTTP 200, stop_reason "refusal") — cek duluan.
        if (($json['stop_reason'] ?? null) === 'refusal') {
            return [
                'ok' => false,
                'status' => 'ditolak',
                'data' => null,
                'raw' => $json,
                'usage' => $this->usage($json),
                'error' => 'AI menolak memproses gambar ini. Gunakan input manual.',
                'model' => $model,
            ];
        }

        $teks = $this->ambilTeks($json);
        $data = $this->parseJson($teks);

        if ($data === null) {
            Log::warning('WorksheetVisionExtractor: respons bukan JSON valid', ['teks' => mb_substr((string) $teks, 0, 500)]);

            return [
                'ok' => false,
                'status' => 'gagal',
                'data' => null,
                'raw' => $json,
                'usage' => $this->usage($json),
                'error' => 'Hasil AI tidak bisa dibaca. Coba foto ulang atau isi manual.',
                'model' => $model,
            ];
        }

        return [
            'ok' => true,
            'status' => 'sukses',
            'data' => ['baris' => $this->normalisasiBaris($data)],
            'raw' => $json,
            'usage' => $this->usage($json),
            'error' => null,
            'model' => $model,
        ];
    }

    /**
     * Susunan pesan: few-shot (foto contoh + JSON benar) lalu foto asli lapangan.
     * Few-shot cuma dilampirin kalau file fotonya ADA — kirim JSON contoh tanpa
     * fotonya malah nyesatin model. Cache breakpoint di few-shot terakhir.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bangunMessages(
        string $isiGambar,
        string $mimeType,
        ?int $jumlahTitik = null,
        ?int $jumlahPengulangan = null,
    ): array {
        $messages = [];

        $contoh = [
            ['file' => 'few_shot_before.jpg', 'json' => $this->fewShotBefore()],
            ['file' => 'few_shot_after.jpg', 'json' => $this->fewShotAfter()],
        ];

        $tersedia = [];
        foreach ($contoh as $c) {
            $path = storage_path('app/'.self::FEW_SHOT_DIR.'/'.$c['file']);
            if (is_file($path)) {
                $tersedia[] = ['b64' => base64_encode((string) file_get_contents($path)), 'json' => $c['json']];
            }
        }

        foreach ($tersedia as $i => $c) {
            $terakhir = $i === array_key_last($tersedia);

            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => [
                        'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $c['b64'],
                    ]],
                    ['type' => 'text', 'text' => 'Extract this table.'],
                ],
            ];

            $blokJawaban = ['type' => 'text', 'text' => $c['json']];
            if ($terakhir) {
                // Breakpoint cache terakhir yang stabil (system + semua few-shot).
                $blokJawaban['cache_control'] = ['type' => 'ephemeral', 'ttl' => '1h'];
            }
            $messages[] = ['role' => 'assistant', 'content' => [$blokJawaban]];
        }

        // Foto ASLI dari lapangan — bagian yang berubah, TIDAK di-cache.
        $messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => [
                    'type' => 'base64', 'media_type' => $mimeType, 'data' => base64_encode($isiGambar),
                ]],
                ['type' => 'text', 'text' => $this->instruksiFoto($jumlahTitik, $jumlahPengulangan)],
            ],
        ];

        return $messages;
    }

    /** Petunjuk jumlah kolom (larutan standar) & baris (Repeat) buat bantu model. */
    private function instruksiFoto(?int $jumlahTitik, ?int $jumlahPengulangan): string
    {
        $teks = 'Extract this table.';

        if ($jumlahTitik !== null && $jumlahTitik > 0) {
            $teks .= " It has {$jumlahTitik} standard buffer columns, left to right.";
        }
        if ($jumlahPengulangan !== null && $jumlahPengulangan > 0) {
            $teks .= " There are up to {$jumlahPengulangan} Repeat rows.";
        }

        return $teks;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract numeric readings from a photographed pH-meter calibration worksheet
table for Sidik Calibration.

The table has one row per "Repeat" (1..N) and one column group per standard
buffer solution (nominal 4, 7, 10), left to right. Each cell holds two numbers:
a pH reading and a temperature in °C.

Return ONLY the JSON matching the provided schema. For each Repeat row, output:
- "ph": the pH reading per buffer, left to right (null if illegible/missing)
- "suhu": the °C reading per buffer, same order (null if illegible/missing)
- "ph_keyakinan" / "suhu_keyakinan": your confidence per cell — "high", "medium", "low"

Rules:
- Transcribe EXACTLY what is written. NEVER correct, round, or "fix" values that
  look like outliers — a deviating reading is exactly what the calibration must
  catch. If a cell clearly reads 5.00 where ~4.0 is expected, output 5.00.
- Indonesian decimal convention: a comma is a decimal point (4,04 → 4.04).
  Output all numbers with a period decimal.
- pH readings are 0–14; temperatures are typically 5–60 °C. Use this only to tell
  the pH column from the °C column when the layout is ambiguous — NOT to reject or
  alter a value.
- Confidence: "high" = crisp and unambiguous; "medium" = readable but a digit is
  smudged/uncertain; "low" = guessed, partially obscured, or handwriting hard to
  read. When unsure between two readings, pick the most likely and mark "low".
- If a whole Repeat row is missing from the photo, omit it. If a single cell is
  unreadable, set its value to null and its confidence to "low".
- Do not invent rows or cells beyond what the photographed table contains.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $keyakinan = [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ];
        $angka = ['type' => 'array', 'items' => ['type' => ['number', 'null']]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['baris'],
            'properties' => [
                'baris' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['ph', 'suhu', 'ph_keyakinan', 'suhu_keyakinan'],
                        'properties' => [
                            'ph' => $angka,
                            'suhu' => $angka,
                            'ph_keyakinan' => $keyakinan,
                            'suhu_keyakinan' => $keyakinan,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function fewShotBefore(): string
    {
        return '{"baris":['
            .'{"ph":[4.04,7.02,9.61],"suhu":[22.2,22.3,22.2],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.04,7.04,9.94],"suhu":[22.2,22.3,22.2],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.04,7.05,9.66],"suhu":[22.2,22.3,22.2],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[5.00,7.02,9.61],"suhu":[22.2,22.3,22.2],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.04,7.02,9.61],"suhu":[22.2,22.3,22.2],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]}'
            .']}';
    }

    private function fewShotAfter(): string
    {
        return '{"baris":['
            .'{"ph":[4.00,7.01,10.11],"suhu":[22.2,22.2,22.1],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.00,7.01,10.11],"suhu":[22.2,22.2,22.1],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.00,7.00,10.11],"suhu":[22.1,22.2,22.1],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.00,7.00,10.11],"suhu":[22.2,22.2,22.1],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]},'
            .'{"ph":[4.00,7.00,10.11],"suhu":[22.2,22.2,22.1],"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]}'
            .']}';
    }

    /** @param array<string, mixed> $json */
    private function ambilTeks(array $json): string
    {
        $teks = '';
        foreach ((array) ($json['content'] ?? []) as $blok) {
            if (($blok['type'] ?? null) === 'text') {
                $teks .= (string) ($blok['text'] ?? '');
            }
        }

        return $teks;
    }

    /** Parse toleran: buang pagar markdown, ambil dari `{` pertama sampai `}` terakhir. */
    private function parseJson(string $teks): ?array
    {
        $teks = trim($teks);
        $teks = preg_replace('/^```(?:json)?|```$/mi', '', $teks) ?? $teks;

        $awal = strpos($teks, '{');
        $akhir = strrpos($teks, '}');
        if ($awal === false || $akhir === false || $akhir < $awal) {
            return null;
        }

        $data = json_decode(substr($teks, $awal, $akhir - $awal + 1), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Rapikan tiap baris: angka dikoersi (koma→titik), keyakinan disamakan
     * panjangnya sama `ph`/`suhu` (kurang → diisi "low"). Baris yang bentuknya
     * kacau dibuang; kalau semua kacau, balik `[]` (mobile → "tak terbaca",
     * teknisi isi manual). Server TIDAK membetulkan nilai — cuma menandai (SPEC §6).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function normalisasiBaris(array $data): array
    {
        $out = [];

        foreach ((array) ($data['baris'] ?? []) as $b) {
            if (! is_array($b) || ! isset($b['ph']) || ! is_array($b['ph'])) {
                continue;
            }

            $ph = array_map(fn ($v) => $this->angka($v), array_values($b['ph']));
            $suhu = array_map(fn ($v) => $this->angka($v), array_values((array) ($b['suhu'] ?? [])));
            $n = count($ph);

            // Suhu disamakan panjang sama ph (pengukuran sepasang per sel).
            $suhu = array_slice(array_pad($suhu, $n, null), 0, $n);

            $out[] = [
                'ph' => $ph,
                'suhu' => $suhu,
                'ph_keyakinan' => $this->keyakinanArray($b['ph_keyakinan'] ?? [], $n),
                'suhu_keyakinan' => $this->keyakinanArray($b['suhu_keyakinan'] ?? [], $n),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $nilai
     * @return array<int, string>
     */
    private function keyakinanArray($nilai, int $panjang): array
    {
        $arr = array_map(fn ($v) => $this->confidence($v), array_values((array) $nilai));

        // Kalau modelnya ngasih lebih pendek dari jumlah sel, sisanya "low".
        return array_slice(array_pad($arr, $panjang, 'low'), 0, $panjang);
    }

    private function angka(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }
        if (is_string($nilai)) {
            $nilai = str_replace(',', '.', trim($nilai));
        }

        return is_numeric($nilai) ? (float) $nilai : null;
    }

    private function confidence(mixed $nilai): string
    {
        $nilai = is_string($nilai) ? strtolower(trim($nilai)) : '';

        return in_array($nilai, ['high', 'medium', 'low'], true) ? $nilai : 'low';
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{input_tokens: int|null, output_tokens: int|null, cache_read_input_tokens: int|null}
     */
    private function usage(array $json): array
    {
        $u = (array) ($json['usage'] ?? []);

        return [
            'input_tokens' => isset($u['input_tokens']) ? (int) $u['input_tokens'] : null,
            'output_tokens' => isset($u['output_tokens']) ? (int) $u['output_tokens'] : null,
            // Buat mastiin prompt caching kena (SPEC §5): harus > 0 setelah panggilan ke-2.
            'cache_read_input_tokens' => isset($u['cache_read_input_tokens']) ? (int) $u['cache_read_input_tokens'] : null,
        ];
    }

    /**
     * @return array{ok: false, status: string, data: null, raw: mixed, usage: array{input_tokens: null, output_tokens: null, cache_read_input_tokens: null}, error: string, model: string}
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
