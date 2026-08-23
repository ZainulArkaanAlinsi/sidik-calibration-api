<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tanda tangan penanda tangan sertifikat (fase-2 §3c, keputusan 26 Jul).
 *
 * Keputusan produknya: **kosong dulu**, lalu ada fitur unggah + drag-and-drop. Yang
 * dibangun di sini separuh backend-nya — unggah gambar, simpan posisi, cetak ke PDF.
 *
 * Tiga hal yang paling dijaga, dan ketiganya keputusan yang gampang salah:
 *
 * 1. **Posisi disimpen di TEMPLATE, bukan per sertifikat.** Sertifikat terbit itu
 *    dokumen terkendali; kalau posisinya per sertifikat, dokumen yang udah dikirim ke
 *    pelanggan bisa diubah, dan dua orang bisa punya PDF beda dengan nomor yang sama.
 * 2. **Gambarnya di disk PRIVAT.** Gambar tanda tangan yang URL-nya bisa diakses
 *    siapa pun berarti siapa pun bisa nempelin ke dokumen palsu.
 * 3. **PNG doang.** JPG nggak punya alpha → kecetak jadi kotak putih yang nutupin
 *    garis tanda tangan & nama. Rusak, tapi rusaknya sunyi.
 */
class TandaTanganSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/organization/tanda-tangan';

    private User $admin;

    private User $teknisi;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    /** PNG placeholder — file asli tinggal di-swap, logika posisinya nggak berubah. */
    private function pngPalsu(string $nama = 'ttd.png'): UploadedFile
    {
        return UploadedFile::fake()->image($nama, 300, 120);
    }

    private function unggah(): void
    {
        $this->actingAs($this->admin)
            ->post(self::URL, ['tanda_tangan' => $this->pngPalsu()])
            ->assertOk();
    }

    // ---------------------------------------------------------------- unggah

    public function test_admin_bisa_unggah_tanda_tangan(): void
    {
        $this->actingAs($this->admin)
            ->post(self::URL, ['tanda_tangan' => $this->pngPalsu()])
            ->assertOk()
            ->assertJsonPath('data.punya_tanda_tangan', true);

        $path = $this->org->fresh()->tanda_tangan_path;

        $this->assertNotNull($path);
        Storage::disk('arsip')->assertExists($path);
    }

    /**
     * Disimpen di disk PRIVAT, dan responsnya nggak bawa URL.
     *
     * Ini beda dari logo yang di disk publik, dan bedanya disengaja: gambar tanda
     * tangan yang bisa diakses lewat URL berarti siapa pun bisa nempelin ke dokumen
     * palsu.
     */
    public function test_disimpen_di_disk_privat_dan_nggak_ada_url_di_respons(): void
    {
        $res = $this->actingAs($this->admin)
            ->post(self::URL, ['tanda_tangan' => $this->pngPalsu()])
            ->assertOk();

        $path = $this->org->fresh()->tanda_tangan_path;

        Storage::disk('arsip')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        // Nggak ada field URL sama sekali — cuma penandanya.
        $data = $res->json('data');
        $this->assertArrayHasKey('punya_tanda_tangan', $data);
        $this->assertArrayNotHasKey('tanda_tangan_url', $data);
        $this->assertStringNotContainsString($path, json_encode($data));
    }

    /**
     * JPG DITOLAK, walaupun logo nerima JPG.
     *
     * JPG nggak punya alpha channel: tanda tangan JPG kecetak sebagai kotak putih
     * yang nutupin garis tanda tangan & nama di bawahnya. Rusak, tapi rusaknya sunyi
     * — nggak ada error, ketahuannya baru waktu ada yang buka PDF-nya.
     */
    public function test_jpg_ditolak_karena_nggak_punya_latar_transparan(): void
    {
        $res = $this->actingAs($this->admin)
            ->post(self::URL, ['tanda_tangan' => UploadedFile::fake()->image('ttd.jpg')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanda_tangan');

        $this->assertStringContainsString(
            'transparan',
            (string) $res->json('errors.tanda_tangan.0'),
            'Pesannya harus nyebut alasannya, bukan cuma "format salah".',
        );
    }

    public function test_file_kegedean_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post(self::URL, [
                'tanda_tangan' => UploadedFile::fake()->create('ttd.png', 3000, 'image/png'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanda_tangan');
    }

    public function test_tanpa_file_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanda_tangan');
    }

    /** Unggah baru ngehapus yang lama — SESUDAH yang baru kesimpen. */
    public function test_unggah_baru_ngehapus_yang_lama(): void
    {
        $this->unggah();
        $pertama = $this->org->fresh()->tanda_tangan_path;

        $this->actingAs($this->admin)
            ->post(self::URL, ['tanda_tangan' => $this->pngPalsu('ttd-baru.png')])
            ->assertOk();

        $kedua = $this->org->fresh()->tanda_tangan_path;

        $this->assertNotSame($pertama, $kedua);
        Storage::disk('arsip')->assertMissing($pertama);
        Storage::disk('arsip')->assertExists($kedua);
    }

    public function test_hapus_tanda_tangan(): void
    {
        $this->unggah();
        $path = $this->org->fresh()->tanda_tangan_path;

        $this->actingAs($this->admin)
            ->deleteJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.punya_tanda_tangan', false);

        Storage::disk('arsip')->assertMissing($path);
        $this->assertNull($this->org->fresh()->tanda_tangan_path);
    }

    // -------------------------------------------------------------- pratinjau

    public function test_pratinjau_ngirim_gambarnya(): void
    {
        $this->unggah();

        $res = $this->actingAs($this->admin)->get(self::URL)->assertOk();

        $this->assertSame('image/png', $res->headers->get('content-type'));
    }

    public function test_pratinjau_404_kalau_belum_ada(): void
    {
        $this->actingAs($this->admin)->getJson(self::URL)->assertNotFound();
    }

    // ------------------------------------------------------------ hak akses

    /** Teknisi & viewer nggak boleh nyentuh sama sekali — permintaan user 26 Jul. */
    public function test_teknisi_dan_viewer_ditolak_di_semua_operasi(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        foreach ([$this->teknisi, $viewer] as $orang) {
            $this->actingAs($orang)->post(self::URL, ['tanda_tangan' => $this->pngPalsu()])->assertForbidden();
            $this->actingAs($orang)->getJson(self::URL)->assertForbidden();
            $this->actingAs($orang)->deleteJson(self::URL)->assertForbidden();
            $this->actingAs($orang)->patchJson(self::URL.'/posisi', ['lebar_mm' => 30])->assertForbidden();
        }

        $this->assertNull($this->org->fresh()->tanda_tangan_path);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson(self::URL, [])->assertUnauthorized();
        $this->getJson(self::URL)->assertUnauthorized();
    }

    // ---------------------------------------------------------------- posisi

    public function test_bawaan_posisi_kalau_belum_disetel(): void
    {
        $this->assertSame(
            ['geser_x_mm' => 0.0, 'geser_y_mm' => 0.0, 'lebar_mm' => 35.0],
            $this->org->pengaturanTandaTangan(),
        );
    }

    public function test_admin_bisa_setel_posisi_dan_lebar(): void
    {
        $this->actingAs($this->admin)
            ->patchJson(self::URL.'/posisi', [
                'geser_x_mm' => -8.5,
                'geser_y_mm' => 4,
                'lebar_mm' => 42,
            ])
            ->assertOk()
            ->assertJsonPath('data.tanda_tangan.geser_x_mm', -8.5)
            ->assertJsonPath('data.tanda_tangan.geser_y_mm', 4)
            ->assertJsonPath('data.tanda_tangan.lebar_mm', 42);
    }

    /**
     * Setelan lain di `settings` NGGAK ilang waktu posisi disetel.
     *
     * `settings` juga nyimpen penandatangan, kode dokumen, ambang reminder, masa
     * berlaku sertifikat. Nimpa seluruh array bikin semuanya ilang sekali klik — dan
     * yang paling parah, masa berlaku sertifikat balik ke default tanpa ada yang
     * sadar.
     */
    public function test_setelan_lain_nggak_keinjek(): void
    {
        $this->org->update(['settings' => [
            'penandatangan_nama' => 'Alex Misramto',
            'masa_berlaku_sertifikat_bulan' => 6,
        ]]);

        $this->actingAs($this->admin)
            ->patchJson(self::URL.'/posisi', ['lebar_mm' => 40])
            ->assertOk();

        $settings = $this->org->fresh()->settings;

        $this->assertSame('Alex Misramto', $settings['penandatangan_nama']);
        $this->assertSame(6, $settings['masa_berlaku_sertifikat_bulan']);

        // Nilai mentahnya di kolom JSON dibandingin longgar: `40.0` yang lewat JSON
        // kebaca balik jadi `40`, dan itu nggak penting. Yang jadi KONTRAK keluaran
        // `pengaturanTandaTangan()` — di situ tipenya dijaga ketat.
        $this->assertEquals(40, $settings['ttd_lebar_mm']);
        $this->assertSame(40.0, $this->org->fresh()->pengaturanTandaTangan()['lebar_mm']);
    }

    public function test_null_balikin_ke_bawaan(): void
    {
        $this->actingAs($this->admin)->patchJson(self::URL.'/posisi', ['lebar_mm' => 60])->assertOk();

        $this->actingAs($this->admin)
            ->patchJson(self::URL.'/posisi', ['lebar_mm' => null])
            ->assertOk()
            ->assertJsonPath('data.tanda_tangan.lebar_mm', 35);

        $this->assertArrayNotHasKey('ttd_lebar_mm', $this->org->fresh()->settings ?? []);
    }

    public function test_nilai_di_luar_batas_ditolak(): void
    {
        foreach ([
            ['lebar_mm' => 5],
            ['lebar_mm' => 200],
            ['geser_x_mm' => 100],
            ['geser_y_mm' => -100],
        ] as $ngawur) {
            $this->actingAs($this->admin)
                ->patchJson(self::URL.'/posisi', $ngawur)
                ->assertStatus(422);
        }
    }

    /**
     * Nilai ngawur yang MASUK LEWAT JALUR LAIN tetap dibatasin.
     *
     * `PUT /organization` nerima `settings` sebagai array bebas, dan panel Filament
     * juga nulis ke situ. Kalau pembatasannya cuma di validasi request endpoint ini,
     * nilai ngawur dari jalur lain bikin tanda tangan kecetak di luar halaman — dan
     * yang lihat cuma tahu sertifikatnya rusak, bukan kenapa.
     */
    public function test_nilai_ngawur_dari_jalur_lain_tetap_dibatasin(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/organization', [
                'settings' => ['ttd_lebar_mm' => 9999, 'ttd_geser_x_mm' => -9999],
            ])
            ->assertOk();

        $posisi = $this->org->fresh()->pengaturanTandaTangan();

        $this->assertSame((float) Organization::MAKS_TTD_LEBAR_MM, $posisi['lebar_mm']);
        $this->assertSame(-1.0 * Organization::MAKS_TTD_GESER_MM, $posisi['geser_x_mm']);
    }

    // ------------------------------------------------------- kecetak di PDF

    private function sesiSiapTerbit(): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::factory()->create()->id,
            'tanggal_kalibrasi' => '2026-07-20',
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        return $sesi->fresh();
    }

    /**
     * Tanda tangan yang udah diunggah beneran kecetak di sertifikat.
     *
     * Diperiksa dari HTML yang dirender template-nya, bukan dari ukuran PDF-nya.
     * Versi pertama test ini cuma ngecek "PDF > 1000 byte" — dan itu **tetap lolos
     * waktu rendering TTD-nya gw matiin buat nguji**, karena PDF-nya besar terlepas
     * dari ada TTD atau nggak. Test dengan klaim yang nggak dia uji lebih jahat
     * daripada nggak ada test.
     */
    public function test_tanda_tangan_kecetak_di_sertifikat(): void
    {
        $this->unggah();
        $this->actingAs($this->admin)->patchJson(self::URL.'/posisi', [
            'geser_x_mm' => -6,
            'geser_y_mm' => 3,
            'lebar_mm' => 40,
        ])->assertOk();

        $sesi = $this->sesiSiapTerbit();
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $sertifikat = $sesi->certificate()->firstOrFail();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);

        $org = $this->org->fresh();
        $html = view('sertifikat.pdf', [
            'sertifikat' => $sertifikat,
            'snapshot' => $sertifikat->snapshot,
            'logo' => null,
            'tandaTangan' => 'data:image/png;base64,VFRE',
            'posisiTtd' => $org->pengaturanTandaTangan(),
            'qr' => null,
            'keputusan' => null,
        ])->render();

        $this->assertStringContainsString('data:image/png;base64,VFRE', $html, 'Gambar TTD-nya harus kerender.');
        // Posisi & lebar dari setelan beneran kepakai, bukan nilai mati di template.
        $this->assertStringContainsString('width: 40mm', $html);
        $this->assertStringContainsString('left: -6mm', $html);
        // `geser_y` positif = naik, jadi `bottom` positif.
        $this->assertStringContainsString('bottom: 3mm', $html);
    }

    /** Tanpa gambar TTD, template nggak nyetak `<img>` — cuma ruang kosongnya. */
    public function test_tanpa_gambar_template_nggak_nyetak_img(): void
    {
        $sesi = $this->sesiSiapTerbit();
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();
        $sertifikat = $sesi->certificate()->firstOrFail();

        $html = view('sertifikat.pdf', [
            'sertifikat' => $sertifikat,
            'snapshot' => $sertifikat->snapshot,
            'logo' => null,
            'tandaTangan' => null,
            'posisiTtd' => $this->org->pengaturanTandaTangan(),
            'qr' => null,
            'keputusan' => null,
        ])->render();

        $this->assertStringContainsString('ruang-ttd', $html, 'Ruang kosongnya tetap ada.');
        $this->assertStringNotContainsString('alt="Tanda tangan"', $html);
    }

    /**
     * Tinggi blok tanda tangan DIPATOK, bukan ikut gambar.
     *
     * Kalau ikut gambar, dua sertifikat dengan format resmi yang sama jadi beda tata
     * letak cuma gara-gara yang satu diunggahin TTD. Dan `margin-top` di `.garis`
     * harus UDAH DIBUANG — kalau dua-duanya ada, jaraknya jadi dobel dan tata letak
     * semua sertifikat yang udah pernah dicetak ikut berubah.
     */
    public function test_tinggi_blok_ttd_dipatok_dan_jaraknya_nggak_dobel(): void
    {
        $css = file_get_contents(resource_path('views/sertifikat/pdf.blade.php'));

        // Angkanya SENGAJA nggak dipatok di test.
        //
        // Dulu ditulis literal `44px`, dan tiap kali skala cetaknya disetel
        // ulang test ini ikut merah padahal aturannya nggak dilanggar — bikin
        // orang mikir dia ngerusak sesuatu. Yang beneran dijaga di sini dua
        // hal: tingginya DIPATOK (ada satuan px, bukan auto/ikut gambar), dan
        // jaraknya cuma ada di SATU tempat.
        $this->assertMatchesRegularExpression(
            '/\.ttd \.ruang-ttd \{ height: \d+(\.\d+)?px;/',
            $css,
            'Tinggi ruang TTD wajib dipatok px — kalau ikut tinggi gambar, dua '
            .'sertifikat dengan format resmi yang sama jadi beda tata letak.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.ttd \.garis \{[^}]*margin-top:/',
            $css,
            'Jaraknya cuma boleh di satu tempat, bukan di `.garis` DAN `.ruang-ttd`.',
        );
    }

    /**
     * Tanpa gambar TTD, sertifikat TETAP terbit — nyetak ruang kosong buat tanda
     * tangan basah. Itu state yang SAH, bukan kekurangan (keputusan user 26 Jul:
     * "kosong dulu").
     */
    public function test_tanpa_tanda_tangan_sertifikat_tetap_terbit(): void
    {
        $sesi = $this->sesiSiapTerbit();

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $sertifikat = $sesi->certificate()->firstOrFail();

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertNull($this->org->fresh()->tanda_tangan_path);
        Storage::disk('arsip')->assertExists($sertifikat->pdf_path);
    }

    /** Nama & jabatan penanda tangan tetap kecetak, entah gambarnya ada atau nggak. */
    public function test_nama_dan_jabatan_tetap_kecetak(): void
    {
        $this->org->update(['settings' => [
            'penandatangan_nama' => 'Alex Misramto',
            'penandatangan_jabatan' => 'Technical Manager',
        ]]);

        $sesi = $this->sesiSiapTerbit();
        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        $footer = $sesi->certificate()->firstOrFail()->snapshot['footer'];

        $this->assertSame('Alex Misramto', $footer['penandatangan']);
        $this->assertSame('Technical Manager', $footer['jabatan']);
    }
}
