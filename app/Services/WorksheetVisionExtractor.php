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
     * Jatah detik yang disisain buat kerjaan sesudah panggilan HTTP: decode JSON,
     * nyatet log ekstraksi, nyusun response.
     */
    private const CADANGAN_DETIK = 5;

    /**
     * Timeout HTTP yang NGGAK boleh lebih panjang dari umur prosesnya sendiri.
     *
     * Kenapa ada: `max_execution_time` di php.ini dev itu 30 detik, sedangkan
     * timeout AI default 60. Artinya PHP mati duluan — dan `catch
     * (ConnectionException)` di bawah, yang tugasnya balikin "Coba lagi
     * sebentar" ke teknisi, NGGAK PERNAH kejalan pas paling dibutuhin.
     * Yang kelihatan di lapangan malah "Maximum execution time exceeded".
     *
     * Dipotong di sini, bukan dinaikin di php.ini: server produksi punya batas
     * sendiri (php-fpm, nginx) yang nggak kekontrol dari repo ini. Yang bisa
     * dijamin cuma satu — aplikasi nggak menjanjikan tunggu yang lebih lama
     * dari jatah hidupnya.
     *
     * `max_execution_time` 0 = nggak dibatasi (CLI, queue worker) → pakai angka
     * config apa adanya.
     */
    private function batasWaktu(int $dariConfig): int
    {
        $batasProses = (int) ini_get('max_execution_time');

        if ($batasProses <= 0) {
            return $dariConfig;
        }

        return max(5, min($dariConfig, $batasProses - self::CADANGAN_DETIK));
    }

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
     * Penyedia AI yang BENERAN kepakai sekarang.
     *
     * @return 'anthropic'|'gemini'
     */
    public static function penyediaAktif(): string
    {
        return strtolower((string) config('services.vision.driver', 'anthropic')) === 'gemini'
            ? 'gemini'
            : 'anthropic';
    }

    /**
     * Nama model yang bakal dipanggil — ngikut penyedia yang aktif.
     *
     * Ada supaya jalur GAGAL bisa nyatat model yang sama dengan jalur sukses.
     * Sebelumnya jalur "API key belum diisi" nyatat `services.anthropic.model`
     * apa pun drivernya, jadi kegagalan karena `GEMINI_API_KEY` kosong
     * kecatat sebagai `claude-opus-4-8` — menyesatkan persis di penelusuran
     * yang jadi alasan log itu ada.
     */
    public static function modelAktif(): string
    {
        return (string) config('services.'.self::penyediaAktif().'.model');
    }

    /**
     * @param  int|null  $jumlahTitik  jumlah larutan standar (kolom, mis. 3) — petunjuk buat model
     * @param  int|null  $jumlahPengulangan  jumlah Repeat (baris) yang diharapkan — petunjuk
     * @param  string|null  $satuan  satuan pembacaan (mis. `pH`, `NTU`) — bikin model tau kolom bacaan vs °C
     * @param  list<float>|null  $nominal  nilai nominal standar tiap kolom (kiri→kanan), mis. [1,100,1000]
     * @param  list<int>|null  $desimal  jumlah desimal tipikal tiap kolom (sejajar $nominal)
     * @param  bool  $kolomSuhu  tiap sel berisi pembacaan + suhu °C (lembar Spectrophotometer: false)
     * @param  bool  $standarDiBaris  standar turun ke bawah & Repeat berjajar ke kanan (Spectrophotometer: true)
     */
    public function extract(
        string $isiGambar,
        string $mimeType,
        ?int $jumlahTitik = null,
        ?int $jumlahPengulangan = null,
        ?string $satuan = null,
        ?array $nominal = null,
        ?array $desimal = null,
        bool $kolomSuhu = true,
        bool $standarDiBaris = false,
    ): array {
        $petunjuk = new PetunjukLembarKerja(
            $jumlahTitik,
            $jumlahPengulangan,
            $satuan,
            $nominal,
            $desimal,
            $kolomSuhu,
            $standarDiBaris,
        );

        $penyedia = self::penyediaAktif();

        $mimeType = strtolower($mimeType);
        if (! in_array($mimeType, self::MIME_DIDUKUNG, true)) {
            $mimeType = 'image/jpeg';
        }

        if ($penyedia === 'gemini') {
            return $this->lewatGemini($isiGambar, $mimeType, $petunjuk);
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
                'text' => $this->systemPrompt($petunjuk),
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ]],
            'messages' => $this->bangunMessages($isiGambar, $mimeType, $petunjuk),
        ];

        // Structured Output: jamin bentuk JSON. Bisa dimatiin lewat env kalau
        // API nolak skema-nya — prompt tetap minta JSON murni sebagai jaring.
        if ((bool) config('services.anthropic.structured_output', true)) {
            $body['output_config'] = [
                'format' => ['type' => 'json_schema', 'schema' => $this->schema($petunjuk)],
            ];
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
            return $this->gagal(
                $model,
                $this->pesanGagalHttp($resp->status(), $resp->json('error.message')),
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
            'data' => $this->hasil($data, $petunjuk),
            'raw' => $json,
            'usage' => $this->usage($json),
            'error' => null,
            'model' => $model,
        ];
    }

    /**
     * Bentuk `data` yang dikirim ke mobile — SATU bentuk, apa pun bentuk
     * kertasnya.
     *
     * Di sinilah lembar yang standarnya turun ke bawah diputar balik: model
     * membacanya baris demi baris seperti yang tercetak (satu baris = satu
     * standar), sedangkan mobile — dan seluruh alur simpan di belakangnya —
     * mengharap satu entri per Repeat. Yang memutar server, bukan model:
     * meminta model menulis ulang tabel secara transpos berarti dia harus
     * menyimpan sepuluh baris di kepala sambil membaca kolom, dan tiap sel yang
     * salah tempat mendarat di panjang gelombang yang salah tanpa gejala.
     *
     * @param  array<string, mixed>  $data
     * @return array{baris: list<array<string, mixed>>, standard_value: list<float|null>}
     */
    private function hasil(array $data, PetunjukLembarKerja $petunjuk): array
    {
        $baris = $this->normalisasiBaris($data, $petunjuk);

        if ($petunjuk->standarDiBaris) {
            $baris = $this->putarKeRepeat($baris);
        }

        return [
            'baris' => $this->catatHasil($baris, $data),
            // Diteruskan apa adanya. Frontend yang mutusin kolom mana
            // mendarat di titik mana — di sini nggak ada daftar titiknya.
            'standard_value' => $this->normalisasiStandarNilai($data),
        ];
    }

    /**
     * Tabel yang standarnya turun ke bawah → satu entri per Repeat.
     *
     * Masuk: N baris (satu per standar), tiap baris `ph` sepanjang jumlah
     * Repeat. Keluar: M baris (satu per Repeat), tiap baris `ph` sepanjang
     * jumlah standar — persis bentuk yang sama dengan lembar pH, jadi mobile
     * nggak perlu tahu kertasnya bentuknya beda.
     *
     * Baris yang lebih pendek dari yang terpanjang diisi `null`, bukan
     * dirapatkan: sel kosong di tengah tabel harus tetap di slotnya. Menggeser
     * satu sel ke kiri buat nutup lubang bikin pembacaan Repeat 3 mendarat di
     * Repeat 2 — persis kegagalan yang dijaga di seluruh berkas ini.
     *
     * @param  list<array<string, mixed>>  $baris
     * @return list<array<string, mixed>>
     */
    private function putarKeRepeat(array $baris): array
    {
        if ($baris === []) {
            return [];
        }

        $jumlahRepeat = max(array_map(
            static fn (array $b): int => count((array) ($b['ph'] ?? [])),
            $baris,
        ));

        $keluar = [];

        for ($r = 0; $r < $jumlahRepeat; $r++) {
            $nilai = [];
            $keyakinan = [];

            foreach ($baris as $b) {
                $nilai[] = ((array) ($b['ph'] ?? []))[$r] ?? null;
                $keyakinan[] = ((array) ($b['ph_keyakinan'] ?? []))[$r] ?? 'low';
            }

            $keluar[] = [
                'ph' => $nilai,
                // Lembar begini nggak punya kolom suhu sama sekali. Dikirim
                // kosong, bukan diisi null sepanjang barisnya: null di sini
                // artinya "ada selnya, nggak kebaca", dan itu yang bikin mobile
                // menandai seluruh lembar `perlu_dicek` selamanya.
                'suhu' => [],
                'ph_keyakinan' => $keyakinan,
                'suhu_keyakinan' => [],
                'repeat' => $r + 1,
            ];
        }

        return $keluar;
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
        PetunjukLembarKerja $petunjuk,
    ): array {
        $messages = [];

        $tersedia = [];
        foreach ($this->contohFewShot($petunjuk) as $c) {
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
                ['type' => 'text', 'text' => $this->instruksiFoto($petunjuk)],
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
        PetunjukLembarKerja $petunjuk,
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
                ->timeout($this->batasWaktu((int) config('services.gemini.timeout', 60)))
                ->baseUrl((string) config(
                    'services.gemini.base_url',
                    'https://generativelanguage.googleapis.com',
                ))
                ->withQueryParameters(['key' => $apiKey])
                ->post("/v1beta/models/{$model}:generateContent", [
                    'system_instruction' => [
                        'parts' => [['text' => $this->systemPrompt($petunjuk)]],
                    ],
                    'contents' => $this->kontenGemini($isiGambar, $mimeType, $petunjuk),
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
            return $this->gagal(
                $model,
                $this->pesanGagalHttp($resp->status(), $resp->json('error.message')),
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
            'data' => $this->hasil($data, $petunjuk),
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
        PetunjukLembarKerja $petunjuk,
    ): array {
        $contents = [];

        foreach ($this->contohFewShot($petunjuk) as $c) {
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
            ['text' => $this->instruksiFoto($petunjuk)],
        ]];

        return $contents;
    }

    /**
     * Contoh foto + JSON yang dilampirkan sebelum foto lapangan.
     *
     * Kosong buat lembar yang bentuknya bukan bentuk pH. Contoh yang ada cuma
     * lembar pH asli (Tirta Gracia, cert 012-CAL-524), dan buat lembar
     * Spectrophotometer contoh itu memperagakan tepat dua hal yang nggak ada di
     * kertasnya: kolom suhu di tiap sel, dan standar sebagai kolom. Model yang
     * dikasih dua contoh begitu lalu disuruh baca tabel yang bentuknya lain
     * cenderung mengikuti CONTOHNYA — mengarang kolom °C, atau memampatkan tiga
     * Repeat jadi satu baris.
     *
     * @return list<array{file: string, json: string}>
     */
    private function contohFewShot(PetunjukLembarKerja $petunjuk): array
    {
        if (! $petunjuk->bentukPh()) {
            return [];
        }

        return [
            ['file' => 'few_shot_before.jpg', 'json' => $this->fewShotBefore()],
            ['file' => 'few_shot_after.jpg', 'json' => $this->fewShotAfter()],
        ];
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

    /** Petunjuk bentuk tabel, jumlah standar, & jumlah Repeat buat bantu model. */
    private function instruksiFoto(PetunjukLembarKerja $petunjuk): string
    {
        $satuan = $petunjuk->satuan;
        $nominal = $petunjuk->nominal;
        $desimal = $petunjuk->desimal;

        $unit = ($satuan !== null && $satuan !== '') ? $satuan : 'the instrument unit';

        $teks = $petunjuk->kolomSuhu
            ? 'Extract this calibration worksheet table. Each cell holds two numbers: '
                ."an instrument reading in {$unit} and a temperature in °C."
            : 'Extract this calibration worksheet table. Each cell holds ONE number: '
                ."an instrument reading in {$unit}. This worksheet has NO temperature column.";

        // Nilai nominal per standar = petunjuk paling kuat: model tau angka tiap
        // kolom mestinya DEKAT nilai itu, jadi penempatan digit & titik desimal
        // jauh lebih akurat (mis. "9,61" nggak kebaca "961" di kolom pH 10, dan
        // "1000" nggak kebaca "100" di kolom NTU 1000). TAPI ditegasin: JANGAN
        // ngubah nilai yang beneran menyimpang — itu justru yang mesti ketangkep.
        if ($nominal !== null && $nominal !== []) {
            $daftar = [];
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
                $daftar[] = 'standard '.$label.' '.$unit.$petunjukDesimal;
            }

            $teks .= $petunjuk->standarDiBaris
                ? ' Rows top to bottom: '.implode(', ', $daftar).'.'
                : ' Columns left to right: '.implode(', ', $daftar).'.';

            $teks .= ' Each reading should be CLOSE TO its standard value —'
                .' use that to place digits and the decimal point correctly, but'
                .' NEVER change a value that genuinely deviates.';
        } elseif ($petunjuk->jumlahTitik !== null && $petunjuk->jumlahTitik > 0) {
            $teks .= $petunjuk->standarDiBaris
                ? " It has {$petunjuk->jumlahTitik} standard rows, top to bottom."
                : " It has {$petunjuk->jumlahTitik} standard columns, left to right.";
        }

        if ($petunjuk->jumlahPengulangan !== null && $petunjuk->jumlahPengulangan > 0) {
            $teks .= $petunjuk->standarDiBaris
                ? " There are up to {$petunjuk->jumlahPengulangan} Repeat columns (X1..X{$petunjuk->jumlahPengulangan})."
                : " There are up to {$petunjuk->jumlahPengulangan} Repeat rows.";
        }

        return $teks;
    }

    /**
     * Prompt sistem = bagian BENTUK + aturan baca yang dipakai semua lembar.
     *
     * Dipecah dua karena cuma bagian pertamanya yang bergantung ke bentuk
     * kertas. Aturan bacanya — jangan membetulkan anomali, koma itu titik
     * desimal, baca tiap sel sendiri-sendiri, bedakan 1 dari 7 — berlaku sama
     * buat lembar mana pun, dan menyalinnya dua kali berarti suatu hari nanti
     * cuma salah satunya yang diperbaiki.
     */
    private function systemPrompt(PetunjukLembarKerja $petunjuk): string
    {
        return $this->bentukLembar($petunjuk)."\n\n".$this->aturanBaca();
    }

    /** Bagian prompt yang menjelaskan susunan tabel & bentuk JSON-nya. */
    private function bentukLembar(PetunjukLembarKerja $petunjuk): string
    {
        $pembuka = <<<'PROMPT'
        You extract numeric readings from a photographed instrument calibration worksheet
        table for Sidik Calibration. The instrument's unit and the expected standards
        (with their nominal values) are given in the user message — read them first; they
        tell you what each cell should look like.
        PROMPT;

        if ($petunjuk->standarDiBaris) {
            // Lembar Spectrophotometer: standar (panjang gelombang / %T) turun
            // ke bawah, Repeat berjajar ke kanan, dan tiap sel cuma satu angka.
            // Yang diminta di sini bentuk SEPERTI TERCETAK — satu obyek per
            // baris kertas. Memutarnya jadi per-Repeat dikerjakan server, bukan
            // model: model yang disuruh transpos harus menahan sepuluh baris di
            // kepala sambil membaca kolom, dan tiap sel yang meleset mendarat di
            // panjang gelombang yang salah tanpa satu pun gejala.
            return $pembuka."\n\n".<<<'PROMPT'
            The table has one row per STANDARD (its nominal value is printed at the left of
            the row) and one column per "Repeat" (X1..Xn), left to right. Each cell holds a
            SINGLE number: the instrument reading. There is NO temperature column — do not
            invent one.

            Return ONLY the JSON matching the provided schema.

            First, output "standard_value": the NOMINAL VALUE printed at the left of each
            row, top to bottom — read it from the row label, not from the readings.
            E.g. labels "279,6 | 287,7 | 334,0" give [279.6, 287.7, 334.0]. Use the number
            as printed, without converting units. Use null for a row whose label you cannot
            read. This array MUST have one entry per row, in the same order as "baris" — it
            is what lets the app place each row on the right standard.

            Then, for each STANDARD row, output:
            - "ph": the reading of each Repeat column, left to right (null if illegible/missing)
            - "ph_keyakinan": your confidence per cell — "high", "medium", "low"

            (The JSON key is "ph" for historical reasons. It means the instrument reading in
            WHATEVER unit the worksheet uses — nm, %T, etc. — not pH.)

            - NEVER omit a standard row. If a whole row is blank in the photo, still output it
              with all values null. Dropping a row would shift every row below it onto the
              wrong standard on the certificate.
            - NEVER omit a Repeat column either: every row must have the same number of
              entries, left to right, with null for the ones you cannot read.
            PROMPT;
        }

        return $pembuka."\n\n".<<<'PROMPT'
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

        - NEVER omit a Repeat row. If a whole row is blank in the photo, still output it
          with all values null. Dropping a row would shift every row below it into the
          wrong Repeat slot on the certificate.
        - Temperatures are typically 5–60 °C. The reading column is in the worksheet's unit.
        PROMPT;
    }

    /** Aturan baca yang sama buat semua bentuk lembar. */
    private function aturanBaca(): string
    {
        return <<<'PROMPT'
        Rules:
        - Transcribe EXACTLY what is written. NEVER correct, round, or "fix" values that
          look like outliers — a deviating reading is exactly what the calibration must
          catch. If a cell clearly reads 5.00 where ~4.0 is expected, output 5.00.
        - Indonesian decimal convention: a comma is a decimal point (4,04 → 4.04).
          Output all numbers with a period decimal.
        - Match each reading to its standard's expected decimal places from the user message
          (e.g. a 0.01-resolution standard reads like 4.60, a whole-number one like 999).
          Use the nominal value only to place digits/decimal point — NEVER to reject or
          alter a genuine value.
        - Confidence: "high" = crisp and unambiguous; "medium" = readable but a digit is
          smudged/uncertain; "low" = guessed, partially obscured, or handwriting hard to
          read. When unsure between two readings, pick the most likely and mark "low".
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
          the reading whose decimal places match the standard's resolution, and mark the
          cell "medium".
        - Trailing zeros are meaningful: "4,60" in a 0.01-resolution column is 4.60, not
          4.6. Keep the digits the technician wrote.
        - If two readings are genuinely equally plausible after examining the strokes,
          pick the more likely one and mark it "low" — never "high". A confidently wrong
          number is far worse here than a flagged uncertain one: low-confidence cells get
          highlighted for a human to check, high-confidence cells do not.
        PROMPT;
    }

    /**
     * Skema JSON yang dipaksakan ke model.
     *
     * `suhu`/`suhu_keyakinan` cuma dipasang buat lembar yang PUNYA kolom suhu.
     * Sebelumnya dua kunci itu selalu `required`, jadi lembar Spectrophotometer
     * — yang tiap selnya cuma satu angka — memaksa model MENGARANG suhu supaya
     * jawabannya lolos skema. Yang keluar bukan error, tapi angka palsu yang
     * kelihatan sah.
     *
     * @return array<string, mixed>
     */
    private function schema(PetunjukLembarKerja $petunjuk): array
    {
        $keyakinan = [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ];
        $angka = ['type' => 'array', 'items' => ['type' => ['number', 'null']]];

        $wajib = ['ph', 'ph_keyakinan'];
        $isiBaris = ['ph' => $angka, 'ph_keyakinan' => $keyakinan];

        if ($petunjuk->kolomSuhu) {
            $wajib = ['ph', 'suhu', 'ph_keyakinan', 'suhu_keyakinan'];
            $isiBaris = [
                'ph' => $angka,
                'suhu' => $angka,
                'ph_keyakinan' => $keyakinan,
                'suhu_keyakinan' => $keyakinan,
            ];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['baris', 'standard_value'],
            'properties' => [
                // Nilai standar tiap KOLOM (atau tiap BARIS kalau
                // `standarDiBaris`), urut seperti di kertas — sama urutannya
                // dengan `ph`/`suhu`.
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
                        'required' => $wajib,
                        'properties' => $isiBaris,
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

    private function normalisasiBaris(array $data, PetunjukLembarKerja $petunjuk): array
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
            $n = count($ph);

            $suhu = [];
            $suhuKeyakinan = [];

            // Lembar tanpa kolom suhu (mis. Spectrophotometer) ninggalin dua
            // array ini KOSONG, bukan diisi null sepanjang $ph. Bedanya kelihatan
            // di HP: `suhu_keyakinan` yang dipadding bakal kebaca "low" semua
            // oleh pengecekan keyakinan rendah, jadi tiap scan yang sebetulnya
            // sempurna tetap dicap perlu dicek manual.
            if ($petunjuk->kolomSuhu) {
                $suhu = array_map(fn ($v) => $this->angka($v), array_values((array) ($b['suhu'] ?? [])));

                // Suhu disamakan panjang sama ph (pengukuran sepasang per sel).
                $suhu = array_slice(array_pad($suhu, $n, null), 0, $n);
                $suhuKeyakinan = $this->keyakinanArray($b['suhu_keyakinan'] ?? [], $n);
            }

            $baris = [
                'ph' => $ph,
                'suhu' => $suhu,
                'ph_keyakinan' => $this->keyakinanArray($b['ph_keyakinan'] ?? [], $n),
                'suhu_keyakinan' => $suhuKeyakinan,
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
     * Pesan buat kegagalan HTTP dari penyedia AI.
     *
     * 429 punya DUA arti yang beda jauh, dan bedanya nggak kelihatan dari kode
     * statusnya:
     *
     *  - **Rate limit / server sibuk** — sembuh sendiri. "Tunggu beberapa
     *    menit" itu saran yang bener.
     *  - **Kredit/kuota habis** — NGGAK akan sembuh dengan nunggu. Kejadian
     *    13 Agt 2026: teknisi nyoba jam 11:53 & 13:22, dua-duanya dapat
     *    "layanan lagi sibuk", padahal yang perlu dilakuin cuma satu — isi
     *    saldo. Sehari kebuang nungguin sesuatu yang nggak berubah.
     *
     * Yang dibedain ISI pesan errornya, bukan statusnya, karena cuma di situ
     * bedanya kelihatan.
     */
    private function pesanGagalHttp(int $status, mixed $pesanApi): string
    {
        $teks = mb_strtolower(is_string($pesanApi) ? $pesanApi : '');

        $habis = str_contains($teks, 'credits are depleted')
            || str_contains($teks, 'insufficient')
            || str_contains($teks, 'billing')
            || str_contains($teks, 'exceeded your current quota')
            || str_contains($teks, 'quota_exceeded');

        if ($habis) {
            return 'Kuota layanan AI habis, jadi foto tabel belum bisa dibaca. '
                .'Kabarin admin buat isi ulang — nunggu nggak bakal bikin ini jalan lagi. '
                .'Sementara ini isi angkanya manual.';
        }

        // 503/429 sisanya MASALAH DI SISI PENYEDIA, bukan fotonya. Pesan yang
        // nyuruh "foto ulang" bikin teknisi motret berkali-kali buat sesuatu
        // yang mustahil berhasil sampai bebannya turun — kejadian 12 Agt 2026,
        // dan dua kali kelihatan kayak "fotonya jelek".
        if (in_array($status, [429, 503], true)) {
            return 'Layanan AI lagi sibuk. Tunggu beberapa menit lalu coba lagi — fotonya nggak perlu diulang.';
        }

        return 'Layanan AI menolak permintaan.';
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
