<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AI Vision baca tabel lembar kerja pH dari foto (ganti OCR) — SPEC-vision-prompt.md.
 * Anthropic-nya di-fake; yang diuji perilaku endpoint: bentuk { baris: [...] },
 * anomali ditranskrip apa adanya, jejak log, penolakan aman, fallback manual.
 */
class WorksheetExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/raw-measurements/extract-from-photo';

    private User $teknisi;

    private User $teknisiLain;

    private CalibrationSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();
        $this->teknisiLain = User::factory()->create();

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter', 'satuan' => 'pH',
        ]);

        $this->sesi = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
    }

    /** Fake respons normal: 2 Repeat, satu sel low-confidence, satu anomali 5.00. */
    private function fakeSukses(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');

        $isi = json_encode(['baris' => [
            ['ph' => [4.04, 7.02, 9.61], 'suhu' => [22.2, 22.3, 22.2],
                'ph_keyakinan' => ['high', 'high', 'low'], 'suhu_keyakinan' => ['high', 'high', 'high']],
            ['ph' => [5.00, 7.02, 9.61], 'suhu' => [22.2, 22.3, 22.2],
                'ph_keyakinan' => ['high', 'high', 'high'], 'suhu_keyakinan' => ['high', 'high', 'high']],
        ]]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 180, 'cache_read_input_tokens' => 0],
            'content' => [['type' => 'text', 'text' => $isi]],
        ])]);
    }

    /** @param array<string, mixed> $payload */
    private function kirim(User $user, array $payload = []): TestResponse
    {
        return $this->actingAs($user)->postJson(self::URL, array_merge([
            'foto' => UploadedFile::fake()->image('lembar.jpg', 1000, 1400),
            'jumlah_titik' => 3,
            'jumlah_pengulangan' => 5,
            'calibration_session_id' => $this->sesi->id,
        ], $payload));
    }

    public function test_petunjuk_satuan_dan_nominal_kekirim_ke_model(): void
    {
        $this->fakeSukses();

        // Turbidimeter: satuan NTU + nominal 1/100/1000 + desimal 2/1/0. Petunjuk
        // ini yang bikin AI nangkap angka lebih akurat (tau tiap kolom mestinya
        // dekat nilai apa & berapa desimal).
        $this->kirim($this->teknisi, [
            'satuan' => 'NTU',
            'titik_nominal' => [1, 100, 1000],
            'desimal' => [2, 1, 0],
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'standard 1000 NTU')
                && str_contains($body, 'standard 1 NTU');
        });
    }

    public function test_ekstrak_sukses_balikin_baris_dan_catat_log(): void
    {
        $this->fakeSukses();

        $this->kirim($this->teknisi)
            ->assertOk()
            ->assertJsonPath('baris.0.ph.0', 4.04)
            ->assertJsonPath('baris.0.ph_keyakinan.2', 'low')
            // Anomali 5.00 WAJIB apa adanya, bukan dibetulkan jadi ~4.0.
            ->assertJsonPath('baris.1.ph.0', 5)
            ->assertJsonCount(2, 'baris')
            ->assertJsonPath('meta.perlu_dicek', true);

        $log = WorksheetExtractionLog::query()->firstOrFail();
        $this->assertSame('sukses', $log->status);
        $this->assertSame($this->sesi->id, $log->calibration_session_id);
        $this->assertSame(1200, $log->input_tokens);
    }

    public function test_tanpa_session_id_tetap_jalan(): void
    {
        $this->fakeSukses();

        $this->kirim($this->teknisi, ['calibration_session_id' => null])
            ->assertOk()
            ->assertJsonCount(2, 'baris');

        $this->assertNull(WorksheetExtractionLog::query()->firstOrFail()->calibration_session_id);
    }

    public function test_koma_desimal_dan_keyakinan_pendek_dirapikan(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        // Model kirim koma desimal (string) & keyakinan lebih pendek dari sel.
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'content' => [['type' => 'text', 'text' => '{"baris":[{"ph":["4,04","7,02",null],"suhu":["22,2"],"ph_keyakinan":["high"],"suhu_keyakinan":[]}]}']],
        ])]);

        $this->kirim($this->teknisi)
            ->assertOk()
            ->assertJsonPath('baris.0.ph.0', 4.04)
            ->assertJsonPath('baris.0.ph.2', null)
            // suhu dipad sepanjang ph (3 sel), sisanya null.
            ->assertJsonCount(3, 'baris.0.suhu')
            ->assertJsonPath('baris.0.suhu.1', null)
            // keyakinan yang kurang dipad "low".
            ->assertJsonPath('baris.0.ph_keyakinan.1', 'low')
            ->assertJsonCount(3, 'baris.0.suhu_keyakinan');
    }

    /**
     * Prompt caching cuma jalan kalau prefix-nya lewat ambang minimum model
     * (claude-opus-4-8: 1024 token; claude-opus-5: 512). Di bawah ambang, API
     * NGGAK ngasih error — dia diam-diam nggak nge-cache. Jadi satu-satunya cara
     * tahu caching beneran kena adalah nyimpen angkanya dan ngeliatnya.
     * SPEC-vision-prompt.md §5 nyuruh ngecek ini; sebelumnya angkanya nggak
     * pernah disimpen, jadi klaim "hemat ~90%" nggak bisa dibuktiin/dibantah.
     */
    public function test_token_cache_read_ikut_dicatat_di_log(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 40,
                'cache_read_input_tokens' => 1536,
            ],
            'content' => [['type' => 'text', 'text' => '{"baris":[{"ph":[4.04],"suhu":[22.2],"ph_keyakinan":["high"],"suhu_keyakinan":["high"]}]}']],
        ])]);

        $this->kirim($this->teknisi)->assertOk();

        $log = WorksheetExtractionLog::sole();
        $this->assertSame(1536, $log->cache_read_input_tokens);
        $this->assertSame(120, $log->input_tokens);
        $this->assertSame(40, $log->output_tokens);
    }

    /**
     * Cache MISS (`0`) harus kecatat apa adanya, jangan dianggap "nggak ada data".
     * Ini justru kondisi nyata sekarang: foto few-shot di storage/app/few_shot/
     * belum diisi, jadi prefix yang di-cache cuma system prompt (~400 token) —
     * di bawah ambang 1024 token claude-opus-4-8, jadi nggak pernah nge-cache.
     * Angka 0 yang muncul terus di kolom ini = sinyal buat naruh foto few-shot.
     */
    public function test_cache_miss_dicatat_sebagai_nol_bukan_null(): void
    {
        $this->fakeSukses();

        $this->kirim($this->teknisi)->assertOk();

        $this->assertSame(0, WorksheetExtractionLog::sole()->cache_read_input_tokens);
    }

    /** Respons tanpa field cache sama sekali nggak boleh bikin error. */
    public function test_token_cache_read_null_kalau_tidak_dikirim_api(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
            'content' => [['type' => 'text', 'text' => '{"baris":[{"ph":[4.04],"suhu":[22.2],"ph_keyakinan":["high"],"suhu_keyakinan":["high"]}]}']],
        ])]);

        $this->kirim($this->teknisi)->assertOk();

        $this->assertNull(WorksheetExtractionLog::sole()->cache_read_input_tokens);
    }

    public function test_tanpa_api_key_balik_503(): void
    {
        Config::set('services.anthropic.api_key', '');

        $this->kirim($this->teknisi)->assertStatus(503);

        // Percobaannya TETAP dicatat, walau nggak pernah nyampe ke Anthropic.
        // SPEC-vision-ai-worksheet-extraction.md §5 minta log ditulis buat tiap
        // percobaan, sukses maupun gagal — dan kalau API key kelupaan diisi,
        // inilah satu-satunya tempat kelihatannya. Tanpa baris ini, gejalanya
        // cuma "tombol foto teknisi nggak jalan" tanpa jejak apa pun.
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('belum_disetel', $log->status);
        $this->assertSame($this->teknisi->id, $log->user_id);
        $this->assertNotNull($log->error);
        // Nggak ada panggilan API, jadi nggak ada pemakaian token buat dicatat.
        $this->assertNull($log->input_tokens);
        $this->assertNull($log->cache_read_input_tokens);
    }

    public function test_refusal_balik_422_dan_fallback_manual(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'refusal',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 0],
            'content' => [],
        ])]);

        $this->kirim($this->teknisi)
            ->assertStatus(422)
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame('ditolak', WorksheetExtractionLog::query()->firstOrFail()->status);
    }

    public function test_respons_bukan_json_balik_422(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            'content' => [['type' => 'text', 'text' => 'Maaf, gambarnya tidak terbaca.']],
        ])]);

        $this->kirim($this->teknisi)
            ->assertStatus(422)
            ->assertJsonPath('fallback_manual', true);
        $this->assertSame('gagal', WorksheetExtractionLog::query()->firstOrFail()->status);
    }

    public function test_teknisi_lain_ditolak(): void
    {
        $this->fakeSukses();

        $this->kirim($this->teknisiLain)->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_foto_bukan_gambar_ditolak(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');

        $this->actingAs($this->teknisi)->postJson(self::URL, [
            'foto' => UploadedFile::fake()->create('data.pdf', 100, 'application/pdf'),
            'calibration_session_id' => $this->sesi->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('foto');
    }
}
