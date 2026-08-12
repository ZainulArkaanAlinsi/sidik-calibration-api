<?php

namespace Tests\Feature;

use App\Services\WorksheetVisionExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Jalur Gemini buat baca lembar kerja dari foto.
 *
 * Yang diuji di sini BUKAN kualitas bacaan modelnya — itu urusan model. Yang
 * diuji: bentuk permintaan yang kita kirim, dan apakah kita bisa mencerna
 * bentuk balasan Gemini (yang beda dari Anthropic) tanpa kehilangan data.
 */
class WorksheetExtractionGeminiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vision.driver' => 'gemini',
            'services.gemini.api_key' => 'kunci-uji',
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);
    }

    private function balasan(string $teks, array $tambahan = []): array
    {
        return array_merge([
            'candidates' => [[
                'content' => ['parts' => [['text' => $teks]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1555, 'candidatesTokenCount' => 276],
        ], $tambahan);
    }

    /**
     * Gemini sering balikin ARRAY telanjang, bukan objek berkunci `baris`.
     *
     * Ini pernah kejadian beneran: parser cuma nyari `{`...`}`, jadi hasil
     * Gemini yang isinya SUDAH BENAR kebuang sebagai "tidak bisa dibaca" dan
     * teknisi disuruh ngetik ulang tabel yang barusan kebaca sempurna.
     */
    public function test_array_telanjang_tetap_kebaca_bukan_dianggap_gagal(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->balasan(
                '[{"repeat":1,"ph":[4.01,6.99,10.02],"suhu":[23.0,22.5,22.1],'
                .'"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]}]',
            )),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('gambar-palsu', 'image/png', 3, 5);

        $this->assertTrue($hasil['ok']);
        $this->assertSame([4.01, 6.99, 10.02], $hasil['data']['baris'][0]['ph']);
    }

    /** Objek berkunci `baris` juga tetap jalan — dua-duanya sah. */
    public function test_objek_berkunci_baris_juga_kebaca(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->balasan(
                '{"baris":[{"repeat":1,"ph":[4.01],"suhu":[23.0],'
                .'"ph_keyakinan":["high"],"suhu_keyakinan":["high"]}]}',
            )),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('gambar-palsu', 'image/png');

        $this->assertTrue($hasil['ok']);
        $this->assertSame([4.01], $hasil['data']['baris'][0]['ph']);
    }

    /**
     * Baris diurutin pakai nomor Repeat-nya, bukan urutan datangnya.
     *
     * Posisi array dipakai sebagai nomor Repeat di hilir, jadi satu baris yang
     * datang kebalik bikin pembacaan mendarat di Repeat yang salah — di
     * dokumen kalibrasi, tanpa ada yang kelihatan salah.
     */
    public function test_baris_diurutin_pakai_nomor_repeat(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->balasan(
                '[{"repeat":3,"ph":[10.0],"suhu":[22.0],"ph_keyakinan":["high"],"suhu_keyakinan":["high"]},'
                .'{"repeat":1,"ph":[4.0],"suhu":[23.0],"ph_keyakinan":["high"],"suhu_keyakinan":["high"]},'
                .'{"repeat":2,"ph":[7.0],"suhu":[22.5],"ph_keyakinan":["high"],"suhu_keyakinan":["high"]}]',
            )),
        ]);

        $baris = app(WorksheetVisionExtractor::class)->extract('x', 'image/png')['data']['baris'];

        $this->assertSame([1, 2, 3], array_column($baris, 'repeat'));
        $this->assertSame([[4.0], [7.0], [10.0]], array_column($baris, 'ph'));
    }

    /** Classifier Gemini nolak lewat `finishReason`, bukan HTTP error. */
    public function test_finish_reason_safety_jadi_ditolak_bukan_sukses(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['finishReason' => 'SAFETY']],
                'usageMetadata' => ['promptTokenCount' => 10],
            ]),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('x', 'image/png');

        $this->assertFalse($hasil['ok']);
        $this->assertSame('ditolak', $hasil['status']);
    }

    /**
     * Jawaban kepotong (`MAX_TOKENS`) itu GAGAL, bukan sukses separuh.
     *
     * JSON yang kepotong bisa aja keparse sebagian; kalau itu dianggap sukses,
     * separuh tabel masuk ke lembar kerja sebagai angka yang kelihatan sah.
     */
    public function test_jawaban_kepotong_dibilang_gagal_dengan_saran_yang_jelas(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    // Sengaja kepotong di tengah angka.
                    'content' => ['parts' => [['text' => '[{"repeat":1,"ph":[4.01,']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1555, 'candidatesTokenCount' => 4096],
            ]),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('x', 'image/png');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('kepotong', (string) $hasil['error']);
    }

    /** Pemakaian token dipetakan ke bentuk yang sama dengan Anthropic. */
    public function test_usage_gemini_dipetakan_ke_bentuk_yang_sama(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->balasan(
                '[{"repeat":1,"ph":[4.0],"suhu":[23.0],"ph_keyakinan":["high"],"suhu_keyakinan":["high"]}]',
            )),
        ]);

        $usage = app(WorksheetVisionExtractor::class)->extract('x', 'image/png')['usage'];

        $this->assertSame(1555, $usage['input_tokens']);
        $this->assertSame(276, $usage['output_tokens']);
        // Null = "nggak berlaku di jalur ini", bukan nol. Nol bakal kebaca
        // sebagai "caching-nya nggak kena", yang artinya beda.
        $this->assertNull($usage['cache_read_input_tokens']);
    }

    /** Kunci belum diisi = salah setup, bukan error data. */
    public function test_tanpa_kunci_gemini_dilempar_bukan_dibilang_gagal_baca(): void
    {
        config(['services.gemini.api_key' => '']);

        $this->expectExceptionMessage('GEMINI_API_KEY belum diisi di server.');

        app(WorksheetVisionExtractor::class)->extract('x', 'image/png');
    }

    /**
     * Prompt-nya WAJIB tetap nyuruh model hati-hati, bukan cuma "baca angka".
     *
     * Dua pagar yang paling gampang keikis waktu ada yang ngerapiin prompt, dan
     * dua-duanya bikin angka salah lolos ke sertifikat berakreditasi TANPA ada
     * yang sadar:
     *
     *  - Sel dibaca sendiri-sendiri. Model gampang banget nyalin nilai dari
     *    baris atas ke bawah karena "kelihatannya sama" — dan itu justru
     *    ngumpetin satu pembacaan yang beda, yang persis jadi alasan kalibrasi
     *    ini dilakuin.
     *  - Ragu = keyakinan RENDAH, bukan tinggi. Sel keyakinan rendah ditandain
     *    buat dicek manusia; yang tinggi nggak. Jadi salah yang percaya diri
     *    jauh lebih bahaya daripada sel yang ngaku ragu.
     */
    public function test_prompt_tetap_bawa_pagar_ketelitian(): void
    {
        $prompt = (new \ReflectionMethod(WorksheetVisionExtractor::class, 'systemPrompt'))
            ->invoke(app(WorksheetVisionExtractor::class));

        foreach ([
            'Read EVERY cell independently',
            'Do NOT copy a value',
            'Map cells by POSITION',
            'never "high"',
            'NEVER correct, round',
        ] as $wajib) {
            $this->assertStringContainsString(
                $wajib,
                $prompt,
                "pagar ketelitian '{$wajib}' ilang dari prompt",
            );
        }
    }

    /**
     * Timeout HTTP nggak boleh lebih panjang dari umur prosesnya sendiri.
     *
     * Pernah kejadian di lapangan (12 Agt 2026): `max_execution_time` php.ini dev
     * 30 detik, timeout AI default 60. Teknisi motret lembar kerja, Gemini lagi
     * lambat, dan yang muncul BUKAN "Coba lagi sebentar" tapi
     * `Fatal error: Maximum execution time of 30+2 seconds exceeded` — PHP
     * dibunuh sebelum `catch (ConnectionException)` sempat jalan.
     *
     * Diuji lewat `batasWaktu()` langsung, bukan lewat request beneran: yang mau
     * dikunci itu aritmatikanya, dan bikin request beneran nunggu 30 detik cuma
     * buat mastiin ini bakal bikin suite-nya lambat tanpa nambah keyakinan.
     */
    public function test_timeout_http_nggak_pernah_ngelewatin_jatah_hidup_proses(): void
    {
        $batasWaktu = new \ReflectionMethod(WorksheetVisionExtractor::class, 'batasWaktu');
        $ekstraktor = app(WorksheetVisionExtractor::class);

        $asli = ini_get('max_execution_time');

        try {
            // Ada batas → timeout WAJIB lebih pendek, biar Guzzle yang nyerah
            // duluan dan pesan ramahnya kebaca teknisi.
            foreach ([30, 60] as $batasProses) {
                ini_set('max_execution_time', (string) $batasProses);

                $this->assertLessThan(
                    $batasProses,
                    $batasWaktu->invoke($ekstraktor, 60),
                    "timeout HTTP nggak boleh >= max_execution_time ({$batasProses}s)",
                );
            }

            // Tanpa batas (CLI, queue worker) → angka config dipakai apa adanya.
            ini_set('max_execution_time', '0');
            $this->assertSame(60, $batasWaktu->invoke($ekstraktor, 60));

            // Batas proses yang mepet banget nggak boleh bikin timeout jadi nol
            // atau negatif — itu di Guzzle artinya "tunggu selamanya".
            ini_set('max_execution_time', '3');
            $this->assertGreaterThan(0, $batasWaktu->invoke($ekstraktor, 60));
        } finally {
            ini_set('max_execution_time', (string) $asli);
        }
    }
}
