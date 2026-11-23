<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Vision\GoogleVisionOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MembuatKunciUji;
use Tests\TestCase;

/**
 * OCR awan: `POST /api/ocr/halaman` (Google Cloud Vision, DOCUMENT_TEXT_DETECTION).
 *
 * Google SELALU di-fake (`Http::fake`): tes ini tidak boleh memanggil layanan
 * sungguhan, dan tidak membuktikan apa pun tentang akurasi atau tagihan Google.
 * Yang dibuktikan: bentuk permintaan yang kita kirim (termasuk tanda tangan JWT),
 * pemetaan respons, gerbang berlapis, batas per lembar, jejak audit tanpa gambar
 * dan tanpa teks, dan semua jalur gagal yang jatuh ke "isi manual".
 */
class OcrHalamanTest extends TestCase
{
    use MembuatKunciUji;
    use RefreshDatabase;

    private const URL = '/api/ocr/halaman';

    private Organization $org;

    private User $teknisi;

    private CalibrationSession $sesi;

    /** @var \OpenSSLAsymmetricKey */
    private $kunci;

    private string $kunciPublik;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        [$this->kunci, $pem] = $this->bangkitkanKunciUji();
        $this->kunciPublik = openssl_pkey_get_details($this->kunci)['key'];

        config([
            'services.google_vision.aktif' => true,
            'services.google_vision.kredensial' => json_encode([
                'type' => 'service_account',
                'client_email' => 'ocr-uji@proyek-uji.iam.gserviceaccount.com',
                'private_key' => $pem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
            'services.google_vision.maks_per_sesi' => 2,
            // Dikunci di sini supaya tes tidak bergantung pada `.env` mesin kerja.
            'services.google_vision.maks_gagal_per_sesi' => 3,
        ]);

        $this->org = Organization::factory()->create();
        $this->teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);

        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'ph'])->id,
            'nama_alat' => 'pH Meter', 'satuan' => 'pH',
        ]);
        $this->sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $alat->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
    }

    /** @return list<array{x?: int, y?: int}> */
    private function kotakGoogle(int $x0, int $y0, int $x1, int $y1): array
    {
        // Google menghilangkan `x`/`y` yang bernilai 0 dari verteks.
        $v = static fn (int $x, int $y): array => array_filter(['x' => $x, 'y' => $y], static fn ($n): bool => $n !== 0);

        return [$v($x0, $y0), $v($x1, $y0), $v($x1, $y1), $v($x0, $y1)];
    }

    /** @return array<string, mixed> */
    private function balasanVision(): array
    {
        return ['responses' => [[
            'fullTextAnnotation' => ['pages' => [[
                'width' => 1000,
                'height' => 2000,
                'blocks' => [[
                    'boundingBox' => ['vertices' => $this->kotakGoogle(100, 200, 500, 260)],
                    'confidence' => 0.98,
                    'paragraphs' => [['words' => [
                        [
                            'boundingBox' => ['vertices' => $this->kotakGoogle(100, 200, 300, 260)],
                            'confidence' => 0.99,
                            'symbols' => [['text' => '3'], ['text' => '3'], ['text' => '3'], ['text' => '.'], ['text' => '7']],
                        ],
                        [
                            'boundingBox' => ['vertices' => $this->kotakGoogle(320, 200, 500, 260)],
                            'confidence' => 0.62,
                            'symbols' => [['text' => '4'], ['text' => '1'], ['text' => '8']],
                        ],
                    ]]],
                ]],
            ]]],
        ]]];
    }

    /** @param  array<string, mixed>|null  $vision */
    private function fakeGoogle(?array $vision = null, int $statusVision = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => Http::response($vision ?? $this->balasanVision(), $statusVision),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function kirim(?User $user = null, array $payload = []): TestResponse
    {
        return $this->actingAs(($user ?? $this->teknisi)->fresh())->postJson(self::URL, array_merge([
            'foto' => UploadedFile::fake()->image('lembar.jpg', 1000, 1400),
            'calibration_session_id' => $this->sesi->id,
        ], $payload));
    }

    private function jumlahPanggilanVision(): int
    {
        return Http::recorded(fn ($req): bool => str_contains($req->url(), 'vision.googleapis.com'))->count();
    }

    // ------------------------------------------------------------ sukses

    public function test_sukses_mengembalikan_blok_kata_dan_kotak_ternormalisasi(): void
    {
        $this->fakeGoogle();

        $this->kirim()
            ->assertOk()
            ->assertJsonPath('halaman.lebar', 1000)
            ->assertJsonPath('halaman.tinggi', 2000)
            ->assertJsonPath('blok.0.teks', '333.7 418')
            ->assertJsonPath('blok.0.keyakinan', 0.98)
            ->assertJsonPath('kata.0.teks', '333.7')
            ->assertJsonPath('kata.0.kotak.x0', 0.1)
            ->assertJsonPath('kata.0.kotak.y0', 0.1)
            ->assertJsonPath('kata.0.kotak.x1', 0.3)
            ->assertJsonPath('kata.0.kotak.y1', 0.13)
            ->assertJsonPath('kata.0.keyakinan', 0.99)
            // Keyakinan rendah dikembalikan APA ADANYA, bukan dibulatkan naik.
            ->assertJsonPath('kata.1.teks', '418')
            ->assertJsonPath('kata.1.keyakinan', 0.62)
            ->assertJsonPath('kata.1.blok', 0)
            ->assertJsonPath('meta.sumber', 'google_vision')
            ->assertJsonPath('meta.fitur', 'DOCUMENT_TEXT_DETECTION')
            ->assertJsonPath('meta.unit', 1)
            ->assertJsonPath('meta.jumlah_halaman', 1)
            ->assertJsonPath('meta.usulan', true)
            ->assertJsonPath('meta.terpotong', false);
    }

    public function test_verteks_tanpa_x_nol_tetap_terbaca_sebagai_nol(): void
    {
        // Google menghilangkan `x`/`y` yang bernilai 0 dari verteks: yang hilang
        // harus terbaca 0, bukan membuat kotak kacau atau melempar.
        $nol = ['responses' => [[
            'fullTextAnnotation' => ['pages' => [[
                'width' => 400, 'height' => 400,
                'blocks' => [[
                    'boundingBox' => ['vertices' => $this->kotakGoogle(0, 0, 200, 100)],
                    'paragraphs' => [['words' => [[
                        'boundingBox' => ['vertices' => $this->kotakGoogle(0, 0, 200, 100)],
                        'symbols' => [['text' => '7']],
                    ]]]],
                ]],
            ]]],
        ]]];
        $this->fakeGoogle($nol);

        $this->kirim(null, ['calibration_session_id' => null])
            ->assertOk()
            ->assertJsonPath('kata.0.kotak.x0', 0)
            ->assertJsonPath('kata.0.kotak.y0', 0)
            ->assertJsonPath('kata.0.kotak.x1', 0.5)
            ->assertJsonPath('kata.0.keyakinan', null);
    }

    public function test_halaman_tanpa_teks_tetap_kosong_dan_tetap_dicatat_satu_unit(): void
    {
        $this->fakeGoogle(['responses' => [new \stdClass]]);

        $this->kirim()
            ->assertOk()
            ->assertJsonPath('blok', [])
            ->assertJsonPath('kata', [])
            ->assertJsonPath('meta.unit', 1);

        $this->assertSame(1, WorksheetExtractionLog::first()->unit_vision);
    }

    public function test_permintaan_ke_google_benar_dan_tanda_tangan_jwt_sah(): void
    {
        $this->fakeGoogle();
        $this->kirim()->assertOk();

        $sudahDicek = ['token' => false, 'vision' => false];
        $publik = $this->kunciPublik;

        Http::assertSent(function ($req) use (&$sudahDicek, $publik): bool {
            if (! str_contains($req->url(), 'oauth2.googleapis.com')) {
                return false;
            }
            parse_str($req->body(), $f);
            $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $f['grant_type']);
            [$h, $c, $s] = explode('.', $f['assertion']);
            $dekode = static fn (string $b): string => base64_decode(strtr($b, '-_', '+/'));

            $this->assertSame('RS256', json_decode($dekode($h), true)['alg']);
            $klaim = json_decode($dekode($c), true);
            $this->assertSame('ocr-uji@proyek-uji.iam.gserviceaccount.com', $klaim['iss']);
            $this->assertSame('https://www.googleapis.com/auth/cloud-vision', $klaim['scope']);
            $this->assertSame('https://oauth2.googleapis.com/token', $klaim['aud']);
            $this->assertSame(1, openssl_verify($h.'.'.$c, $dekode($s), $publik, OPENSSL_ALGO_SHA256));

            return $sudahDicek['token'] = true;
        });

        Http::assertSent(function ($req) use (&$sudahDicek): bool {
            if (! str_contains($req->url(), 'vision.googleapis.com/v1/images:annotate')) {
                return false;
            }
            $this->assertSame('Bearer token-uji', $req->header('Authorization')[0]);
            $isi = $req->data()['requests'][0];
            $this->assertSame('DOCUMENT_TEXT_DETECTION', $isi['features'][0]['type']);
            $this->assertCount(1, $isi['features']);
            $this->assertNotEmpty($isi['image']['content']);

            return $sudahDicek['vision'] = true;
        });

        $this->assertSame(['token' => true, 'vision' => true], $sudahDicek);
    }

    public function test_token_di_cache_jadi_pertukaran_token_cuma_sekali(): void
    {
        $this->fakeGoogle();
        $this->kirim(null, ['calibration_session_id' => null])->assertOk();
        $this->kirim(null, ['calibration_session_id' => null])->assertOk();

        $this->assertSame(2, $this->jumlahPanggilanVision());
        $this->assertSame(1, Http::recorded(fn ($r): bool => str_contains($r->url(), 'oauth2.googleapis.com'))->count());
    }

    // ------------------------------------------------------------- audit

    public function test_log_mencatat_metadata_tanpa_gambar_dan_tanpa_teks(): void
    {
        $this->fakeGoogle();
        $this->kirim()->assertOk();

        $log = WorksheetExtractionLog::sole();

        $this->assertSame(GoogleVisionOcr::MODEL, $log->model);
        $this->assertSame('sukses', $log->status);
        $this->assertSame($this->teknisi->id, $log->user_id);
        $this->assertSame($this->sesi->id, $log->calibration_session_id);
        $this->assertSame(1, $log->unit_vision);
        $this->assertGreaterThan(0, $log->ukuran_gambar_byte);
        $this->assertNotNull($log->durasi_ms);
        $this->assertNotNull($log->created_at);
        $this->assertNull($log->raw_model_response);
        $this->assertNull($log->extracted);
        $this->assertNull($log->error);

        // Isi hasil OCR tidak boleh bocor ke jejak audit dalam bentuk apa pun.
        $this->assertStringNotContainsString('333.7', json_encode($log->toArray()));
    }

    // ---------------------------------------------------------- gerbang

    public function test_env_mati_503_dan_google_tidak_dipanggil(): void
    {
        config(['services.google_vision.aktif' => false]);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(503)
            ->assertJsonPath('kode', 'ocr_cloud_belum_aktif')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('dimatikan', $log->status);
        $this->assertNull($log->unit_vision);
    }

    public function test_tidak_ikut_menyala_bersama_vision_aktif_llm(): void
    {
        // Menyalakan jalur LLM TIDAK boleh membuka OCR awan, dan sebaliknya.
        config(['services.vision.aktif' => true, 'services.google_vision.aktif' => false]);
        $this->fakeGoogle();

        $this->kirim()->assertStatus(503)->assertJsonPath('kode', 'ocr_cloud_belum_aktif');
        Http::assertNothingSent();
    }

    public function test_kill_switch_super_admin_mematikan_seketika(): void
    {
        $this->org->update(['settings' => ['fitur_nonaktif' => ['ocr_cloud']]]);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(503)
            ->assertJsonPath('kode', 'fitur_dimatikan')
            ->assertJsonPath('fitur', 'ocr_cloud');

        Http::assertNothingSent();
    }

    public function test_tamu_ditolak_401(): void
    {
        $this->postJson(self::URL, [])->assertStatus(401);
    }

    /**
     * Rute ini di dalam grup `role:admin,teknisi`, bukan `role:admin,teknisi,viewer`:
     * viewer hanya membaca, dan tiap panggilan ke sini berbiaya serta mengirim foto
     * ke pihak ketiga. Dijaga di tes supaya rute yang bergeser ke grup luar
     * (membuka pintu bagi viewer) langsung merah, bukan diam-diam menagih.
     */
    public function test_viewer_ditolak_403_google_tidak_dipanggil_dan_tidak_tercatat(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'organization_id' => $this->org->id]);
        $this->fakeGoogle();

        $this->kirim($viewer)->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_sesi_teknisi_lain_403_dan_google_tidak_dipanggil(): void
    {
        $lain = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);
        $this->fakeGoogle();

        $this->kirim($lain)->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_foto_wajib_ada_dan_harus_gambar(): void
    {
        $this->fakeGoogle();

        $this->actingAs($this->teknisi->fresh())->postJson(self::URL, [])->assertStatus(422);
        $this->actingAs($this->teknisi->fresh())->postJson(self::URL, [
            'foto' => UploadedFile::fake()->create('lembar.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_foto_melewati_batas_ukuran_ditolak_sebelum_dikirim(): void
    {
        config(['services.google_vision.maks_byte' => 100]);
        $this->fakeGoogle();

        $this->kirim()->assertStatus(422)->assertJsonPath('kode', 'foto_terlalu_besar');

        Http::assertNothingSent();
        $this->assertNull(WorksheetExtractionLog::sole()->unit_vision);
    }

    public function test_batas_per_lembar_menolak_panggilan_ketiga_tanpa_menghubungi_google(): void
    {
        $this->fakeGoogle();

        $this->kirim()->assertOk();
        $this->kirim()->assertOk();
        $this->kirim()
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(2, $this->jumlahPanggilanVision());
        $this->assertSame(1, WorksheetExtractionLog::where('status', 'dibatasi')->count());
    }

    // ------------------------------------------------------ jalur gagal

    public function test_kredensial_kosong_503_belum_disetel_dan_tidak_memakan_jatah(): void
    {
        config(['services.google_vision.kredensial' => '']);
        $this->fakeGoogle();

        $this->kirim()
            ->assertStatus(503)
            ->assertJsonPath('kode', 'ocr_cloud_belum_disetel')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('belum_disetel', $log->status);
        $this->assertNull($log->unit_vision);
    }

    public function test_kredensial_boleh_base64(): void
    {
        config(['services.google_vision.kredensial' => base64_encode((string) config('services.google_vision.kredensial'))]);
        $this->fakeGoogle();

        $this->kirim()->assertOk();
    }

    public function test_kredensial_rusak_tidak_membocorkan_isinya(): void
    {
        config(['services.google_vision.kredensial' => '{"client_email":"a@b.c","private_key":"RAHASIA-BUKAN-PEM"}']);
        $this->fakeGoogle();

        $r = $this->kirim()->assertStatus(503);

        $this->assertStringNotContainsString('RAHASIA-BUKAN-PEM', (string) $r->getContent());
        $this->assertStringNotContainsString('RAHASIA-BUKAN-PEM', (string) WorksheetExtractionLog::sole()->error);
    }

    public function test_google_menolak_http_403_jatuh_ke_manual_dan_dicatat_gagal(): void
    {
        $this->fakeGoogle(['error' => ['code' => 403, 'message' => 'Cloud Vision API has not been used']], 403);

        $this->kirim()
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('message', 'OCR awan sedang tidak bisa dipakai. Foto dibaca di HP atau isi manual.')
            ->assertJsonPath('fallback_manual', true);

        $log = WorksheetExtractionLog::sole();
        $this->assertSame('gagal', $log->status);
        // Sampai ke Google, tetapi tidak ada unit tertagih.
        $this->assertSame(0, $log->unit_vision);
    }

    /**
     * Pesan galat Google (403/429) lazim memuat nomor proyek dan alamat konsol. Tidak
     * boleh sampai ke teknisi maupun ke kolom log yang terbaca di layar audit.
     */
    public function test_pesan_galat_google_tidak_diteruskan_ke_teknisi_maupun_log(): void
    {
        $this->fakeGoogle(['error' => [
            'code' => 403,
            'status' => 'PERMISSION_DENIED',
            'message' => 'Cloud Vision API has not been used in project 123456789012 https://console.developers.google.com/apis/api/vision',
        ]], 403);

        $r = $this->kirim()->assertStatus(502);

        $log = WorksheetExtractionLog::sole();
        foreach (['123456789012', 'console.developers'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $r->getContent());
            $this->assertStringNotContainsString($rahasia, (string) $log->error);
        }
        $this->assertStringContainsString('HTTP 403 PERMISSION_DENIED', (string) $log->error);
    }

    public function test_galat_per_gambar_tidak_meneruskan_pesan_google(): void
    {
        $this->fakeGoogle(['responses' => [['error' => ['code' => 3, 'message' => 'Bad image data project 987654321']]]]);

        $r = $this->kirim()->assertStatus(422);

        $this->assertStringNotContainsString('987654321', (string) $r->getContent());
        $this->assertStringNotContainsString('987654321', (string) WorksheetExtractionLog::sole()->error);
    }

    /**
     * 200 yang bukan jawaban Vision bukan "halaman kosong": tidak boleh tercatat satu
     * unit tertagih (rekonsiliasi `SUM(unit_vision)` ke tagihan jadi meleset).
     */
    public function test_respons_200_tanpa_responses_dianggap_gagal_dan_tidak_tertagih(): void
    {
        $this->fakeGoogle(['bukan' => 'jawaban vision']);

        $this->kirim()->assertStatus(502)->assertJsonPath('fallback_manual', true);

        $log = WorksheetExtractionLog::sole();
        $this->assertSame('gagal', $log->status);
        $this->assertSame(0, $log->unit_vision);
    }

    /**
     * Vision yang menjawab menurut urutan: `true` = sukses, `false` = galat 500. Panggilan
     * melewati urutan = sukses.
     *
     * @param  list<bool>  $urutan
     */
    private function fakeVisionBerurutan(array $urutan): void
    {
        $n = 0;
        $balasan = $this->balasanVision();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => function () use (&$n, $urutan, $balasan) {
                $sukses = $urutan[$n] ?? true;
                $n++;

                return $sukses ? Http::response($balasan) : Http::response(['error' => ['code' => 500, 'status' => 'INTERNAL']], 500);
            },
        ]);
    }

    /** Galat Google tidak tertagih, jadi tidak memakan jatah BERHASIL per lembar (maks 2 di tes). */
    public function test_galat_google_tidak_menghabiskan_batas_per_lembar(): void
    {
        $this->fakeVisionBerurutan([false, false]);

        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(502);
        $this->kirim()->assertOk();
        $this->kirim()->assertOk();
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'batas_per_lembar');
    }

    /** Tiga kegagalan, lalu berhenti: Google tidak dihubungi lagi dan klien kembali ke jalur biasa. */
    public function test_tiga_kegagalan_menghentikan_panggilan_ke_google_untuk_lembar_itu(): void
    {
        $this->fakeVisionBerurutan([false, false, false, true, true]);

        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(502);
        $this->kirim()
            ->assertStatus(429)
            ->assertJsonPath('kode', 'gagal_berulang_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(3, $this->jumlahPanggilanVision(), 'Google masih dihubungi sesudah tiga kegagalan.');
        $this->assertSame(1, WorksheetExtractionLog::where('status', 'dibatasi')->count());
    }

    /** Batasnya TOTAL per lembar, bukan beruntun: satu sukses di tengah tidak mengulang hitungan. */
    public function test_batas_kegagalan_total_per_lembar_bukan_beruntun(): void
    {
        $this->fakeVisionBerurutan([false, true, false, false]);

        $this->kirim()->assertStatus(502);
        $this->kirim()->assertOk();
        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'gagal_berulang_per_lembar');
    }

    /** Lembar lain tidak ikut terhenti. */
    public function test_batas_kegagalan_per_lembar_tidak_menghentikan_lembar_lain(): void
    {
        $this->fakeVisionBerurutan([false, false, false, true]);
        $sesiLain = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => $this->sesi->equipment_id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);

        foreach (range(1, 3) as $_) {
            $this->kirim()->assertStatus(502);
        }
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'gagal_berulang_per_lembar');

        $this->kirim(null, ['calibration_session_id' => $sesiLain->id])->assertOk();
    }

    /** Penolakan di sisi kita (foto terlalu besar) tidak sampai ke Google, jadi bukan "kegagalan Google". */
    public function test_penolakan_lokal_tidak_menghabiskan_jatah_gagal(): void
    {
        $this->fakeGoogle();

        config(['services.google_vision.maks_byte' => 100]);
        foreach (range(1, 4) as $_) {
            $this->kirim()->assertStatus(422)->assertJsonPath('kode', 'foto_terlalu_besar');
        }

        config(['services.google_vision.maks_byte' => 6 * 1024 * 1024]);
        $this->kirim()->assertOk();
    }

    public function test_vision_401_membuang_token_di_cache_supaya_diminta_ulang(): void
    {
        $n = 0;
        $balasan = $this->balasanVision();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => function () use (&$n, $balasan) {
                $n++;

                return $n === 1 ? Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401) : Http::response($balasan);
            },
        ]);

        $this->kirim()->assertStatus(502);
        $this->kirim()->assertOk();

        $permintaanToken = Http::recorded(fn ($req): bool => str_contains($req->url(), 'oauth2.googleapis.com'))->count();
        $this->assertSame(2, $permintaanToken, 'Token yang ditolak Vision dipakai lagi dari cache.');
    }

    public function test_foto_di_atas_batas_ocr_awan_tapi_di_bawah_pagar_kasar_dijawab_dengan_kode(): void
    {
        $this->fakeGoogle();

        $this->kirim(null, ['foto' => UploadedFile::fake()->image('besar.jpg', 100, 100)->size(7000)])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'foto_terlalu_besar')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $this->assertSame('ditolak', WorksheetExtractionLog::sole()->status);
    }

    public function test_sesi_organisasi_lain_404_dan_google_tidak_dipanggil(): void
    {
        $orgLain = Organization::factory()->create();
        $sesiLain = CalibrationSession::factory()->create([
            'organization_id' => $orgLain->id,
            'teknisi_id' => User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $orgLain->id])->id,
            'equipment_id' => $this->sesi->equipment_id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
        $this->fakeGoogle();

        $this->kirim(null, ['calibration_session_id' => $sesiLain->id])->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_sesi_yang_sudah_dihapus_404_dan_google_tidak_dipanggil(): void
    {
        $this->sesi->delete();
        $this->fakeGoogle();

        $this->kirim()->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_galat_per_gambar_422_gambar_tidak_terbaca(): void
    {
        $this->fakeGoogle(['responses' => [['error' => ['code' => 3, 'message' => 'Bad image data.']]]]);

        $this->kirim()->assertStatus(422)->assertJsonPath('kode', 'gambar_tidak_terbaca');

        $this->assertSame('ditolak', WorksheetExtractionLog::sole()->status);
    }

    public function test_waktu_habis_jatuh_ke_manual_tanpa_crash(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->kirim()
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame('gagal', WorksheetExtractionLog::sole()->status);
    }

    public function test_google_menolak_token_503_belum_disetel(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
            'vision.googleapis.com/*' => Http::response($this->balasanVision()),
        ]);

        $this->kirim()->assertStatus(503)->assertJsonPath('kode', 'ocr_cloud_belum_disetel');

        $this->assertSame(0, $this->jumlahPanggilanVision());
    }

    /** Teks galat dari jalur token (mis. `error_description` Google) tidak boleh sampai ke teknisi atau log. */
    public function test_pesan_galat_token_tidak_diteruskan_ke_teknisi_maupun_log(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'SENTINEL-RAHASIA-TOKEN'], 400),
        ]);

        // Permintaan yang tidak cocok pola di atas (mis. ke Vision) harus gagal, bukan menyentuh jaringan.
        Http::preventStrayRequests();

        $r = $this->kirim()->assertStatus(503)->assertJsonPath('kode', 'ocr_cloud_belum_disetel');

        $this->assertStringNotContainsString('SENTINEL-RAHASIA-TOKEN', (string) $r->getContent());
        $log = WorksheetExtractionLog::sole();
        $this->assertStringNotContainsString('SENTINEL-RAHASIA-TOKEN', (string) $log->error);
        // Bukti jalur token yang jalan (bukan kredensial rusak): kalimat tetap dengan status HTTP-nya.
        $this->assertSame('Google menolak permintaan token (HTTP 400).', $log->error);
        $this->assertNull($log->unit_vision);
    }

    /** Jaringan ke endpoint token putus: Google memang dihubungi dan gagal, jadi 502 dan ikut batas kegagalan. */
    public function test_jaringan_putus_saat_meminta_token_dihitung_kegagalan_bukan_belum_disetel(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => fn () => throw new ConnectionException('timeout'),
            'vision.googleapis.com/*' => Http::response($this->balasanVision()),
        ]);

        foreach (range(1, 3) as $_) {
            $this->kirim()->assertStatus(502)->assertJsonPath('kode', 'ocr_cloud_gagal');
        }
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'gagal_berulang_per_lembar');

        $this->assertSame(0, $this->jumlahPanggilanVision());
        $this->assertSame(0, WorksheetExtractionLog::where('status', 'belum_disetel')->count());
        $this->assertSame(3, WorksheetExtractionLog::where('status', 'gagal')->where('unit_vision', 0)->count());
    }

    /** Batas kegagalan tercapai: SATU baris audit untuk admin, tanpa pesan Google, dan tidak bertambah lagi. */
    public function test_batas_kegagalan_tercapai_mencatat_satu_baris_audit_untuk_admin(): void
    {
        Log::spy();
        $this->fakeGoogle(['error' => [
            'code' => 403,
            'status' => 'PERMISSION_DENIED',
            'message' => 'SENTINEL-PESAN-GOOGLE project 555',
        ]], 403);

        foreach (range(1, 3) as $_) {
            $this->kirim()->assertStatus(502);
        }
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'gagal_berulang_per_lembar');
        $this->kirim()->assertStatus(429);

        $baris = AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->get();
        $this->assertCount(1, $baris, 'Harus tepat satu baris audit per lembar.');

        $a = $baris->first();
        $this->assertSame('ocr_cloud_sesi', $a->entity_type);
        $this->assertSame($this->sesi->id, (int) $a->entity_id);
        $this->assertSame($this->org->id, (int) $a->organization_id);
        $this->assertSame($this->teknisi->id, (int) $a->changed_by);
        $this->assertSame(3, $a->new_data['batas']);
        $this->assertSame(['Google Vision menolak permintaan (HTTP 403 PERMISSION_DENIED).' => 3], $a->new_data['sebab']);
        $this->assertStringNotContainsString('SENTINEL-PESAN-GOOGLE', json_encode($a->new_data).$a->note);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($pesan): bool => str_contains((string) $pesan, 'dihentikan'))
            ->once();
    }

    public function test_dua_kegagalan_belum_mencatat_baris_audit(): void
    {
        $this->fakeVisionBerurutan([false, false]);

        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(502);

        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());
    }

    /**
     * Google yang tidak bisa membaca FOTO (kualitas gambar) bukan konfigurasi yang salah: baris
     * audit tetap ditulis (lembar dihentikan), tetapi catatannya tidak menyuruh admin memeriksa
     * kredensial atau jaringan.
     */
    public function test_foto_ditolak_google_berulang_tidak_menjadi_alarm_konfigurasi(): void
    {
        $this->fakeGoogle(['responses' => [['error' => ['code' => 3, 'message' => 'Bad image data.']]]]);

        foreach (range(1, 3) as $_) {
            $this->kirim()->assertStatus(422)->assertJsonPath('kode', 'gambar_tidak_terbaca');
        }

        $a = AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->sole();
        $this->assertSame(['ditolak' => 3], $a->new_data['jenis']);
        $this->assertStringContainsString('kualitas foto', $a->note);
        $this->assertStringNotContainsString('kredensial', $a->note);
        $this->assertStringNotContainsString('jaringan', $a->note);
    }

    /** Kegagalan sistem (`gagal`) memang menyuruh admin memeriksa kredensial, izin, dan jaringan. */
    public function test_kegagalan_sistem_berulang_menyuruh_admin_memeriksa_konfigurasi(): void
    {
        $this->fakeVisionBerurutan([false, false, false]);

        foreach (range(1, 3) as $_) {
            $this->kirim()->assertStatus(502);
        }

        $a = AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->sole();
        $this->assertSame(['gagal' => 3], $a->new_data['jenis']);
        $this->assertStringContainsString('kredensial', $a->note);
    }

    /** @param  int  $jumlah  banyaknya baris kegagalan Google yang disemai untuk sesi uji */
    private function semaiKegagalan(int $jumlah, string $status = 'gagal'): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            WorksheetExtractionLog::create([
                'calibration_session_id' => $this->sesi->id,
                'user_id' => $this->teknisi->id,
                'model' => GoogleVisionOcr::MODEL,
                'status' => $status,
                'error' => 'Google Vision menolak permintaan (HTTP 403 PERMISSION_DENIED).',
                'unit_vision' => 0,
            ]);
        }
    }

    private function semaiBarisAudit(): void
    {
        AuditLog::create([
            'organization_id' => $this->org->id,
            'entity_type' => 'ocr_cloud_sesi',
            'entity_id' => $this->sesi->id,
            'action' => AuditLog::ACTION_OCR_DIHENTIKAN,
            'changed_by' => $this->teknisi->id,
            'note' => 'semai',
        ]);
    }

    /** Penjaga idempoten: baris audit yang sudah ada tidak digandakan dan peringatan tidak diulang. */
    public function test_baris_audit_yang_sudah_ada_tidak_digandakan(): void
    {
        Log::spy();
        $this->semaiKegagalan(2);
        $this->semaiBarisAudit();
        $this->fakeGoogle(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403);

        $this->kirim()->assertStatus(502);

        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($pesan): bool => str_contains((string) $pesan, 'dihentikan')), \Mockery::any()]);
    }

    /**
     * Sinyal yang gagal ditulis di kegagalan terakhir (mis. database sempat sibuk) tidak hilang:
     * penolakan berikutnya melengkapinya, dan hanya sekali.
     */
    public function test_sinyal_yang_gagal_ditulis_dilengkapi_oleh_penolakan_berikutnya(): void
    {
        $this->semaiKegagalan(3);
        $this->fakeGoogle();

        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());

        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'gagal_berulang_per_lembar');
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());

        $this->kirim()->assertStatus(429);
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());
        Http::assertNothingSent();
    }

    /** Alur kamera tanpa sesi kerja tidak punya batas per lembar: kegagalannya tetap masuk log server. */
    public function test_kegagalan_tanpa_sesi_masuk_log_server_satu_kali_per_jendela(): void
    {
        Log::spy();
        $this->fakeGoogle(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403);

        $this->kirim(null, ['calibration_session_id' => null])->assertStatus(502);
        $this->kirim(null, ['calibration_session_id' => null])->assertStatus(502);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($pesan): bool => str_contains((string) $pesan, 'tanpa sesi kerja'))
            ->once();
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());
    }

    /** Konfigurasi salah (belum disetel) masuk log server, tetapi tidak membanjiri: satu kali per jendela. */
    public function test_belum_disetel_masuk_log_server_satu_kali_per_jendela(): void
    {
        Log::spy();
        config(['services.google_vision.kredensial' => '']);
        $this->fakeGoogle();

        $this->kirim()->assertStatus(503);
        $this->kirim()->assertStatus(503);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($pesan): bool => str_contains((string) $pesan, 'belum disetel benar'))
            ->once();
        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());
    }

    /** Gangguan sementara di Google saat meminta token (5xx/429) = kegagalan biasa, bukan salah setup. */
    public function test_token_ditolak_5xx_atau_429_dihitung_kegagalan_bukan_belum_disetel(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'backend_error'], 503)
                ->push(['error' => 'rate_limit'], 429),
            'vision.googleapis.com/*' => Http::response($this->balasanVision()),
        ]);

        $this->kirim()->assertStatus(502)->assertJsonPath('kode', 'ocr_cloud_gagal');
        $this->kirim()->assertStatus(502)->assertJsonPath('kode', 'ocr_cloud_gagal');

        $this->assertSame(0, $this->jumlahPanggilanVision());
        $this->assertSame(2, WorksheetExtractionLog::where('status', 'gagal')->where('unit_vision', 0)->count());
        $this->assertSame(0, WorksheetExtractionLog::where('status', 'belum_disetel')->count());
    }

    /** Batas kegagalan 0 dipaksa TEPAT 1: satu kegagalan sudah menghentikan lembar itu. */
    public function test_batas_kegagalan_bernilai_nol_dipaksa_tepat_satu(): void
    {
        config(['services.google_vision.maks_gagal_per_sesi' => 0]);
        $this->fakeVisionBerurutan([false]);

        $this->kirim()->assertStatus(502);
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'gagal_berulang_per_lembar');

        $this->assertSame(1, $this->jumlahPanggilanVision());
    }

    /** Nilai config 0 (mis. env kosong) tidak boleh mengunci semua lembar: dipaksa minimal 1. */
    public function test_batas_bernilai_nol_dipaksa_minimal_satu(): void
    {
        config(['services.google_vision.maks_gagal_per_sesi' => 0, 'services.google_vision.maks_per_sesi' => 0]);
        $this->fakeGoogle();

        $this->kirim()->assertOk();
        // Batas berhasil dipaksa 1: panggilan kedua ditolak batas itu, bukan batas gagal.
        $this->kirim()->assertStatus(429)->assertJsonPath('kode', 'batas_per_lembar');
    }

    // ------------------------------------------ tidak ada koreksi otomatis

    /**
     * Respons Google dengan satu blok berisi kata-kata persis seperti diberikan.
     *
     * @param  list<string>  $kata
     * @return array<string, mixed>
     */
    private function balasanDariKata(array $kata): array
    {
        $words = [];
        foreach ($kata as $i => $k) {
            $words[] = [
                'boundingBox' => ['vertices' => $this->kotakGoogle(10 + $i, 20, 60 + $i, 40)],
                'confidence' => 0.5,
                'symbols' => array_map(static fn (string $c): array => ['text' => $c], mb_str_split($k)),
            ];
        }

        return ['responses' => [[
            'fullTextAnnotation' => ['pages' => [[
                'width' => 5000,
                'height' => 100,
                'blocks' => [[
                    'boundingBox' => ['vertices' => $this->kotakGoogle(10, 20, 4000, 40)],
                    'paragraphs' => [['words' => $words]],
                ]],
            ]]],
        ]]];
    }

    /**
     * Teks dari Google dikembalikan APA ADANYA. Tidak ada pembulatan ke nilai
     * terdekat, tidak ada koma/titik/minus yang ditambah atau dibuang, tidak ada
     * penyesuaian ke format atau rentang. Bacaan yang jelek tetap jelek: yang
     * menolak atau menandai adalah lapisan sesudah endpoint ini, bukan endpoint ini.
     */
    public function test_teks_dikembalikan_apa_adanya_tanpa_koreksi_otomatis(): void
    {
        $kata = ['33,37', '-', '--0', '333.7.1', '.5', '5.', '0O', '1l', 'O', 'S3G.2', '637-3', '٣٣٣',
            '1e5', '+7', '12.5', 'a|b', '||', '___', '1,000.5', '00.7', '445', '4O5', 'qqs,v'];
        $this->fakeGoogle($this->balasanDariKata($kata));

        $r = $this->kirim()->assertOk();

        $this->assertSame($kata, array_column($r->json('kata'), 'teks'));
        // Keyakinan juga tidak dinaikkan atau diganti angka pengganti.
        $this->assertSame([0.5], array_values(array_unique(array_column($r->json('kata'), 'keyakinan'))));
    }

    /**
     * Masukan acak dengan seed tetap: derau, goresan garis tabel (`|`, `_`, `-`),
     * serpihan angka, dan teks yang tidak sesuai format apa pun. Semuanya kembali
     * verbatim; kata kosong tidak diisi apa pun (sel kosong tetap kosong).
     */
    public function test_masukan_acak_kembali_verbatim_dan_kata_kosong_tidak_diisi(): void
    {
        mt_srand(20261123);
        $alfabet = str_split('0123456789.,-+|_/\\OlIS$%()');

        $masukan = [];
        for ($i = 0; $i < 300; $i++) {
            $s = '';
            for ($j = mt_rand(1, 9); $j > 0; $j--) {
                $s .= $alfabet[mt_rand(0, count($alfabet) - 1)];
            }
            $masukan[] = $s;
        }
        // Kata tanpa simbol sama sekali (sel kosong / noda): tidak boleh muncul sebagai teks.
        $dengan = $masukan;
        array_splice($dengan, 100, 0, ['', '']);

        $this->fakeGoogle($this->balasanDariKata($dengan));

        $r = $this->kirim()->assertOk();

        $this->assertSame($masukan, array_column($r->json('kata'), 'teks'));
        $this->assertCount(300, $r->json('kata'));
    }
}
