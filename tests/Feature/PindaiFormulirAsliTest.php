<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorksheetScan;
use App\Services\Ocr\FormulirAsli;
use App\Services\Ocr\PemrosesScanLembarKerja;
use App\Services\Ocr\ValidasiSel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `POST /api/worksheet-scans` dengan `kertas: "asli"` — hasil pindai FORMULIR
 * ASLI lab (pilot pH, SIDIK-FM-CAL-0509 Rev.4) yang tidak punya marker maupun
 * QR. HP meratakan halaman dari tulisan CETAK formulir (jangkar teks), lalu
 * server memberi vonis per sel, per isian kondisi lingkungan, dan per kotak
 * centang. `docs/PANDUAN-OCR-LEMBAR-KERJA.md` §3, §4, §6.
 *
 * Yang dijaga, dari yang paling mahal:
 *
 * - Formulir lain / revisi lain / geometri yang meragukan menolak SELURUH
 *   lembar — sama dengan jalur cetak, cuma pengenalnya kode FM tercetak, bukan
 *   QR.
 * - Mode uji (keputusan pemilik K1, 9 Okt 2026): sebelum formulir lulus >=20
 *   foto nyata, pindai boleh dipakai tapi TIDAK ADA satu butir pun yang hijau.
 *   Tanpa mode uji, formulir yang belum siap ditolak seperti jalur lama.
 * - Tulisan tangan tidak pernah hijau, walau sakelar global dimatikan.
 * - Jalur cetak bermarker tidak berubah (dijaga `WorksheetScanTest` yang tidak
 *   disentuh, plus dua kasus kontrak di bawah).
 */
class PindaiFormulirAsliTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/worksheet-scans';

    private User $teknisi;

    private CalibrationSession $sesi;

    private ?string $folderFixture = null;

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

        // Bawaan berkas ini: mode uji NYALA (keadaan yang dipakai lab sampai
        // >=20 foto). Kasus yang butuh mati menyetelnya sendiri.
        Config::set('ocr.formulir_asli.mode_uji', true);
    }

    protected function tearDown(): void
    {
        if ($this->folderFixture !== null) {
            foreach (glob($this->folderFixture.'/asli/*.json') ?: [] as $berkas) {
                @unlink($berkas);
            }

            @rmdir($this->folderFixture.'/asli');
            @rmdir($this->folderFixture);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Lolos penuh
    // ------------------------------------------------------------------

    public function test_lolos_penuh_mode_uji_semua_maksimal_kuning(): void
    {
        $respons = $this->kirim()->assertCreated();

        $respons->assertJsonPath('kertas', 'asli');
        $respons->assertJsonPath('mode_uji', true);
        $respons->assertJsonPath('status', 'perlu_review');
        $respons->assertJsonPath('ringkasan.total_sel', 60 + 4 + 9);
        $respons->assertJsonPath('ringkasan.hijau', 0);
        $respons->assertJsonPath('ringkasan.merah', 0);
        $respons->assertJsonPath('boleh_auto_isi', true);
        $respons->assertJsonPath('wajib_dicek', true);
        $respons->assertJsonPath('template.versi', 4);
        $respons->assertJsonPath('template.kode_dokumen', 'SIDIK-FM-CAL-0509');
        $respons->assertJsonCount(4, 'isian');
        $respons->assertJsonCount(9, 'centang');

        foreach ($this->semuaButir($respons) as $b) {
            $this->assertContains($b['status'], [ValidasiSel::KUNING, ValidasiSel::KOSONG], "{$b['kunci']} lolos dari batas mode uji.");
        }

        // Centang bukan tulisan tangan — yang menahannya di kuning cuma mode uji.
        $th2 = $this->centangDari($respons, 'thermohygro_standard_id', 'TH-2');
        $this->assertTrue($th2['dicentang']);
        $this->assertSame(ValidasiSel::KUNING, $th2['status']);
        $this->assertContains('template_belum_terverifikasi', $th2['alasan']);

        $scan = WorksheetScan::firstOrFail();
        $this->assertSame(4, $scan->template_versi);
        $this->assertSame('asli', $scan->hasil['kertas']);
        $this->assertTrue($scan->hasil['mode_uji']);
        $this->assertSame(73, $scan->cells()->count());
        $this->assertSame(73, $scan->total_sel);
        // Aturan versi jalur asli terpisah dari jalur cetak, dan sama dengan
        // yang diumumkan template ke HP.
        $this->assertSame(FormulirAsli::aturanVersi(), $scan->aturan_versi);
        $this->assertNotSame(config('ocr.aturan_versi'), $scan->aturan_versi);
        $this->assertSame(0, $this->sesi->rawMeasurements()->count());
    }

    public function test_aturan_versi_template_sama_dengan_yang_dicatat_pindai(): void
    {
        $template = $this->actingAs($this->teknisi)
            ->getJson('/api/worksheet-templates/ph_meter?kertas=asli')
            ->assertOk()
            ->json('data');

        $this->kirim()->assertCreated();

        $this->assertSame($template['aturan_versi'], WorksheetScan::firstOrFail()->aturan_versi);
        // Kolom `worksheet_scans.aturan_versi` string(30) — MySQL strict menolak
        // yang lebih panjang.
        $this->assertLessThanOrEqual(30, strlen(FormulirAsli::aturanVersi()));
        // Mode uji tidak pernah menyalakan siap_pindai.
        $this->assertTrue($template['mode_uji']);
        $this->assertFalse($template['siap_pindai']);
    }

    public function test_hasil_tersimpan_bisa_dibuka_lagi_lengkap_dengan_isian_dan_centang(): void
    {
        $scanId = $this->kirim()->assertCreated()->json('scan_id');

        $this->actingAs($this->teknisi)->getJson(self::URL.'/'.$scanId)
            ->assertOk()
            ->assertJsonPath('kertas', 'asli')
            ->assertJsonPath('mode_uji', true)
            ->assertJsonPath('wajib_dicek', true)
            ->assertJsonCount(4, 'isian')
            ->assertJsonCount(9, 'centang');
    }

    /**
     * Mode uji: lembar yang kosong total pun tetap `perlu_review`, bukan `ok`.
     */
    public function test_mode_uji_tidak_pernah_berstatus_ok(): void
    {
        $payload = $this->payload();

        foreach ($payload['sel'] as $i => $s) {
            $payload['sel'][$i]['teks_mentah'] = '';
        }

        foreach ($payload['isian'] as $i => $s) {
            $payload['isian'][$i]['teks_mentah'] = '';
        }

        foreach ($payload['centang'] as $i => $c) {
            $payload['centang'][$i]['rasio_gelap'] = 0.0;
        }

        // 60 sel + 4 isian kosong; 9 centang kosong naik ke kuning di mode uji.
        $this->kirimMentah($payload)
            ->assertCreated()
            ->assertJsonPath('status', 'perlu_review')
            ->assertJsonPath('ringkasan.kosong', 64)
            ->assertJsonPath('ringkasan.kuning', 9);
    }

    /**
     * Formulir yang sudah terverifikasi (siap_pindai) tanpa mode uji: centang
     * yang jelas boleh hijau, tapi angka tulisan tangan TETAP mentok kuning —
     * termasuk kalau sakelar global `ocr.tulisan_tangan.aktif` dimatikan.
     * Formulir asli selalu diisi tangan; sakelar itu tidak berlaku di sini.
     */
    public function test_siap_tanpa_mode_uji_centang_boleh_hijau_tulisan_tangan_tidak(): void
    {
        $this->fixtureAsli(terverifikasi: true);
        Config::set('ocr.formulir_asli.mode_uji', false);
        Config::set('ocr.tulisan_tangan.aktif', false);

        $respons = $this->kirim()->assertCreated();

        $respons->assertJsonPath('mode_uji', false);
        $this->assertSame(ValidasiSel::HIJAU, $this->centangDari($respons, 'thermohygro_standard_id', 'TH-2')['status']);

        foreach ($this->semuaButir($respons) as $b) {
            if ($b['tabel_id'] === 'centang') {
                continue;
            }

            $this->assertNotSame(ValidasiSel::HIJAU, $b['status'], "{$b['kunci']} tulisan tangan jadi hijau.");
        }
    }

    // ------------------------------------------------------------------
    // Template: kode FM, revisi, kesiapan
    // ------------------------------------------------------------------

    public function test_tanpa_mode_uji_formulir_belum_siap_ditolak(): void
    {
        Config::set('ocr.formulir_asli.mode_uji', false);

        $this->kirim()
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali')
            ->assertJsonPath('fallback_manual', true);

        $scan = WorksheetScan::firstOrFail();
        $this->assertStringContainsString('belum siap dipindai', $scan->pesan);
        $this->assertSame('asli', $scan->hasil['kertas']);
        $this->assertSame(0, $scan->cells()->count());
    }

    public function test_kode_formulir_lain_ditolak(): void
    {
        $this->kirim(['kode_dokumen_terbaca' => 'SIDIK-FM-CAL-0510'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali');

        $this->assertStringContainsString('SIDIK-FM-CAL-0509', WorksheetScan::firstOrFail()->pesan);
    }

    /**
     * Normalisasi cuma spasi & huruf besar — bukan pencocokan longgar.
     */
    public function test_kode_formulir_dinormalisasi_spasi_dan_huruf(): void
    {
        $this->kirim(['kode_dokumen_terbaca' => ' sidik-fm-cal- 0509 '])->assertCreated();
        $this->kirim(['kode_dokumen_terbaca' => 'SIDIK-FM-CAL-0509_Rev.4'])->assertStatus(422);
    }

    public function test_revisi_beda_ditolak(): void
    {
        $this->kirim(['template_versi' => 5])
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali');

        $this->kirim(['revisi_terbaca' => 'Rev. 5'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali');

        $this->kirim(['revisi_terbaca' => 'Rev. 04'])->assertCreated();
    }

    public function test_template_id_lain_ditolak(): void
    {
        $this->kirim(['template_id' => 'turbidimeter'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'template_tidak_dikenali');
    }

    // ------------------------------------------------------------------
    // Geometri jangkar teks
    // ------------------------------------------------------------------

    public function test_jangkar_kurang_dari_delapan_ditolak(): void
    {
        $tujuh = $this->jangkarPerKuadran([2, 2, 2, 1]);
        $this->assertCount(7, $tujuh);

        $this->tolakGeometri(['jangkar_cocok' => $tujuh, 'residual_reproyeksi_pt' => 0.6], 'cuma 7');

        $delapan = $this->jangkarPerKuadran([2, 2, 2, 2]);
        $this->kirim(['geometri' => ['jangkar_cocok' => $delapan, 'residual_reproyeksi_pt' => 0.6]])->assertCreated();
    }

    public function test_jangkar_cuma_tiga_kuadran_ditolak(): void
    {
        $this->tolakGeometri(
            ['jangkar_cocok' => $this->jangkarPerKuadran([10, 10, 10, 0]), 'residual_reproyeksi_pt' => 0.6],
            'menyebar',
        );
    }

    public function test_indeks_jangkar_dobel_ditolak(): void
    {
        $jangkar = $this->jangkar();
        $jangkar[] = $jangkar[0];

        $this->tolakGeometri(['jangkar_cocok' => $jangkar, 'residual_reproyeksi_pt' => 0.6], 'dua kali');
    }

    public function test_indeks_jangkar_di_luar_rentang_ditolak(): void
    {
        $jangkar = $this->jangkar();
        $jangkar[] = ['indeks' => count($jangkar), 'teks_mentah' => 'PT.'];

        $this->tolakGeometri(['jangkar_cocok' => $jangkar, 'residual_reproyeksi_pt' => 0.6], 'nggak ada di formulir');
    }

    public function test_residual_lewat_ambang_ditolak(): void
    {
        $maks = (float) config('ocr.geometri.jangkar_teks.residual_maks_pt');

        $this->tolakGeometri(['jangkar_cocok' => $this->jangkar(), 'residual_reproyeksi_pt' => $maks + 0.01], 'presisi');
        $this->kirim(['geometri' => ['jangkar_cocok' => $this->jangkar(), 'residual_reproyeksi_pt' => $maks]])->assertCreated();
    }

    public function test_residual_tidak_dikirim_ditolak(): void
    {
        $this->tolakGeometri(['jangkar_cocok' => $this->jangkar()], 'Perbarui aplikasi');
    }

    /**
     * Teks yang dibaca HP di posisi jangkar harus = teks tercetak. Yang beda
     * tidak dihitung (bukan ditolak sendirian): 8 cocok + 1 beda tetap lolos,
     * 7 cocok + 1 beda tidak.
     */
    public function test_jangkar_yang_teksnya_beda_tidak_dihitung(): void
    {
        $delapan = $this->jangkarPerKuadran([2, 2, 2, 2]);
        $delapan[0]['teks_mentah'] = 'NGAWUR';

        $this->tolakGeometri(['jangkar_cocok' => $delapan, 'residual_reproyeksi_pt' => 0.6], 'cuma 7');

        $sembilan = $this->jangkarPerKuadran([3, 2, 2, 2]);
        $sembilan[0]['teks_mentah'] = 'NGAWUR';
        // Beda tanda baca & huruf besar-kecil tetap cocok.
        $sembilan[1]['teks_mentah'] = strtolower(str_replace('.', '', $sembilan[1]['teks_mentah'])).' ';

        $this->kirim(['geometri' => ['jangkar_cocok' => $sembilan, 'residual_reproyeksi_pt' => 0.6]])->assertCreated();

        $geometri = WorksheetScan::latest('id')->firstOrFail()->hasil['geometri'];
        $this->assertSame(8, $geometri['jangkar_dihitung']);
        $this->assertSame([$sembilan[0]['indeks']], $geometri['jangkar_teks_beda']);
    }

    // ------------------------------------------------------------------
    // Sel tabel
    // ------------------------------------------------------------------

    public function test_sel_kurang_dobel_asing_menolak_seluruh_lembar(): void
    {
        $payload = $this->payload();
        array_pop($payload['sel']);
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        $payload = $this->payload();
        $payload['sel'][] = $payload['sel'][0];
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        $payload = $this->payload();
        $payload['sel'][0]['tabel_id'] = 'tabel_ngawur';
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');
    }

    /**
     * Titik peta yang tidak jatuh di tepat satu kotak = sel tanpa kotak. Mode
     * uji tidak boleh dipakai untuk membaca sel yang letaknya tidak diketahui.
     */
    public function test_sel_template_tanpa_kotak_menolak_seluruh_lembar(): void
    {
        $this->fixtureAsli(terverifikasi: false, ubahPeta: function (array $peta): array {
            $peta['sel'][0]['x'] = 0.001;
            $peta['sel'][0]['y'] = 0.001;

            return $peta;
        });

        $this->kirim()
            ->assertStatus(422)
            ->assertJsonPath('status', 'mapping_gagal');

        $this->assertStringContainsString('kotak', WorksheetScan::firstOrFail()->pesan);
    }

    // ------------------------------------------------------------------
    // Isian kondisi lingkungan
    // ------------------------------------------------------------------

    public function test_isian_hilang_dobel_asing_menolak_seluruh_lembar(): void
    {
        $payload = $this->payload();
        array_pop($payload['isian']);
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        $payload = $this->payload();
        $payload['isian'][] = $payload['isian'][0];
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        // Identitas belum dibuka di tahap 1 (K2).
        $payload = $this->payload();
        $payload['isian'][] = $this->isian('pemilik_nama', 'PT Contoh');
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');
    }

    /**
     * Rentang yang sudah ada di kode, bukan karangan: suhu ruang kerja
     * `ocr.suhu` 5–45 °C, kelembaban `CalibrationValidator` 20–90 %RH.
     */
    public function test_isian_di_luar_rentang_merah_dan_kosong_boleh(): void
    {
        $payload = $this->payload();
        $payload['isian'] = [
            $this->isian('suhu_awal', '250'),
            $this->isian('kelembaban_awal', '5'),
            $this->isian('suhu_akhir', ''),
            $this->isian('kelembaban_akhir', '91'),
        ];

        $respons = $this->kirimMentah($payload)->assertCreated();

        $isian = collect($respons->json('isian'))->keyBy('kode');
        $this->assertSame(ValidasiSel::MERAH, $isian['suhu_awal']['status']);
        $this->assertContains('di_luar_rentang', $isian['suhu_awal']['alasan']);
        $this->assertSame(ValidasiSel::MERAH, $isian['kelembaban_awal']['status']);
        $this->assertSame(ValidasiSel::MERAH, $isian['kelembaban_akhir']['status']);
        $this->assertSame(ValidasiSel::KOSONG, $isian['suhu_akhir']['status']);
        $respons->assertJsonPath('boleh_auto_isi', false);
    }

    public function test_isian_wajar_tulisan_tangan_kuning_dengan_nilai(): void
    {
        $isian = collect($this->kirim()->assertCreated()->json('isian'))->keyBy('kode');

        $this->assertSame(ValidasiSel::KUNING, $isian['kelembaban_awal']['status']);
        $this->assertEqualsWithDelta(55.0, $isian['kelembaban_awal']['nilai'], 1e-9);
        $this->assertEqualsWithDelta(25.0, $isian['suhu_awal']['nilai'], 1e-9);
        $this->assertSame('%RH', $isian['kelembaban_awal']['satuan']);
    }

    // ------------------------------------------------------------------
    // Centang
    // ------------------------------------------------------------------

    public function test_centang_ambang_dicentang_kosong_dan_ragu(): void
    {
        $tercentang = (float) config('ocr.centang.ambang_tercentang');
        $kosong = (float) config('ocr.centang.ambang_kosong');

        $this->assertLessThan($tercentang, $kosong);

        $respons = $this->kirim(['centang' => $this->centang([
            'TH-2' => $tercentang,
            'TH-6' => $kosong,
            'TH-7' => ($tercentang + $kosong) / 2,
            'TH-4' => 0.0,
        ])])->assertCreated();

        $th2 = $this->centangDari($respons, 'thermohygro_standard_id', 'TH-2');
        $th6 = $this->centangDari($respons, 'thermohygro_standard_id', 'TH-6');
        $th7 = $this->centangDari($respons, 'thermohygro_standard_id', 'TH-7');

        $this->assertTrue($th2['dicentang']);
        $this->assertSame(1.0, (float) $th2['nilai']);
        $this->assertFalse($th6['dicentang']);
        $this->assertSame(0.0, (float) $th6['nilai']);
        // Mode uji: kosong tetap terbaca kosong, tapi wajib dilihat.
        $this->assertSame(ValidasiSel::KUNING, $th6['status']);
        $this->assertContains(PemrosesScanLembarKerja::ALASAN_MODE_UJI, $th6['alasan']);
        $this->assertNull($th7['dicentang']);
        $this->assertNull($th7['nilai']);
        $this->assertSame(ValidasiSel::KUNING, $th7['status']);
        $this->assertContains('centang_ragu', $th7['alasan']);
    }

    public function test_thermohygro_lebih_dari_satu_dicentang_merah(): void
    {
        $respons = $this->kirim(['centang' => $this->centang(['TH-2' => 0.5, 'TH-6' => 0.5])])->assertCreated();

        foreach (['TH-2', 'TH-6'] as $pilihan) {
            $c = $this->centangDari($respons, 'thermohygro_standard_id', $pilihan);
            $this->assertSame(ValidasiSel::MERAH, $c['status']);
            $this->assertContains('pilihan_ganda', $c['alasan']);
            $this->assertStringContainsString('TH-2', $c['pesan']);
        }

        $respons->assertJsonPath('boleh_auto_isi', false);
    }

    public function test_thermohygro_nol_dicentang_kosong_semua(): void
    {
        $respons = $this->kirim(['centang' => $this->centang(['TH-2' => 0.0])])->assertCreated();

        foreach (['TH-2', 'TH-6', 'TH-7', 'TH-4'] as $pilihan) {
            $c = $this->centangDari($respons, 'thermohygro_standard_id', $pilihan);
            $this->assertFalse($c['dicentang']);
            $this->assertSame(ValidasiSel::KUNING, $c['status']);
            $this->assertContains(PemrosesScanLembarKerja::ALASAN_MODE_UJI, $c['alasan']);
        }

        $respons->assertJsonPath('ringkasan.merah', 0);
    }

    /**
     * Di luar mode uji (formulir sudah terverifikasi), centang yang jelas
     * kosong tetap KOSONG — naik ke kuning itu aturan mode uji saja.
     */
    public function test_centang_kosong_tanpa_mode_uji_tetap_kosong(): void
    {
        $this->fixtureAsli(terverifikasi: true);
        Config::set('ocr.formulir_asli.mode_uji', false);

        $respons = $this->kirim(['centang' => $this->centang(['TH-2' => 0.0])])->assertCreated();

        foreach (['TH-2', 'TH-6', 'TH-7', 'TH-4'] as $pilihan) {
            $c = $this->centangDari($respons, 'thermohygro_standard_id', $pilihan);
            $this->assertSame(ValidasiSel::KOSONG, $c['status']);
            $this->assertNotContains(PemrosesScanLembarKerja::ALASAN_MODE_UJI, $c['alasan']);
        }
    }

    /**
     * Usage Check per baris tercetak: beberapa baris boleh dicentang bersamaan,
     * dan tiap baris dikenali dari `baris_ke`, bukan urutan kiriman.
     */
    public function test_usage_check_per_baris_dan_urutan_tidak_ngaruh(): void
    {
        $centang = array_reverse($this->centang([1 => 0.5, 2 => 0.0, 3 => 0.5, 4 => 0.0, 5 => 0.5]));

        $respons = $this->kirim(['centang' => $centang])->assertCreated();

        $this->assertTrue($this->centangDari($respons, 'standar_dicek.*.dipakai', null, 1)['dicentang']);
        $this->assertFalse($this->centangDari($respons, 'standar_dicek.*.dipakai', null, 2)['dicentang']);
        $this->assertTrue($this->centangDari($respons, 'standar_dicek.*.dipakai', null, 5)['dicentang']);
        $respons->assertJsonPath('ringkasan.merah', 0);
    }

    public function test_rasio_centang_tidak_dikirim_merah(): void
    {
        $centang = $this->centang();
        $centang[0]['rasio_gelap'] = null;

        $c = collect($this->kirim(['centang' => $centang])->assertCreated()->json('centang'))
            ->first(fn (array $c): bool => $c['baris_ke'] === $centang[0]['baris_ke'] && $c['pilihan'] === $centang[0]['pilihan']);

        $this->assertSame(ValidasiSel::MERAH, $c['status']);
    }

    public function test_centang_hilang_dobel_asing_menolak_seluruh_lembar(): void
    {
        $centang = $this->centang();
        array_pop($centang);
        $this->kirim(['centang' => $centang])->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        $centang = $this->centang();
        $centang[] = $centang[0];
        $this->kirim(['centang' => $centang])->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        $centang = $this->centang();
        $centang[] = ['kode' => 'thermohygro_standard_id', 'pilihan' => 'TH-9', 'baris_ke' => null, 'rasio_gelap' => 0.0];
        $this->kirim(['centang' => $centang])->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');

        // Baris yang tidak tercetak.
        $centang = $this->centang();
        $centang[] = ['kode' => 'standar_dicek.*.dipakai', 'pilihan' => null, 'baris_ke' => 6, 'rasio_gelap' => 0.0];
        $this->kirim(['centang' => $centang])->assertStatus(422)->assertJsonPath('status', 'mapping_gagal');
    }

    // ------------------------------------------------------------------
    // Multipart
    // ------------------------------------------------------------------

    /**
     * Multipart tidak bertipe: boolean & angka sampai sebagai string. `"0"`
     * pada kolom boolean BARU (`isian.*.kotak_teks_di_dalam_sel`) wajib tetap
     * dibaca false — kalau tidak, angka yang meluber lolos tanpa ditandai.
     */
    public function test_multipart_boolean_string_di_kolom_baru(): void
    {
        $payload = $this->payload();
        $payload['template_versi'] = '4';
        $payload['geometri']['residual_reproyeksi_pt'] = '0.6';

        foreach ($payload['geometri']['jangkar_cocok'] as $i => $j) {
            $payload['geometri']['jangkar_cocok'][$i]['indeks'] = (string) $j['indeks'];
        }

        foreach ($payload['sel'] as $i => $s) {
            $payload['sel'][$i]['kotak_teks_di_dalam_sel'] = '1';
        }

        foreach ($payload['isian'] as $i => $s) {
            $payload['isian'][$i]['kotak_teks_di_dalam_sel'] = '1';
        }

        $payload['isian'][0]['kotak_teks_di_dalam_sel'] = '0';

        foreach ($payload['centang'] as $i => $c) {
            $payload['centang'][$i]['rasio_gelap'] = (string) $c['rasio_gelap'];
            $payload['centang'][$i]['baris_ke'] = $c['baris_ke'] === null ? '' : (string) $c['baris_ke'];
            $payload['centang'][$i]['pilihan'] = $c['pilihan'] ?? '';
        }

        $respons = $this->actingAs($this->teknisi)->post(self::URL, $payload)->assertCreated();

        $respons->assertJsonPath('ringkasan.total_sel', 73);
        $respons->assertJsonPath('ringkasan.merah', 1);
        $isian = collect($respons->json('isian'))->keyBy('kode');
        $this->assertContains('teks_meluber_dari_sel', $isian[$payload['isian'][0]['kode']]['alasan']);
        $this->assertTrue($this->centangDari($respons, 'thermohygro_standard_id', 'TH-2')['dicentang']);
    }

    // ------------------------------------------------------------------
    // Kontrak & jalur cetak
    // ------------------------------------------------------------------

    public function test_bentuk_kiriman_asli_divalidasi(): void
    {
        $this->kirim(['kode_dokumen_terbaca' => null])->assertStatus(422)->assertJsonValidationErrors('kode_dokumen_terbaca');
        $this->kirim(['kertas' => 'foto'])->assertStatus(422)->assertJsonValidationErrors('kertas');

        $payload = $this->payload();
        $payload['isian'] = array_fill(0, 21, $this->isian('suhu_awal', '25'));
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonValidationErrors('isian');

        $payload = $this->payload();
        $payload['centang'][0]['rasio_gelap'] = 1.5;
        $this->kirimMentah($payload)->assertStatus(422)->assertJsonValidationErrors('centang.0.rasio_gelap');

        $this->assertSame(0, WorksheetScan::count());
    }

    /**
     * QR tetap wajib di jalur cetak — termasuk kalau `kertas=cetak` ditulis
     * eksplisit — dengan pesan yang sama.
     */
    public function test_jalur_cetak_tetap_wajib_qr(): void
    {
        $payload = $this->payload(['kertas' => 'cetak', 'template_versi' => 1]);
        unset($payload['qr']);

        $this->kirimMentah($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qr' => 'Status pembacaan QR wajib dikirim.']);
    }

    // ------------------------------------------------------------------
    // Crop & koreksi
    // ------------------------------------------------------------------

    /**
     * Kotak formulir asli ternormal 0..1, jadi potongannya = kotak × ukuran
     * citra warp (halaman utuh yang sudah diratakan).
     */
    public function test_crop_sel_isian_dan_centang_formulir_asli(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD nggak ada — endpoint crop-nya emang nolak duluan di server begini.');
        }

        Storage::fake('local');

        $respons = $this->actingAs($this->teknisi)->post(self::URL, [
            ...$this->payload(),
            'citra_warp' => UploadedFile::fake()->image('warp.jpg', 1584, 1224),
        ])->assertCreated();
        $scanId = $respons->json('scan_id');

        $kotak = $this->template()['sel']['sebelum_adjustment|1|1|pembacaan']['kotak'];

        $isi = $this->actingAs($this->teknisi)
            ->get(self::URL."/{$scanId}/sel/".rawurlencode('sebelum_adjustment|1|1|pembacaan').'/crop')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->getContent();

        $gambar = imagecreatefromstring($isi);
        $this->assertEqualsWithDelta($kotak['w'] * 1584 + 12, imagesx($gambar), 2);
        $this->assertEqualsWithDelta($kotak['h'] * 1224 + 12, imagesy($gambar), 2);

        $kunciIsian = collect($respons->json('isian'))->firstWhere('kode', 'suhu_awal')['kunci'];
        $kunciCentang = $this->centangDari($respons, 'thermohygro_standard_id', 'TH-2')['kunci'];

        foreach ([$kunciIsian, $kunciCentang] as $kunci) {
            $this->actingAs($this->teknisi)
                ->get(self::URL."/{$scanId}/sel/".rawurlencode($kunci).'/crop')
                ->assertOk();
        }
    }

    /**
     * Citra yang bukan halaman lanskap utuh tidak dipotong: kotak ternormalnya
     * akan jatuh di tempat yang salah tanpa gejala.
     */
    public function test_crop_formulir_asli_menolak_citra_yang_bukan_halaman_utuh(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD nggak ada.');
        }

        Storage::fake('local');

        $scanId = $this->actingAs($this->teknisi)->post(self::URL, [
            ...$this->payload(),
            'citra_warp' => UploadedFile::fake()->image('warp.jpg', 1654, 2339),
        ])->assertCreated()->json('scan_id');

        $this->actingAs($this->teknisi)
            ->get(self::URL."/{$scanId}/sel/".rawurlencode('sebelum_adjustment|1|1|pembacaan').'/crop')
            ->assertStatus(422);
    }

    public function test_koreksi_sel_isian_dan_centang(): void
    {
        $respons = $this->kirim()->assertCreated();
        $scanId = $respons->json('scan_id');
        $kunciIsian = collect($respons->json('isian'))->firstWhere('kode', 'kelembaban_awal')['kunci'];
        $kunciCentang = $this->centangDari($respons, 'thermohygro_standard_id', 'TH-2')['kunci'];

        $this->actingAs($this->teknisi)->postJson(self::URL."/{$scanId}/koreksi", [
            'koreksi' => [
                ['kunci' => 'sebelum_adjustment|1|1|pembacaan', 'nilai_final' => 4.01],
                ['kunci' => $kunciIsian, 'nilai_final' => 57],
                ['kunci' => $kunciCentang, 'nilai_final' => 1],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.tercatat', 3)
            ->assertJsonPath('data.cocok', 2)
            ->assertJsonPath('data.meleset', 1)
            ->assertJsonPath('data.kunci_tidak_dikenal', []);

        // Centang cuma 0 atau 1.
        $this->actingAs($this->teknisi)->postJson(self::URL."/{$scanId}/koreksi", [
            'koreksi' => [['kunci' => $kunciCentang, 'nilai_final' => 0.5]],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Akurasi
    // ------------------------------------------------------------------

    /**
     * `ocr:akurasi` tidak mencampur formulir asli dengan lembar cetak: bawaan =
     * cetak (arti lamanya), `--kertas=asli` = formulir asli saja.
     */
    public function test_akurasi_dipisah_per_kertas(): void
    {
        $respons = $this->kirim()->assertCreated();
        $scanId = $respons->json('scan_id');
        $kunciIsian = collect($respons->json('isian'))->firstWhere('kode', 'suhu_awal')['kunci'];

        $this->actingAs($this->teknisi)->postJson(self::URL."/{$scanId}/koreksi", [
            'koreksi' => [
                ['kunci' => 'sebelum_adjustment|1|1|pembacaan', 'nilai_final' => 4.01],
                ['kunci' => 'sebelum_adjustment|1|2|pembacaan', 'nilai_final' => 4.01],
                ['kunci' => $kunciIsian, 'nilai_final' => 25.0],
            ],
        ])->assertOk();

        // Satu sel lembar CETAK yang sudah dikoreksi.
        $cetak = WorksheetScan::factory()->create(['hasil' => ['tabel' => [], 'ringkasan' => []]]);
        $cetak->cells()->create([
            'kunci' => 'sebelum_adjustment|1|1|pembacaan', 'tabel_id' => 'sebelum_adjustment',
            'baris_ke' => 1, 'repeat_no' => 1, 'field_id' => 'pembacaan', 'status' => ValidasiSel::KUNING,
            'nilai' => 4.01, 'nilai_final' => 4.01, 'cocok' => true, 'dikoreksi_pada' => now(),
        ]);

        $this->assertSame(0, Artisan::call('ocr:akurasi'));
        $this->assertStringContainsString(': 1 sel', Artisan::output());

        $this->assertSame(0, Artisan::call('ocr:akurasi', ['--kertas' => 'asli']));
        $keluaran = Artisan::output();
        $this->assertStringContainsString(': 3 sel', $keluaran);
        $this->assertStringContainsString('isian / suhu_awal', $keluaran);

        $this->assertSame(1, Artisan::call('ocr:akurasi', ['--kertas' => 'ngawur']));

        // Jalur kamera tabel tidak membaca worksheet_scans sama sekali.
        $this->assertSame(0, Artisan::call('ocr:akurasi-kamera'));
    }

    // ------------------------------------------------------------------
    // Akses
    // ------------------------------------------------------------------

    public function test_organisasi_lain_tidak_bisa_membaca_pindai_asli(): void
    {
        $scanId = $this->kirim()->assertCreated()->json('scan_id');

        $labLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $labLain->id]);

        $this->actingAs($adminLain)->getJson(self::URL."/{$scanId}")->assertNotFound();
        $this->actingAs($adminLain)
            ->get(self::URL."/{$scanId}/sel/".rawurlencode('sebelum_adjustment|1|1|pembacaan').'/crop')
            ->assertNotFound();
        $this->actingAs($adminLain)->postJson(self::URL."/{$scanId}/koreksi", [
            'koreksi' => [['kunci' => 'sebelum_adjustment|1|1|pembacaan', 'nilai_final' => 4.0]],
        ])->assertNotFound();

        $this->assertNull(WorksheetScan::findOrFail($scanId)->cells()->where('kunci', 'sebelum_adjustment|1|1|pembacaan')->value('dikoreksi_pada'));
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return app(FormulirAsli::class)->untukKode('ph_meter', $this->sesi->equipment);
    }

    /**
     * Kiriman HP yang sehat: semua jangkar cocok, semua sel kebaca, Env.
     * wajar, centang Usage Check 1–3 + TH-2.
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function payload(array $ganti = []): array
    {
        $sel = [];

        foreach ($this->template()['sel'] as $d) {
            $sel[] = [
                'tabel_id' => $d['tabel_id'],
                'baris_ke' => $d['baris_ke'],
                'repeat_no' => $d['repeat_no'],
                'field_id' => $d['field_id'],
                'teks_mentah' => $d['field_id'] === 'suhu'
                    ? '25,0'
                    : number_format((float) $d['titik_ukur'] + 0.01, 2, ',', ''),
                'confidence_ocr' => 0.97,
                'kotak_teks_di_dalam_sel' => true,
                'titik_ukur' => $d['titik_ukur'],
            ];
        }

        return array_merge([
            'kertas' => 'asli',
            'template_id' => 'ph_meter',
            'template_versi' => 4,
            'kode_dokumen_terbaca' => 'SIDIK-FM-CAL-0509',
            'revisi_terbaca' => '4',
            'calibration_session_id' => $this->sesi->id,
            'geometri' => [
                'jangkar_cocok' => $this->jangkar(),
                'residual_reproyeksi_pt' => 0.6,
            ],
            'kualitas' => [
                'blur_laplacian' => 240.0,
                'kecerahan_rata' => 150.0,
                'rasio_glare' => 0.01,
                'sudut_kemiringan_deg' => 1.2,
                'px_per_sel_tinggi' => 48,
            ],
            'perangkat' => ['model' => 'Redmi Note 12', 'os' => 'Android 13', 'app' => '1.5.0', 'ocr' => 'mlkit-v2'],
            'sel' => $sel,
            'isian' => [
                $this->isian('suhu_awal', '25,0'),
                $this->isian('kelembaban_awal', '55'),
                $this->isian('suhu_akhir', '25,4'),
                $this->isian('kelembaban_akhir', '56'),
            ],
            'centang' => $this->centang(),
        ], $ganti);
    }

    /**
     * @return array<string, mixed>
     */
    private function isian(string $kode, string $teks): array
    {
        return [
            'kode' => $kode,
            'teks_mentah' => $teks,
            'confidence_ocr' => 0.97,
            'kotak_teks_di_dalam_sel' => true,
            'sumber' => 'mlkit',
        ];
    }

    /**
     * Sembilan centang template. `$rasio` menimpa per `pilihan` (TH-n) atau per
     * `baris_ke` (Usage Check). Bawaan: Usage Check 1–3 dicentang, TH-2 dicentang.
     *
     * @param  array<int|string, float|null>  $rasio
     * @return list<array<string, mixed>>
     */
    private function centang(array $rasio = []): array
    {
        $bawaan = [1 => 0.4, 2 => 0.4, 3 => 0.4, 4 => 0.01, 5 => 0.01,
            'TH-2' => 0.4, 'TH-6' => 0.0, 'TH-7' => 0.0, 'TH-4' => 0.0];
        $rasio = array_replace($bawaan, $rasio);
        $hasil = [];

        foreach ($this->template()['centang'] as $c) {
            $hasil[] = [
                'kode' => $c['kode'],
                'pilihan' => $c['pilihan'],
                'baris_ke' => $c['baris_ke'],
                'rasio_gelap' => $rasio[$c['pilihan'] ?? $c['baris_ke']],
            ];
        }

        return $hasil;
    }

    /**
     * Semua jangkar teks template, teksnya persis tercetak.
     *
     * @return list<array{indeks: int, teks_mentah: string}>
     */
    private function jangkar(): array
    {
        $hasil = [];

        foreach ($this->template()['geometri']['jangkar_teks'] as $i => $j) {
            $hasil[] = ['indeks' => $i, 'teks_mentah' => $j['teks']];
        }

        return $hasil;
    }

    /**
     * Ambil N jangkar per kuadran [kiri-atas, kanan-atas, kiri-bawah, kanan-bawah].
     * Kuadran dihitung di sini sendiri dari pusat kotak, tidak meminjam kode
     * yang diuji.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $jumlah
     * @return list<array{indeks: int, teks_mentah: string}>
     */
    private function jangkarPerKuadran(array $jumlah): array
    {
        $hasil = [];
        $terambil = [0, 0, 0, 0];

        foreach ($this->template()['geometri']['jangkar_teks'] as $i => $j) {
            $k = $j['kotak'];
            $q = ($k['x'] + $k['w'] / 2 >= 0.5 ? 1 : 0) + ($k['y'] + $k['h'] / 2 >= 0.5 ? 2 : 0);

            if ($terambil[$q] < $jumlah[$q]) {
                $hasil[] = ['indeks' => $i, 'teks_mentah' => $j['teks']];
                $terambil[$q]++;
            }
        }

        $this->assertSame($jumlah, $terambil, 'Template tidak punya cukup jangkar di kuadran yang diminta.');

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $ganti
     */
    private function kirim(array $ganti = []): TestResponse
    {
        return $this->kirimMentah($this->payload($ganti));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function kirimMentah(array $payload): TestResponse
    {
        return $this->actingAs($this->teknisi)->postJson(self::URL, $payload);
    }

    /**
     * @param  array<string, mixed>  $geometri
     */
    private function tolakGeometri(array $geometri, string $kutipan): void
    {
        $this->kirim(['geometri' => $geometri])
            ->assertStatus(422)
            ->assertJsonPath('status', 'geometri_meragukan');

        $this->assertStringContainsString($kutipan, WorksheetScan::latest('id')->firstOrFail()->pesan);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function semuaButir(TestResponse $respons): array
    {
        $butir = [];

        foreach ($respons->json('tabel') as $tabel) {
            foreach ($tabel['baris'] as $baris) {
                foreach ($baris['pengulangan'] as $p) {
                    foreach ($p['kolom'] as $sel) {
                        $butir[] = $sel;
                    }
                }
            }
        }

        $this->assertCount(60, $butir);

        return [...$butir, ...$respons->json('isian'), ...$respons->json('centang')];
    }

    /**
     * @return array<string, mixed>
     */
    private function centangDari(TestResponse $respons, string $kode, ?string $pilihan, ?int $barisKe = null): array
    {
        $c = collect($respons->json('centang'))->first(
            fn (array $c): bool => $c['kode'] === $kode && $c['pilihan'] === $pilihan && $c['baris_ke'] === $barisKe,
        );

        $this->assertNotNull($c, "Centang {$kode} {$pilihan} {$barisKe} tidak ada di respons.");

        return $c;
    }

    /**
     * Salin draf & peta pH ke folder sementara dengan flag `terverifikasi`
     * yang disetel — berkas produksinya tidak pernah disentuh.
     */
    private function fixtureAsli(bool $terverifikasi, ?callable $ubahPeta = null): void
    {
        $this->folderFixture ??= storage_path('framework/testing/ocr-pindai-asli-'.getmypid());
        @mkdir($this->folderFixture.'/asli', 0755, true);

        $draf = json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.draf.json')), true);
        $peta = json_decode((string) file_get_contents(database_path('ocr-templates/asli/ph_meter-0509.peta.json')), true);
        $draf['terverifikasi'] = $terverifikasi;
        $peta['terverifikasi'] = $terverifikasi;
        $peta = $ubahPeta !== null ? $ubahPeta($peta) : $peta;

        file_put_contents($this->folderFixture.'/asli/ph_meter-0509.draf.json', json_encode($draf));
        file_put_contents($this->folderFixture.'/asli/ph_meter-0509.peta.json', json_encode($peta));

        Config::set('ocr.folder_template', $this->folderFixture);
    }
}
