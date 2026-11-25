<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Vision\GoogleVisionOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Concerns\MembuatKunciUji;
use Tests\Concerns\MenyiapkanOcrSelUnggah;
use Tests\TestCase;

/**
 * Batas, jatah, dan jalur gagal `POST /api/ocr/sel-awan` (kontrak 22B).
 *
 * Google SELALU di-fake. Yang dibuktikan: setiap kegagalan (batas, Google 5xx, jaringan putus, pengecualian tak
 * terduga, kredensial kosong, pembatas laju) dijawab terstruktur dengan `fallback_manual: true`, bukan 500
 * polos; batas menolak SEBELUM Google dihubungi; jatah panggilan per lembar dibagi dengan jalur 22A dan
 * `/ocr/halaman`; sel yang tidak sempat dibaca dilaporkan, tidak dibuang diam-diam.
 *
 * Angka batas (307.200 byte, 500.000 piksel, 160 sel per lembar, 240 sel per hari) adalah tebakan awal yang
 * belum diukur pada foto nyata; tes ini mengunci PERILAKU batas, bukan kecocokan angkanya.
 */
class OcrSelUnggahBatasTest extends TestCase
{
    use MembuatKunciUji;
    use MenyiapkanOcrSelUnggah;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanOcrSelUnggah();
    }

    // ------------------------------------------------------------ bawaan mati

    public function test_bawaan_kode_sakelar_google_vision_mati_dan_tanpa_env_baru(): void
    {
        $sumber = (string) file_get_contents(config_path('services.php'));
        $this->assertMatchesRegularExpression(
            "/'aktif'\s*=>\s*filter_var\(env\('GOOGLE_VISION_AKTIF',\s*false\),\s*FILTER_VALIDATE_BOOLEAN\)/",
            $sumber,
            'Sakelar awan harus mati bila env tidak diisi.',
        );

        // Jalur ini memakai sakelar yang sama dengan 22A, bukan env baru.
        $this->assertStringNotContainsString('OCR_SEL_UNGGAH', $sumber);
        $this->assertStringNotContainsString('OCR_SEL_UNGGAH', (string) file_get_contents(config_path('ocr.php')));

        // Konfigurasi bawaan lingkungan uji (phpunit.xml) juga mati.
        $konfig = require config_path('services.php');
        $this->assertFalse($konfig['google_vision']['aktif']);
    }

    // ------------------------------------------------------------ jatah per lembar

    public function test_batas_panggilan_per_lembar_menolak_tanpa_menghubungi_google(): void
    {
        config(['services.google_vision.maks_per_sesi' => 2]);
        $this->fakeGoogle();

        foreach ([1, 1] as $unit) {
            WorksheetExtractionLog::create([
                'calibration_session_id' => $this->sesi->id,
                'user_id' => $this->teknisi->id,
                'model' => GoogleVisionOcr::MODEL,
                'status' => 'sukses',
                'unit_vision' => $unit,
                'ukuran_gambar_byte' => 100,
            ]);
        }

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $this->assertSame(1, WorksheetExtractionLog::where('status', 'dibatasi')->count());
    }

    public function test_jatah_lembar_dimakan_panggilan_jalur_ini_dan_dibagi_dengan_jalur_lain(): void
    {
        config(['services.google_vision.maks_per_sesi' => 1]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1))->assertOk();
        $this->kirim($this->kunciAngka(1))
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(1, $this->jumlahPanggilanVision());
    }

    public function test_batas_panggilan_per_lembar_memotong_batch_dan_melaporkan_sisanya(): void
    {
        // 17 sel = 2 panggilan (16 + 1). Jatah tinggal 1 panggilan: 16 sel terbaca, 1 dilewati.
        config(['services.google_vision.maks_per_sesi' => 1]);
        $this->fakeGoogle();
        $kunci = $this->kunciAngka(17);

        $r = $this->kirim($kunci)->assertOk();

        $this->assertSame(1, $this->jumlahPanggilanVision());
        $this->assertSame(16, $this->jumlahGambarTerkirim());
        $this->assertCount(16, $r->json('hasil'));
        $this->assertSame([['kunci' => $kunci[16], 'alasan' => 'jatah_lembar_habis']], $r->json('dilewati'));
        $r->assertJsonPath('meta.jumlah_panggilan', 1);
    }

    public function test_satu_permintaan_banyak_sel_dikirim_dalam_batch_paling_banyak_16(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(17))->assertOk();

        $ukuran = array_map(static fn (Request $q): int => count($q->data()['requests']), $this->permintaanVision());
        $this->assertSame([16, 1], $ukuran);
        $this->assertCount(17, $r->json('hasil'));
        $this->assertSame(2, WorksheetExtractionLog::count(), 'Satu baris log per panggilan Google.');
        $r->assertJsonPath('meta.jumlah_panggilan', 2)->assertJsonPath('meta.unit', 17);
    }

    // ------------------------------------------------------------ batas sel yang diunggah

    public function test_batas_sel_per_lembar_menolak_permintaan_berikutnya_tanpa_menghubungi_google(): void
    {
        config(['ocr.awan_sel.maks_sel_unggah_per_lembar' => 2]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(2))->assertOk()->assertJsonPath('meta.sisa_sel_lembar', 0);

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_sel_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(1, $this->jumlahPanggilanVision());
        $this->assertSame(1, WorksheetExtractionLog::where('status', 'dibatasi')->count());
    }

    public function test_sel_di_atas_sisa_lembar_dilewati_dan_dilaporkan(): void
    {
        config(['ocr.awan_sel.maks_sel_unggah_per_lembar' => 3]);
        $this->fakeGoogle();
        $kunci = $this->kunciAngka(5);

        $r = $this->kirim($kunci)->assertOk();

        $this->assertSame(3, $this->jumlahGambarTerkirim());
        $this->assertSame($kunci[0], $r->json('hasil.0.kunci'));
        $this->assertSame([
            $kunci[3] => 'melebihi_batas_lembar',
            $kunci[4] => 'melebihi_batas_lembar',
        ], $this->alasanDilewati($r));
        $r->assertJsonPath('meta.sisa_sel_lembar', 0);
    }

    public function test_sel_yang_ditolak_lokal_tidak_memakan_jatah_sel_lembar(): void
    {
        $this->fakeGoogle();
        [$sah] = $this->kunciAngka(1);

        $this->kirim(['isian|suhu_awal', 'centang|ya', $sah, ['kunci' => $this->kunciAngka(2)[1], 'isi' => 'bukan citra']])
            ->assertOk()
            ->assertJsonPath('meta.sisa_sel_lembar', 159);
    }

    public function test_tanpa_sesi_batas_harian_per_akun_dan_akun_lain_tidak_terpengaruh(): void
    {
        config(['ocr.awan_sel.maks_sel_unggah_tanpa_sesi_per_hari' => 2]);
        $this->fakeGoogle();
        $lain = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);

        $this->kirim($this->kunciAngka(2), [], null, false)->assertOk()->assertJsonPath('meta.sisa_sel_lembar', 0);

        $this->kirim($this->kunciAngka(1), [], null, false)
            ->assertStatus(429)
            ->assertJsonPath('kode', 'batas_sel_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->kirim($this->kunciAngka(1), [], $lain, false)->assertOk();
        $this->assertSame(2, $this->jumlahPanggilanVision());
    }

    // ------------------------------------------------------------ pembatas laju

    public function test_pembatas_laju_per_pengguna_dijawab_429_dengan_fallback_manual(): void
    {
        $this->fakeGoogle();

        foreach (range(1, 4) as $_) {
            $this->kirim($this->kunciAngka(1))->assertOk();
        }

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(429)
            ->assertJsonPath('kode', 'terlalu_sering')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(4, $this->jumlahPanggilanVision());
    }

    // ------------------------------------------------------------ jalur gagal

    public function test_google_5xx_dijawab_sopan_bukan_500_dan_dicatat(): void
    {
        $this->fakeGoogle(500);

        $r = $this->kirim($this->kunciAngka(2))
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('fallback_manual', true);

        // Pesan untuk pengguna tetap, tidak membawa teks galat Google.
        $this->assertStringNotContainsString('INTERNAL', (string) $r->getContent());

        $log = WorksheetExtractionLog::sole();
        $this->assertSame('gagal', $log->status);
        $this->assertSame(0, $log->unit_vision);
        $this->assertNull($log->raw_model_response);
        $this->assertNull($log->extracted);
    }

    public function test_jaringan_putus_dijawab_sopan(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame('gagal', WorksheetExtractionLog::sole()->status);
    }

    public function test_pengecualian_tak_terduga_tetap_dijawab_502_terstruktur_bukan_500(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => fn () => throw new LogicException('rincian-internal-yang-tidak-boleh-bocor'),
        ]);

        $r = $this->kirim($this->kunciAngka(1))
            ->assertStatus(502)
            ->assertJsonPath('kode', 'ocr_cloud_gagal')
            ->assertJsonPath('fallback_manual', true);

        $this->assertStringNotContainsString('rincian-internal', (string) $r->getContent());
    }

    public function test_kredensial_kosong_503_belum_disetel_dan_tidak_memakan_jatah(): void
    {
        config(['services.google_vision.kredensial' => '']);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(503)
            ->assertJsonPath('kode', 'ocr_cloud_belum_disetel')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(0, $this->jumlahPanggilanVision());
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('belum_disetel', $log->status);
        $this->assertNull($log->unit_vision);
    }

    public function test_semua_gambar_ditolak_google_422_gambar_tidak_terbaca(): void
    {
        $this->jawaban = ['galat', 'galat'];
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(2))
            ->assertStatus(422)
            ->assertJsonPath('kode', 'gambar_tidak_terbaca')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame('ditolak', WorksheetExtractionLog::sole()->status);
    }

    public function test_kegagalan_di_tengah_batch_menyisakan_hasil_sebagian(): void
    {
        // Panggilan pertama (16 sel) sukses, kedua (1 sel) gagal.
        $kunci = $this->kunciAngka(17);
        $panggilan = 0;
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'vision.googleapis.com/*' => function (Request $req) use (&$panggilan) {
                $panggilan++;
                if ($panggilan === 2) {
                    return Http::response(['error' => ['code' => 500, 'status' => 'INTERNAL']], 500);
                }

                return Http::response(['responses' => array_map(
                    fn () => $this->responsGambar(['7.5', 0.95]),
                    $req->data()['requests'],
                )]);
            },
        ]);

        $r = $this->kirim($kunci)->assertOk();

        $this->assertCount(16, $r->json('hasil'));
        $r->assertJsonPath('meta.sebagian', true);
        $this->assertSame([['kunci' => $kunci[16], 'alasan' => 'google_gagal']], $r->json('dilewati'));
        $this->assertSame(['sukses', 'gagal'], WorksheetExtractionLog::orderBy('id')->pluck('status')->all());
    }

    public function test_batas_kegagalan_per_lembar_menghentikan_google_dan_menulis_jejak_admin(): void
    {
        // Dua kegagalan sudah tercatat; yang ketiga datang dari panggilan ini.
        foreach ([1, 2] as $_) {
            WorksheetExtractionLog::create([
                'calibration_session_id' => $this->sesi->id,
                'user_id' => $this->teknisi->id,
                'model' => GoogleVisionOcr::MODEL,
                'status' => 'gagal',
                'error' => 'Google Vision menolak permintaan (HTTP 500).',
                'unit_vision' => 0,
                'ukuran_gambar_byte' => 100,
            ]);
        }
        $this->fakeGoogle(500);

        $this->kirim($this->kunciAngka(1))->assertStatus(502);
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count());

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(429)
            ->assertJsonPath('kode', 'gagal_berulang_per_lembar')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(1, $this->jumlahPanggilanVision(), 'Google dihubungi lagi sesudah batas kegagalan.');
        $this->assertSame(1, AuditLog::where('action', AuditLog::ACTION_OCR_DIHENTIKAN)->count(), 'Jejak admin harus idempoten.');
    }
}
