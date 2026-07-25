# SPEC — Prompt & Implementasi AI Vision Worksheet (siap tempel ke backend)

Pendamping teknis `SPEC-vision-ai-worksheet-extraction.md`. Berisi: model, cara
"melatih" yang benar, JSON schema, system prompt final, **few-shot dari data
Tirta Gracia asli**, dan contoh kode Laravel. Endpoint yang dipanggil mobile:
`POST /api/raw-measurements/extract-from-photo` (lihat
`docs/permintaan-backend-2026-07-24.md` §4).

---

## 0. Luruskan dulu: "dilatih" = prompt engineering, BUKAN fine-tune model

- **Claude tidak perlu di-training/fine-tune.** Anthropic tidak menyediakan
  fine-tuning Claude untuk kasus ini, dan tidak dibutuhkan — model langsung baca
  gambar via API.
- Yang bikin akurat & konsisten = **(a) few-shot examples** (foto worksheet asli
  + JSON benar, ditaruh di prompt) dan **(b) structured output** (paksa bentuk
  JSON). Perbaikan berkelanjutan lewat log koreksi teknisi (§7), bukan retrain.
- **KOREKSI dari SPEC lama:** `temperature: 0` **sudah usang** — parameter
  `temperature` **dihapus** di Claude Opus 4.8 / Sonnet 5 (kirim → error 400).
  Gantinya pakai **Structured Outputs** (`output_config.format`) untuk menjamin
  bentuk JSON. Itu yang bikin hasil pasti bisa di-parse, bukan `temperature`.

## 1. Model

| Model | ID | Alasan |
|---|---|---|
| **Claude Opus 4.8** (default) | `claude-opus-4-8` | Paling akurat, vision resolusi tinggi (≤2576px), structured output. Data ini masuk sertifikat resmi → akurasi wajib. Volume rendah, biaya bukan isu. $5/$25 per 1M token. |
| Claude Sonnet 5 | `claude-sonnet-5` | Lebih murah; tes trade-off akurasi/biaya di worksheet asli |
| Claude Haiku 4.5 | `claude-haiku-4-5` | Termurah; **wajib uji akurasi dulu** |

> Verifikasi ID model masih aktif via dashboard sebelum hardcode.

## 2. Bentuk output (harus sama dengan yang di-parse mobile)

Mobile (`lib/services/worksheet_vision.dart`) memetakan respons ke sel. **Satu
foto = satu tabel** (Before ATAU After adjustment). `baris` = Repeat 1..n; tiap
array sepanjang jumlah larutan standar (4/7/10), urut kiri→kanan; `null` =
sel tak terbaca. Keyakinan: `high` | `medium` | `low`.

```json
{
  "baris": [
    { "ph": [4.04, 7.02, 9.61], "suhu": [22.2, 22.3, 22.2],
      "ph_keyakinan": ["high","high","medium"],
      "suhu_keyakinan": ["high","high","high"] }
  ]
}
```

### JSON Schema untuk `output_config.format`

Structured output menolak constraint numerik/panjang string (`minimum`,
`maxLength`, dll) dan **mewajibkan `additionalProperties: false`**. Skema ini
sudah aman:

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": ["baris"],
  "properties": {
    "baris": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["ph", "suhu", "ph_keyakinan", "suhu_keyakinan"],
        "properties": {
          "ph":   { "type": "array", "items": { "type": ["number", "null"] } },
          "suhu": { "type": "array", "items": { "type": ["number", "null"] } },
          "ph_keyakinan":   { "type": "array", "items": { "type": "string", "enum": ["high","medium","low"] } },
          "suhu_keyakinan": { "type": "array", "items": { "type": "string", "enum": ["high","medium","low"] } }
        }
      }
    }
  }
}
```

## 3. System prompt final (English — akurasi terbaik)

```
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
```

## 4. Few-shot — data Tirta Gracia ASLI (cert 012-CAL-524)

Sumber: `Master Olah Data_pH for trial_CSV/INPUT DATA.csv`, tabel **Before
Adjustment Reading**, buffer 3.99 / 7 / 10.01. **Lampirkan foto worksheet tabel
ini** sebagai image block sebelum JSON-nya (ground truth). Perhatikan **Repeat 4
pH kolom-1 = 5.00** — anomali asli yang WAJIB ditranskrip apa adanya, bukan
dibetulkan jadi 4.0x.

Ground-truth JSON untuk tabel Before di atas:

```json
{
  "baris": [
    { "ph": [4.04, 7.02, 9.61], "suhu": [22.2, 22.3, 22.2], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.04, 7.04, 9.94], "suhu": [22.2, 22.3, 22.2], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.04, 7.05, 9.66], "suhu": [22.2, 22.3, 22.2], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [5.00, 7.02, 9.61], "suhu": [22.2, 22.3, 22.2], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.04, 7.02, 9.61], "suhu": [22.2, 22.3, 22.2], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] }
  ]
}
```

Tabel **After Adjustment** yang sama (buat contoh ke-2 kalau mau — lampirkan foto After-nya):

```json
{
  "baris": [
    { "ph": [4.00, 7.01, 10.11], "suhu": [22.2, 22.2, 22.1], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.00, 7.01, 10.11], "suhu": [22.2, 22.2, 22.1], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.00, 7.00, 10.11], "suhu": [22.1, 22.2, 22.1], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.00, 7.00, 10.11], "suhu": [22.2, 22.2, 22.1], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] },
    { "ph": [4.00, 7.00, 10.11], "suhu": [22.2, 22.2, 22.1], "ph_keyakinan": ["high","high","high"], "suhu_keyakinan": ["high","high","high"] }
  ]
}
```

> **Tugas transisi buat Arkaan:** foto tabel Before & After di worksheet fisik
> Tirta Gracia, simpan 2 file gambar itu (mis. `few_shot_before.jpg`,
> `few_shot_after.jpg`), lampirkan di prompt bersama JSON di atas. Idealnya 2–3
> few-shot dari worksheet berbeda supaya AI kenal variasi tulisan tangan.

## 5. Contoh kode Laravel (`App\Services\WorksheetVisionExtractor`)

Pakai SDK resmi: `composer require "anthropic-ai/sdk"`. **Structured output**
menjamin bentuk; **prompt caching** bikin system + few-shot (termasuk foto
contoh yang besar) tidak diproses ulang tiap panggilan → lebih murah & cepat.

```php
<?php

namespace App\Services;

use Anthropic\Client;

class WorksheetVisionExtractor
{
    private Client $claude;

    // Skema & system prompt dari §2–§3 (taruh sebagai const/config).
    private const MODEL = 'claude-opus-4-8';

    public function __construct()
    {
        // JANGAN taruh API key di mobile — hanya di server (.env: ANTHROPIC_API_KEY)
        $this->claude = new Client(apiKey: config('services.anthropic.key'));
    }

    /**
     * @param string $fotoPath  path/URL foto tabel worksheet yang diunggah teknisi
     * @return array{baris: array}  JSON terstruktur (lihat §2)
     */
    public function ekstrak(string $fotoPath): array
    {
        $fotoB64 = base64_encode(file_get_contents($fotoPath));

        $message = $this->claude->messages->create(
            model: self::MODEL,
            maxTokens: 2048,

            // System di-cache: konten stabil, dipakai tiap panggilan.
            system: [[
                'type' => 'text',
                'text' => self::SYSTEM_PROMPT,          // §3
                'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ]],

            // Few-shot sebagai giliran user→assistant, LALU foto baru.
            // Foto contoh (besar) di-cache lewat breakpoint di akhir contoh.
            messages: [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => [
                            'type' => 'base64', 'media_type' => 'image/jpeg',
                            'data' => base64_encode(file_get_contents(
                                storage_path('app/few_shot/few_shot_before.jpg')
                            )),
                        ]],
                        ['type' => 'text', 'text' => 'Extract this table.'],
                    ],
                ],
                [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => self::FEW_SHOT_BEFORE_JSON, // §4, string JSON
                        // Breakpoint cache TERAKHIR yang stabil (system + contoh).
                        'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h'],
                    ]],
                ],
                // Foto ASLI dari lapangan — bagian yang berubah, TIDAK di-cache.
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => [
                            'type' => 'base64', 'media_type' => 'image/jpeg',
                            'data' => $fotoB64,
                        ]],
                        ['type' => 'text', 'text' => 'Extract this table.'],
                    ],
                ],
            ],

            // Paksa bentuk JSON (pengganti temperature:0 yang sudah dihapus).
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA], // §2
            ],
        );

        // Dengan structured output, blok teks pertama = JSON valid.
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return json_decode($block->text, true);
            }
        }

        throw new \RuntimeException('AI vision: tidak ada output JSON.');
    }
}
```

**Catatan implementasi:**
- **Tanpa `temperature`, tanpa `budget_tokens`** (keduanya error 400 di Opus 4.8).
- **Prompt caching**: cek `$message->usage->cacheReadInputTokens > 0` untuk
  memastikan cache kena. TTL `1h` cocok karena panggilan lapangan sporadis.
  Verifikasi hit → hemat ~90% di porsi ter-cache.
- **Adaptive thinking (opsional)**: `thinking: ['type' => 'adaptive']` bisa
  menaikkan akurasi di tulisan tangan sulit (kompatibel dengan structured
  output), tapi nambah latensi/biaya. Uji dulu; untuk cetak rapi biasanya tak perlu.
- **Ukuran gambar**: kompres wajar (≤~1.5MB) sebelum kirim; resolusi cukup buat
  baca koma desimal. Jangan over-compress (nanti `4,04` kebaca `404`).

## 6. Validasi server-side (sebelum balikin ke mobile)

- Panjang tiap `ph`/`suhu` per baris = jumlah larutan standar (mis. 3).
- Tolak/`null`-kan sel pH di luar 0–14 atau suhu di luar rentang lab **hanya
  untuk menandai anomali** (jangan diam-diam dibetulkan — itu tugas verifikasi
  admin/GUM, bukan extractor).
- Kalau AI balikin bentuk aneh, kembalikan JSON kosong `{"baris":[]}` — mobile
  menampilkannya sebagai "tak terbaca" dan teknisi ketik manual (fallback).

## 7. Loop perbaikan (pengganti "training")

Tabel `worksheet_extraction_logs` (SPEC §3.4): simpan `raw_model_response`
(JSON AI) + `technician_corrections` (diff AI vs nilai final yang dikonfirmasi
teknisi). Tiap beberapa minggu, review diff → temukan pola salah baca (mis.
`9`↔`4`, koma↔titik) → **perbaiki system prompt / tambah few-shot**, bukan
retrain model. Ini bikin akurasi naik seiring waktu tanpa biaya training.

## 8. Ringkas alur
Teknisi foto tabel → mobile upload ke `POST /raw-measurements/extract-from-photo`
→ backend `WorksheetVisionExtractor.ekstrak()` (Claude Opus 4.8 + few-shot +
structured output + cache) → JSON per sel + keyakinan → mobile pra-isi sel,
tandai `low` kuning → **teknisi konfirmasi** → simpan + log koreksi (§7).

Alur besar end-to-end:
```
Teknisi FOTO worksheet SIDIK-FM-CAL-0509
   │  AI Vision → pembacaan MENTAH (pH+°C per buffer per repeat)
   ▼
Teknisi KONFIRMASI (sel low-confidence dicek) → kirim ke admin
   ▼
ADMIN "olah data" → hitung Standard Value (terkoreksi suhu), Correction,
   U95%, k, kondisi lingkungan ±   ← MESIN RUMUS DETERMINISTIK (lihat §9)
   ▼
Validasi → SERTIFIKAT (mis. 012-CAL-524)
```

## 9. BATASAN AI DI SISI ADMIN — WAJIB BACA

**Angka sertifikat WAJIB dari mesin hitung deterministik (rumus GUM), BUKAN dari
AI/LLM.** Lihat contoh angka di `SERTIFIKAT.csv`: `Standard Value 4.009244572`,
`Correction 0.00924…`, `U95% 0.02343221021262627`, `k 1.9706589608358136`,
`Env T 20.97 ± 1.7117…` — belasan desimal, hasil rumus, bukan tebakan.

**Kenapa LLM TIDAK boleh menghitung angka sertifikat:**
1. **Reproducible** — input sama harus SELALU keluar angka sama. LLM tidak
   deterministik.
2. **Traceable** — lab terakreditasi KAN (ISO/IEC 17025) harus bisa
   mempertanggungjawabkan metode hitungnya saat audit. "AI mengira-ngira" tidak
   bisa dipertahankan.
3. **LLM buruk di aritmetika presisi** — tidak akan andal mengeluarkan
   `0.02343221021262627`.

Backend **sudah punya** mesin ini (GumCalculator / UncertaintyCalculation —
pipeline Tirta Gracia sudah terbukti end-to-end). **Jangan diganti AI.**

**Di mana AI boleh dipakai di sisi admin (di ATAS mesin hitung, bukan
menggantikan):**

| Boleh AI ✅ | Contoh |
|---|---|
| 🚩 Flag anomali | Menandai Repeat-4 pH=5.00 → "cek, menyimpang" sebelum dihitung (spec poin 11) |
| 🔎 QA silang | Bandingkan worksheet ↔ sertifikat: "angka report cocok dengan data mentah?" — mata kedua |
| ✍️ Draft teks | Menyusun catatan/keterangan bahasa natural (bukan angka sertifikat) |
| 📥 Parsing Import Excel | Baca Excel lama → petakan ke struktur DB (spec poin 12C) |
| 🔗 Auto-isi dari DB | Owner/alamat/metode/standar ketarik otomatis (spec poin 11) |

| WAJIB mesin rumus, BUKAN AI ❌ |
|---|
| Standard Value terkoreksi suhu, Correction, U95%, faktor cakupan k |
| Rata-rata & STDEV pembacaan, ketidakpastian gabungan |
| Kondisi lingkungan ± (T, %RH), penentuan lulus/tidak |
| Angka apa pun yang tercetak di sertifikat resmi |

**Garis batas satu kalimat:** *AI membaca & membantu (foto, flag, QA, draft),
mesin rumus yang menghitung & sertifikat yang mengesahkan.*
