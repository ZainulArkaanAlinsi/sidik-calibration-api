<?php

namespace Tests\Feature;

use App\Services\Dokumen\AmbangKeyakinan;
use App\Services\Dokumen\EkstraktorDokumenGenerik;
use App\Services\Dokumen\KlienVisi;
use App\Services\Dokumen\PenguraiStrukturDokumen;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Jalur generik dari ujung ke ujung, dengan penyedia AI dipalsukan di lapisan
 * HTTP — bukan servicenya yang di-mock. Yang diuji termasuk penyusunan
 * request, penguraian jawaban, dan penegakan aturannya.
 */
class EkstraktorDokumenGenerikTest extends TestCase
{
    private function ekstraktor(): EkstraktorDokumenGenerik
    {
        return new EkstraktorDokumenGenerik(
            new KlienVisi,
            new PenguraiStrukturDokumen(new AmbangKeyakinan),
        );
    }

    private function pasangAnthropic(array $isi): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($isi)]],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 340],
        ])]);
    }

    /** Lembar yang belum pernah ada profilnya tetap kebaca — inti dari fitur ini. */
    public function test_lembar_asing_tetap_menghasilkan_struktur(): void
    {
        $this->pasangAnthropic([
            'document' => [
                'title' => 'Calibration Worksheet - Viscometer Rotasi',
                'equipment_name' => 'Viscometer Rotasi',
                'worksheet_code' => 'SIDIK-FM-CAL-0999',
                'revision' => 'Rev.2',
                'confidence' => 0.93,
            ],
            'sections' => [[
                'name' => 'Spindle Measurement',
                'fields' => [[
                    'label' => 'Spindle No', 'value' => 'S-62',
                    'confidence' => 0.95, 'source' => 'handwriting',
                ]],
                'tables' => [[
                    'headers' => ['RPM', 'Standard (cP)', 'Reading (cP)'],
                    'cells' => [
                        ['row' => 0, 'column' => 0, 'value' => '10', 'confidence' => 0.97, 'source' => 'static_document'],
                        ['row' => 0, 'column' => 1, 'value' => '1000', 'confidence' => 0.96, 'source' => 'static_document'],
                        ['row' => 0, 'column' => 2, 'value' => '998,4', 'confidence' => 0.91, 'source' => 'handwriting'],
                    ],
                ]],
            ]],
            'warnings' => [],
        ]);

        $hasil = $this->ekstraktor()->ekstrak(base64_encode('foto'), 'image/jpeg');

        $this->assertTrue($hasil['ok']);
        $this->assertSame('Viscometer Rotasi', $hasil['dokumen']['document']['equipment_name']);
        $this->assertSame('SIDIK-FM-CAL-0999', $hasil['dokumen']['document']['worksheet_code']);

        $tabel = $hasil['dokumen']['sections'][0]['tables'][0];
        $this->assertSame(['RPM', 'Standard (cP)', 'Reading (cP)'], $tabel['headers']);
        $this->assertSame('998,4', $tabel['rows'][0][2]['value']);
        $this->assertSame(PenguraiStrukturDokumen::SUMBER_TULISAN, $tabel['rows'][0][2]['source']);
        $this->assertSame(1200, $hasil['usage']['input_tokens']);
    }

    /** Dua lembar beda struktur menghasilkan skema beda — bukan dipaksa seragam. */
    public function test_dua_lembar_berbeda_menghasilkan_struktur_berbeda(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        // Urutan, bukan dua `Http::fake` — panggilan kedua MENAMBAH stub, bukan
        // mengganti, jadi stub pertama tetap yang menjawab dan ujinya jadi
        // membandingkan lembar yang sama dua kali.
        $jawab = fn (array $isi) => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($isi)]],
        ]);

        Http::fake(['*/v1/messages' => Http::sequence()
            ->pushResponse($jawab(['document' => [], 'sections' => [[
                'name' => 'A', 'fields' => [], 'tables' => [['headers' => ['X1', 'X2', 'X3'], 'cells' => []]],
            ]], 'warnings' => []]))
            ->pushResponse($jawab(['document' => [], 'sections' => [[
                'name' => 'B', 'fields' => [], 'tables' => [['headers' => ['Position', 'Standard'], 'cells' => []]],
            ]], 'warnings' => []])),
        ]);

        $a = $this->ekstraktor()->ekstrak('x', 'image/jpeg');
        $b = $this->ekstraktor()->ekstrak('x', 'image/jpeg');

        $this->assertSame(['X1', 'X2', 'X3'], $a['dokumen']['sections'][0]['tables'][0]['headers']);
        $this->assertSame(['Position', 'Standard'], $b['dokumen']['sections'][0]['tables'][0]['headers']);
        $this->assertNotSame(
            $a['dokumen']['sections'][0]['name'],
            $b['dokumen']['sections'][0]['name'],
        );
    }

    public function test_alat_pilihan_teknisi_beda_dari_lembar_jadi_peringatan(): void
    {
        $this->pasangAnthropic([
            'document' => ['equipment_name' => 'Turbidimeter'],
            'sections' => [], 'warnings' => [],
        ]);

        $hasil = $this->ekstraktor()->ekstrak('x', 'image/jpeg', 'pH Meter');

        $this->assertNotEmpty(array_filter(
            $hasil['dokumen']['warnings'],
            fn ($p) => str_contains($p, 'Turbidimeter') && str_contains($p, 'pH Meter'),
        ));
        // Yang menang tetap LEMBARNYA.
        $this->assertSame('Turbidimeter', $hasil['dokumen']['document']['equipment_name']);
    }

    public function test_nama_alat_yang_cocok_longgar_tidak_bikin_peringatan_palsu(): void
    {
        $this->pasangAnthropic([
            'document' => ['equipment_name' => 'Calibration Worksheet - pH Meter'],
            'sections' => [], 'warnings' => [],
        ]);

        $hasil = $this->ekstraktor()->ekstrak('x', 'image/jpeg', 'pH Meter');

        $this->assertSame([], $hasil['dokumen']['warnings']);
    }

    public function test_layanan_sibuk_tidak_menyuruh_foto_ulang(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response(
            ['error' => ['message' => 'overloaded']], 503,
        )]);

        $hasil = $this->ekstraktor()->ekstrak('x', 'image/jpeg');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('nggak perlu diulang', $hasil['error']);
        $this->assertNull($hasil['dokumen']);
    }

    public function test_kuota_habis_dibedakan_dari_sibuk(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response(
            ['error' => ['message' => 'Your credits are depleted']], 400,
        )]);

        $hasil = $this->ekstraktor()->ekstrak('x', 'image/jpeg');

        $this->assertStringContainsString('Kuota', $hasil['error']);
        $this->assertStringContainsString('admin', $hasil['error']);
    }

    public function test_jawaban_bukan_json_tidak_bikin_data_karangan(): void
    {
        config([
            'services.vision.driver' => 'anthropic',
            'services.anthropic.api_key' => 'kunci-uji',
            'services.anthropic.model' => 'model-uji',
        ]);

        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Maaf, gambarnya buram sekali.']],
        ])]);

        $hasil = $this->ekstraktor()->ekstrak('x', 'image/jpeg');

        $this->assertFalse($hasil['ok']);
        $this->assertSame('tak_terbaca', $hasil['status']);
        $this->assertNull($hasil['dokumen']);
    }

    public function test_gemini_yang_balik_json_polos_ikut_terbaca(): void
    {
        config([
            'services.vision.driver' => 'gemini',
            'services.gemini.api_key' => 'kunci-uji',
            'services.gemini.model' => 'model-uji',
        ]);

        Http::fake(['*generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'document' => ['equipment_name' => 'Stopwatch'],
                'sections' => [], 'warnings' => [],
            ])]]]]],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 120],
        ])]);

        $hasil = $this->ekstraktor()->ekstrak('x', 'image/jpeg');

        $this->assertTrue($hasil['ok']);
        $this->assertSame('Stopwatch', $hasil['dokumen']['document']['equipment_name']);
        $this->assertSame(900, $hasil['usage']['input_tokens']);
    }
}
