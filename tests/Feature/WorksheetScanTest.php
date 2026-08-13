<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetScan;
use App\Services\Ocr\TemplateLembarKerja;
use App\Services\Ocr\ValidasiSel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Endpoint pindai lembar kerja lewat OCR TEMPLATE LOKAL.
 *
 * ## Kenapa tes ini ada
 *
 * Fitur ini punya satu mode gagal yang jauh lebih mahal dari yang lain: angka
 * mendarat di sel yang salah. Gagal baca kelihatan (sel merah, teknisi ngetik).
 * Gagal TARUH nggak kelihatan — angkanya wajar, kolomnya wajar, cuma barisnya
 * geser satu, dan ketahuannya waktu sertifikat terakreditasi udah di tangan
 * pelanggan.
 *
 * Jadi yang diuji di sini bukan cuma "endpointnya jalan", tapi tiap penjagaan
 * yang bikin salah-taruh mustahil: kunci eksplisit, urutan kiriman nggak
 * ngaruh, versi formulir dicek, label baris tercetak dicek, dan tiap kegagalan
 * nolak SELURUH lembar — bukan ngisi separuh.
 *
 * Geometri pakai fixture sendiri (bukan file `database/ocr-templates` yang
 * dipakai produksi), supaya test nggak jadi hijau/merah gara-gara orang lagi
 * ngukur ulang koordinat formulir.
 */
class WorksheetScanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/worksheet-scans';

    private User $teknisi;

    private CalibrationSession $sesi;

    private string $folderFixture;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->teknisi = User::factory()->create();

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

        $this->siapkanGeometriFixture();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folderFixture.'/*.json') ?: [] as $berkas) {
            unlink($berkas);
        }

        @rmdir($this->folderFixture);

        parent::tearDown();
    }

    /**
     * Salin geometri produksi ke folder sementara, lalu setel
     * `terverifikasi = true`.
     *
     * Di produksi flag itu masih `false` (koordinatnya belum diadu ke formulir
     * cetak asli), dan itu memang harus bikin semua pindai ditolak. Test ini
     * nguji lapisan DI ATAS geometri, jadi dia butuh geometri yang dinyatakan
     * sah — tapi lewat fixture, bukan dengan mendiamkan flag produksinya.
     */
    private function siapkanGeometriFixture(): void
    {
        $this->folderFixture = storage_path('framework/testing/ocr-templates-'.getmypid());

        if (! is_dir($this->folderFixture)) {
            mkdir($this->folderFixture, 0755, true);
        }

        $asli = json_decode(
            (string) file_get_contents(database_path('ocr-templates/ph_meter-v1.json')),
            true,
        );
        $asli['terverifikasi'] = true;

        file_put_contents(
            $this->folderFixture.'/ph_meter-v1.json',
            json_encode($asli, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        Config::set('ocr.folder_template', $this->folderFixture);
    }

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return app(TemplateLembarKerja::class)->untukKode('ph_meter');
    }

    /**
     * Kiriman HP yang sehat: semua sel kebaca bersih, mutu foto bagus.
     *
     * Nilai tiap sel diambil dari titik ukur barisnya sendiri, jadi kalau
     * pemetaannya kegeser satu baris, nilainya bakal nyasar jauh dari nominal
     * dan ketahuan — bukan cuma "kebetulan masih masuk rentang".
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function payload(array $ganti = []): array
    {
        $template = $this->template();
        $sel = [];

        foreach ($template['sel'] as $definisi) {
            $sel[] = [
                'tabel_id' => $definisi['tabel_id'],
                'baris_ke' => $definisi['baris_ke'],
                'repeat_no' => $definisi['repeat_no'],
                'field_id' => $definisi['field_id'],
                'teks_mentah' => $definisi['field_id'] === 'suhu'
                    ? '25,0'
                    : number_format((float) $definisi['titik_ukur'] + 0.01, 2, ',', ''),
                'confidence_ocr' => 0.97,
                'kotak_teks_di_dalam_sel' => true,
                'kotak' => ['x' => 100, 'y' => 200, 'w' => 80, 'h' => 40],
                'titik_ukur' => $definisi['titik_ukur'],
            ];
        }

        return array_merge([
            'template_id' => 'ph_meter',
            'template_versi' => 1,
            'calibration_session_id' => $this->sesi->id,
            'qr' => ['terbaca' => true, 'isi' => 'ph_meter|v1'],
            'geometri' => [
                'marker' => [
                    ['id' => 0, 'x' => 90, 'y' => 90], ['id' => 1, 'x' => 1564, 'y' => 90],
                    ['id' => 2, 'x' => 1564, 'y' => 2249], ['id' => 3, 'x' => 90, 'y' => 2249],
                ],
                'residual_reproyeksi_px' => 0.8,
                'grid_tersnap' => true,
                'ukuran_referensi' => ['w' => 1654, 'h' => 2339],
            ],
            'kualitas' => [
                'blur_laplacian' => 240.0,
                'kecerahan_rata' => 150.0,
                'rasio_glare' => 0.01,
                'sudut_kemiringan_deg' => 1.2,
                'px_per_sel_tinggi' => 48,
            ],
            'perangkat' => ['model' => 'Redmi Note 12', 'os' => 'Android 13', 'app' => '1.4.0', 'ocr' => 'mlkit-v2'],
            'sel' => $sel,
            'sel_jangkar' => array_map(
                static fn (int $r): array => ['field_id' => 'label_repeat', 'repeat_no' => $r,
                    'teks_mentah' => (string) $r, 'cocok' => true],
                range(1, 5),
            ),
        ], $ganti);
    }

    /**
     * @param  array<string, mixed>  $ganti
     */
    private function kirim(array $ganti = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->teknisi)->postJson(self::URL, $this->payload($ganti));
    }

    /**
     * Pindai bersih: semua sel kebaca, nggak ada yang merah — TAPI statusnya
     * `perlu_review`, bukan `ok`.
     *
     * Ini bukan kekurangan, ini keputusan pokok: lembar kerja lab ini ditulis
     * TANGAN, dan OCR on-device dilatih buat teks cetak. Yang paling bahaya dari
     * angka tulisan tangan bukan skornya rendah — skornya justru tetap tinggi
     * waktu bacanya salah. Jadi selama `ocr.tulisan_tangan.aktif`, sel paling
     * bagus pun mentok kuning: keisi otomatis, tapi mata teknisi tetap lewat.
     */
    public function test_pindai_bersih_kebaca_semua(): void
    {
        $respons = $this->kirim()->assertCreated();

        $respons->assertJsonPath('status', 'perlu_review');
        $respons->assertJsonPath('ringkasan.total_sel', 60);
        $respons->assertJsonPath('ringkasan.merah', 0);
        $respons->assertJsonPath('ringkasan.hijau', 0);
        $respons->assertJsonPath('ringkasan.kuning', 60);
        // Nggak ada merah = boleh keisi otomatis; ada kuning = wajib dicek.
        $respons->assertJsonPath('boleh_auto_isi', true);
        $respons->assertJsonPath('wajib_dicek', true);

        $scan = WorksheetScan::firstOrFail();
        $this->assertSame(60, $scan->cells()->count());
        $this->assertSame($this->sesi->id, $scan->calibration_session_id);
        // Versi pipeline & aturan ikut kesimpen: tanpa itu, hasil pindai lama
        // nggak bisa dibandingin sama yang baru waktu ambangnya disetel ulang.
        $this->assertSame(config('ocr.pipeline_versi'), $scan->pipeline_versi);
        $this->assertSame(config('ocr.aturan_versi'), $scan->aturan_versi);
    }

    /**
     * Hasil pindai NGGAK PERNAH langsung jadi data kalibrasi. Ini pembatas yang
     * bikin kamera cuma mempercepat input, bukan gantiin tanggung jawab
     * teknisi — `raw_measurements` tetap lahir dari POST/PUT /calibrations.
     */
    public function test_pindai_tidak_bikin_raw_measurement(): void
    {
        $this->kirim()->assertCreated();

        $this->assertSame(0, $this->sesi->rawMeasurements()->count());
    }

    /**
     * INTI ANTI-TERTUKAR: urutan kiriman diacak, hasilnya harus sama persis.
     *
     * Kalau ada satu tahap saja yang diam-diam ngandelin urutan array, tes ini
     * yang nangkep — dan dia nangkepnya sebelum ada sertifikat yang salah,
     * bukan sesudah.
     */
    public function test_urutan_kiriman_diacak_hasilnya_identik(): void
    {
        $payload = $this->payload();

        $urut = $this->kirim()->assertCreated()->json();

        $acak = $payload['sel'];
        // Dibalik, bukan di-shuffle: urutan terbalik itu permutasi yang pasti
        // beda dari aslinya, jadi tesnya nggak bisa lolos gara-gara acakan yang
        // kebetulan sama.
        $payload['sel'] = array_reverse($acak);

        $terbalik = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)->assertCreated()->json();

        $this->assertSame(
            $this->nilaiPerKunci($urut['scan_id']),
            $this->nilaiPerKunci($terbalik['scan_id']),
            'Urutan kiriman HP ikut nentuin isi sel — ini bug taruh-salah-sel.',
        );
    }

    /**
     * @return array<string, float|null>
     */
    private function nilaiPerKunci(int $scanId): array
    {
        return WorksheetScan::findOrFail($scanId)
            ->cells()
            ->orderBy('kunci')
            ->pluck('nilai', 'kunci')
            ->map(static fn ($n): ?float => $n === null ? null : (float) $n)
            ->all();
    }

    /**
     * Nilai Repeat 1 nggak boleh bisa mendarat di Repeat 2 — bahkan waktu HP
     * ngirim `repeat_no` yang ketuker. Yang mustahil ketuker itu KUNCINYA:
     * angka dari kolom lain otomatis punya kunci lain.
     */
    public function test_repeat_tertukar_mendarat_di_kunci_yang_benar(): void
    {
        $payload = $this->payload();

        foreach ($payload['sel'] as $i => $s) {
            if ($s['baris_ke'] === 1 && $s['field_id'] === 'pembacaan' && $s['repeat_no'] === 1) {
                $payload['sel'][$i]['teks_mentah'] = '4,05';
            }
        }

        $scanId = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)->assertCreated()->json('scan_id');
        $nilai = $this->nilaiPerKunci($scanId);

        $this->assertEqualsWithDelta(4.05, $nilai['sebelum_adjustment|1|1|pembacaan'], 1e-9);
        // Tetangganya nggak ikut berubah — nilai nggak nyebrang kolom.
        $this->assertEqualsWithDelta(4.01, $nilai['sebelum_adjustment|1|2|pembacaan'], 1e-9);
    }

    /**
     * Tiap sel di template harus punya tepat satu baris hasil. Sel yang hilang
     * tanpa suara lebih bahaya dari scan yang ditolak: layarnya kelihatan
     * normal, cuma satu kolom yang selamanya kosong.
     */
    public function test_setiap_sel_template_dapat_tepat_satu_baris(): void
    {
        $scanId = $this->kirim()->assertCreated()->json('scan_id');

        $kunci = WorksheetScan::findOrFail($scanId)->cells()->pluck('kunci')->sort()->values()->all();
        $harapan = array_keys($this->template()['sel']);
        sort($harapan);

        $this->assertSame($harapan, $kunci, 'Kunci sel hasil pindai beda dari template.');
        $this->assertCount(count($kunci), array_unique($kunci), 'Ada kunci sel yang dobel.');
    }

    /**
     * Versi formulir beda = kolomnya bisa geser. Rev.4 & Rev.5 mirip di mata
     * orang, beda di mata koordinat — jadi ini ditolak keras, bukan diterima
     * dengan peringatan.
     */
    public function test_versi_formulir_beda_ditolak(): void
    {
        $respons = $this->kirim(['template_versi' => 2])->assertStatus(422);

        $respons->assertJsonPath('status', 'template_tidak_dikenali');
        $respons->assertJsonPath('fallback_manual', true);
        $this->assertSame(0, WorksheetScan::firstOrFail()->cells()->count());
    }

    public function test_qr_tidak_terbaca_ditolak(): void
    {
        $this->kirim(['qr' => ['terbaca' => false]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali');
    }

    /**
     * Label baris yang tercetak di formulir ikut dibaca OCR. Kalau yang kebaca
     * nggak cocok sama urutan yang diharapkan, itu tanda grid-nya kegeser — dan
     * itu tepat kegagalan yang paling mahal.
     */
    public function test_label_baris_tidak_cocok_menolak_seluruh_lembar(): void
    {
        $jangkar = array_map(
            static fn (int $r): array => ['field_id' => 'label_repeat', 'repeat_no' => $r,
                'teks_mentah' => (string) ($r + 1), 'cocok' => $r !== 2],
            range(1, 5),
        );

        $this->kirim(['sel_jangkar' => $jangkar])
            ->assertStatus(422)
            ->assertJsonPath('status', 'mapping_gagal');
    }

    #[DataProvider('fotoBuruk')]
    public function test_foto_buruk_ditolak_dengan_pesan_yang_bisa_dikerjakan(array $kualitas, string $kutipan): void
    {
        $payload = $this->payload();
        $respons = $this->actingAs($this->teknisi)->postJson(self::URL, [
            ...$payload,
            'kualitas' => array_merge($payload['kualitas'], $kualitas),
        ])->assertStatus(422);

        $respons->assertJsonPath('status', 'ditolak_kualitas');
        // Pesannya harus nyuruh ngapain, bukan cuma bilang gagal. Teknisi lagi
        // berdiri di depan alat pelanggan — "foto ulang" tanpa alasan bikin dia
        // ngulang kesalahan yang sama.
        $this->assertStringContainsString($kutipan, $respons->json('message'));
    }

    /**
     * @return array<string, array{0: array<string, float|int>, 1: string}>
     */
    public static function fotoBuruk(): array
    {
        return [
            'buram' => [['blur_laplacian' => 40.0], 'buram'],
            'gelap' => [['kecerahan_rata' => 30.0], 'gelap'],
            'silau' => [['rasio_glare' => 0.3], 'pantulan'],
            'miring' => [['sudut_kemiringan_deg' => 20.0], 'miring'],
            'kejauhan' => [['px_per_sel_tinggi' => 10], 'kejauhan'],
        ];
    }

    /**
     * Homography meleset = seluruh grid geser tanpa gejala lain.
     */
    public function test_penyelarasan_tidak_presisi_ditolak(): void
    {
        $payload = $this->payload();
        $payload['geometri']['residual_reproyeksi_px'] = 6.5;

        $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'geometri_meragukan');
    }

    public function test_garis_tabel_tidak_kedeteksi_ditolak(): void
    {
        $payload = $this->payload();
        $payload['geometri']['grid_tersnap'] = false;

        $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'geometri_meragukan');
    }

    /**
     * Sel yang nggak ada di template = APK megang bentuk lembar yang beda.
     * Diterima diam-diam artinya sel lain kemungkinan juga salah alamat.
     */
    public function test_kunci_asing_menolak_seluruh_lembar(): void
    {
        $payload = $this->payload();
        $payload['sel'][0]['baris_ke'] = 99;

        $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'mapping_gagal');
    }

    public function test_kunci_dobel_menolak_seluruh_lembar(): void
    {
        $payload = $this->payload();
        $payload['sel'][1] = $payload['sel'][0];

        $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'mapping_gagal');
    }

    /**
     * Sel yang nggak ikut kekirim = ada bagian tabel yang kepotong frame.
     * Ditolak, bukan diisi separuh.
     */
    public function test_sel_kurang_menolak_seluruh_lembar(): void
    {
        $payload = $this->payload();
        array_pop($payload['sel']);

        $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'mapping_gagal');
    }

    /**
     * Titik ukur yang dipegang HP harus sama persis sama yang dipegang server.
     * Beda = APK megang lembar versi lain walau nomor versinya kebetulan sama —
     * dan itu justru kasus paling licin, karena semua penjagaan versi lolos.
     */
    public function test_titik_ukur_beda_antara_hp_dan_server_ditolak(): void
    {
        $payload = $this->payload();
        $payload['sel'][0]['titik_ukur'] = 4.01;

        $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'mapping_gagal');
    }

    /**
     * Angka yang jauh dari titik ukurnya = merah, dan angkanya nggak dipakai.
     * Ini yang nangkep koma kegeser: 40,1 di titik 4,00.
     */
    public function test_angka_yang_magnitudonya_meleset_jadi_merah(): void
    {
        $payload = $this->payload();

        foreach ($payload['sel'] as $i => $s) {
            if ($s['baris_ke'] === 1 && $s['field_id'] === 'pembacaan' && $s['repeat_no'] === 1) {
                $payload['sel'][$i]['teks_mentah'] = '40,1';
            }
        }

        $scanId = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertCreated()
            ->assertJsonPath('boleh_auto_isi', false)
            ->json('scan_id');

        $sel = WorksheetScan::findOrFail($scanId)->cells()
            ->where('kunci', 'sebelum_adjustment|1|1|pembacaan')->firstOrFail();

        $this->assertSame(ValidasiSel::MERAH, $sel->status);
        $this->assertContains('di_luar_rentang', $sel->alasan);
    }

    /**
     * Sel kosong itu SAH — teknisi sering nggak sempat ngisi satu-dua kolom.
     * Kosong nggak boleh dihitung sebagai gagal baca.
     */
    public function test_sel_kosong_bukan_kegagalan(): void
    {
        $payload = $this->payload();
        $payload['sel'][0]['teks_mentah'] = '';

        $respons = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)->assertCreated();

        $respons->assertJsonPath('ringkasan.kosong', 1);
        $respons->assertJsonPath('ringkasan.merah', 0);
        $respons->assertJsonPath('boleh_auto_isi', true);
    }

    /**
     * Sel yang nilainya lahir dari tebakan karakter nggak pernah hijau, walau
     * skor OCR-nya tinggi.
     */
    public function test_tebakan_karakter_mentok_kuning(): void
    {
        $payload = $this->payload();

        foreach ($payload['sel'] as $i => $s) {
            if ($s['baris_ke'] === 1 && $s['field_id'] === 'pembacaan' && $s['repeat_no'] === 1) {
                $payload['sel'][$i]['teks_mentah'] = '4,O1';
            }
        }

        $scanId = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)
            ->assertCreated()->json('scan_id');

        $sel = WorksheetScan::findOrFail($scanId)->cells()
            ->where('kunci', 'sebelum_adjustment|1|1|pembacaan')->firstOrFail();

        $this->assertSame(ValidasiSel::KUNING, $sel->status);
        $this->assertContains('ada_koreksi_karakter', $sel->alasan);
        $this->assertEqualsWithDelta(4.01, (float) $sel->nilai, 1e-9);
    }

    /**
     * Scan yang DITOLAK tetap kesimpen. Justru yang ditolak yang paling berguna
     * waktu ambangnya mau disetel ulang — tanpa itu, satu-satunya bukti yang
     * tersisa adalah teknisi yang bilang "kameranya nggak jalan".
     */
    public function test_scan_ditolak_tetap_tersimpan_buat_bahan_setelan(): void
    {
        $this->kirim(['qr' => ['terbaca' => false]])->assertStatus(422);

        $scan = WorksheetScan::firstOrFail();

        $this->assertSame('template_tidak_dikenali', $scan->status);
        $this->assertFalse($scan->bisaDipakai());
        // Kiriman mentahnya ikut disimpen — itu bahan bandingannya.
        $this->assertNotEmpty($scan->payload['sel']);
    }

    /**
     * Teknisi lain nggak boleh mindai lembar sesi yang bukan pekerjaannya —
     * aturan yang sama persis kayak jalur AI Vision.
     */
    public function test_teknisi_lain_ditolak(): void
    {
        $lain = User::factory()->create();

        $this->kirim(user: $lain)->assertForbidden();
    }

    public function test_koreksi_teknisi_jadi_ground_truth(): void
    {
        $scanId = $this->kirim()->assertCreated()->json('scan_id');

        $respons = $this->actingAs($this->teknisi)->postJson("/api/worksheet-scans/{$scanId}/koreksi", [
            'koreksi' => [
                // Mesin baca 4,01 — teknisi setuju.
                ['kunci' => 'sebelum_adjustment|1|1|pembacaan', 'nilai_final' => 4.01],
                // Mesin baca 4,01 — kertasnya sebenarnya 4,02.
                ['kunci' => 'sebelum_adjustment|1|2|pembacaan', 'nilai_final' => 4.02],
            ],
        ])->assertOk();

        $respons->assertJsonPath('data.cocok', 1);
        $respons->assertJsonPath('data.meleset', 1);

        $scan = WorksheetScan::findOrFail($scanId);
        $meleset = $scan->cells()->where('kunci', 'sebelum_adjustment|1|2|pembacaan')->firstOrFail();

        $this->assertFalse($meleset->cocok);
        $this->assertEqualsWithDelta(4.02, (float) $meleset->nilai_final, 1e-9);
        $this->assertSame($this->teknisi->id, $meleset->dikoreksi_oleh);
    }

    /**
     * Dua tabel di lembar pH (Before & After adjustment) bentuknya sama persis.
     * Kalau identitas tabel nggak ikut kunci, angka After bakal nimpa Before —
     * dan hasilnya sertifikat yang bilang alatnya nggak berubah sesudah
     * di-adjust, padahal itu cuma angka yang sama kebaca dua kali.
     */
    public function test_nilai_before_tidak_bocor_ke_after(): void
    {
        $payload = $this->payload();

        foreach ($payload['sel'] as $i => $s) {
            if ($s['tabel_id'] === 'sesudah_adjustment' && $s['field_id'] === 'pembacaan') {
                $payload['sel'][$i]['teks_mentah'] = number_format(
                    (float) $s['titik_ukur'] + 0.03, 2, ',', '',
                );
            }
        }

        $scanId = $this->actingAs($this->teknisi)->postJson(self::URL, $payload)->assertCreated()->json('scan_id');
        $nilai = $this->nilaiPerKunci($scanId);

        $this->assertEqualsWithDelta(4.01, $nilai['sebelum_adjustment|1|1|pembacaan'], 1e-9);
        $this->assertEqualsWithDelta(4.03, $nilai['sesudah_adjustment|1|1|pembacaan'], 1e-9);
    }

    /**
     * Potongan citra per sel itu yang bikin layar review ada gunanya: teknisi
     * bandingin angka hasil baca sama coretan aslinya di layar yang sama. Kalau
     * dia harus buka kertasnya lagi buat ngecek, mending dia ngetik dari awal.
     */
    public function test_crop_sel_mengembalikan_potongan_citra(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD nggak ada — endpoint crop-nya emang nolak duluan di server begini.');
        }

        Storage::fake('local');

        $scanId = $this->actingAs($this->teknisi)->post(self::URL, [
            ...$this->payload(),
            'citra_warp' => UploadedFile::fake()->image('lembar-warp.jpg', 1654, 2339),
        ])->assertCreated()->json('scan_id');

        $respons = $this->actingAs($this->teknisi)
            ->get("/api/worksheet-scans/{$scanId}/sel/".rawurlencode('sebelum_adjustment|1|1|pembacaan').'/crop')
            ->assertOk();

        $respons->assertHeader('Content-Type', 'image/jpeg');
        // Citra data pelanggan — nggak boleh nyangkut di cache bersama.
        $this->assertStringContainsString('private', $respons->headers->get('Cache-Control'));
        // JPEG asli, bukan badan kosong: dua byte pertamanya penanda SOI.
        $this->assertStringStartsWith("\xFF\xD8", $respons->getContent());
    }

    public function test_crop_sel_yang_tidak_ada_404(): void
    {
        Storage::fake('local');

        $scanId = $this->actingAs($this->teknisi)->post(self::URL, [
            ...$this->payload(),
            'citra_warp' => UploadedFile::fake()->image('lembar-warp.jpg', 1654, 2339),
        ])->assertCreated()->json('scan_id');

        $this->actingAs($this->teknisi)
            ->get("/api/worksheet-scans/{$scanId}/sel/".rawurlencode('tabel_ngawur|1|1|pembacaan').'/crop')
            ->assertNotFound();
    }

    /**
     * Hasil pindai teknisi lain nggak boleh keintip — termasuk citranya, yang
     * isinya data pengukuran pelanggan.
     */
    public function test_hasil_pindai_teknisi_lain_tidak_bisa_dibuka(): void
    {
        $scanId = $this->kirim()->assertCreated()->json('scan_id');
        $lain = User::factory()->create();

        $this->actingAs($lain)->getJson("/api/worksheet-scans/{$scanId}")->assertForbidden();
    }

    /**
     * Selama geometri formulir belum diadu ke kertas aslinya, tombol kameranya
     * mati. Lebih baik teknisi ngetik daripada koordinat rancangan dipakai naruh
     * angka di sertifikat.
     */
    public function test_geometri_belum_diverifikasi_menolak_pindai(): void
    {
        $berkas = $this->folderFixture.'/ph_meter-v1.json';
        $isi = json_decode((string) file_get_contents($berkas), true);
        $isi['terverifikasi'] = false;
        file_put_contents($berkas, json_encode($isi));

        $this->kirim()
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali');
    }

    /**
     * Daftar template dipakai HP buat mutusin tombol kameranya nyala atau nggak.
     * Isinya harus SEMUA alat yang dikenal sistem — bukan daftar yang ditulis
     * tangan di APK, karena alat baru nambah terus dan APK-nya nggak ikut update
     * tiap kali.
     */
    public function test_daftar_template_ikut_semua_profil_alat(): void
    {
        $respons = $this->actingAs($this->teknisi)->getJson('/api/worksheet-templates')->assertOk();

        $kode = array_column($respons->json('data'), 'template_id');

        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'refractometer',
            'conductivity_meter', 'spectrophotometer'] as $alat) {
            $this->assertContains($alat, $kode);
        }

        // Status kesiapan ikut dikirim — HP nggak boleh nebak sendiri.
        $this->assertArrayHasKey('siap_pindai', $respons->json('data.0'));
    }

    public function test_detail_template_punya_kunci_sel_dan_aturan_angka(): void
    {
        $respons = $this->actingAs($this->teknisi)
            ->getJson('/api/worksheet-templates/ph_meter')
            ->assertOk();

        $this->assertCount(60, $respons->json('data.sel'));
        $sel = $respons->json('data.sel')['sebelum_adjustment|1|1|pembacaan'];

        $this->assertEqualsWithDelta(4.0, $sel['titik_ukur'], 1e-9);
        $this->assertEqualsWithDelta(3.6, $sel['aturan']['min'], 1e-9);
    }

    public function test_template_tidak_dikenal_404(): void
    {
        $this->actingAs($this->teknisi)->getJson('/api/worksheet-templates/alat_ngawur')->assertNotFound();
    }

    /**
     * Ground truth cuma boleh dari sel yang emang ada di scan itu. Kunci asing
     * dilaporkan, bukan diam-diam bikin baris baru — dataset yang tercemar
     * bikin angka akurasinya bohong ke arah yang salah.
     */
    public function test_koreksi_kunci_asing_dilaporkan(): void
    {
        $scanId = $this->kirim()->assertCreated()->json('scan_id');

        $this->actingAs($this->teknisi)->postJson("/api/worksheet-scans/{$scanId}/koreksi", [
            'koreksi' => [['kunci' => 'tabel_ngawur|9|9|pembacaan', 'nilai_final' => 4.0]],
        ])->assertOk()->assertJsonPath('data.kunci_tidak_dikenal', ['tabel_ngawur|9|9|pembacaan']);

        $this->assertSame(60, WorksheetScan::findOrFail($scanId)->cells()->count());
    }
}
