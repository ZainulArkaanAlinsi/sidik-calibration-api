<?php

namespace Tests\Feature;

use App\Services\Dokumen\KlienVisi;
use App\Services\WorksheetVisionExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Jalur OpenAI (ChatGPT) buat baca lembar kerja dari foto.
 *
 * Sama seperti `WorksheetExtractionGeminiTest`: yang diuji bentuk permintaan
 * yang kita kirim dan cara mencerna balasannya — bukan kualitas bacaan model.
 * Tidak ada satu pun panggilan sungguhan ke OpenAI.
 */
class WorksheetExtractionOpenAiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vision.driver' => 'openai',
            'services.openai.api_key' => 'kunci-uji',
            'services.openai.model' => 'gpt-5',
            'services.openai.base_url' => 'https://api.openai.com',
        ]);
    }

    private function balasan(string $isi, string $alasan = 'stop', array $pesan = []): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $isi, ...$pesan],
                'finish_reason' => $alasan,
            ]],
            'usage' => [
                'prompt_tokens' => 1200,
                'completion_tokens' => 300,
                'prompt_tokens_details' => ['cached_tokens' => 1024],
            ],
        ];
    }

    public function test_driver_openai_dikenali_kedua_transport(): void
    {
        $this->assertSame('openai', WorksheetVisionExtractor::penyediaAktif());
        $this->assertSame('gpt-5', WorksheetVisionExtractor::modelAktif());
        $this->assertSame('openai', KlienVisi::penyediaAktif());
        $this->assertSame('gpt-5', KlienVisi::model());
    }

    public function test_angka_dari_foto_kebaca_dan_permintaannya_berbentuk_benar(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->balasan(
                '{"baris":[{"repeat":1,"ph":[4.01,6.99,10.02],"suhu":[23.0,22.5,22.1],'
                .'"ph_keyakinan":["high","high","high"],"suhu_keyakinan":["high","high","high"]}]}',
            )),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('gambar-palsu', 'image/png', 3, 5);

        $this->assertTrue($hasil['ok']);
        $this->assertSame([4.01, 6.99, 10.02], $hasil['data']['baris'][0]['ph']);
        $this->assertSame(['input_tokens' => 1200, 'output_tokens' => 300, 'cache_read_input_tokens' => 1024], $hasil['usage']);

        Http::assertSent(function (Request $r): bool {
            $body = $r->data();
            $akhir = end($body['messages']);

            return $r->url() === 'https://api.openai.com/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer kunci-uji')
                && $body['model'] === 'gpt-5'
                && $body['response_format'] === ['type' => 'json_object']
                // Model penalaran menolak `temperature` selain bawaan.
                && ! array_key_exists('temperature', $body)
                && $body['messages'][0]['role'] === 'system'
                && str_starts_with($akhir['content'][0]['image_url']['url'], 'data:image/png;base64,'.base64_encode('gambar-palsu'))
                && str_contains($akhir['content'][1]['text'], 'JSON');
        });
    }

    /** `finish_reason: length` = JSON kepotong. Gagal, bukan sukses separuh. */
    public function test_jawaban_kepotong_dibilang_gagal_dengan_saran_yang_jelas(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->balasan('{"baris":[{"repeat":1,"ph":[4.01,', 'length')),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('x', 'image/png');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('kepotong', (string) $hasil['error']);
    }

    /** Penolakan OpenAI itu HTTP 200 dengan `message.refusal`. */
    public function test_refusal_jadi_ditolak_bukan_sukses(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->balasan('', 'stop', ['refusal' => 'I can’t help with that.'])),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('x', 'image/png');

        $this->assertFalse($hasil['ok']);
        $this->assertSame('ditolak', $hasil['status']);
    }

    public function test_kuota_habis_dibedakan_dari_sibuk(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                ['error' => ['message' => 'You exceeded your current quota, please check your plan and billing details.']],
                429,
            ),
        ]);

        $hasil = app(WorksheetVisionExtractor::class)->extract('x', 'image/png');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('Kuota layanan AI habis', (string) $hasil['error']);
    }

    public function test_tanpa_kunci_dilempar_supaya_jadi_503(): void
    {
        config(['services.openai.api_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');

        app(WorksheetVisionExtractor::class)->extract('x', 'image/png');
    }

    public function test_klien_dokumen_lewat_openai_juga(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->balasan('{"nomor_seri":"ABC-123"}')),
        ]);

        $hasil = app(KlienVisi::class)->minta(base64_encode('foto'), 'image/jpeg', 'sistem', 'baca nomor seri');

        $this->assertTrue($hasil['ok']);
        $this->assertSame(['nomor_seri' => 'ABC-123'], $hasil['data']);

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.openai.com/v1/chat/completions'
            && $r->data()['messages'][1]['content'][0]['image_url']['url'] === 'data:image/jpeg;base64,'.base64_encode('foto'));
    }
}
