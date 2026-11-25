<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetExtractionLog;
use App\Models\WorksheetScan;
use App\Services\Vision\GoogleVisionOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MembuatKunciUji;
use Tests\Concerns\MenyiapkanOcrSelUnggah;
use Tests\Support\CitraSintetis;
use Tests\TestCase;

/**
 * OCR awan atas potongan sel angka yang DIUNGGAH HP: `POST /api/ocr/sel-awan` (kontrak 22B).
 *
 * Google SELALU di-fake: tes ini tidak memanggil layanan sungguhan dan tidak membuktikan apa pun tentang
 * akurasi atau tagihan Google. Yang dibuktikan: gerbang berlapis (env, dua saklar, peran, organisasi), sel
 * mana yang boleh keluar dari server (hanya kunci baris angka templat server, potongan kecil berformat
 * JPEG/PNG), penolakan PER SEL, status yang tidak pernah hijau, teks mentah yang tidak dikoreksi diam-diam,
 * potongan yang tidak tersimpan di mana pun, jejak audit tanpa gambar dan tanpa teks.
 *
 * Batas, jatah, dan jalur gagal ada di `OcrSelUnggahBatasTest`.
 */
class OcrSelUnggahTest extends TestCase
{
    use MembuatKunciUji;
    use MenyiapkanOcrSelUnggah;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanOcrSelUnggah();
    }

    // ------------------------------------------------------------ gerbang

    public function test_bawaan_config_mati_dan_env_mati_dijawab_503_dengan_fallback_manual(): void
    {
        config(['services.google_vision.aktif' => false]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(503)
            ->assertJsonPath('kode', 'ocr_cloud_belum_aktif')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $log = WorksheetExtractionLog::sole();
        $this->assertSame('dimatikan', $log->status);
        $this->assertNull($log->unit_vision);
    }

    public function test_saklar_super_admin_ocr_cloud_sel_mati_ditolak_dan_google_tidak_dihubungi(): void
    {
        $this->org->update(['settings' => ['fitur_nonaktif' => ['ocr_cloud_sel']]]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1))
            ->assertStatus(503)
            ->assertJsonPath('kode', 'fitur_dimatikan')
            ->assertJsonPath('fitur', 'ocr_cloud_sel');

        Http::assertNothingSent();
    }

    public function test_saklar_pindai_lembar_mati_juga_menutup_jalur_ini(): void
    {
        $this->org->update(['settings' => ['fitur_nonaktif' => ['pindai_lembar']]]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1))->assertStatus(503)->assertJsonPath('fitur', 'pindai_lembar');

        Http::assertNothingSent();
    }

    public function test_tamu_ditolak_401(): void
    {
        $this->postJson('/api/ocr/sel-awan', ['template_id' => 'ph_meter'])->assertStatus(401);
    }

    public function test_viewer_ditolak_403_dan_google_tidak_dihubungi(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER, 'organization_id' => $this->org->id]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1), [], $viewer)->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_sesi_organisasi_lain_dijawab_404_tanpa_menghubungi_google(): void
    {
        $orgLain = Organization::factory()->create();
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $orgLain->id]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1), [], $teknisiLain)
            ->assertStatus(404)
            ->assertJsonPath('kode', 'sesi_tidak_ditemukan')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_sesi_yang_tidak_ada_dijawab_sama_dengan_sesi_organisasi_lain(): void
    {
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1), ['calibration_session_id' => 999999])
            ->assertStatus(404)
            ->assertJsonPath('kode', 'sesi_tidak_ditemukan');

        Http::assertNothingSent();
    }

    public function test_teknisi_lain_di_organisasi_sama_403_tetapi_admin_boleh(): void
    {
        $teknisiLain = User::factory()->create(['role' => User::ROLE_TEKNISI, 'organization_id' => $this->org->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'organization_id' => $this->org->id]);
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(1), [], $teknisiLain)
            ->assertStatus(403)
            ->assertJsonPath('kode', 'bukan_sesi_anda')
            ->assertJsonPath('fallback_manual', true);
        $this->assertSame(0, $this->jumlahPanggilanVision());

        $this->kirim($this->kunciAngka(1), [], $admin)->assertOk();
        $this->assertSame(1, $this->jumlahPanggilanVision());
    }

    // ------------------------------------------------------------ validasi bentuk permintaan

    public function test_daftar_sel_kosong_atau_tanpa_templat_ditolak_422_dan_tidak_menghubungi_google(): void
    {
        $this->fakeGoogle();

        $this->kirim([])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'permintaan_tidak_valid')
            ->assertJsonPath('fallback_manual', true)
            ->assertJsonStructure(['errors' => ['sel']]);

        $this->kirim($this->kunciAngka(1), ['template_id' => ''])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'permintaan_tidak_valid');

        $this->kirim($this->kunciAngka(1), ['kertas' => 'hologram'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'permintaan_tidak_valid');

        Http::assertNothingSent();
    }

    public function test_sel_tanpa_berkas_gambar_ditolak_422_seluruh_permintaan(): void
    {
        $this->fakeGoogle();
        [$a, $b] = $this->kunciAngka(2);

        $this->kirim([$a, ['kunci' => $b, 'isi' => null]])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'permintaan_tidak_valid')
            ->assertJsonPath('fallback_manual', true);

        Http::assertNothingSent();
    }

    public function test_batas_48_sel_per_permintaan_49_ditolak_422(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(48))->assertOk();
        $this->assertCount(48, $r->json('hasil'));
        $this->assertSame([16, 16, 16], array_map(
            static fn ($q): int => count($q->data()['requests']),
            $this->permintaanVision(),
        ));

        // Sel ke-49 (kunci apa pun): ditolak di validasi, sebelum templat atau Google disentuh.
        $kunci = $this->kunciAngka(49);
        $this->kirim($kunci)
            ->assertStatus(422)
            ->assertJsonPath('kode', 'permintaan_tidak_valid')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame(3, $this->jumlahPanggilanVision(), 'Permintaan 49 sel tidak boleh sampai ke Google.');
    }

    public function test_templat_tidak_dikenal_422_tanpa_menghubungi_google(): void
    {
        $this->fakeGoogle();

        $this->kirim(['x|1|1|pembacaan'], ['template_id' => 'alat_yang_tidak_ada'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'templat_tidak_dikenal')
            ->assertJsonPath('fallback_manual', true);

        $this->kirim(['x|1|1|pembacaan'], ['template_id' => 'alat_yang_tidak_ada', 'kertas' => 'asli'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'templat_tidak_dikenal');

        Http::assertNothingSent();
    }

    // ------------------------------------------------- yang boleh keluar dari server (per sel)

    public function test_kunci_isian_centang_asing_dan_kembar_dilewati_dengan_alasan_dan_tidak_dikirim(): void
    {
        $this->fakeGoogle();
        [$angka] = $this->kunciAngka(1);

        $r = $this->kirim([
            'isian|suhu_awal',
            'centang|ya',
            'identitas|nama_pelanggan',
            'nomor_seri',
            $angka,
            $angka,
        ])->assertOk();

        $this->assertSame([$angka], $this->kunciHasil($r));
        $this->assertSame(1, $this->jumlahGambarTerkirim(), 'Hanya satu sel angka yang boleh keluar.');

        $this->assertSame([
            'isian|suhu_awal' => 'isian_tidak_dikirim',
            'centang|ya' => 'centang_tidak_dikirim',
            'identitas|nama_pelanggan' => 'tidak_ada_di_templat',
            'nomor_seri' => 'tidak_ada_di_templat',
            $angka => 'kunci_kembar',
        ], $this->alasanDilewati($r));
    }

    public function test_kunci_baris_jam_autoklaf_bukan_sel_angka_dan_tidak_dikirim(): void
    {
        $this->fakeGoogle();
        $kunci = $this->kunciAutoklaf();

        $r = $this->kirim([$kunci['jam'], $kunci['angka']], ['template_id' => 'autoclave'])->assertOk();

        $this->assertSame([$kunci['angka']], $this->kunciHasil($r));
        $this->assertSame(1, $this->jumlahGambarTerkirim());
        $this->assertSame('bukan_sel_angka', $this->alasanDilewati($r)[$kunci['jam']]);
    }

    public function test_semua_sel_bukan_angka_422_tidak_ada_potongan_valid_dan_google_tidak_dihubungi(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim(['isian|suhu_awal', 'centang|ya', 'tanda_tangan', 'tanggal_kalibrasi'])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'tidak_ada_potongan_valid')
            ->assertJsonPath('fallback_manual', true);

        $this->assertCount(4, $r->json('dilewati'));
        Http::assertNothingSent();
        $this->assertSame(0, WorksheetExtractionLog::count());
    }

    public function test_potongan_terlalu_besar_bukan_citra_atau_kebesaran_piksel_dilewati_per_sel(): void
    {
        $this->fakeGoogle();
        [$sah, $besarByte, $besarPiksel, $bukanCitra, $kosong, $png] = $this->kunciAngka(6);

        config(['ocr.awan_sel.maks_byte_potongan' => 5_000]);

        $r = $this->kirim([
            $sah,
            ['kunci' => $besarByte, 'isi' => CitraSintetis::jpegSungguhan(60, 30).str_repeat("\0", 6_000)],
            ['kunci' => $besarPiksel, 'isi' => CitraSintetis::jpegMengaku(1000, 1000)],
            ['kunci' => $bukanCitra, 'isi' => 'GIF89a bukan jpeg atau png'],
            ['kunci' => $kosong, 'isi' => ''],
            ['kunci' => $png, 'isi' => CitraSintetis::pngMengaku(900, 900)],
        ])->assertOk();

        $this->assertSame([$sah], $this->kunciHasil($r));
        $this->assertSame(1, $this->jumlahGambarTerkirim(), 'Hanya potongan yang sah yang boleh keluar.');
        $this->assertSame([
            $besarByte => 'potongan_kebesaran_byte',
            $besarPiksel => 'potongan_kebesaran_piksel',
            $bukanCitra => 'potongan_bukan_citra',
            $kosong => 'potongan_bukan_citra',
            $png => 'potongan_kebesaran_piksel',
        ], $this->alasanDilewati($r));
    }

    public function test_semua_potongan_ditolak_422_dan_google_tidak_dihubungi(): void
    {
        $this->fakeGoogle();
        [$a, $b] = $this->kunciAngka(2);

        $r = $this->kirim([
            ['kunci' => $a, 'isi' => CitraSintetis::jpegMengaku(4000, 3000)],
            ['kunci' => $b, 'isi' => 'bukan citra'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'tidak_ada_potongan_valid')
            ->assertJsonPath('fallback_manual', true);

        $this->assertSame([
            $a => 'potongan_kebesaran_piksel',
            $b => 'potongan_bukan_citra',
        ], $this->alasanDilewati($r));
        Http::assertNothingSent();
    }

    public function test_potongan_di_batas_piksel_dan_byte_masih_dikirim(): void
    {
        $this->fakeGoogle();
        [$a] = $this->kunciAngka(1);
        $isi = CitraSintetis::jpegSungguhan(500, 1000); // 500.000 piksel tepat

        config(['ocr.awan_sel.maks_byte_potongan' => strlen($isi)]);

        $this->kirim([['kunci' => $a, 'isi' => $isi]])->assertOk();

        $this->assertSame(1, $this->jumlahGambarTerkirim());
    }

    public function test_yang_dikirim_ke_google_adalah_byte_potongan_apa_adanya(): void
    {
        $this->fakeGoogle();
        [$a] = $this->kunciAngka(1);
        $isi = $this->potonganSah();

        $this->kirim([['kunci' => $a, 'isi' => $isi]])->assertOk();

        $semua = $this->permintaanVision()[0]->data()['requests'];
        $this->assertCount(1, $semua);
        $this->assertSame([['type' => GoogleVisionOcr::FITUR]], $semua[0]['features']);
        $this->assertSame($isi, base64_decode((string) $semua[0]['image']['content'], true), 'Potongan tidak boleh diubah di server.');
    }

    public function test_identitas_dan_tanda_tangan_tidak_punya_jalan_keluar_apa_pun_isi_unggahannya(): void
    {
        $this->fakeGoogle();

        $r = $this->kirim([
            ['kunci' => 'nama_pelanggan', 'isi' => $this->potonganSah()],
            ['kunci' => 'alamat', 'isi' => $this->potonganSah()],
            ['kunci' => 'tanda_tangan', 'isi' => $this->potonganSah()],
        ])->assertStatus(422);

        $this->assertSame('tidak_ada_potongan_valid', $r->json('kode'));
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ hasil

    public function test_status_tidak_pernah_hijau_walau_keyakinan_tinggi(): void
    {
        $this->jawaban = [['4.01', 1.0], ['4,00', 0.99]];
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(2))->assertOk();

        foreach ($r->json('hasil') as $item) {
            $this->assertSame('kuning', $item['status']);
        }
        $r->assertJsonPath('meta.usulan', true)->assertJsonPath('meta.sumber', 'google_vision');
    }

    public function test_teks_mentah_dikembalikan_apa_adanya_dan_bentuk_angka_terpisah(): void
    {
        $this->jawaban = [['4.01', 0.9], ['4,00', 0.9]];
        $this->fakeGoogle();
        [$a, $b] = $this->kunciAngka(2);

        $r = $this->kirim([$a, $b])->assertOk();

        $r->assertJsonPath('hasil.0.kunci', $a)
            ->assertJsonPath('hasil.0.teks_awan', '4.01')
            ->assertJsonPath('hasil.0.teks_normal', '4,01')
            ->assertJsonPath('hasil.0.dinormalkan', true)
            ->assertJsonPath('hasil.1.teks_awan', '4,00')
            ->assertJsonPath('hasil.1.teks_normal', '4,00')
            ->assertJsonPath('hasil.1.dinormalkan', false);

        $this->assertEqualsWithDelta(4.01, $r->json('hasil.0.nilai_awan'), 1e-9);
    }

    public function test_teks_yang_bukan_angka_murni_tetap_mentah_dan_merah_tanpa_koreksi(): void
    {
        // "4 01" (spasi di tengah), "abc" (bukan angka), "4,O1" (huruf O): ditolak, tidak diperbaiki.
        $this->jawaban = [['4 01', 0.99], ['abc', 0.99], ['4,O1', 0.99]];
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(3))->assertOk();

        $r->assertJsonPath('hasil.0.teks_awan', '4 01')
            ->assertJsonPath('hasil.0.status', 'merah')
            ->assertJsonPath('hasil.0.nilai_awan', null)
            ->assertJsonPath('hasil.1.teks_awan', 'abc')
            ->assertJsonPath('hasil.1.status', 'merah')
            ->assertJsonPath('hasil.1.nilai_awan', null)
            ->assertJsonPath('hasil.2.teks_awan', '4,O1')
            ->assertJsonPath('hasil.2.status', 'merah')
            ->assertJsonPath('hasil.2.nilai_awan', null);

        foreach ([0, 1, 2] as $i) {
            $this->assertNotEmpty($r->json("hasil.{$i}.alasan"));
            $this->assertNull($r->json("hasil.{$i}.teks_normal"));
        }
    }

    public function test_keyakinan_rendah_merah_bukan_kuning(): void
    {
        $this->jawaban = [['4,01', 0.30]];
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(1))->assertOk();

        $r->assertJsonPath('hasil.0.status', 'merah')->assertJsonPath('hasil.0.alasan.0', 'keyakinan_rendah');
        $this->assertEqualsWithDelta(0.30, $r->json('hasil.0.keyakinan'), 1e-9);
    }

    public function test_sel_kosong_di_mata_google_tetap_kosong_bukan_angka_karangan(): void
    {
        $this->jawaban = ['kosong', ['4,01', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(2))->assertOk();

        $r->assertJsonPath('hasil.0.status', 'kosong')
            ->assertJsonPath('hasil.0.teks_awan', '')
            ->assertJsonPath('hasil.0.nilai_awan', null)
            ->assertJsonPath('hasil.1.status', 'kuning');
    }

    public function test_satu_gambar_ditolak_google_sel_itu_merah_yang_lain_tetap_terbaca(): void
    {
        $this->jawaban = ['galat', ['4,01', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(2))->assertOk();

        $r->assertJsonPath('hasil.0.status', 'merah')
            ->assertJsonPath('hasil.0.teks_awan', null)
            ->assertJsonPath('hasil.0.alasan.0', 'google_gagal_sel')
            ->assertJsonPath('hasil.1.status', 'kuning')
            ->assertJsonPath('meta.unit', 1)
            ->assertJsonPath('meta.sebagian', false);
    }

    public function test_meta_dan_hitungan_sukses(): void
    {
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(3))
            ->assertOk()
            ->assertJsonPath('meta.jumlah_dikirim', 3)
            ->assertJsonPath('meta.jumlah_panggilan', 1)
            ->assertJsonPath('meta.unit', 3)
            ->assertJsonPath('meta.fitur', GoogleVisionOcr::FITUR)
            ->assertJsonPath('meta.sebagian', false)
            ->assertJsonPath('meta.sisa_sel_lembar', 157)
            ->assertJsonPath('dilewati', []);
    }

    public function test_bentuk_jawaban_per_sel_sama_dengan_jalur_hasil_pindai(): void
    {
        // Penjaga duplikasi `SusunHasilSelAwan` / `OcrSelAwanController::susunHasil`: dua jalur, jawaban Google
        // yang sama, bentuk per sel harus identik. Kalau salah satu berubah sendirian, tes ini merah.
        config([
            'ocr.penyimpanan.disk' => 'local',
            'ocr.awan_sel.maks_sel_per_scan' => 80,
            'ocr.awan_sel.margin_px' => 4,
        ]);

        $gambar = imagecreatetruecolor(400, 600);
        imagefill($gambar, 0, 0, (int) imagecolorallocate($gambar, 255, 255, 255));
        ob_start();
        imagejpeg($gambar, null, 90);
        $isiHalaman = (string) ob_get_clean();
        unset($gambar);
        Storage::disk('local')->put('worksheet-scans/paritas-warp.jpg', $isiHalaman);

        $scan = WorksheetScan::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->teknisi->id,
            'calibration_session_id' => $this->sesi->id,
            'citra_warp_path' => 'worksheet-scans/paritas-warp.jpg',
            'hasil' => ['tabel' => [['tabel_id' => 'tabel_a', 'baris' => [
                ['baris_ke' => 1, 'tipe' => 'angka'],
                ['baris_ke' => 2, 'tipe' => 'angka'],
                ['baris_ke' => 3, 'tipe' => 'angka'],
                ['baris_ke' => 4, 'tipe' => 'angka'],
            ]]]],
        ]);
        foreach ([1, 2, 3, 4] as $i) {
            $scan->cells()->create([
                'kunci' => "tabel_a|{$i}|1|pembacaan", 'tabel_id' => 'tabel_a', 'baris_ke' => $i, 'repeat_no' => 1,
                'field_id' => 'pembacaan', 'teks_mentah' => '4,00', 'teks_normal' => '4,00', 'nilai' => 4.0,
                'status' => 'kuning', 'kotak' => ['x' => 10, 'y' => 10 + ($i - 1) * 40, 'w' => 80, 'h' => 30],
            ]);
        }

        // Empat jawaban Google yang menjangkau semua cabang: normal, dinormalkan, bukan angka, kosong.
        $this->jawaban = [['4,01', 0.9], ['4.01', 0.9], ['4 01', 0.99], 'kosong'];
        $this->fakeGoogle();
        $lewatPindai = $this->actingAs($this->teknisi->fresh())
            ->postJson('/api/worksheet-scans/'.$scan->id.'/ocr-awan', [
                'kunci' => ['tabel_a|1|1|pembacaan', 'tabel_a|2|1|pembacaan', 'tabel_a|3|1|pembacaan', 'tabel_a|4|1|pembacaan'],
            ])->assertOk();

        $this->urutanJawaban = 0;
        $lewatUnggah = $this->kirim($this->kunciAngka(4))->assertOk();

        $this->assertCount(4, $lewatPindai->json('hasil'));
        $this->assertCount(4, $lewatUnggah->json('hasil'));

        foreach ([0, 1, 2, 3] as $i) {
            $a = $lewatPindai->json("hasil.{$i}");
            $b = $lewatUnggah->json("hasil.{$i}");
            unset($a['kunci'], $b['kunci']);

            $this->assertSame($a, $b, "Bentuk atau isi jawaban sel ke-{$i} berbeda antara jalur pindai dan jalur unggah.");
        }
    }

    // ------------------------------------------------------------ tanpa jejak isi

    public function test_potongan_tidak_disimpan_di_disk_mana_pun(): void
    {
        // `Storage::fake('local')` di setUp mengosongkan disk; sesudah permintaan sukses disk harus tetap kosong.
        Storage::fake('public');
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(3))->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles(), 'Potongan sel tidak boleh ditulis ke disk.');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Potongan sel tidak boleh ditulis ke disk.');
    }

    public function test_log_mencatat_metadata_tanpa_gambar_dan_tanpa_teks(): void
    {
        // Angka sengaja tidak lazim supaya tidak kebetulan sama dengan id atau cap waktu di baris log.
        $this->jawaban = [['8675309.7', 0.9], ['2468013,8', 0.9]];
        $this->fakeGoogle();

        $r = $this->kirim($this->kunciAngka(2))->assertOk();

        $log = WorksheetExtractionLog::sole();

        $this->assertSame(GoogleVisionOcr::MODEL, $log->model);
        $this->assertSame('sukses', $log->status);
        $this->assertSame($this->teknisi->id, $log->user_id);
        $this->assertSame($this->sesi->id, $log->calibration_session_id);
        $this->assertSame(2, $log->unit_vision);
        $this->assertSame(2 * strlen($this->potonganSah()), $log->ukuran_gambar_byte);
        $this->assertNotNull($log->durasi_ms);
        $this->assertNull($log->raw_model_response);
        $this->assertNull($log->extracted);
        $this->assertNull($log->error);

        $json = json_encode($log->toArray());
        // Teks hasil dan isi gambar (JPEG base64 selalu diawali "/9j/") tidak boleh ada di log.
        $this->assertStringNotContainsString('8675309', $json);
        $this->assertStringNotContainsString('2468013', $json);
        $this->assertStringNotContainsString('/9j/', $json);

        $this->assertCount(2, $r->json('hasil'));
    }

    public function test_tanpa_sesi_jalur_tetap_jalan_dan_log_tanpa_sesi(): void
    {
        $this->fakeGoogle();

        $this->kirim($this->kunciAngka(2), [], null, false)
            ->assertOk()
            ->assertJsonPath('meta.jumlah_dikirim', 2)
            ->assertJsonPath('meta.sisa_sel_lembar', 238);

        $log = WorksheetExtractionLog::sole();
        $this->assertNull($log->calibration_session_id);
        $this->assertSame($this->teknisi->id, $log->user_id);
    }
}
