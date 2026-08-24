<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\CalibrationCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teknisi nambah nama alat sendiri dari lapangan, dan apa yang harus tetap
 * kejaga waktu dia bisa.
 *
 * ## Kenapa berkas ini ada
 *
 * Keputusan pemilik proyek: nama alat yang ditambah teknisi LANGSUNG kepakai,
 * tanpa antrean persetujuan admin. Itu keputusan yang bener — teknisi nggak
 * boleh mentok di lokasi pelanggan cuma gara-gara master data belum lengkap.
 *
 * Tapi baris yang lahir dari situ nggak punya angka CMC, dan itu bukan
 * kekurangan yang netral. Yang nentuin angka ketidakpastian di sertifikat itu
 * pencocokan titik ukur ke baris CMC; baris tanpa rentang nggak akan pernah
 * cocok, sesinya jatuh ke jalur generik tanpa lantai CMC, dan **U95 yang terbit
 * bisa lebih KECIL daripada yang diakreditasi lab — tanpa satu pun error di
 * mana pun.** Buat lab terakreditasi itu temuan audit.
 *
 * Jadi yang dikunci di sini dua hal sekaligus, dan dua-duanya harus benar
 * bareng: teknisi BISA kerja, DAN angkanya nggak bisa terbit diam-diam
 * seolah-olah terakreditasi.
 */
class KemampuanAlatTeknisiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private EquipmentCategory $kategori;

    private Customer $pelanggan;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        // `id => 1` dipatok karena `CalibrationCapabilitySeeder` nulis
        // kategorinya ke `organization_id => 1` (nilai mati di seeder-nya).
        // Tanpa ini, test perisai seeder di bawah nyeed ke organisasi yang beda
        // dari yang dipakai test lain dan hasilnya nggak nyambung.
        Organization::factory()->create(['id' => 1]);

        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create(['role' => User::ROLE_TEKNISI]);

        $this->kategori = EquipmentCategory::factory()->create([
            'organization_id' => 1,
            'kode' => 'panjang',
            'nama' => 'Panjang',
        ]);

        $this->pelanggan = Customer::factory()->create();
        $this->standar = Standard::factory()->create();
    }

    private function url(string $kode = 'panjang'): string
    {
        return "/api/categories/{$kode}/kemampuan";
    }

    // ------------------------------------------------- teknisi bisa nambah

    public function test_teknisi_nambah_nama_alat_dan_langsung_bisa_dipakai(): void
    {
        $respons = $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => 'Comparator Stand', 'satuan' => 'mm'])
            ->assertCreated()
            ->assertJsonPath('data.nama_alat', 'Comparator Stand')
            ->assertJsonPath('data.sumber', CalibrationCapability::SUMBER_TEKNISI)
            // Inti permintaan: barisnya NANDAIN DIRINYA SENDIRI sebagai tanpa
            // CMC, di respons yang dibaca layar teknisi begitu selesai simpan.
            ->assertJsonPath('data.tanpa_cmc', true)
            ->assertJsonPath('data.ketidakpastian_terbaik', null);

        $this->assertNotNull($respons->json('data.alasan_tanpa_cmc'));
        $this->assertNotNull(
            $respons->json('peringatan'),
            'Peringatannya harus ada di tingkat atas juga — layar yang cuma nampilin `message` '
                .'sesudah simpan sukses nggak akan pernah lihat `data.alasan_tanpa_cmc`.',
        );

        $kemampuan = CalibrationCapability::firstWhere('nama_alat', 'Comparator Stand');

        $this->assertNotNull($kemampuan);
        $this->assertSame(CalibrationCapability::SUMBER_TEKNISI, $kemampuan->sumber);
        $this->assertSame($this->teknisi->id, $kemampuan->dibuat_oleh_user_id);
        $this->assertSame($this->kategori->organization_id, $kemampuan->organization_id);
        $this->assertSame($this->kategori->id, $kemampuan->equipment_category_id);

        // "Langsung dipakai" = muncul di daftar yang dibaca HP, DAN bisa
        // ditautkan ke alat pelanggan tanpa nunggu siapa pun. Kalau salah satu
        // gagal, teknisinya tetap mentok — cuma pindah tempat mentoknya.
        $daftar = $this->actingAs($this->teknisi)
            ->getJson('/api/categories/panjang')
            ->assertOk()
            ->json('data.kemampuan');

        $baris = collect($daftar)->firstWhere('nama_alat', 'Comparator Stand');

        $this->assertNotNull($baris, 'Nama alat yang baru ditambah harus langsung kebaca di GET /categories/{kode}.');
        $this->assertTrue($baris['tanpa_cmc']);
        $this->assertSame(CalibrationCapability::SUMBER_TEKNISI, $baris['sumber']);

        $this->actingAs($this->teknisi)->postJson('/api/equipments', [
            'pelanggan_id' => $this->pelanggan->id,
            'kategori' => 'panjang',
            'nama_alat' => 'Comparator Pelanggan',
            'nama_alat_kemampuan' => 'Comparator Stand',
            'serial_number' => 'CS-0001',
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ])->assertCreated();
    }

    public function test_admin_nambah_lewat_endpoint_yang_sama_kecap_admin(): void
    {
        $this->actingAs($this->admin)
            ->postJson($this->url(), ['nama_alat' => 'Height Gauge Baru'])
            ->assertCreated()
            ->assertJsonPath('data.sumber', CalibrationCapability::SUMBER_ADMIN);
    }

    public function test_viewer_ditolak(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)
            ->postJson($this->url(), ['nama_alat' => 'Apa Aja'])
            ->assertForbidden();
    }

    /**
     * `sumber` dari payload NGGAK boleh dipercaya.
     *
     * Kalau bisa, satu `{"sumber":"akreditasi"}` dari HP cukup buat bikin baris
     * kosong menyamar jadi salinan lampiran akreditasi — dan sesudah itu nggak
     * ada satu pun penjagaan di sistem ini yang bisa ngebedain, termasuk
     * perisai seeder & penanda di panel admin.
     */
    public function test_sumber_dari_klien_diabaikan(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), [
                'nama_alat' => 'Alat Selundupan',
                'sumber' => CalibrationCapability::SUMBER_AKREDITASI,
                // Angka CMC juga nggak boleh nyelip lewat sini — jalurnya cuma
                // panel admin.
                'ketidakpastian_terbaik' => 0.000001,
                'range_min' => 0,
                'range_max' => 300,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sumber', CalibrationCapability::SUMBER_TEKNISI)
            ->assertJsonPath('data.tanpa_cmc', true)
            ->assertJsonPath('data.ketidakpastian_terbaik', null)
            ->assertJsonPath('data.range_max', null);
    }

    // ------------------------------------------------------- nama kembar

    public function test_nama_kembar_ditolak_walau_beda_besar_kecil_huruf(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => 'Oven Pengering'])
            ->assertCreated();

        // "Oven" dan "oven" itu alat yang sama. Dua baris buat satu alat bikin
        // setengah alat pelanggan nunjuk ke baris A dan setengahnya ke baris B,
        // dan cuma salah satunya yang nanti dilengkapi CMC-nya sama admin.
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => 'oven pengering'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nama_alat');

        // Spasi ujung & spasi ganda juga dirapikan dulu — kalau nggak, dia
        // lolos tiap penyaring kembar dan di dropdown kelihatan sama persis.
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => '  Oven   Pengering '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nama_alat');

        $this->assertSame(1, CalibrationCapability::where('nama_alat', 'like', '%ven%engering')->count());
    }

    public function test_nama_yang_kembar_sama_baris_akreditasi_juga_ditolak(): void
    {
        CalibrationCapability::factory()->create([
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'Vernier Caliper',
        ]);

        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => 'vernier caliper'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nama_alat');
    }

    public function test_nama_sama_di_kategori_lain_tetap_boleh(): void
    {
        $lain = EquipmentCategory::factory()->create([
            'organization_id' => 1,
            'kode' => 'massa',
            'nama' => 'Massa',
        ]);

        $this->actingAs($this->teknisi)->postJson($this->url(), ['nama_alat' => 'Oven'])->assertCreated();
        $this->actingAs($this->teknisi)->postJson($this->url('massa'), ['nama_alat' => 'Oven'])->assertCreated();

        $this->assertSame(2, CalibrationCapability::where('nama_alat', 'Oven')->count());
        $this->assertSame(1, CalibrationCapability::where('nama_alat', 'Oven')
            ->where('equipment_category_id', $lain->id)->count());
    }

    public function test_nama_alat_wajib_diisi(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['parameter' => 'Suhu'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nama_alat');
    }

    // ----------------------------------------------------- batas antar lab

    public function test_teknisi_lab_lain_nggak_lihat_dan_nggak_bisa_nyentuh(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => 'Comparator Stand'])
            ->assertCreated();

        // Lab kedua, dengan KODE KATEGORI YANG SAMA. Ini bentuk paling jahatnya:
        // `{kode}` itu string yang dicocokin, bukan id — kalau penyaring
        // organisasinya kelewat, lab kedua nembak `panjang` dan yang kebuka
        // kategori lab pertama.
        $labLain = Organization::factory()->create();
        $kategoriLain = EquipmentCategory::factory()->create([
            'organization_id' => $labLain->id,
            'kode' => 'panjang',
            'nama' => 'Panjang',
        ]);
        $teknisiLain = User::factory()->create([
            'organization_id' => $labLain->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        $daftar = $this->actingAs($teknisiLain)
            ->getJson('/api/categories/panjang')
            ->assertOk()
            ->json('data.kemampuan');

        $this->assertSame(
            [],
            $daftar,
            'Teknisi lab lain kebagian daftar kemampuan lab sebelah — nama alat (dan nanti angka CMC-nya) bocor lintas PT.',
        );

        // Nama yang sama boleh dibikin lagi di lab kedua, dan barisnya harus
        // MILIK lab kedua — bukan nempel ke kategori lab pertama.
        $this->actingAs($teknisiLain)
            ->postJson($this->url(), ['nama_alat' => 'Comparator Stand'])
            ->assertCreated();

        $baris = CalibrationCapability::where('nama_alat', 'Comparator Stand')->get();

        $this->assertCount(2, $baris);
        $this->assertEqualsCanonicalizing(
            [1, $labLain->id],
            $baris->pluck('organization_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$this->kategori->id, $kategoriLain->id],
            $baris->pluck('equipment_category_id')->all(),
        );
    }

    public function test_kategori_yang_bukan_punya_labnya_balik_404(): void
    {
        $labLain = Organization::factory()->create();
        $teknisiLain = User::factory()->create([
            'organization_id' => $labLain->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        // Kode `panjang` cuma ada di lab pertama.
        $this->actingAs($teknisiLain)
            ->postJson($this->url(), ['nama_alat' => 'Comparator Stand'])
            ->assertNotFound();

        $this->assertSame(0, CalibrationCapability::count());
    }

    // ------------------------------------------------------ perisai seeder

    /**
     * `CalibrationCapabilitySeeder` dijalanin ulang — perintah yang wajar tiap
     * deploy — nggak boleh nyabut nama alat buatan admin & teknisi.
     *
     * Sebelum perisainya dipasang, seeder ini mulai dengan ngehapus baris
     * se-KATEGORI. Sekali jalan, semua nama alat yang ditambah orang di sepuluh
     * kategori ilang sekaligus, dan yang kelihatan bukan error: alat yang
     * kemarin ada di dropdown teknisi hari ini nggak ada.
     */
    public function test_seeder_diulang_nggak_ngehapus_nama_alat_buatan_orang(): void
    {
        $this->seed(CalibrationCapabilitySeeder::class);

        $kategoriTerseed = EquipmentCategory::where('organization_id', 1)
            ->whereHas('capabilities')
            ->firstOrFail();

        $sebelum = CalibrationCapability::where('sumber', CalibrationCapability::SUMBER_AKREDITASI)->count();
        $this->assertGreaterThan(0, $sebelum, 'Seeder lampiran akreditasi nggak nulis apa-apa.');

        $dariTeknisi = CalibrationCapability::factory()->tanpaCmc()->create([
            'equipment_category_id' => $kategoriTerseed->id,
            'nama_alat' => 'Alat Tambahan Teknisi',
        ]);
        $dariAdmin = CalibrationCapability::factory()
            ->tanpaCmc(CalibrationCapability::SUMBER_ADMIN)
            ->create([
                'equipment_category_id' => $kategoriTerseed->id,
                'nama_alat' => 'Alat Tambahan Admin',
            ]);

        $this->seed(CalibrationCapabilitySeeder::class);

        $this->assertNotNull(
            $dariTeknisi->fresh(),
            'Nama alat buatan teknisi kehapus waktu seeder lampiran dijalanin lagi.',
        );
        $this->assertNotNull(
            $dariAdmin->fresh(),
            'Nama alat buatan admin kehapus waktu seeder lampiran dijalanin lagi.',
        );

        // Baris akreditasinya sendiri tetap ditulis ulang utuh — perisainya
        // nggak boleh bikin seeder-nya berhenti nyegerin datanya sendiri.
        $this->assertSame(
            $sebelum,
            CalibrationCapability::where('sumber', CalibrationCapability::SUMBER_AKREDITASI)->count(),
        );
    }

    public function test_seeder_diulang_nggak_ninggalin_baris_mati_yang_numpuk(): void
    {
        $this->seed(CalibrationCapabilitySeeder::class);
        $this->seed(CalibrationCapabilitySeeder::class);

        // Modelnya pakai `SoftDeletes` sekarang. Kalau seeder-nya cuma
        // `delete()`, tiap kali jalan dia ninggalin 151 baris mati — dan
        // `updateOrCreate()` di seeder per-alat nggak bisa lihat baris
        // soft-deleted, jadi numpuknya nggak pernah berhenti.
        $this->assertSame(
            0,
            CalibrationCapability::onlyTrashed()->count(),
            'Seeder ninggalin baris soft-deleted; tiap deploy tabelnya nambah sampah yang nggak kelihatan.',
        );
    }

    // ------------------------------------------- penjaga U95 (paling penting)

    /**
     * Alat yang nunjuk ke nama tanpa CMC NGGAK boleh diam-diam nerbitin U95
     * yang lebih kecil dari kemampuan terakreditasi.
     *
     * Titik ukurnya sengaja dekat NOL. Itu bentuk kegagalan yang paling runcing:
     * `kemampuanUntukTitik()` nyocokin titik tunggal generik lewat `abs($titik -
     * (float) $k->range_max)`, dan `(float) null` itu `0.0` — jadi baris tanpa
     * rentang berperilaku sebagai "kemampuan di titik 0" dan nyangkut ke tiap
     * titik dalam radius 0,1 dari nol. Begitu nyangkut, `ketidakpastian_terbaik`
     * NULL kebaca `0.0`, lantai CMC-nya ilang, dan U95-nya mengecil tanpa satu
     * pun error.
     */
    public function test_titik_dekat_nol_nggak_nyangkut_ke_baris_tanpa_cmc(): void
    {
        $sesi = $this->sesiPakaiAlatTanpaCmc();

        $titik = $sesi->uncertaintyCalculations()->firstOrFail();

        $sumberKomponen = collect($titik->type_b_components ?? [])->pluck('sumber')->all();

        $this->assertNotContains(
            'cmc_kemampuan_kalibrasi',
            $sumberKomponen,
            'Baris tanpa CMC kepasang sebagai kemampuan kalibrasi. `ketidakpastian_terbaik` NULL bakal '
                .'kebaca 0.0 dan lantai CMC-nya ilang — U95 di sertifikat jadi lebih kecil dari yang diakreditasi.',
        );
        $this->assertNull($titik->metode, 'Jalur generik nggak nunjuk ke CMC, jadi nggak punya nomor IK.');
        $this->assertGreaterThan(0.0, (float) $titik->ketidakpastian_diperluas);
    }

    /**
     * Sesi yang pakai alat tanpa CMC harus KELIHATAN waktu admin meriksa, dan
     * nggak bisa disetujui tanpa admin sadar.
     *
     * Bukan ERROR: teknisi tetap harus bisa kerja dan sertifikatnya tetap boleh
     * terbit. Yang nggak boleh itu terbit tanpa ada yang lihat.
     */
    public function test_admin_lihat_peringatannya_waktu_meriksa_dan_harus_sadar_buat_nyetujuin(): void
    {
        $sesi = $this->sesiPakaiAlatTanpaCmc();

        $validasi = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            // Tetap boleh terbit — yang ditahan cuma penerbitan yang nggak
            // disadari.
            ->assertJsonPath('data.boleh_terbit', true)
            ->json('data.temuan');

        $this->assertContains(
            'alat_tanpa_cmc',
            collect($validasi)->pluck('kode')->all(),
            'Sesi yang pakai nama alat tanpa CMC nggak kelihatan waktu admin meriksa.',
        );

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('butuh_konfirmasi', true);

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }

    /**
     * Kebalikannya, biar penjagaannya nggak jadi peringatan yang selalu nyala:
     * alat yang nama kemampuannya PUNYA CMC nggak boleh ikut ke-flag.
     */
    public function test_alat_yang_cmcnya_lengkap_nggak_ikut_diperingatin(): void
    {
        CalibrationCapability::factory()->create([
            'equipment_category_id' => $this->kategori->id,
            'nama_alat' => 'Vernier Caliper',
            'range_min' => 0,
            'range_max' => 300,
            'satuan' => 'mm',
            'ketidakpastian_terbaik' => 0.02,
            'satuan_ketidakpastian' => 'mm',
        ]);

        $sesi = $this->sesiUntuk('Vernier Caliper', titik: 50.0, pembacaan: [50.02, 50.01, 50.03]);

        $temuan = $this->actingAs($this->admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');

        $this->assertNotContains('alat_tanpa_cmc', collect($temuan)->pluck('kode')->all());
    }

    // ------------------------------------------------------------- helpers

    private function sesiPakaiAlatTanpaCmc(): CalibrationSession
    {
        $this->actingAs($this->teknisi)
            ->postJson($this->url(), ['nama_alat' => 'Comparator Stand'])
            ->assertCreated();

        return $this->sesiUntuk('Comparator Stand', titik: 0.05, pembacaan: [0.05, 0.06, 0.05]);
    }

    /** @param  list<float>  $pembacaan */
    private function sesiUntuk(string $namaKemampuan, float $titik, array $pembacaan): CalibrationSession
    {
        $alat = Equipment::factory()->create([
            'organization_id' => 1,
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'nama_alat_kemampuan' => $namaKemampuan,
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [[
                'titik_ukur' => $titik,
                'satuan' => 'mm',
                'pembacaan' => $pembacaan,
            ]],
        ])->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }
}
