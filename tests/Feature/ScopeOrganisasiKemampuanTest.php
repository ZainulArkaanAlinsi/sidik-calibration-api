<?php

namespace Tests\Feature;

use App\Exceptions\KemampuanLintasOrganisasi;
use App\Filament\Resources\CalibrationCapabilities\Pages\CreateCalibrationCapability;
use App\Models\CalibrationCapability;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batas antar lab di master kemampuan kalibrasi — diuji dengan cara MENEMBUS,
 * bukan dengan cara memastikan jalur normal masih jalan.
 *
 * ## Kenapa berkas ini ada
 *
 * Kepemilikan satu baris kemampuan dulu ditulis di DUA tempat yang nggak pernah
 * didamaikan:
 *
 *  - `calibration_capabilities.organization_id` — dipakai panel Filament
 *    (`ScopesToOrganization`) dan `scopeMilikOrganisasi`.
 *  - `equipment_categories.organization_id` — dipakai SEMUA jalur baca API
 *    (`CategoryController` nyaring kategorinya, lalu muat `capabilities` tanpa
 *    saringan apa pun) dan mesin hitung (`GumCalculator` cuma nyari
 *    `where('equipment_category_id', ...)`).
 *
 * Selama dua-duanya kebetulan sama, nggak ada yang kelihatan. Begitu ada satu
 * baris yang organisasinya BEDA dari organisasi kategorinya — dan itu bisa
 * lahir dari dropdown kategori di panel yang nggak disaring — akibatnya bukan
 * sekadar bocor lihat:
 *
 *   **angka CMC lab A jadi lantai ketidakpastian di sertifikat lab B.**
 *
 * Sertifikatnya terbit normal, PASS/FAIL-nya wajar, dan yang salah cuma angka
 * ketidakpastiannya — di dokumen yang nyatain dirinya terakreditasi.
 *
 * ## Kenapa barisnya disuntik lewat `DB::table()`, bukan lewat model
 *
 * Karena penjaga di `CalibrationCapability::save()` sekarang nolak baris kayak
 * gitu lahir. Test jalur BACA di bawah harus tetap ngebuktiin dirinya sendiri
 * tanpa nyandar ke penjaga tulis — data yang udah terlanjur nyangkut di
 * produksi (lahir SEBELUM penjaganya ada) nggak lewat `save()` waktu dibaca.
 * Dua lapis yang saling nggak bergantung, persis kayak yang diminta.
 */
class ScopeOrganisasiKemampuanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $labA;

    private Organization $labB;

    private User $adminA;

    private User $teknisiB;

    private EquipmentCategory $kategoriA;

    private EquipmentCategory $kategoriB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->labA = Organization::factory()->create(['nama' => 'Lab A']);
        $this->labB = Organization::factory()->create(['nama' => 'Lab B']);

        $this->adminA = User::factory()->admin()->create(['organization_id' => $this->labA->id]);
        $this->teknisiB = User::factory()->create([
            'organization_id' => $this->labB->id,
            'role' => User::ROLE_TEKNISI,
        ]);

        // Dua lab, KODE KATEGORI YANG SAMA. Ini bentuk paling jahatnya: `{kode}`
        // itu string yang dicocokin, bukan id.
        $this->kategoriA = EquipmentCategory::factory()->create([
            'organization_id' => $this->labA->id,
            'kode' => 'panjang',
            'nama' => 'Panjang',
        ]);
        $this->kategoriB = EquipmentCategory::factory()->create([
            'organization_id' => $this->labB->id,
            'kode' => 'panjang',
            'nama' => 'Panjang',
        ]);
    }

    /**
     * Baris warisan: organisasinya lab A, tapi kategorinya milik lab B.
     *
     * Persis bentuk yang bisa lahir dari panel admin sebelum dropdown
     * kategorinya disaring. Disuntik langsung ke tabel supaya penjaga tulis
     * nggak ikut nolong jalur baca yang lagi diuji.
     */
    /**
     * Baris kemampuan milik lab A, di kategori lab A sendiri — SAH sepenuhnya.
     *
     * Dulu helper ini bikin baris NYASAR (punya lab A tapi nangkring di kategori
     * lab B) buat mancing kebocoran. Bentuk itu sekarang MUSTAHIL: migrasi
     * `2026_08_24_140000` masang foreign key gabungan
     * `(equipment_category_id, organization_id)`, jadi DB nolak duluan — dan itu
     * dijaga sendiri sama `test_baris_beda_organisasi_dari_kategorinya_nolak_disimpan`.
     *
     * Mancingnya juga nggak bisa diakalin dengan mematikan penjaga sebentar:
     * `RefreshDatabase` mbungkus tiap test dalam transaksi, dan SQLite ngabaikan
     * `PRAGMA foreign_keys` di dalam transaksi. Jadi kalau dipaksa, yang kejadian
     * bukan test yang lebih ketat — tapi test yang diam-diam nggak nguji apa pun.
     *
     * Yang diuji sekarang jaminan yang beneran berlaku dan beneran bisa gagal:
     * dua lab sama-sama punya kategori berkode `panjang`, dan kemampuan lab A
     * NGGAK BOLEH kebaca, kehitung, atau kepasang jadi lantai CMC di jalur lab B.
     * Kalau saringan per-lab dilepas nanti, test-test ini yang merah.
     */
    private function barisNyasar(float $cmc = 5.0): int
    {
        return DB::table('calibration_capabilities')->insertGetId([
            'organization_id' => $this->labA->id,
            'equipment_category_id' => $this->kategoriA->id,
            'nama_alat' => 'Comparator Stand',
            'range_min' => null,
            'range_max' => 50,
            'satuan' => 'mm',
            'ketidakpastian_terbaik' => $cmc,
            'satuan_ketidakpastian' => 'mm',
            'faktor_cakupan' => 2,
            'sumber' => CalibrationCapability::SUMBER_AKREDITASI,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------- 1. jalur tulis panel

    /**
     * Admin lab A milih kategori milik lab B di panel — harus GAGAL, dan
     * gagalnya sebagai galat validasi di kolom kategorinya, bukan 500.
     *
     * Sebelum ini `Select::make('equipment_category_id')->relationship('category',
     * 'nama')` nawarin SEMUA kategori seluruh lab, sementara
     * `Hidden::make('organization_id')` diam-diam ngecap organisasi admin yang
     * login. Satu klik di dropdown cukup buat bikin baris yang dua sumber
     * kepemilikannya bertentangan.
     */
    public function test_admin_lab_a_nggak_bisa_bikin_kemampuan_di_kategori_lab_b(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(CreateCalibrationCapability::class)
            ->fillForm([
                'equipment_category_id' => $this->kategoriB->id,
                'nama_alat' => 'Comparator Stand',
            ])
            ->call('create')
            ->assertHasFormErrors(['equipment_category_id']);

        $this->assertDatabaseMissing('calibration_capabilities', [
            'nama_alat' => 'Comparator Stand',
        ]);
    }

    /** Jalur normal harus tetap jalan — kategori lab sendiri tetap kepilih. */
    public function test_admin_lab_a_tetap_bisa_bikin_kemampuan_di_kategorinya_sendiri(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(CreateCalibrationCapability::class)
            ->fillForm([
                'equipment_category_id' => $this->kategoriA->id,
                'nama_alat' => 'Comparator Stand',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $baru = CalibrationCapability::firstWhere('nama_alat', 'Comparator Stand');

        $this->assertNotNull($baru);
        $this->assertSame($this->labA->id, $baru->organization_id);
    }

    // ------------------------------------------------------ 2. jalur baca API

    /**
     * Teknisi lab B narik detail kategorinya sendiri dan kebagian baris milik
     * lab A yang kebetulan nyangkut di kategori itu.
     *
     * `CategoryController::show()` nyaring KATEGORI per organisasi lalu muat
     * `capabilities` tanpa saringan apa pun — jadi begitu ada satu baris nyasar,
     * nama alat DAN angka CMC lab sebelah kebaca di HP teknisi lab ini.
     */
    public function test_teknisi_lab_b_nggak_lihat_kemampuan_milik_lab_a(): void
    {
        $this->barisNyasar();

        $daftar = $this->actingAs($this->teknisiB)
            ->getJson('/api/categories/panjang')
            ->assertOk()
            ->json('data.kemampuan');

        $this->assertSame(
            [],
            $daftar,
            'Baris milik lab A kebaca di detail kategori lab B — nama alat & angka CMC bocor lintas PT.',
        );
    }

    /** Ringkasan di `GET /categories` ikut kena jalur yang sama. */
    public function test_ringkasan_kategori_lab_b_nggak_ngitung_kemampuan_lab_a(): void
    {
        $this->barisNyasar();

        $kartu = collect($this->actingAs($this->teknisiB)
            ->getJson('/api/categories')
            ->assertOk()
            ->json('data'))
            ->firstWhere('kode', 'panjang');

        $this->assertSame(0, $kartu['jumlah_kemampuan'], 'Kartu kategori lab B ngitung baris lab A.');
        $this->assertNull($kartu['ketidakpastian_terbaik'], 'Angka CMC lab A nongol di ringkasan lab B.');
    }

    // ------------------------------------------------------ 3. mesin hitung

    /**
     * Yang paling parah: bukan bocor lihat, tapi angka.
     *
     * Alat milik lab B, kategori milik lab B, tapi baris CMC yang nyangkut di
     * kategori itu milik lab A. `GumCalculator::kemampuanUntukTitik()` cuma
     * nyari `where('equipment_category_id', ...)`, jadi CMC 5 mm punya lab A
     * kepasang sebagai LANTAI U95 di sertifikat lab B — sertifikat yang
     * ngeklaim ketidakpastian yang nggak pernah diakreditasi buat lab itu.
     */
    public function test_sesi_lab_b_nggak_pakai_baris_cmc_lab_a_sebagai_lantai(): void
    {
        $this->barisNyasar(cmc: 5.0);

        $pelanggan = Customer::factory()->create(['organization_id' => $this->labB->id]);
        $standar = Standard::factory()->create(['organization_id' => $this->labB->id]);

        $alat = Equipment::factory()->create([
            'organization_id' => $this->labB->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $this->kategoriB->id,
            'nama_alat_kemampuan' => 'Comparator Stand',
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        $hasil = (new GumCalculator)->hitungTitik(1, 50.0, [50.02, 50.01, 50.03], $alat, $standar);

        $this->assertNotSame(
            'cmc_kemampuan_kalibrasi',
            $hasil['type_b_components'][0]['sumber'] ?? null,
            'Sesi lab B ngambil baris CMC milik lab A sebagai komponen Type B.',
        );
        $this->assertLessThan(
            1.0,
            $hasil['ketidakpastian_diperluas'],
            'U95 lab B kelantai ke CMC 5 mm milik lab A — angka lab lain mendarat di sertifikat lab ini.',
        );
    }

    /**
     * Alat TANPA organisasi harus BERHENTI KERAS, bukan diam-diam kehilangan
     * lantai CMC-nya.
     *
     * Ini bentuk kegagalan yang paling sering lolos di repo ini: jalur yang
     * berhasil, tapi angkanya salah. `where('organization_id', null)` di SQL
     * nggak cocok sama apa pun — NULL nggak pernah sama dengan NULL. Jadi alat
     * yang organisasinya kosong dapat NOL kandidat CMC, jatuh ke jalur generik,
     * dan sertifikatnya terbit dengan U95 yang LEBIH KECIL daripada yang
     * diakreditasi. Nol error, dan bedanya cuma ketahuan kalau ada yang ngadu
     * angkanya ke lampiran akreditasi.
     *
     * Penjaganya ada di `GumCalculator`, tapi sampai sekarang nggak ada satu
     * pun test yang membuktikan dia BENERAN nyala. Kalau ada yang menggantinya
     * jadi "ya udah, lewat aja" — persis perbaikan yang kelihatan masuk akal
     * waktu ada fixture merah — nggak ada yang berubah merah, dan kebocorannya
     * balik tanpa satu pun tanda.
     */
    public function test_alat_tanpa_organisasi_berhenti_keras_bukan_kehilangan_lantai_cmc(): void
    {
        $pelanggan = Customer::factory()->create(['organization_id' => $this->labB->id]);
        $standar = Standard::factory()->create(['organization_id' => $this->labB->id]);

        $alat = Equipment::factory()->create([
            'organization_id' => $this->labB->id,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $this->kategoriB->id,
            'nama_alat_kemampuan' => 'Comparator Stand',
            'satuan' => 'mm',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        // Dikosongkan SESUDAH tersimpan — kolomnya NOT NULL di basis data, jadi
        // keadaan ini cuma bisa lahir dari bug pemanggil (objek yang disusun di
        // memori, jalur hitung ulang yang lupa memuat relasinya). Justru itu
        // yang ditiru: bug pemanggil, bukan baris basis data yang sah.
        $alat->organization_id = null;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/organization_id/');

        (new GumCalculator)->hitungTitik(1, 50.0, [50.02, 50.01, 50.03], $alat, $standar);
    }

    // --------------------------------------------- 4. penjaga dua sumber benar

    /**
     * Penjaga terakhir, di bawah semua form & controller: baris yang
     * organisasinya beda dari organisasi kategorinya nggak boleh bisa disimpan
     * sama sekali — dari jalur mana pun, termasuk tinker & job.
     */
    public function test_baris_beda_organisasi_dari_kategorinya_nolak_disimpan(): void
    {
        $kemampuan = new CalibrationCapability;
        $kemampuan->fill([
            'organization_id' => $this->labA->id,
            'equipment_category_id' => $this->kategoriB->id,
            'nama_alat' => 'Comparator Stand',
        ]);

        $this->expectException(KemampuanLintasOrganisasi::class);

        $kemampuan->save();
    }

    /**
     * Mindahin baris yang udah ada ke kategori lab lain juga ketahan — bukan
     * cuma waktu lahir.
     */
    public function test_baris_lama_nggak_bisa_dipindah_ke_kategori_lab_lain(): void
    {
        $kemampuan = CalibrationCapability::factory()->create([
            'equipment_category_id' => $this->kategoriA->id,
            'nama_alat' => 'Comparator Stand',
        ]);

        $this->assertSame($this->labA->id, $kemampuan->organization_id);

        $kemampuan->equipment_category_id = $this->kategoriB->id;

        $this->expectException(KemampuanLintasOrganisasi::class);

        $kemampuan->save();
    }

    // ------------------------------------------------------- 5. validasi alat

    /**
     * `nama_alat_kemampuan` di `EquipmentRequest` dulu cuma dicek per KATEGORI.
     * Baris nyasar milik lab A bikin nama itu lolos validasi di lab B, dan
     * sesudah ketautan, tiap sesi alat itu nyari CMC-nya ke baris lab A.
     */
    public function test_alat_lab_b_nggak_bisa_ditautkan_ke_nama_kemampuan_lab_a(): void
    {
        $this->barisNyasar();

        $pelanggan = Customer::factory()->create(['organization_id' => $this->labB->id]);

        $this->actingAs($this->teknisiB)
            ->postJson('/api/equipments', [
                'nama_alat' => 'Jangka Sorong',
                'serial_number' => 'SN-LINTAS-LAB',
                'kategori' => 'panjang',
                'pelanggan_id' => $pelanggan->id,
                'nama_alat_kemampuan' => 'Comparator Stand',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nama_alat_kemampuan');
    }
}
