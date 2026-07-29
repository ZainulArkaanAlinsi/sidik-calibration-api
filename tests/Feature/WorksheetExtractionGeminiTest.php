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
}
