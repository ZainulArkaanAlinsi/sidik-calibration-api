<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\ImporWorkbook;
use App\Services\Studio\Workbook\KanonikWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use App\Services\Studio\Workbook\PengecualianLembar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use Tests\Concerns\WorkbookUji;
use Tests\TestCase;

/**
 * Pembaca `.xlsm` terenkripsi + `studio:impor-workbook --xlsm` (Studio S1b).
 *
 * Fixture dibangkitkan di test (Writer Xlsx, Agile AES-256/SHA-512). Yang
 * dikunci, sesuai temuan spike S0c:
 *
 *  - jalur sukses: nilai cache, rumus, format, gaya, tata letak, status sheet;
 *  - sandi salah, profil spin > 100.000, bukan OOXML → kode galat stabil;
 *  - exception DI TENGAH `load()` (ZIP masih dipegang reader — jalur yang
 *    bocor di Windows) → temp privat tetap bersih;
 *  - berkas asli disimpan identik byte (terenkripsi), sandi tidak pernah
 *    tampil;
 *  - K-49-12: isi DATABASE/SERTIFIKAT (kanari sintetis) TERSIMPAN apa adanya,
 *    lembarnya hanya bertanda `tautan_tabel`;
 *  - format kanonik 3: lebar/tinggi bawaan, freeze dari split, garis kisi,
 *    zoom, gaya bungkus/vertikal/ukuran/font, sel kosong ber-isi saja.
 */
class StudioImporWorkbookXlsmTest extends TestCase
{
    use RefreshDatabase;
    use WorkbookUji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanFolderUji();
    }

    protected function tearDown(): void
    {
        $this->bersihkanFolderUji();
        parent::tearDown();
    }

    public function test_jalur_sukses_nilai_rumus_gaya_tata_letak_dan_temp_bersih(): void
    {
        $hasil = app(PembacaXlsmWorkbook::class)->baca($this->buatXlsm(), self::SANDI_UJI);

        $this->assertTrue($hasil['terenkripsi']);
        $this->assertSame(
            ['INPUT DATA', 'DATABASE', 'Bantu', 'Rahasia Makro', 'SERTIFIKAT'],
            array_column($hasil['lembar'], 'nama'),
        );
        $this->assertSame(['tidak', 'tidak', 'hidden', 'veryHidden', 'tidak'], array_column($hasil['lembar'], 'tersembunyi'));
        // K-49-12: tidak ada lagi lembar yang dikecualikan; DATABASE/SERTIFIKAT hanya bertautan.
        $this->assertSame([false, false, false, false, false], array_column($hasil['lembar'], 'dikecualikan'));
        $this->assertSame([null, 'customers', null, null, 'certificates'], array_column($hasil['lembar'], 'tautan_tabel'));
        $this->assertSame([null, null, null, null, null], array_column($hasil['lembar'], 'alasan_dikecualikan'));

        $input = $hasil['lembar'][0];
        $sel = array_column(json_decode($input['sel_kanonik'], true), null, 'a');

        // Kunci gaya = kontrak mobile `GayaSel.fromJson` (tebal/miring/isi/teks/rata/border).
        $this->assertSame(['t' => 's', 's' => 'Judul', 'g' => ['isi' => '#FFFF00', 'tebal' => true]], ['t' => $sel['A1']['t'], 's' => $sel['A1']['s'], 'g' => $sel['A1']['g']]);
        $this->assertSame(1.5, $sel['B2']['n']);
        $this->assertSame(['miring' => true, 'rata' => 'center', 'teks' => '#C00000'], $sel['B2']['g']);
        $this->assertSame(['f', '=B2*2', 3.0, '3.000', '0.000'], [$sel['C2']['t'], $sel['C2']['r'], $sel['C2']['n'], $sel['C2']['s'], $sel['C2']['f']]);
        $this->assertSame('=SUM(B2:C2)', $sel['D2']['r']);
        $this->assertSame(['kosong', ['border' => ['atas' => 'thin', 'bawah' => 'thin', 'kanan' => 'thin', 'kiri' => 'thin']]], [$sel['F6']['t'], $sel['F6']['g']]);
        $this->assertSame(2, $input['jumlah_rumus']);

        // Format 3: bungkus + vertikal (bottom bawaan tidak dikirim), ukuran & font bila beda dari bawaan workbook.
        $this->assertSame(['bungkus' => true, 'vertikal' => 'top'], $sel['A7']['g']);
        $this->assertSame("baris satu\nbaris dua", $sel['A7']['s']);
        $this->assertSame(['font' => 'Arial', 'ukuran' => 8.0], $sel['B7']['g']);
        // Sel kosong: disimpan bila ber-isi (Excel menggambar warnanya), TIDAK bila cuma tebal.
        $this->assertSame(['kosong', '', ['isi' => '#DDEBF7']], [$sel['G7']['t'], $sel['G7']['s'], $sel['G7']['g']]);
        $this->assertArrayNotHasKey('H7', $sel);

        $tata = $input['tata_letak'];
        $this->assertSame(['A5:C5'], $tata['merge']);
        // Kontrak mobile `TataLetakLembar.fromJson`: peta huruf → lebar, peta baris → tinggi, `freeze`.
        $this->assertSame(20.0, $tata['lebar_kolom']['B']);
        $this->assertSame([3 => 30.0], $tata['tinggi_baris']);
        $this->assertSame(['E'], $tata['kolom_tersembunyi']);
        $this->assertSame([4], $tata['baris_tersembunyi']);
        // Dari xSplit/ySplit, bukan topLeftCell pane (D10 = posisi gulir saat disimpan).
        $this->assertSame('B3', $tata['freeze']);
        $this->assertArrayNotHasKey('beku', $tata);
        $this->assertSame(1, $tata['jumlah_komentar']);
        $this->assertSame([12.5, 18.0, false, false, 85], [
            $tata['lebar_kolom_bawaan'], $tata['tinggi_baris_bawaan'], $tata['lebar_perkiraan'], $tata['garis_kisi'], $tata['zoom'],
        ]);
        $this->assertSame(array_keys(KanonikWorkbook::tataLetakKosong()), array_keys($tata));

        $kabel = (string) json_encode(KanonikWorkbook::tataLetakKabel($tata), JSON_PRESERVE_ZERO_FRACTION);
        $this->assertStringContainsString('"tinggi_baris":{"3":30.0}', $kabel);
        $this->assertStringContainsString('"B":20.0', $kabel);
        $this->assertStringContainsString('"freeze":"B3"', $kabel);
        $this->assertStringContainsString('"merge":["A5:C5"]', $kabel);
        $this->assertStringContainsString('"garis_kisi":false', $kabel);

        // Lembar tanpa tata letak (Bantu): peta kosong tetap OBJEK di kabel;
        // bawaan sheet tidak disebut → bawaan font workbook (Calibri 11: 9,140625 / 15 pt).
        $bantu = $hasil['lembar'][2]['tata_letak'];
        $kosong = (string) json_encode(KanonikWorkbook::tataLetakKabel($bantu));
        $this->assertStringContainsString('"tinggi_baris":{}', $kosong);
        $this->assertSame([9.140625, 15.0, true, null, null], [
            $bantu['lebar_kolom_bawaan'], $bantu['tinggi_baris_bawaan'], $bantu['garis_kisi'], $bantu['zoom'], $bantu['freeze'],
        ]);

        // K-49-12: isi DATABASE & SERTIFIKAT terbaca apa adanya.
        $this->assertSame(self::KANARI_DATABASE, json_decode($hasil['lembar'][1]['sel_kanonik'], true)[0]['s']);
        $this->assertSame(self::KANARI_SERTIFIKAT, json_decode($hasil['lembar'][4]['sel_kanonik'], true)[0]['s']);
        $this->assertSame(1, $hasil['lembar'][1]['jumlah_sel']);

        $this->assertStringNotContainsString(self::SANDI_UJI, (string) json_encode($hasil));

        $this->assertSame([], $this->isiTemp(), 'temp terdekripsi tertinggal sesudah jalur sukses');
    }

    public function test_sandi_salah_kode_stabil_dan_temp_bersih(): void
    {
        $berkas = $this->buatXlsm();

        try {
            app(PembacaXlsmWorkbook::class)->baca($berkas, 'bukan-sandinya');
            $this->fail('sandi salah harus ditolak');
        } catch (GagalBacaWorkbook $e) {
            $this->assertSame(GagalBacaWorkbook::SANDI_SALAH, $e->kode);
            $this->assertNull($e->getPrevious(), 'galat dekripsi tidak boleh dirantai (jejaknya membawa sandi)');
            $this->assertStringNotContainsString('bukan-sandinya', $e->getMessage().$e->getTraceAsString());
        }

        $this->assertSame([], $this->isiTemp());
    }

    public function test_exception_di_tengah_load_temp_privat_tetap_bersih(): void
    {
        $berkas = $this->buatXlsm();

        // Saringan yang meledak saat sel pertama INPUT DATA dibaca: exception
        // terjadi DI DALAM Reader\Xlsx::load(), selagi ZIP terdekripsi masih
        // dipegang reader — persis jalur yang bocor di spike S0c.
        $pembaca = new class(new PengecualianLembar) extends PembacaXlsmWorkbook
        {
            /** @var list<string> */
            public array $tempSaatLedak = [];

            protected function saringan(): IReadFilter
            {
                $self = $this;

                return new class($self) implements IReadFilter
                {
                    public function __construct(private readonly object $induk) {}

                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        $akar = PembacaXlsmWorkbook::akarTemp();
                        $this->induk->tempSaatLedak = glob($akar.'/*/*') ?: [];

                        throw new RuntimeException('UJI: gagal disengaja di tengah load()');
                    }
                };
            }
        };

        try {
            $pembaca->baca($berkas, self::SANDI_UJI);
            $this->fail('exception di tengah load harus naik');
        } catch (GagalBacaWorkbook $e) {
            $this->assertSame(GagalBacaWorkbook::GAGAL_MUAT, $e->kode);
        }

        // Bukti fixture-nya benar-benar menguji: saat meledak, ZIP terdekripsi ADA di temp privat.
        $this->assertNotEmpty($pembaca->tempSaatLedak, 'ZIP terdekripsi tidak ada saat exception — test tidak menguji jalur bocor');
        $this->assertSame([], $this->isiTemp(), 'temp terdekripsi tertinggal sesudah exception di tengah load()');
    }

    public function test_profil_spin_di_atas_batas_dan_bukan_ooxml_ditolak(): void
    {
        $spinBesar = $this->buatXlsm('spin-besar.xlsm', PembacaXlsmWorkbook::SPIN_MAKS + 1);

        try {
            app(PembacaXlsmWorkbook::class)->baca($spinBesar, self::SANDI_UJI);
            $this->fail('spin > batas harus ditolak');
        } catch (GagalBacaWorkbook $e) {
            $this->assertSame(GagalBacaWorkbook::PROFIL_ENKRIPSI_DITOLAK, $e->kode);
        }

        $palsu = $this->folderUji.'/palsu.xlsm';
        file_put_contents($palsu, 'bukan workbook');

        try {
            app(PembacaXlsmWorkbook::class)->baca($palsu, self::SANDI_UJI);
            $this->fail('bukan OOXML harus ditolak');
        } catch (GagalBacaWorkbook $e) {
            $this->assertSame(GagalBacaWorkbook::BUKAN_OOXML, $e->kode);
        }

        $this->assertSame([], $this->isiTemp());
    }

    public function test_perintah_xlsm_simpan_berkas_asli_identik_dan_idempoten(): void
    {
        $lab = Organization::factory()->create();
        $berkas = $this->buatXlsm();
        $sha = hash_file('sha256', $berkas);

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas], '--oleh' => $this->olehLab($lab)])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
            ->doesntExpectOutputToContain(self::SANDI_UJI)
            ->doesntExpectOutputToContain(self::KANARI_DATABASE)
            ->expectsOutputToContain('baru 1')
            ->assertSuccessful();

        $wb = WorkbookMaster::query()->sole();
        $this->assertSame([$lab->id, 'xlsm', $sha, filesize($berkas), 'micrometer', 5], [
            $wb->organization_id, $wb->sumber, $wb->sha256, $wb->ukuran, $wb->kode_profil, $wb->jumlah_lembar,
        ]);
        $this->assertSame('local', $wb->berkas_disk);
        $this->assertSame(ImporWorkbook::jalurBerkasAsli($lab->id, $sha), $wb->berkas_path);
        $this->assertSame("workbook-master/{$lab->id}/{$sha}.xlsm", $wb->berkas_path);
        $this->assertTrue($wb->berkas_terenkripsi);

        // K-49-11: byte asli TERENKRIPSI, identik — bukan versi terdekripsi.
        $tersimpan = Storage::disk('local')->get($wb->berkas_path);
        $this->assertSame(file_get_contents($berkas), $tersimpan);
        $this->assertStringStartsWith("\xD0\xCF\x11\xE0", (string) $tersimpan);

        // K-49-12: isi DATABASE/SERTIFIKAT tersimpan (lembar bertautan, tidak dikecualikan); sandi tidak pernah.
        $isiDb = (string) json_encode([DB::table('workbook_master')->get(), DB::table('workbook_master_lembar')->get(), DB::table('audit_logs')->get()]);
        $this->assertStringNotContainsString(self::SANDI_UJI, $isiDb);
        $database = $wb->lembar()->where('nama', 'DATABASE')->sole();
        $this->assertFalse($database->dikecualikan);
        $this->assertSame('customers', $database->tautan_tabel);
        $this->assertSame(self::KANARI_DATABASE, $database->daftarSel()[0]['s']);
        $this->assertSame(self::KANARI_SERTIFIKAT, $wb->lembar()->where('nama', 'SERTIFIKAT')->sole()->daftarSel()[0]['s']);

        // Impor ulang: dikenali dari sha256 byte, tidak menulis apa pun.
        $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas], '--oleh' => $this->olehLab($lab)])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', 'sandi-apa-pun')
            ->expectsOutputToContain('sudah_ada 1')
            ->assertSuccessful();
        $this->assertSame(1, WorkbookMaster::query()->count());
        $this->assertSame([], $this->isiTemp());
    }

    public function test_perintah_xlsm_sandi_salah_dilaporkan_lalu_lanjut_berkas_lain(): void
    {
        Organization::factory()->create();
        $baik = $this->buatXlsm('Master Olah Data Autoclave.xlsm');
        $lain = $this->buatXlsm('Master Olah Data Sieve.xlsm', sandi: 'sandi-lain-sendiri');

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$this->folderUji], '--oleh' => $this->olehLab()])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
            ->expectsOutputToContain('sandi_workbook_salah')
            ->expectsOutputToContain('baru 1')
            ->doesntExpectOutputToContain(self::SANDI_UJI)
            ->assertFailed();

        $this->assertSame(['Master Olah Data Autoclave'], WorkbookMaster::query()->pluck('nama')->all());
        $this->assertSame('autoclave', WorkbookMaster::query()->value('kode_profil'));
        $this->assertSame(1, count(Storage::disk('local')->allFiles('workbook-master')));
        $this->assertFileExists($baik);
        $this->assertFileExists($lain);
        $this->assertSame([], $this->isiTemp());
    }

    /**
     * K-49-12 mencabut penahan `--saya-paham-data-pelanggan`: opsinya sudah
     * tidak ada, dan `--xlsm` polos langsung jalan.
     */
    public function test_penahan_data_pelanggan_sudah_dicabut(): void
    {
        $this->assertFalse(
            $this->app->make(Kernel::class)->all()['studio:impor-workbook']->getDefinition()->hasOption('saya-paham-data-pelanggan'),
        );
    }

    /**
     * `--ganti` untuk xlsm (sesudah format kanonik naik): berkas yang byte-nya
     * SAMA diekstrak ulang dan menggantikan baris xlsm bernama sama di lab
     * yang sama; baris csv bernama sama, xlsm bernama lain, dan lab lain tidak
     * tersentuh. Tanpa `--ganti` tetap `sudah_ada` tanpa membuka berkas.
     */
    public function test_ganti_xlsm_mengekstrak_ulang_hanya_xlsm_bernama_sama_di_lab_sendiri(): void
    {
        $lab = Organization::factory()->create();
        $lab2 = Organization::factory()->create();
        $berkas = $this->buatXlsm();
        $nama = 'Master Olah Data Micrometer 0-25mm';
        $lain = $this->buatXlsm('Master Olah Data Sieve.xlsm');

        foreach ([$lab, $lab2] as $org) {
            $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas, $lain], '--organisasi' => (string) $org->id, '--oleh' => $this->olehLab($org)])
                ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
                ->expectsOutputToContain('baru 2')
                ->assertSuccessful();
        }

        $lama = WorkbookMaster::query()->where('organization_id', $lab->id)->where('nama', $nama)->sole();
        $lembarLama = $lama->lembar()->pluck('id')->all();
        // Tiru baris yang diekstrak format lama: lembar DATABASE masih dikecualikan.
        DB::table('workbook_master_lembar')->whereIn('id', $lembarLama)->where('nama', 'DATABASE')->update(['dikecualikan' => true, 'sel' => '[]']);
        $csv = WorkbookMaster::query()->create([
            'organization_id' => $lab->id, 'nama' => $nama, 'sumber' => WorkbookMaster::SUMBER_CSV,
            'sha256' => str_repeat('c', 64), 'ukuran' => 1, 'jumlah_lembar' => 0,
        ]);
        $sieve = WorkbookMaster::query()->where('organization_id', $lab->id)->where('nama', 'Master Olah Data Sieve')->sole();
        $milikLab2 = WorkbookMaster::query()->where('organization_id', $lab2->id)->where('nama', $nama)->sole();

        // Tanpa --ganti: dikenali dari sha byte, tidak menulis.
        $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas], '--organisasi' => (string) $lab->id, '--oleh' => $this->olehLab($lab)])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
            ->expectsOutputToContain('sudah_ada 1')
            ->assertSuccessful();
        $this->assertTrue(WorkbookMaster::query()->whereKey($lama->id)->exists());

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas], '--organisasi' => (string) $lab->id, '--ganti' => true, '--oleh' => $this->olehLab($lab)])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
            // Satu baris keluaran hanya bisa memenuhi satu harapan: "(diganti 1) … diganti: 1".
            ->expectsOutputToContain('(diganti 1)')
            ->assertSuccessful();

        $this->assertFalse(WorkbookMaster::query()->whereKey($lama->id)->exists());
        $this->assertSame(0, WorkbookMasterLembar::query()->whereIn('id', $lembarLama)->count());

        $baru = WorkbookMaster::query()->where('organization_id', $lab->id)->where('nama', $nama)->where('sumber', 'xlsm')->sole();
        $this->assertSame($lama->sha256, $baru->sha256);
        $this->assertSame($lama->berkas_path, $baru->berkas_path);
        $this->assertTrue(Storage::disk('local')->exists($baru->berkas_path), 'berkas asli tidak boleh ikut terhapus');
        $this->assertStringContainsString("Menggantikan impor xlsm lama #{$lama->id}", (string) $baru->catatan);
        $database = $baru->lembar()->where('nama', 'DATABASE')->sole();
        $this->assertFalse($database->dikecualikan);
        $this->assertSame(self::KANARI_DATABASE, $database->daftarSel()[0]['s']);

        foreach ([$csv, $sieve, $milikLab2] as $tetap) {
            $this->assertTrue(WorkbookMaster::query()->whereKey($tetap->id)->exists(), "#{$tetap->id} {$tetap->sumber} tidak boleh tersentuh");
        }

        $this->assertTrue(AuditLog::query()
            ->where('organization_id', $lab->id)
            ->where('entity_type', 'workbook_master')
            ->where('entity_id', $lama->id)
            ->where('action', AuditLog::ACTION_DIHAPUS)
            ->exists());
        $this->assertSame([], $this->isiTemp());
    }

    /**
     * Tinjau S1b #5: `.xlsm` ZIP polos (tidak terenkripsi) DITANDAI — tidak
     * pernah tampil sebagai terenkripsi sandi, di DB, catatan, maupun API.
     * Tahap C (14 Okt 2026): sandi tidak ditanya bila tidak ada berkas
     * bersandi, dan salinannya disimpan terenkripsi kunci aplikasi (`.enc`),
     * bukan ZIP polos di disk; dekripsinya identik byte dengan sumber.
     */
    public function test_xlsm_tidak_terenkripsi_ditandai_dan_salinannya_terenkripsi_kunci_aplikasi(): void
    {
        $lab = Organization::factory()->create();
        $berkas = $this->buatXlsm('Master Olah Data Autoclave.xlsm', sandi: null);
        $this->assertStringStartsWith("PK\x03\x04", (string) file_get_contents($berkas, false, null, 0, 4));

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas], '--oleh' => $this->olehLab($lab)])
            ->expectsOutputToContain('baru 1')
            ->assertSuccessful();

        $wb = WorkbookMaster::query()->sole();
        $this->assertFalse($wb->berkas_terenkripsi);
        $this->assertStringContainsString('TIDAK terenkripsi', (string) $wb->catatan);
        $this->assertStringEndsWith('.xlsm.enc', (string) $wb->berkas_path);
        $tersimpan = (string) Storage::disk('local')->get($wb->berkas_path);
        $this->assertStringStartsNotWith("PK\x03\x04", $tersimpan);
        $this->assertSame(file_get_contents($berkas), Crypt::decryptString($tersimpan));

        $admin = User::factory()->create(['organization_id' => $lab->id, 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_AKTIF]);
        $this->actingAs($admin)->getJson('/api/studio/workbook')->assertOk()
            ->assertJsonPath('data.0.berkas_asli_tersimpan', true)
            ->assertJsonPath('data.0.berkas_asli_terenkripsi', false);
    }

    public function test_kering_xlsm_tidak_menyimpan_berkas_maupun_baris(): void
    {
        Organization::factory()->create();
        $berkas = $this->buatXlsm();

        $this->artisan('studio:impor-workbook', ['--xlsm' => [$berkas], '--kering' => true])
            ->expectsQuestion('Sandi workbook (tidak ditampilkan, tidak disimpan)', self::SANDI_UJI)
            ->expectsOutputToContain('kering 1')
            ->assertSuccessful();

        $this->assertSame(0, WorkbookMaster::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('workbook-master'));
    }

    public function test_penyapu_hanya_menghapus_sisa_yang_tua(): void
    {
        $akar = PembacaXlsmWorkbook::akarTemp();
        mkdir($akar.'/impor-tua', 0700, true);
        file_put_contents($akar.'/impor-tua/isi.zip', 'zip terdekripsi');
        mkdir($akar.'/impor-baru', 0700, true);
        touch($akar.'/impor-tua', time() - 2 * 3600);

        $this->artisan('studio:sapu-temp-workbook')
            ->expectsOutputToContain('1 dihapus')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($akar.'/impor-tua');
        $this->assertDirectoryExists($akar.'/impor-baru');

        $this->artisan('studio:sapu-temp-workbook', ['--umur' => '0'])->assertSuccessful();
        $this->assertSame([], $this->isiTemp());
    }
}
