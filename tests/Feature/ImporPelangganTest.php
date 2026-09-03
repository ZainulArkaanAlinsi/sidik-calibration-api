<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `customers:impor` — pintu masuk pelanggan historis lab.
 *
 * Yang dijaga bukan "perintahnya jalan", tapi tiga janji yang kalau dilanggar
 * tidak menghasilkan error sama sekali:
 *
 *   1. **Idempoten.** Admin yang ragu apakah impornya sudah jalan akan
 *      menjalankannya lagi. Kalau jalan kedua menggandakan, yang lahir dua
 *      folder arsip untuk tiap PT.
 *   2. **`perlu_tinjau` tidak pernah menyentuh database.** Baris meragukan yang
 *      diam-diam masuk akan ikut ke `certificates.snapshot` dan tercetak.
 *   3. **Tidak pernah meng-update baris yang sudah ada.** Satu jalan ulang
 *      dengan berkas lama tidak boleh menimpa alamat yang sudah dibetulkan
 *      admin di panel.
 */
class ImporPelangganTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sampah = [];

    protected function tearDown(): void
    {
        foreach ($this->sampah as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function berkas(string $isi): string
    {
        $path = tempnam(sys_get_temp_dir(), 'impor').'.csv';
        file_put_contents($path, $isi);
        $this->sampah[] = $path;

        return $path;
    }

    private function organisasi(): Organization
    {
        return Organization::factory()->create();
    }

    public function test_pelanggan_baru_masuk_dengan_nama_normal_sumber_dan_penanggung_jawab(): void
    {
        $org = $this->organisasi();
        $user = User::factory()->admin()->create(['organization_id' => $org->id]);
        $berkas = $this->berkas("nama,alamat\nPT. Maju  Jaya,Jl. Industri 5\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--oleh' => $user->id,
        ])->assertSuccessful();

        $pelanggan = Customer::where('organization_id', $org->id)->sole();

        // Spasi gandanya dirapikan pembaca, dan itu disengaja: kolom `nama`
        // inilah yang tercetak di sertifikat, jadi yang tersimpan harus bentuk
        // yang mau dibaca pelanggan — bukan salinan mentah sel Excel-nya.
        $this->assertSame('PT. Maju Jaya', $pelanggan->nama);
        // Diturunkan lewat `Customer::booted()`, bukan ditulis perintahnya —
        // dua aturan berbeda di satu kolom bikin penjaga kembarnya bohong.
        $this->assertSame('pt maju jaya', $pelanggan->nama_normal);
        $this->assertSame(Customer::SUMBER_ADMIN, $pelanggan->sumber);
        $this->assertSame($user->id, $pelanggan->dibuat_oleh_user_id);
        $this->assertSame('Jl. Industri 5', $pelanggan->alamat);
    }

    public function test_riwayat_audit_menyebut_penanggung_jawab_impor(): void
    {
        // `Diaudit` mengambil pelakunya dari `Auth::id()`, yang di baris
        // perintah selalu kosong. Tanpa penerusan `--oleh`, impor 500 pelanggan
        // mendarat di `audit_logs` sebagai 500 pembuatan tanpa siapa-siapa di
        // belakangnya — dan riwayat begitu yang ditanya asesor.
        $org = $this->organisasi();
        $user = User::factory()->admin()->create(['organization_id' => $org->id]);
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--oleh' => $user->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'customers',
            'action' => 'dibikin',
            'changed_by' => $user->id,
        ]);
    }

    public function test_jalan_dua_kali_tidak_menambah_baris(): void
    {
        $org = $this->organisasi();
        $berkas = $this->berkas("nama\nPT Maju Jaya\nPT Sinar Abadi\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();
        $this->assertSame(2, Customer::where('organization_id', $org->id)->count());

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();
        $this->assertSame(2, Customer::where('organization_id', $org->id)->count());
    }

    public function test_baris_perlu_tinjau_tidak_pernah_masuk_database(): void
    {
        $org = $this->organisasi();
        Customer::factory()->create(['organization_id' => $org->id, 'nama' => 'PT Maju Jaya']);

        $berkas = $this->berkas("nama\nPT Maju Raya\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();

        $this->assertSame(1, Customer::where('organization_id', $org->id)->count());
        $this->assertFalse(Customer::where('nama', 'PT Maju Raya')->exists());
    }

    public function test_pt_dan_cv_dua_duanya_masuk(): void
    {
        $org = $this->organisasi();
        Customer::factory()->create(['organization_id' => $org->id, 'nama' => 'PT Maju Jaya']);

        $berkas = $this->berkas("nama\nCV Maju Jaya\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();

        $this->assertTrue(Customer::where('nama', 'CV Maju Jaya')->exists());
        $this->assertSame(2, Customer::where('organization_id', $org->id)->count());
    }

    public function test_uji_coba_tidak_menulis_apa_pun(): void
    {
        $org = $this->organisasi();
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--uji-coba' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Customer::where('organization_id', $org->id)->count());
    }

    public function test_pelanggan_yang_sudah_dihapus_dikenali_bukan_ditabrak(): void
    {
        // Soft delete cuma mengisi `deleted_at` — barisnya masih ada dan unique
        // index masih memegangnya. Tanpa `withTrashed()`, impor membaca "belum
        // ada", lalu database menolak insert-nya di tengah transaksi dan SEMUA
        // baris lain ikut batal.
        $org = $this->organisasi();
        $lama = Customer::factory()->create(['organization_id' => $org->id, 'nama' => 'PT Maju Jaya']);
        $lama->delete();

        $berkas = $this->berkas("nama\nPT Maju Jaya\nPT Sinar Abadi\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])
            ->assertSuccessful();

        $this->assertTrue(Customer::where('nama', 'PT Sinar Abadi')->exists());
        $this->assertSame(1, Customer::withTrashed()->where('nama', 'PT Maju Jaya')->count());
    }

    public function test_laporan_membedakan_pelanggan_terhapus_dari_yang_masih_aktif(): void
    {
        // "sudah ada" saja bikin admin mencarinya di panel dan tidak menemukan
        // apa-apa — lalu dia mengira laporannya yang salah.
        $org = $this->organisasi();
        Customer::factory()->create(['organization_id' => $org->id, 'nama' => 'PT Maju Jaya'])->delete();

        $laporan = sys_get_temp_dir().'/laporan-impor-'.uniqid().'.csv';
        $this->sampah[] = $laporan;

        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--laporan' => $laporan,
            '--uji-coba' => true,
        ])->assertSuccessful();

        $this->assertStringContainsString('sudah DIHAPUS', (string) file_get_contents($laporan));
    }

    public function test_impor_tidak_menimpa_alamat_yang_sudah_dibetulkan_admin(): void
    {
        $org = $this->organisasi();
        Customer::factory()->create([
            'organization_id' => $org->id,
            'nama' => 'PT Maju Jaya',
            'alamat' => 'Jl. Yang Sudah Dibetulkan 99',
        ]);

        $berkas = $this->berkas("nama,alamat\nPT Maju Jaya,Jl. Lama Yang Salah 1\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();

        $this->assertSame(
            'Jl. Yang Sudah Dibetulkan 99',
            Customer::where('nama', 'PT Maju Jaya')->value('alamat'),
        );
    }

    public function test_alamat_kosong_tetap_kosong_bukan_diisi_placeholder(): void
    {
        $org = $this->organisasi();
        $berkas = $this->berkas("nama,alamat\nPT Maju Jaya,\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();

        $this->assertNull(Customer::where('nama', 'PT Maju Jaya')->value('alamat'));
    }

    public function test_organisasi_wajib_diisi(): void
    {
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', ['berkas' => $berkas])->assertFailed();

        $this->assertSame(0, Customer::count());
    }

    public function test_organisasi_yang_tidak_ada_ditolak(): void
    {
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => 999999])->assertFailed();

        $this->assertSame(0, Customer::count());
    }

    public function test_user_dari_organisasi_lain_ditolak(): void
    {
        // Penanggung jawab dari lab sebelah bikin riwayat impor menunjuk orang
        // yang tidak berwenang di organisasi ini.
        $org = $this->organisasi();
        $lain = User::factory()->admin()->create(['organization_id' => $this->organisasi()->id]);
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--oleh' => $lain->id,
        ])->assertFailed();

        $this->assertSame(0, Customer::where('organization_id', $org->id)->count());
    }

    public function test_sumber_di_luar_daftar_ditolak(): void
    {
        $org = $this->organisasi();
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--sumber' => 'entah',
        ])->assertFailed();

        $this->assertSame(0, Customer::where('organization_id', $org->id)->count());
    }

    public function test_koneksi_yang_tidak_terdaftar_ditolak(): void
    {
        $org = $this->organisasi();
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--koneksi' => 'produks',
        ])->assertFailed();

        $this->assertSame(0, Customer::where('organization_id', $org->id)->count());
    }

    /**
     * Penjaga yang jadi alasan seluruh opsi `--koneksi` ada.
     *
     * `produksi` sengaja ditulis TANPA nilai bawaan di config/database.php.
     * Kalau suatu saat ada yang "merapikannya" jadi `env('DB_PRODUKSI_HOST',
     * '127.0.0.1')` seperti koneksi `mysql` di atasnya, perintah ini akan jalan
     * mulus ke MySQL laptop sambil dikira menulis ke produksi — persis kejadian
     * yang bikin opsi ini dibuat, dan nol error yang muncul.
     *
     * Test ini merah kalau bawaan itu dipasang.
     */
    public function test_koneksi_produksi_yang_belum_disetel_ditolak_bukan_jatuh_ke_bawaan(): void
    {
        // `assertNull`, bukan "null atau string kosong" — dan bedanya menentukan
        // apakah baris ini menjaga apa pun.
        //
        // Kunci yang ADA TAPI KOSONG di `.env` menang atas nilai bawaan di
        // `env()`; bawaan cuma terpicu kalau kuncinya tidak ada sama sekali.
        // Jadi kalau `.env.example` memuat `DB_PRODUKSI_HOST=`, bawaan
        // `'127.0.0.1'` yang berbahaya TIDAK PERNAH terpicu di CI (workflow-nya
        // `cp .env.example .env`), nilainya `''`, dan assertion yang menerima
        // string kosong akan hijau sambil membiarkan bahayanya lewat.
        //
        // Itu bukan dugaan: versi longgar pernah ditulis di sini dan lulus
        // dengan bawaan berbahaya terpasang. Karena itu kuncinya dikomentari di
        // `.env.example`, dan assertion-nya dikembalikan ketat.
        $this->assertNull(
            config('database.connections.produksi.host'),
            'Koneksi `produksi` tidak boleh punya host bawaan — lihat config/database.php. '
            .'Kalau ini merah karena `\'\'`, kunci DB_PRODUKSI_* di .env.example tidak lagi dikomentari.',
        );

        $org = $this->organisasi();
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--koneksi' => 'produksi',
        ])->assertFailed();

        $this->assertSame(0, Customer::where('organization_id', $org->id)->count());
    }

    /**
     * Koneksinya diperiksa SEBELUM berkasnya dibuka.
     *
     * Path-nya sengaja menunjuk berkas yang tidak ada: kalau pemeriksaan
     * koneksi dipindah ke belakang pembacaan berkas, yang gagal duluan adalah
     * pembacaannya, dan pesan yang sampai ke admin bicara soal berkas padahal
     * masalahnya koneksi.
     */
    public function test_koneksi_diperiksa_sebelum_berkas_dibaca(): void
    {
        $org = $this->organisasi();

        $this->artisan('customers:impor', [
            'berkas' => '/tmp/tidak-ada-berkas-ini-juga.csv',
            '--organization' => $org->id,
            '--koneksi' => 'produksi',
        ])
            ->expectsOutputToContain('belum disetel')
            ->assertFailed();
    }

    public function test_tanpa_koneksi_jalan_seperti_biasa_dan_menyebut_tujuannya(): void
    {
        $org = $this->organisasi();
        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        // Tujuannya dicetak di SETIAP jalan, bukan cuma waktu `--koneksi`
        // dipakai — admin yang tidak tahu `.env`-nya menunjuk ke mana justru
        // yang tidak akan menuliskan opsinya.
        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
        ])
            ->expectsOutputToContain('Tujuan: koneksi')
            ->assertSuccessful();

        $this->assertSame(1, Customer::where('organization_id', $org->id)->count());
    }

    public function test_berkas_tidak_ada_gagal_dengan_pesan_bukan_exception(): void
    {
        $org = $this->organisasi();

        $this->artisan('customers:impor', [
            'berkas' => '/tmp/tidak-ada-berkas-ini.csv',
            '--organization' => $org->id,
        ])->assertFailed();
    }

    public function test_laporan_ditulis_dan_memuat_ketiga_keranjang(): void
    {
        $org = $this->organisasi();
        Customer::factory()->create(['organization_id' => $org->id, 'nama' => 'PT Maju Jaya']);

        $laporan = sys_get_temp_dir().'/laporan-impor-'.uniqid().'.csv';
        $this->sampah[] = $laporan;

        $berkas = $this->berkas("nama\nPT Maju Jaya\nPT Maju Raya\nPT Sinar Abadi\n,\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--laporan' => $laporan,
            '--uji-coba' => true,
        ])->assertSuccessful();

        $isi = (string) file_get_contents($laporan);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi, 'Laporan wajib ber-BOM biar kebaca Excel.');
        $this->assertStringContainsString('kembar_pasti', $isi);
        $this->assertStringContainsString('perlu_tinjau', $isi);
        $this->assertStringContainsString('PT Sinar Abadi', $isi);
    }

    public function test_laporan_memakai_pemisah_yang_sama_dengan_berkas_masukan(): void
    {
        // Excel membaca CSV dengan pemisah dari setelan wilayah, bukan dari isi
        // berkasnya. Lab yang Excel-nya menulis `;` juga membacanya dengan `;`
        // — laporan ber-`,` terbuka jadi satu kolom penuh di layar admin.
        $org = $this->organisasi();
        $laporan = sys_get_temp_dir().'/laporan-impor-'.uniqid().'.csv';
        $this->sampah[] = $laporan;

        $berkas = $this->berkas("nama;alamat\nPT Maju Jaya;Jl. A\n");

        $this->artisan('customers:impor', [
            'berkas' => $berkas,
            '--organization' => $org->id,
            '--laporan' => $laporan,
            '--uji-coba' => true,
        ])->assertSuccessful();

        $this->assertStringContainsString('keranjang;baris_berkas;nama', (string) file_get_contents($laporan));
    }

    public function test_pelanggan_organisasi_lain_tidak_ikut_diadu(): void
    {
        // Dua lab sah punya pelanggan bernama sama, dan itu bukan urusan satu
        // sama lain — unique index-nya pun per organisasi.
        $org = $this->organisasi();
        $lain = $this->organisasi();
        Customer::factory()->create(['organization_id' => $lain->id, 'nama' => 'PT Maju Jaya']);

        $berkas = $this->berkas("nama\nPT Maju Jaya\n");

        $this->artisan('customers:impor', ['berkas' => $berkas, '--organization' => $org->id])->assertSuccessful();

        $this->assertSame(1, Customer::where('organization_id', $org->id)->count());
    }
}
