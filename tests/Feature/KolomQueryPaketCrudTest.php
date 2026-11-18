<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\KategoriKelolaController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
     * Berkas paket CRUD yang memuat query/aturan `exists` baru. `EquipmentRequest`
     * sengaja tidak dipindai (banyak aturan lama di luar paket); kolom kategorinya
     * ({@see DIRUJUK} `equipment_categories`) tetap diperiksa di test pertama.
     */
    private const BERKAS = [
        'app/Http/Controllers/Api/KategoriKelolaController.php',
        'app/Http/Controllers/Api/ArsipDrafSesiController.php',
        'app/Http/Controllers/Api/PenugasanController.php',
        'app/Http/Requests/PenugasanUbahRequest.php',
        'app/Http/Requests/PenugasanRequest.php',
        'app/Http/Requests/KategoriRequest.php',
        'app/Services/JejakAlasan.php',
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
     * Pemindai: tabel dan kolom literal di `Rule::exists(...)`, `Rule::unique(...)`,
     * dan `DB::table(...)` pada berkas paket CRUD harus terdaftar di DIRUJUK.
     */
    public function test_literal_tabel_dan_kolom_di_berkas_paket_crud_terdaftar_dan_ada(): void
    {
        $ditemukan = 0;
        $masalah = [];

        foreach (self::BERKAS as $berkas) {
            $sumber = (string) file_get_contents(base_path($berkas));

            preg_match_all("/Rule::(?:exists|unique)\\('([a-z_]+)'(?:,\\s*'([a-z_]+)')?/", $sumber, $aturan, PREG_SET_ORDER);
            foreach ($aturan as $cocok) {
                $ditemukan++;
                $this->periksa($berkas, $cocok[1], $cocok[2] ?? null, $masalah);
            }

            preg_match_all("/DB::table\\('([a-z_]+)'\\)/", $sumber, $tabel, PREG_SET_ORDER);
            foreach ($tabel as $cocok) {
                $ditemukan++;
                $this->periksa($berkas, $cocok[1], null, $masalah);
            }
        }

        $this->assertGreaterThanOrEqual(8, $ditemukan, 'pemindai tidak menemukan apa pun: pola atau daftar berkasnya basi');
        $this->assertSame([], $masalah, "Literal tabel/kolom yang belum terdaftar atau tidak ada di skema:\n  - ".implode("\n  - ", $masalah));
    }

    /** @param  list<string>  $masalah */
    private function periksa(string $berkas, string $tabel, ?string $kolom, array &$masalah): void
    {
        if (! array_key_exists($tabel, self::DIRUJUK)) {
            $masalah[] = "{$berkas}: tabel `{$tabel}` belum ada di DIRUJUK (daftarkan setelah memastikan kolomnya ada)";

            return;
        }

        if ($kolom !== null && ! Schema::hasColumn($tabel, $kolom)) {
            $masalah[] = "{$berkas}: kolom `{$tabel}.{$kolom}` tidak ada di skema";
        }
    }
}
