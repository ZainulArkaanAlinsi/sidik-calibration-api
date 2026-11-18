<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\KategoriKelolaController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PemindaiKolomQuery;
use Tests\TestCase;

/**
 * Tiap tabel/kolom yang dirujuk query paket CRUD (18 Nov 2026) HARUS ada di skema.
 *
 * ## Kenapa berkas ini ada (jebakan SQLite, CI MySQL 18 Nov 2026)
 *
 * `KategoriKelolaController::pemakai()` menghitung `formulas.equipment_category_id`
 * -- kolom yang tidak ada. Di SQLite itu LOLOS: identifier ber-kutip-ganda yang
 * tidak dikenal diperlakukan sebagai string literal, kondisinya selalu salah, dan
 * hasilnya 0 tanpa galat. MySQL menjawab 500 `Unknown column`. Jadi test Feature
 * yang menjalankan query itu di SQLite tidak membuktikan nama kolomnya benar;
 * satu-satunya pembuktian yang ikut jalan di SQLite adalah memeriksa skemanya
 * langsung (`Schema::hasColumn`), seperti di sini.
 *
 * Daftar `DIRUJUK` disusun dari audit seluruh diff cabang (setiap `DB::table`,
 * `where`, `pluck`, `count`, `orderBy`, `Rule::exists`). Test kedua memindai
 * berkas-berkas baru: tabel atau kolom `Rule::exists`/`DB::table` literal yang
 * BELUM ada di daftar membuat test ini merah, jadi penambah query berikutnya
 * dipaksa mendaftarkannya (dan dengan begitu diperiksa terhadap skema).
 */
class KolomQueryPaketCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * tabel => kolom yang dirujuk kode paket CRUD (hasil audit diff).
     *
     * @var array<string, list<string>>
     */
    private const DIRUJUK = [
        'calibration_sessions' => [
            'id', 'organization_id', 'teknisi_id', 'status', 'nomor_sesi', 'client_request_id',
            'deleted_at', 'updated_at',
        ],
        'equipment_categories' => [
            'id', 'organization_id', 'kode', 'nama', 'nonaktif_pada', 'deleted_at', 'updated_at',
        ],
        'equipments' => ['equipment_category_id', 'deleted_at'],
        'calibration_capabilities' => ['equipment_category_id', 'deleted_at'],
        'calibration_methods' => ['equipment_category_id'],
        'formulas' => ['organization_id', 'besaran'],
        'penugasan' => [
            'id', 'organization_id', 'judul', 'tipe', 'status', 'tanggal_target', 'catatan', 'updated_at',
        ],
        'penugasan_teknisi' => ['id', 'penugasan_id', 'user_id', 'peran', 'dilihat_pada'],
        'penugasan_item' => [
            'id', 'penugasan_id', 'equipment_category_id', 'order_id', 'jenis_alat', 'jumlah',
            'jumlah_selesai', 'catatan',
        ],
        'users' => ['id', 'organization_id', 'role', 'status'],
        'orders' => ['id', 'organization_id'],
        'audit_logs' => [
            'id', 'organization_id', 'entity_type', 'entity_id', 'action', 'old_data', 'new_data',
            'changed_by', 'note',
        ],
    ];

    /**
     * Berkas paket CRUD yang memuat kueri/aturan `exists` baru (atau yang diubah
     * paket ini), dipindai `PemindaiKolomQuery`.
     */
    private const BERKAS = [
        'app/Http/Controllers/Api/KategoriKelolaController.php',
        'app/Http/Controllers/Api/CategoryController.php',
        'app/Http/Controllers/Api/ArsipDrafSesiController.php',
        'app/Http/Controllers/Api/PenugasanController.php',
        'app/Http/Requests/PenugasanUbahRequest.php',
        'app/Http/Requests/PenugasanRequest.php',
        'app/Http/Requests/KategoriRequest.php',
        'app/Http/Requests/EquipmentRequest.php',
        'app/Services/JejakAlasan.php',
    ];

    /** Relasi yang dikenali pemindai (nama => tabel); rantai di atasnya diperiksa ke tabel itu. */
    private const RELASI_TABEL = [
        'anggota' => 'penugasan_teknisi',
        'item' => 'penugasan_item',
    ];

    public function test_semua_tabel_dan_kolom_yang_dirujuk_paket_crud_ada_di_skema(): void
    {
        $hilang = [];
        $diperiksa = 0;

        foreach (self::DIRUJUK as $tabel => $kolom) {
            if (! Schema::hasTable($tabel)) {
                $hilang[] = "tabel `{$tabel}`";

                continue;
            }

            foreach ($kolom as $nama) {
                $diperiksa++;

                if (! Schema::hasColumn($tabel, $nama)) {
                    $hilang[] = "{$tabel}.{$nama}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(50, $diperiksa, 'daftar yang diperiksa menyusut diam-diam');
        $this->assertSame(
            [],
            $hilang,
            "Kolom/tabel ini dirujuk kode tetapi TIDAK ADA di skema (SQLite meloloskannya sebagai string literal, MySQL 500):\n  - "
            .implode("\n  - ", $hilang),
        );
    }

    public function test_pemakai_kategori_hanya_menyebut_kolom_yang_ada(): void
    {
        $this->assertGreaterThanOrEqual(4, count(KategoriKelolaController::PEMAKAI_LEWAT_ID));

        foreach (KategoriKelolaController::PEMAKAI_LEWAT_ID as $kunci => [$tabel, $kolom]) {
            $this->assertTrue(Schema::hasTable($tabel), "pemakai `{$kunci}`: tabel `{$tabel}` tidak ada");
            $this->assertTrue(Schema::hasColumn($tabel, $kolom), "pemakai `{$kunci}`: kolom `{$tabel}.{$kolom}` tidak ada");
        }

        [$tabel, $besaran, $organisasi] = KategoriKelolaController::PEMAKAI_RUMUS;
        $this->assertTrue(Schema::hasTable($tabel));
        $this->assertTrue(Schema::hasColumn($tabel, $besaran), "pemakai `rumus`: `{$tabel}.{$besaran}` tidak ada");
        $this->assertTrue(Schema::hasColumn($tabel, $organisasi), "pemakai `rumus`: `{$tabel}.{$organisasi}` tidak ada");

        // Pelajaran aslinya: `formulas` TIDAK punya FK kategori.
        $this->assertFalse(Schema::hasColumn('formulas', 'equipment_category_id'));
    }

    /**
     * Pemindai teks (`PemindaiKolomQuery`): SETIAP kolom literal di rantai
     * `->where()/orWhere/whereNull/whereIn/orderBy/pluck/value`, `DB::table()`, dan
     * `Rule::exists/unique` pada berkas paket CRUD diadu ke skema sungguhan.
     * Inilah yang akan menangkap `formulas.equipment_category_id`.
     */
    public function test_semua_kolom_di_rantai_kueri_berkas_paket_crud_ada_di_skema(): void
    {
        $diperiksa = [];
        $masalah = [];

        foreach (self::BERKAS as $berkas) {
            $hasil = PemindaiKolomQuery::pindai((string) file_get_contents(base_path($berkas)), self::RELASI_TABEL);

            foreach ($hasil as $h) {
                $diperiksa[$h['tabel'].'.'.$h['kolom']] = true;

                if (! Schema::hasTable($h['tabel'])) {
                    $masalah[] = "{$berkas}: tabel `{$h['tabel']}` (dari {$h['akar']}) tidak ada";
                } elseif (! Schema::hasColumn($h['tabel'], $h['kolom'])) {
                    $masalah[] = "{$berkas}: kolom `{$h['tabel']}.{$h['kolom']}` (dari {$h['akar']}) tidak ada di skema";
                }
            }
        }

        $this->assertGreaterThanOrEqual(35, count($diperiksa), 'pemindai hampir tidak menemukan apa pun: pola atau daftar berkasnya basi');
        $this->assertSame([], $masalah, 'Kolom/tabel yang dirujuk kueri tetapi TIDAK ADA di skema:
  - '.implode('
  - ', $masalah));
    }

    /**
     * Pemindainya sendiri harus mampu menangkap bug aslinya: kueri `formulas`
     * dengan kolom yang tidak ada ditemukan pemindai DAN dibuktikan tidak ada di
     * skema. Tanpa test ini pemindai bisa rusak diam-diam dan test di atas hijau
     * karena tidak menemukan apa-apa.
     */
    public function test_pemindai_menangkap_bug_formulas_yang_asli(): void
    {
        $bug = <<<'PHP'
<?php
$n = DB::table('formulas')->where('equipment_category_id', $id)->count();
$m = DB::table('calibration_methods')
    ->where('organization_id', 1)
    ->orderBy('kolom_ngawur')
    ->pluck('nama');
PHP;

        $hasil = PemindaiKolomQuery::pindai($bug);
        $pasangan = array_map(fn (array $h): string => $h['tabel'].'.'.$h['kolom'], $hasil);

        $this->assertContains('formulas.equipment_category_id', $pasangan);
        $this->assertContains('calibration_methods.kolom_ngawur', $pasangan);
        $this->assertContains('calibration_methods.nama', $pasangan);
        $this->assertFalse(Schema::hasColumn('formulas', 'equipment_category_id'));
        $this->assertFalse(Schema::hasColumn('calibration_methods', 'kolom_ngawur'));
    }

    public function test_pemindai_melewati_komentar_dan_koleksi_di_dalam_argumen(): void
    {
        $sumber = <<<'PHP'
<?php
// DB::table('palsu')->where('kolom_di_komentar', 1) -- tidak boleh dihitung; apostrof ' dan titik-koma ;
$a = User::query()
    // ->where('kolom_komentar_lain', 1)
    ->whereIn('id', $koleksi->pluck('user_id'))
    ->where('status', 'aktif')
    ->get();
$b = Penugasan::query()->whereHas('anggota', fn ($q) => $q->where('user_id', 1))->get();
PHP;

        $hasil = PemindaiKolomQuery::pindai($sumber);
        $pasangan = array_map(fn (array $h): string => $h['tabel'].'.'.$h['kolom'], $hasil);

        // `User`/`Penugasan` tidak bisa diselesaikan tanpa baris `use`, jadi tak ada akar -- yang
        // diuji di sini hanya `DB::table('palsu')` di komentar: harus TIDAK ditemukan.
        $this->assertNotContains('palsu.kolom_di_komentar', $pasangan);
        $this->assertNotContains('users.kolom_komentar_lain', $pasangan);
    }
}
