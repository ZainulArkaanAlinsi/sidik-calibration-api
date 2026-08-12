<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Baca tabel lembar kerja dari FOTO pakai Claude Vision (ganti OCR di HP).
 *
 * **Nggak khusus pH**, walau nama kunci JSON-nya masih `ph` (dipertahankan
 * karena mobile udah nge-parse nama itu). Yang dibaca selalu bentuk yang sama —
 * tabel Repeat × larutan standar, tiap sel isinya sepasang angka
 * "pembacaan + suhu °C" — dan itu berlaku buat keempat alat: pH, Turbidimeter,
 * Chlorine, Refractometer. Yang mbedain cuma PETUNJUK yang dikirim mobile
 * (`$satuan`, `$nominal`, `$desimal`), diambil dari `bentukLembarKerja()`
 * profil alatnya. Nambah alat ke-5 nggak perlu nyentuh file ini.
 *
 * Implementasi dari docs/SPEC-vision-prompt.md:
 * - SATU foto = SATU tabel (Before ATAU After adjustment).
 * - Output: { "baris": [...] } — tiap `baris` = satu Repeat, array `ph`/`suhu`/
 *   keyakinan sepanjang jumlah larutan standar, urut kiri→kanan.
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
     * @param  string|null  $satuan  satuan pembacaan (mis. `pH`, `NTU`) — bikin model tau kolom bacaan vs °C
     * @param  list<float>|null  $nominal  nilai nominal standar tiap kolom (kiri→kanan), mis. [1,100,1000]
     * @param  list<int>|null  $desimal  jumlah desimal tipikal tiap kolom (sejajar $nominal)
     */
    public function extract(
        string $isiGambar,
        string $mimeType,
        ?int $jumlahTitik = null,
        ?int $jumlahPengulangan = null,
        ?string $satuan = null,
        ?array $nominal = null,
        ?array $desimal = null,
    ): array {
        $penyedia = strtolower((string) config('services.vision.driver', 'anthropic'));

        $mimeType = strtolower($mimeType);
        if (! in_array($mimeType, self::MIME_DIDUKUNG, true)) {
            $mimeType = 'image/jpeg';
        }

        if ($penyedia === 'gemini') {
            return $this->lewatGemini($isiGambar, $mimeType, $jumlahTitik, $jumlahPengulangan, $satuan, $nominal, $desimal);
        }

        $apiKey = (string) config('services.anthropic.api_key');
        $model = (string) config('services.anthropic.model');

        if ($apiKey === '') {
            // Bukan error data — ini salah setup. Controller nerjemahin jadi 503.
            //
            // Penyedianya ikut disebut: pesan "ANTHROPIC_API_KEY belum diisi"
            // doang bikin orang nyari kunci Anthropic, padahal yang salah
            // biasanya `VISION_DRIVER`-nya — lab ini pakai Gemini, dan kunci
            // Gemini-nya udah ada.
            throw new RuntimeException(
                'ANTHROPIC_API_KEY belum diisi di server, padahal VISION_DRIVER-nya `'
                .$penyedia.'`. Kalau maunya Gemini, setel VISION_DRIVER=gemini di `.env`.',
            );
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
            'messages' => $this->bangunMessages($isiGambar, $mimeType, $jumlahTitik, $jumlahPengulangan, $satuan, $nominal, $desimal),
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

            // 503/429 itu MASALAH DI SISI GOOGLE, bukan fotonya. Pesan yang
            // nyuruh "foto ulang" bikin teknisi motret berkali-kali buat
            // sesuatu yang mustahil berhasil sampai bebannya turun — kejadian
            // 12 Agt 2026, dan dua kali kelihatan kayak "fotonya jelek".
            $sibuk = in_array($resp->status(), [429, 503], true);

            return $this->gagal(
                $model,
                $sibuk
                    ? 'Layanan AI lagi sibuk. Tunggu beberapa menit lalu coba lagi — fotonya nggak perlu diulang.'
                    : 'Layanan AI menolak permintaan.',
                $resp->json() ?? $resp->body(),
            );
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
            'data' => [
                'baris' => $this->catatHasil($this->normalisasiBaris($data), $data),
                // Diteruskan apa adanya. Frontend yang mutusin kolom mana
                // mendarat di titik mana — di sini nggak ada daftar titiknya.
                'standard_value' => $this->normalisasiStandarNilai($data),
            ],
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
        ?string $satuan = null,
        ?array $nominal = null,
        ?array $desimal = null,
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
                ['type' => 'text', 'text' => $this->instruksiFoto($jumlahTitik, $jumlahPengulangan, $satuan, $nominal, $desimal)],
            ],
        ];

        return $messages;
    }

    /**
     * Jalur Gemini (Google AI Studio).
     *
     * Kenapa ada dua penyedia, bukan satu yang "terbaik": bentuk hasil, prompt,
     * skema, dan normalisasi barisnya **dipakai bareng** — yang beda cuma cara
     * ngomong ke servernya. Jadi ganti penyedia nggak ngubah apa pun yang
     * nyampe ke lembar kerja teknisi, dan lab nggak kekunci ke satu vendor.
     *
     * Bedanya sama jalur Anthropic:
     * - **Nggak ada prompt caching.** Gemini nggak punya padanan langsung buat
     *   `cache_control`, jadi few-shot ikut kekirim tiap panggilan. Efeknya ke
     *   biaya, bukan ke hasil.
     * - **Skema JSON nggak dikirim.** `responseSchema` Gemini cuma nerima
     *   subset OpenAPI, bukan JSON Schema penuh — skema kita bakal ditolak.
     *   Yang dipakai `responseMimeType: application/json` + prompt yang minta
     *   JSON murni, dan parser toleran yang udah ada tetap jadi jaringnya.
     *
     * @return array<string, mixed>
     */
    private function lewatGemini(
        string $isiGambar,
        string $mimeType,
        ?int $jumlahTitik,
        ?int $jumlahPengulangan,
        ?string $satuan = null,
        ?array $nominal = null,
        ?array $desimal = null,
    ): array {
        $apiKey = (string) config('services.gemini.api_key');
        // Sengaja TANPA fallback literal (sama kayak jalur Anthropic di atas):
        // default-nya cuma boleh hidup di `config/services.php`. Salinan kedua di
        // sini pernah nyangkut di `gemini-2.0-flash` yang udah kena 429 — dan
        // karena config-nya udah bener, nggak ada yang curiga ke baris ini.
        $model = (string) config('services.gemini.model');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY belum diisi di server.');
        }

        try {
            $resp = Http::withHeaders(['content-type' => 'application/json'])
                ->timeout((int) config('services.gemini.timeout', 60))
                ->baseUrl((string) config(
                    'services.gemini.base_url',
                    'https://generativelanguage.googleapis.com',
                ))
                ->withQueryParameters(['key' => $apiKey])
                ->post("/v1beta/models/{$model}:generateContent", [
                    'system_instruction' => [
                        'parts' => [['text' => $this->systemPrompt()]],
                    ],
                    'contents' => $this->kontenGemini(
                        $isiGambar,
                        $mimeType,
                        $jumlahTitik,
                        $jumlahPengulangan,
                        $satuan,
                        $nominal,
                        $desimal,
                    ),
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => (int) config('services.gemini.max_tokens', 4096),
                        // Ekstraksi angka dari foto itu tugas baca, bukan
                        // mengarang — variasi di sini cuma nambah risiko salah
                        // baca yang beda tiap jepretan.
                        'temperature' => 0,
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('WorksheetVisionExtractor: koneksi ke Gemini gagal', ['pesan' => $e->getMessage()]);

            return $this->gagal($model, 'Gagal menghubungi layanan AI. Coba lagi sebentar.', null);
        }

        if ($resp->failed()) {
            Log::warning('WorksheetVisionExtractor: Gemini balik error', [
                'status' => $resp->status(),
                'pesan' => $resp->json('error.message') ?? $resp->body(),
            ]);

            // 503/429 itu MASALAH DI SISI GOOGLE, bukan fotonya. Pesan yang
            // nyuruh "foto ulang" bikin teknisi motret berkali-kali buat
            // sesuatu yang mustahil berhasil sampai bebannya turun — kejadian
            // 12 Agt 2026, dan dua kali kelihatan kayak "fotonya jelek".
            $sibuk = in_array($resp->status(), [429, 503], true);

            return $this->gagal(
                $model,
                $sibuk
                    ? 'Layanan AI lagi sibuk. Tunggu beberapa menit lalu coba lagi — fotonya nggak perlu diulang.'
                    : 'Layanan AI menolak permintaan.',
                $resp->json() ?? $resp->body(),
            );
        }

        $json = $resp->json();

        // Gemini nolak lewat `finishReason`, bukan HTTP error. `SAFETY` &
        // `PROHIBITED_CONTENT` = classifier; `MAX_TOKENS` = kepotong, dan JSON
        // yang kepotong lebih baik dibilang gagal daripada diparse separuh lalu
        // diisikan ke lembar kerja sebagai angka.
        $alasan = (string) ($json['candidates'][0]['finishReason'] ?? '');
        if (in_array($alasan, ['SAFETY', 'PROHIBITED_CONTENT', 'RECITATION'], true)) {
            return [
                'ok' => false,
                'status' => 'ditolak',
                'data' => null,
                'raw' => $json,
                'usage' => $this->usageGemini($json),
                'error' => 'AI menolak memproses gambar ini. Gunakan input manual.',
                'model' => $model,
            ];
        }

        $teks = '';
        foreach ((array) ($json['candidates'][0]['content']['parts'] ?? []) as $bagian) {
            $teks .= (string) ($bagian['text'] ?? '');
        }

        $data = $this->parseJson($teks);

        if ($data === null) {
            Log::warning('WorksheetVisionExtractor: respons Gemini bukan JSON valid', [
                'finish_reason' => $alasan,
                'teks' => mb_substr($teks, 0, 500),
            ]);

            return [
                'ok' => false,
                'status' => 'gagal',
                'data' => null,
                'raw' => $json,
                'usage' => $this->usageGemini($json),
                // MAX_TOKENS = jatah keluaran abis, BUKAN fotonya kurang
                // dekat. Motret ulang nggak ngubah apa pun; yang ngubah cuma
                // `GEMINI_MAX_TOKENS`.
                'error' => $alasan === 'MAX_TOKENS'
                    ? 'Jawaban AI kepotong sebelum selesai (batas token). Ini setelan server, bukan fotonya — lapor ke yang ngurus backend, atau isi manual dulu.'
                    : 'Hasil AI tidak bisa dibaca. Coba foto ulang atau isi manual.',
                'model' => $model,
            ];
        }

        return [
            'ok' => true,
            'status' => 'sukses',
            'data' => [
                'baris' => $this->catatHasil($this->normalisasiBaris($data), $data),
                // Diteruskan apa adanya. Frontend yang mutusin kolom mana
                // mendarat di titik mana — di sini nggak ada daftar titiknya.
                'standard_value' => $this->normalisasiStandarNilai($data),
            ],
            'raw' => $json,
            'usage' => $this->usageGemini($json),
            'error' => null,
            'model' => $model,
        ];
    }

    /**
     * Few-shot + foto lapangan dalam bentuk `contents` Gemini.
     *
     * Isinya SAMA PERSIS dengan yang dikirim ke Anthropic — cuma dibungkus
     * beda. Kalau contohnya beda antar penyedia, hasil ekstraksinya ikut beda
     * dan nggak ada yang tahu kenapa.
     *
     * @return array<int, array<string, mixed>>
     */
    private function kontenGemini(
        string $isiGambar,
        string $mimeType,
        ?int $jumlahTitik,
        ?int $jumlahPengulangan,
        ?string $satuan = null,
        ?array $nominal = null,
        ?array $desimal = null,
    ): array {
        $contents = [];

        foreach ([
            ['file' => 'few_shot_before.jpg', 'json' => $this->fewShotBefore()],
            ['file' => 'few_shot_after.jpg', 'json' => $this->fewShotAfter()],
        ] as $c) {
            $path = storage_path('app/'.self::FEW_SHOT_DIR.'/'.$c['file']);
            if (! is_file($path)) {
                continue;
            }

            $contents[] = ['role' => 'user', 'parts' => [
                ['inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => base64_encode((string) file_get_contents($path)),
                ]],
                ['text' => 'Extract this table.'],
            ]];
            $contents[] = ['role' => 'model', 'parts' => [['text' => $c['json']]]];
        }

        $contents[] = ['role' => 'user', 'parts' => [
            ['inline_data' => [
                'mime_type' => $mimeType,
                'data' => base64_encode($isiGambar),
            ]],
            ['text' => $this->instruksiFoto($jumlahTitik, $jumlahPengulangan, $satuan, $nominal, $desimal)],
        ]];

        return $contents;
    }

    /**
     * Pemakaian token Gemini, dipetakan ke bentuk yang sama dengan Anthropic
     * supaya `worksheet_extraction_logs` nggak perlu tahu penyedianya siapa.
     *
     * `cache_read_input_tokens` selalu null: Gemini nggak punya padanan prompt
     * caching di jalur ini. Null artinya "nggak berlaku", bukan nol — dan nol
     * bakal kebaca sebagai "caching-nya nggak kena", yang beda arti.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, int|null>
     */
    private function usageGemini(array $json): array
    {
        $u = (array) ($json['usageMetadata'] ?? []);

        return [
            'input_tokens' => isset($u['promptTokenCount']) ? (int) $u['promptTokenCount'] : null,
            'output_tokens' => isset($u['candidatesTokenCount']) ? (int) $u['candidatesTokenCount'] : null,
            'cache_read_input_tokens' => null,
        ];
    }

    /**
     * Nilai standar per kolom, dibersihin ke `list<float|null>`.
     *
     * Balikin `[]` kalau model nggak ngirim — frontend punya jalur balik ke
     * pemetaan posisi, jadi respons tanpa kolom ini tetap kepakai (cuma nggak
     * dapat perlindungan geser barisnya).
     *
     * @param  array<string, mixed>  $data
     * @return list<float|null>
     */
    private function normalisasiStandarNilai(array $data): array
    {
        $nilai = $data['standard_value'] ?? null;

        if (! is_array($nilai)) {
            return [];
        }

        return array_values(array_map(
            static fn ($v): ?float => is_numeric($v) ? (float) $v : null,
            $nilai,
        ));
    }

    /** Petunjuk jumlah kolom (larutan standar) & baris (Repeat) buat bantu model. */
    /**
     * @param  list<float>|null  $nominal
     * @param  list<int>|null  $desimal
     */
    private function instruksiFoto(
        ?int $jumlahTitik,
        ?int $jumlahPengulangan,
        ?string $satuan = null,
        ?array $nominal = null,
        ?array $desimal = null,
    ): string {
        $unit = ($satuan !== null && $satuan !== '') ? $satuan : 'the instrument unit';
        $teks = 'Extract this calibration worksheet table. Each cell holds two numbers: '
            ."an instrument reading in {$unit} and a temperature in °C.";

        // Nilai nominal per kolom = petunjuk paling kuat: model tau angka tiap
        // kolom mestinya DEKAT nilai itu, jadi penempatan digit & titik desimal
        // jauh lebih akurat (mis. "9,61" nggak kebaca "961" di kolom pH 10, dan
        // "1000" nggak kebaca "100" di kolom NTU 1000). TAPI ditegasin: JANGAN
        // ngubah nilai yang beneran menyimpang — itu justru yang mesti ketangkep.
        if ($nominal !== null && $nominal !== []) {
            $kolom = [];
            foreach (array_values($nominal) as $i => $n) {
                $d = isset($desimal[$i]) ? max(0, (int) $desimal[$i]) : null;
                $label = number_format((float) $n, $d ?? 2, '.', '');
                // Buang nol belakang HANYA di bagian desimal — "1.00"→"1", tapi
                // "1000" tetap "1000" (jangan strip nol pada bilangan bulat).
                if (str_contains($label, '.')) {
                    $label = rtrim(rtrim($label, '0'), '.');
                }
                $petunjukDesimal = $d !== null
                    ? " (reading usually {$d} decimal place".($d === 1 ? '' : 's').')'
                    : '';
                $kolom[] = 'standard '.$label.' '.$unit.$petunjukDesimal;
            }
            $teks .= ' Columns left to right: '.implode(', ', $kolom).'.';
            $teks .= " Each column's reading should be CLOSE TO its standard value —"
                .' use that to place digits and the decimal point correctly, but'
                .' NEVER change a value that genuinely deviates.';
        } elseif ($jumlahTitik !== null && $jumlahTitik > 0) {
            $teks .= " It has {$jumlahTitik} standard columns, left to right.";
        }

        if ($jumlahPengulangan !== null && $jumlahPengulangan > 0) {
            $teks .= " There are up to {$jumlahPengulangan} Repeat rows.";
        }

        return $teks;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract numeric readings from a photographed instrument calibration worksheet
table for Sidik Calibration. The instrument's unit and the expected standard
columns (with their nominal values) are given in the user message — read them
first; they tell you what each column should look like.

The table has one row per "Repeat" (1..N) and one column group per standard
solution, left to right. Each cell holds two numbers: an instrument reading and a
temperature in °C.

Return ONLY the JSON matching the provided schema.

First, output "standard_value": the NOMINAL VALUE of each standard solution
column, left to right — read it from the column header, not from the readings.
E.g. headers "25 µS/cm | 1412 µS/cm | 111 mS/cm" give [25, 1412, 111]. Use the
number as printed, without converting units. Use null for a column whose header
you cannot read. This array MUST have one entry per column, in the same order as
"ph" and "suhu" — it is what lets the app place each column on the right row.

Then, for each Repeat row, output:
- "ph": the instrument reading per column, left to right (null if illegible/missing)
- "suhu": the °C reading per column, same order (null if illegible/missing)
- "ph_keyakinan" / "suhu_keyakinan": your confidence per cell — "high", "medium", "low"

(The JSON keys are "ph" and "suhu" for historical reasons. "ph" means the
instrument reading in WHATEVER unit the worksheet uses — pH, NTU, etc. — not
necessarily pH. "suhu" is the temperature in °C.)

Rules:
- Transcribe EXACTLY what is written. NEVER correct, round, or "fix" values that
  look like outliers — a deviating reading is exactly what the calibration must
  catch. If a cell clearly reads 5.00 where ~4.0 is expected, output 5.00.
- Indonesian decimal convention: a comma is a decimal point (4,04 → 4.04).
  Output all numbers with a period decimal.
- Match each reading to its column's expected decimal places from the user message
  (e.g. a 0.01-resolution column reads like 4.60, a whole-number column like 999).
  Use the column's nominal value only to place digits/decimal point and to tell the
  reading column from the °C column — NEVER to reject or alter a genuine value.
- Temperatures are typically 5–60 °C. The reading column is in the worksheet's unit.
- Confidence: "high" = crisp and unambiguous; "medium" = readable but a digit is
  smudged/uncertain; "low" = guessed, partially obscured, or handwriting hard to
  read. When unsure between two readings, pick the most likely and mark "low".
- NEVER omit a Repeat row. If a whole row is blank in the photo, still output it
  with all values null. Dropping a row would shift every row below it into the
  wrong Repeat slot on the certificate.
- If a single cell is unreadable, set its value to null and its confidence to "low".
- Do not invent rows or cells beyond what the photographed table contains.

Read each cell on its own:
- Read EVERY cell independently, digit by digit. Do NOT copy a value from the row
  above, the row below, or a neighbouring column because it "looks the same".
  Repeated readings are common on real worksheets, but they must come from what is
  actually written in THAT cell — an assumed repeat hides the one reading that
  differs, which is the whole point of the measurement.
- A cell that looks empty IS empty: output null, do not fill it from context.
- Map cells by POSITION in the grid, not by proximity to a number. A blank cell
  must keep its slot; never slide a value left or right to close a gap.

Handwriting — distinguish carefully:
- These pairs are the usual mistakes on handwritten worksheets. Compare the actual
  strokes before deciding: 1 vs 7 (crossbar/serif), 4 vs 9 (closed loop), 3 vs 8
  (left side open or closed), 5 vs 6 (top stroke), 0 vs 6, 2 vs Z, 6 vs b.
- Decide from the stroke shape, not from what the expected nominal makes
  convenient. If the strokes say 9 where 4 is expected, output 9 and let the
  calibration flag it.
- Distinguish a decimal separator from a stray pen mark or a grid line: a real
  separator sits ON the baseline between digits. If a mark is ambiguous, prefer
  the reading whose decimal places match the column's resolution, and mark the
  cell "medium".
- Trailing zeros are meaningful: "4,60" in a 0.01-resolution column is 4.60, not
  4.6. Keep the digits the technician wrote.
- If two readings are genuinely equally plausible after examining the strokes,
  pick the more likely one and mark it "low" — never "high". A confidently wrong
  number is far worse here than a flagged uncertain one: low-confidence cells get
  highlighted for a human to check, high-confidence cells do not.
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
            'required' => ['baris', 'standard_value'],
            'properties' => [
                // Nilai standar tiap KOLOM, urut kiri→kanan — sama urutannya
                // dengan `ph`/`suhu` di tiap baris.
                //
                // Ini yang bikin frontend bisa naruh angka di BARIS YANG BENAR
                // tanpa nebak dari urutan. Lembar yang digambar nggak selalu
                // sejumlah & seurut kolom di foto: lembar Conductivity generik
                // punya 4 baris (dua varian satuan titik tengah) sementara
                // fotonya 3 kolom. Tanpa kolom ini semua angka geser satu baris,
                // diam-diam, tanpa satu pun error.
                'standard_value' => $angka,
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

        // Objek `{...}` MAUPUN array telanjang `[...]`.
        //
        // Anthropic balikin objek berkunci `baris`; Gemini — dengan
        // `responseMimeType: application/json` — sering balikin arraynya
        // langsung tanpa pembungkus. Dulu cuma `{` yang dicari, jadi hasil
        // Gemini yang SUDAH BENAR isinya kebuang sebagai "tidak bisa dibaca",
        // dan teknisi disuruh ngetik ulang tabel yang barusan kebaca sempurna.
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

        // Yang paling awal muncul yang dipakai — kalau responsnya
        // `{"baris": [...]}`, `{` datang duluan dan pembungkusnya ikut kebaca.
        ksort($kandidat);
        $data = json_decode(reset($kandidat), true);

        if (! is_array($data)) {
            return null;
        }

        // Array telanjang dibungkus balik ke bentuk yang dipahami
        // `normalisasiBaris()` — satu bentuk di hilir, apa pun penyedianya.
        return array_is_list($data) ? ['baris' => $data] : $data;
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
    /**
     * Catat kalau responsnya SAH tapi nggak ada satu angka pun kebaca.
     *
     * Sebelum ini kasus itu lolos tanpa jejak: warning cuma ditulis waktu JSON
     * gagal di-parse. Jadi "nggak ada angka yang kebaca" di HP nggak ninggalin
     * apa pun di log, dan nggak ada cara bedain "modelnya balik null semua"
     * dari "requestnya nggak pernah nyampe".
     *
     * @param  list<array<string, mixed>>  $baris
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function catatHasil(array $baris, array $data): array
    {
        $kebaca = 0;

        foreach ($baris as $b) {
            foreach (['ph', 'suhu'] as $kolom) {
                foreach ((array) ($b[$kolom] ?? []) as $v) {
                    if ($v !== null) {
                        $kebaca++;
                    }
                }
            }
        }

        // Sel kosong PER KOLOM, bukan cuma totalnya. Ini yang bikin keluhan
        // "masih harus ngetik manual" punya data: kolom mana yang sering
        // dilewatin model kelihatan dari sini, bukan ditebak.
        $kosongPerKolom = [];

        foreach ($baris as $b) {
            foreach (['ph', 'suhu'] as $kolom) {
                foreach ((array) ($b[$kolom] ?? []) as $i => $v) {
                    if ($v === null) {
                        $kosongPerKolom["{$kolom}[{$i}]"] = ($kosongPerKolom["{$kolom}[{$i}]"] ?? 0) + 1;
                    }
                }
            }
        }

        $konteks = [
            'jumlah_baris' => count($baris),
            'sel_kebaca' => $kebaca,
            'sel_kosong_per_kolom' => $kosongPerKolom,
            'standard_value' => $this->normalisasiStandarNilai($data),
        ];

        if ($kebaca === 0) {
            // Dipotong: cukup buat tau bentuknya, nggak nyalin seluruh respons.
            $konteks['cuplikan'] = mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '', 0, 600);

            Log::warning('WorksheetVisionExtractor: respons sah tapi NOL angka kebaca', $konteks);

            return $baris;
        }

        // Yang BERHASIL ikut dicatat. Sebelum ini cuma kegagalan yang
        // ninggalin jejak, jadi scan yang "berhasil tapi nyisain sel kosong"
        // — yang bikin teknisi tetap ngetik manual — nggak kelihatan sama
        // sekali di log.
        Log::info('WorksheetVisionExtractor: ekstraksi selesai', $konteks);

        return $baris;
    }

    private function normalisasiBaris(array $data): array
    {
        $out = [];

        // Model kadang naruh barisnya di `data`, bukan `baris` — kejadian nyata
        // 12 Agt 2026: seluruh tabel kebaca SEMPURNA (angka benar, keyakinan
        // `high` semua, `standard_value` lengkap) tapi kebuang total cuma
        // gara-gara nama kunci, dan di HP muncul sebagai "fotonya gelap/buram".
        //
        // Dua-duanya diterima. Ini bukan nebak isi: strukturnya identik, cuma
        // labelnya beda, dan schema-nya sendiri yang minta `baris`.
        $baris = $data['baris'] ?? $data['data'] ?? [];

        foreach ((array) $baris as $b) {
            if (! is_array($b) || ! isset($b['ph']) || ! is_array($b['ph'])) {
                continue;
            }

            $ph = array_map(fn ($v) => $this->angka($v), array_values($b['ph']));
            $suhu = array_map(fn ($v) => $this->angka($v), array_values((array) ($b['suhu'] ?? [])));
            $n = count($ph);

            // Suhu disamakan panjang sama ph (pengukuran sepasang per sel).
            $suhu = array_slice(array_pad($suhu, $n, null), 0, $n);

            $baris = [
                'ph' => $ph,
                'suhu' => $suhu,
                'ph_keyakinan' => $this->keyakinanArray($b['ph_keyakinan'] ?? [], $n),
                'suhu_keyakinan' => $this->keyakinanArray($b['suhu_keyakinan'] ?? [], $n),
            ];

            // Nomor Repeat dari kolom paling kiri, kalau modelnya ngasih.
            //
            // Posisi array dipakai sebagai nomor Repeat di hilir, jadi satu
            // baris yang kelewat bikin SELURUH baris di bawahnya geser —
            // pembacaan Repeat 3 mendarat di slot Repeat 2, di dokumen
            // kalibrasi, tanpa ada yang kelihatan salah. Nomor eksplisit ini
            // yang bikin pergeseran itu ketahuan (dan bisa ditempatkan benar)
            // daripada diam-diam kejadian.
            $nomor = filter_var($b['repeat'] ?? null, FILTER_VALIDATE_INT);
            if ($nomor !== false && $nomor > 0) {
                $baris['repeat'] = $nomor;
            }

            $out[] = $baris;
        }

        // Kalau SEMUA baris punya nomor, urutin sesuai nomornya — bukan sesuai
        // urutan datangnya dari model.
        $bernomor = array_filter($out, fn (array $b): bool => isset($b['repeat']));
        if (count($bernomor) === count($out) && $out !== []) {
            usort($out, fn (array $a, array $b): int => $a['repeat'] <=> $b['repeat']);
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
