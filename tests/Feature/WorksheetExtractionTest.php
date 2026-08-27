<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Services\Calibration\CalibrationProfileRegistry;
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

    /**
     * Tanpa sesi, fotonya DITOLAK — dan itu pembalikan yang disengaja.
     *
     * ## Yang berlaku sebelum 27 Agt 2026, dan kenapa dicabut
     *
     * Test ini dulu menegakkan kebalikannya: `calibration_session_id: null`
     * tetap jalan, jejaknya tercatat dengan sesi null. Itu fitur yang memang
     * dimaui — ekstrak dari foto tanpa perlu punya sesi dulu.
     *
     * Yang nggak pernah ditimbang waktu fitur itu dibuat: **gerbang bentuk
     * kertas ikut terbuka.** Tanpa sesi nggak ada alat, tanpa alat nggak ada
     * profil, dan tanpa profil bawaan `didukung`-nya `true`. Jadi seluruh
     * lembar yang sengaja ditolak — Autoklaf, TIDS, kelima Enclosure — bisa
     * dikirim ke penyedia AI pihak ketiga **cukup dengan menghilangkan satu
     * kolom opsional dari permintaannya.**
     *
     * Pemisahan `didukung`/`lokal` (PR #120) nggak menutup itu; dia cuma
     * memindahkan pintunya. Sapuan `test_tiap_lembar_tak_didukung_...` juga
     * nggak: dia SELALU membuat sesi, jadi buta persis di jalur yang nggak
     * punya profil sama sekali.
     *
     * Dicabut atas keputusan pemilik lab (27 Agt 2026), dan biayanya nol nyata:
     * aplikasi mobile **nggak punya satu pun call site** ke endpoint ini.
     *
     * Gagalnya sekarang MENUTUP. "Nggak tahu kertasnya apa" itu bentuk paling
     * murni dari nggak bisa dibuktikan muat.
     */
    public function test_session_id_null_ditolak_sebelum_foto_keluar(): void
    {
        Http::fake();
        $this->fakeSukses();

        $this->kirim($this->teknisi, ['calibration_session_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
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

    /**
     * 429 punya DUA arti yang beda jauh, dan bedanya nggak kelihatan dari kode
     * statusnya — cuma dari isi pesannya.
     *
     * Kredit habis NGGAK sembuh dengan nunggu. Kejadian 13 Agt 2026: teknisi
     * nyoba jam 11:53 & 13:22, dua-duanya dapat "layanan lagi sibuk, tunggu
     * beberapa menit", padahal yang perlu dilakuin cuma isi saldo. Sehari
     * kebuang nungguin sesuatu yang nggak berubah.
     */
    public function test_kuota_habis_pesannya_beda_dari_server_sibuk(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'Your prepayment credits are depleted. Please go to AI Studio to manage billing.'],
        ], 429)]);

        $respons = $this->kirim($this->teknisi)->assertStatus(422);

        $pesan = (string) $respons->json('message');

        $this->assertStringContainsString('Kuota layanan AI habis', $pesan);
        $this->assertStringNotContainsString('Tunggu beberapa menit', $pesan);

        // Jalur ketik manual tetap kebuka — gagal baca foto nggak pernah bikin
        // teknisi buntu.
        $this->assertTrue($respons->json('fallback_manual'));
    }

    /** 429 rate limit BIASA tetap dapat saran nunggu — itu emang sembuh sendiri. */
    public function test_server_sibuk_tetap_disuruh_nunggu(): void
    {
        Config::set('services.anthropic.api_key', 'test-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'Number of requests has exceeded the rate limit. Please slow down.'],
        ], 429)]);

        $this->kirim($this->teknisi)
            ->assertStatus(422)
            ->assertJsonPath('fallback_manual', true);

        $this->assertStringContainsString(
            'Tunggu beberapa menit',
            (string) $this->kirim($this->teknisi)->json('message'),
        );
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

    /**
     * Model yang dicatat harus MODEL PENYEDIA YANG AKTIF.
     *
     * Lab ini jalan di `VISION_DRIVER=gemini`. Jalur "API key belum diisi" dulu
     * nyatat `services.anthropic.model` apa pun drivernya, jadi kegagalan karena
     * `GEMINI_API_KEY` kosong kecatat sebagai `claude-opus-4-8` — menyesatkan
     * persis di penelusuran yang jadi alasan log ini ada. Yang nelusur bakal
     * ngecek kunci Anthropic yang emang nggak pernah dipakai, dan kunci Gemini
     * yang beneran kosong nggak kelihatan sama sekali.
     */
    public function test_log_belum_disetel_nyatat_model_penyedia_yang_aktif(): void
    {
        Config::set('services.vision.driver', 'gemini');
        Config::set('services.gemini.api_key', '');
        Config::set('services.gemini.model', 'gemini-3.6-flash');

        $this->kirim($this->teknisi)->assertStatus(503);

        $log = WorksheetExtractionLog::sole();
        $this->assertSame('belum_disetel', $log->status);
        $this->assertSame('gemini-3.6-flash', $log->model);
    }

    /**
     * Lembar Autoklaf bentuknya matriks — tujuh baris besaran campur × lima
     * titik waktu — dan nggak ada kombinasi `kolom_suhu`/`standar_di_baris`
     * yang menggambarkannya.
     *
     * Yang HARUS terjadi: ditolak sebelum fotonya dikirim ke mana pun. Bukan
     * dicoba pakai bentuk lembar pH. Yang balik dari percobaan itu bukan error
     * melainkan angka yang bentuknya wajar tapi mendarat di baris yang salah —
     * dan itu lolos sampai sertifikat.
     */
    public function test_lembar_yang_bentuknya_nggak_didukung_ditolak_sebelum_foto_dikirim(): void
    {
        Http::fake();
        $this->fakeSukses();

        $sesiAutoklaf = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => Equipment::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
                'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'autoklaf'])->id,
                'nama_alat' => 'Autoklaf',
            ])->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);

        $this->kirim($this->teknisi, ['calibration_session_id' => $sesiAutoklaf->id])
            ->assertStatus(422)
            ->assertJsonPath('fallback_manual', true);

        // Nggak ada satu pun panggilan keluar: fotonya berhenti di server kita.
        Http::assertNothingSent();

        $log = WorksheetExtractionLog::sole();
        $this->assertSame('bentuk_tidak_didukung', $log->status);
    }

    /**
     * SAPUAN: tiap lembar yang `didukung`-nya `false` DITOLAK sebelum fotonya
     * dikirim ke mana pun — bukan cuma Autoklaf.
     *
     * ## Kenapa sapuan, dan kenapa ini bukan sekadar kerapian
     *
     * `didukung` menggerbangi endpoint INI, yang **mengirim foto lembar kerja
     * pelanggan ke layanan pihak ketiga** (Gemini/Anthropic). Menaikkannya
     * bukan cuma memperluas fitur — dia **melebarkan batas data**.
     *
     * Test di atas menegakkannya lewat SATU profil (Autoklaf). Itu ternyata
     * tidak cukup: 27 Agt 2026, `TidsProfile` dinaikkan ke `didukung: true`
     * supaya tombol kamera LOKAL-nya hidup — dan lembar TIDS diam-diam ikut
     * memenuhi syarat dikirim keluar begitu `VISION_AKTIF` menyala. Test
     * Autoklaf tetap hijau selama itu, karena dia berdiri di profil yang salah.
     *
     * Yang membetulkannya bukan menerima pelebaran itu, tapi **memisahkan
     * gerbangnya**: `lokal` buat tombol on-device, `didukung` buat jalur cloud.
     * Sapuan ini yang menjaga pemisahan itu tetap berlaku — profil ke-21 yang
     * menaikkan `didukung` cuma buat menyalakan kameranya bakal MERAH di sini.
     *
     * `Http::assertNothingSent()` bagian yang paling menentukan: 422 saja bisa
     * datang sesudah fotonya terlanjur diunggah.
     */
    public function test_tiap_lembar_tak_didukung_ditolak_sebelum_foto_keluar(): void
    {
        Http::fake();
        $this->fakeSukses();

        $registry = app(CalibrationProfileRegistry::class);

        // Satu pelanggan & satu kategori dipakai bersama: yang menentukan profil
        // mana yang kepilih itu NAMA alatnya (`cocokkanNama`), bukan
        // kategorinya — dan bikin kategori baru per profil menguras nilai unik
        // factory-nya tanpa menambah apa pun yang diuji.
        $pelanggan = Customer::factory()->create();
        $kategori = EquipmentCategory::factory()->create();
        $diperiksa = [];

        foreach ($registry->semua() as $profil) {
            if (($profil->bentukPindaiFoto()['didukung'] ?? true) !== false) {
                continue;
            }

            $nama = $profil->namaAlatKemampuan();
            $this->assertNotNull(
                $nama,
                "Profil `{$profil->kode()}` nggak punya nama kemampuan, jadi sesi ujinya "
                .'nggak bisa diarahkan ke profil itu — sapuan ini bakal nguji profil yang salah.',
            );

            $alat = Equipment::factory()->create([
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => $nama,
                'nama_alat_kemampuan' => $nama,
            ]);

            // Sanity: sesinya beneran jatuh ke profil yang lagi diuji. Tanpa ini
            // sapuannya bisa hijau sambil menguji lembar pH tujuh kali.
            $this->assertSame(
                $profil->kode(),
                $registry->untukAlat($alat)->kode(),
                "Sesi buat `{$nama}` nggak jatuh ke profil `{$profil->kode()}`.",
            );

            $sesi = CalibrationSession::factory()->create([
                'teknisi_id' => $this->teknisi->id,
                'equipment_id' => $alat->id,
                'status' => CalibrationSession::STATUS_DRAFT,
            ]);

            $this->kirim($this->teknisi, ['calibration_session_id' => $sesi->id])
                ->assertStatus(422)
                ->assertJsonPath('fallback_manual', true);

            $diperiksa[] = $profil->kode();
        }

        // Penjaga lantai: sapuan yang daftarnya dari registry bisa menyusut jadi
        // nol dan tetap "lolos" tanpa memeriksa apa pun.
        $this->assertGreaterThanOrEqual(
            7,
            count($diperiksa),
            'Cuma '.count($diperiksa).' profil ber-`didukung: false` yang kesapu ('
            .implode(', ', $diperiksa).'), di bawah lantai 7 (Autoklaf, TIDS, kelima '
            .'Enclosure). Kalau memang ada yang sengaja dinaikkan, turunkan angkanya '
            .'SEKALIAN — supaya pelebaran batas datanya jadi keputusan yang tercatat, '
            .'bukan kejadian yang kelewat.',
        );

        // Yang paling menentukan: nggak satu pun fotonya keluar dari server kita.
        Http::assertNothingSent();
    }

    /**
     * TANPA `calibration_session_id`, fotonya DITOLAK — bukan dikirim keluar.
     *
     * ## Lubang yang ditutup test ini
     *
     * Kolom itu divalidasi `sometimes|nullable`. Dihilangkan dari permintaan,
     * `sesiTervalidasi` pulang null tanpa error, `bentukKertas` nggak punya
     * alat buat ditanya, dan bawaannya dulu `didukung: true`.
     *
     * Akibatnya: SELURUH lembar yang sengaja ditolak — Autoklaf, TIDS, kelima
     * Enclosure — bisa dikirim ke penyedia AI pihak ketiga **cukup dengan
     * menghilangkan satu kolom opsional.** Pemisahan `didukung`/`lokal` yang
     * baru saja dibuat nggak menutup itu; dia cuma memindahkan pintunya.
     *
     * ## Kenapa sapuan sebelumnya nggak menangkapnya
     *
     * `test_tiap_lembar_tak_didukung_ditolak_sebelum_foto_keluar` **selalu
     * membuat sesi** — dia menyapu dua puluh profil dan buta di satu jalur yang
     * nggak punya profil sama sekali. Persis kelas kebutaan yang dia sendiri
     * dibuat buat menutup, cuma pindah tempat: yang lama berdiri di satu
     * profil, yang ini berdiri di satu bentuk permintaan.
     *
     * Gagalnya sekarang MENUTUP: yang nggak bisa dibuktikan muat, ditolak.
     * "Nggak tahu kertasnya apa" itu bentuk paling murni dari nggak bisa
     * dibuktikan.
     */
    public function test_tanpa_kunci_session_id_ditolak_sebelum_foto_keluar(): void
    {
        Http::fake();
        $this->fakeSukses();

        // Lewat `postJson` langsung, BUKAN helper `kirim`: helper itu
        // `array_merge` sesi bawaan, dan `[]` nggak bisa MENGHILANGKAN kunci —
        // cuma menimpanya. Bedanya menentukan: yang diuji di sini justru
        // permintaan yang kuncinya nggak ada sama sekali.
        $this->actingAs($this->teknisi)
            ->postJson(self::URL, [
                'foto' => UploadedFile::fake()->image('lembar.jpg', 1000, 1400),
                'jumlah_titik' => 3,
                'jumlah_pengulangan' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
    }

    /**
     * Penanda `didukung` NGGAK boleh bisa ditimpa pemanggil. Dua penanda bentuk
     * yang lain soal pilihan; yang ini soal kertas yang nggak bisa digambarkan
     * sama sekali, dan klien yang ngirim `kolom_suhu` nggak mengubah itu.
     */
    public function test_klien_nggak_bisa_maksa_lembar_yang_nggak_didukung(): void
    {
        Http::fake();
        $this->fakeSukses();

        $sesiAutoklaf = CalibrationSession::factory()->create([
            'teknisi_id' => $this->teknisi->id,
            'equipment_id' => Equipment::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
                'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'autoklaf'])->id,
                'nama_alat' => 'Autoklaf',
            ])->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);

        $this->kirim($this->teknisi, [
            'calibration_session_id' => $sesiAutoklaf->id,
            'kolom_suhu' => true,
            'standar_di_baris' => false,
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * Jalur ini sekarang CADANGAN — mobile pindah ke pindai lokal dan nggak
     * pernah manggilnya lagi. Karena dia mengirim foto lembar kerja pelanggan
     * ke layanan pihak ketiga, lab harus bisa mematikannya lewat satu baris
     * `.env`, bukan lewat hapus kode.
     */
    public function test_saklar_vision_mati_bikin_endpoint_berhenti_di_server(): void
    {
        Http::fake();
        $this->fakeSukses();
        Config::set('services.vision.aktif', false);

        $this->kirim($this->teknisi)
            ->assertStatus(503)
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();

        $this->assertSame('dimatikan', WorksheetExtractionLog::sole()->status);
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
