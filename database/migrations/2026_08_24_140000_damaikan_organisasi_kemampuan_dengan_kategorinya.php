<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris kemampuan kalibrasi cuma boleh punya SATU pemilik.
 *
 * ## Masalahnya: dua sumber kebenaran yang nggak pernah didamaikan
 *
 * Sejak migrasi 2026_08_24_100000, kepemilikan baris kemampuan ditulis di dua
 * tempat:
 *
 *  - `calibration_capabilities.organization_id` — dipakai panel Filament
 *    (`ScopesToOrganization`) dan `CalibrationCapability::scopeMilikOrganisasi`.
 *  - `equipment_categories.organization_id` — dipakai SELURUH jalur baca API
 *    (`CategoryController` nyaring kategorinya, relasi `capabilities`-nya lewat
 *    `equipment_category_id` doang) dan mesin hitung
 *    (`GumCalculator::kemampuanUntukTitik()`).
 *
 * Selama dua-duanya kebetulan sama, nggak ada yang kelihatan. Satu baris yang
 * beda cukup buat bikin **angka CMC lab A jadi lantai ketidakpastian di
 * sertifikat lab B** — tanpa satu pun error, di dokumen yang nyatain dirinya
 * terakreditasi. Baris kayak gitu bisa lahir dari panel: dropdown Kategori di
 * `CalibrationCapabilityForm` nawarin kategori SEMUA lab, sementara
 * `organization_id`-nya dicap dari admin yang login.
 *
 * ## Yang dikerjakan migrasi ini, berurutan
 *
 * 1. **Baris yang udah nyasar didamaikan**, `organization_id`-nya diselaraskan
 *    ke organisasi kategorinya — bukan sebaliknya, dan bukan dihapus. Lihat
 *    alasannya di bawah.
 * 2. **Kunci di tingkat DB**: foreign key gabungan
 *    `(equipment_category_id, organization_id)` → `equipment_categories
 *    (id, organization_id)`. Nggak ada jalur tulis mana pun — tinker, SQL
 *    tangan, impor — yang bisa bikin baris nyasar lagi.
 *
 * ## Kenapa diselaraskan ke kategori, BUKAN sebaliknya, dan bukan dihapus
 *
 * Karena penyelarasan ke arah ini NGGAK MENGGESER SATU ANGKA PUN.
 *
 * Semua jalur yang bisa bikin angka baris itu nyampe ke sertifikat — daftar
 * kemampuan di `GET /categories/{kode}`, validasi `nama_alat_kemampuan`,
 * pencocokan titik di `GumCalculator` — nyarinya lewat `equipment_category_id`.
 * Artinya, secara perilaku, baris nyasar itu SELAMA INI memang sudah jadi milik
 * lab pemilik kategorinya; kolom `organization_id`-nya cuma nggak jujur soal
 * itu. Nyamain kolom ke kategori bikin nilainya jujur tanpa mindahin apa pun.
 *
 * Dua pilihan lain sama-sama menggeser angka, dan itu yang bikin mereka salah
 * buat migrasi otomatis:
 *  - Mindahin `equipment_category_id` ke kategori lab pemilik kolom = baris
 *    itu HILANG dari kategori yang selama ini memakainya, dan sesi berikutnya
 *    di lab itu jatuh ke jalur generik tanpa lantai CMC — U95 yang terbit
 *    mengecil, persis kegagalan yang seluruh penjagaan CMC ada buat mencegah.
 *  - Menghapus barisnya sama saja, cuma lebih permanen.
 *
 * Perubahan yang beneran terjadi ke orang: baris tadi PINDAH panel. Dulu
 * kelihatan di Master Data admin lab A (panel nyaring lewat kolom), sekarang
 * kelihatan di lab B — lab yang API & mesin hitungnya emang udah memakainya.
 * Makanya tiap baris yang kegeser dicatat ke log dengan id-nya, bukan
 * didiamkan.
 *
 * ## Query buat nemuin sendiri, kapan pun
 *
 *     select cc.id, cc.nama_alat, cc.organization_id as org_baris,
 *            ec.organization_id as org_kategori
 *     from calibration_capabilities cc
 *     join equipment_categories ec on ec.id = cc.equipment_category_id
 *     where cc.organization_id <> ec.organization_id;
 *
 * Sesudah migrasi ini jalan, query itu HARUS pulang kosong selamanya — dan
 * kalau nggak, FK gabungan di bawah yang bakal nolak duluan.
 *
 * ## Soal SQLite — DIPERIKSA, bukan diasumsikan
 *
 * Dugaan pertama waktu migrasi ini ditulis: FK gabungan cuma bakal hidup di
 * MySQL, karena `SQLiteGrammar::compileForeign()` emang nggak nulis apa-apa
 * buat FK yang ditambah ke tabel yang udah ada. Itu SALAH di Laravel 13 —
 * `Schema::table()` di SQLite mbangun ulang tabelnya, dan FK-nya beneran
 * kepasang. Kebuktinya di test: baris nyasar yang disuntik `DB::table()` mental
 * di `SQLSTATE[23000] FOREIGN KEY constraint failed`, di SQLite.
 *
 *     pragma foreign_key_list(calibration_capabilities);
 *     -- id 0 seq 0: equipment_category_id -> equipment_categories.id
 *     -- id 0 seq 1: organization_id       -> equipment_categories.organization_id
 *
 * Artinya kuncinya ditegakkan di DUA mesin, dan test yang perlu bikin baris
 * warisan (buat mbuktiin jalur baca berdiri sendiri) harus sengaja mematikan
 * FK-nya dulu — lihat `ScopeOrganisasiKemampuanTest::barisNyasar()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nyasar = DB::table('calibration_capabilities as cc')
            ->join('equipment_categories as ec', 'ec.id', '=', 'cc.equipment_category_id')
            ->whereColumn('cc.organization_id', '!=', 'ec.organization_id')
            ->select([
                'cc.id',
                'cc.nama_alat',
                'cc.organization_id as org_baris',
                'ec.organization_id as org_kategori',
            ])
            ->get();

        // Dicatat SEBELUM diperbaiki. Sesudah `update` jalan, jejak siapa yang
        // pindah panel nggak bisa direkonstruksi dari data lagi.
        foreach ($nyasar as $baris) {
            Log::warning('Kemampuan kalibrasi nyasar organisasi — diselaraskan ke organisasi kategorinya.', [
                'calibration_capability_id' => $baris->id,
                'nama_alat' => $baris->nama_alat,
                'organization_id_lama' => $baris->org_baris,
                'organization_id_baru' => $baris->org_kategori,
            ]);
        }

        if ($nyasar->isNotEmpty()) {
            // Subquery berkorelasi, bukan `UPDATE ... JOIN`: sintaks JOIN di
            // UPDATE cuma sah di MySQL, dan test jalan di SQLite. Alasan yang
            // sama persis kayak migrasi 2026_08_24_100000.
            DB::table('calibration_capabilities')
                ->whereIn('id', $nyasar->pluck('id')->all())
                ->update([
                    'organization_id' => DB::raw(
                        '(select organization_id from equipment_categories '
                        .'where equipment_categories.id = calibration_capabilities.equipment_category_id)',
                    ),
                ]);
        }

        // Indeks unik penopang FK gabungan di bawah.
        //
        // `id` itu primary key, jadi `(id, organization_id)` unik dengan
        // sendirinya — indeksnya ADA bukan buat menegakkan keunikan, tapi karena
        // MySQL nolak bikin foreign key kalau kolom yang diacu nggak punya
        // indeks yang diawali kolom-kolom itu, dengan urutan yang sama.
        if (! $this->punyaIndeks('equipment_categories', 'eqcat_id_org_idx')) {
            Schema::table('equipment_categories', function (Blueprint $table) {
                $table->unique(['id', 'organization_id'], 'eqcat_id_org_idx');
            });
        }

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            // `cascadeOnDelete` biar sejalan sama DUA foreign key yang udah ada
            // di kolom-kolom ini (`equipment_category_id` cascade sejak migrasi
            // 2026_07_14_120300, `organization_id` cascade sejak 2026_08_24_100000).
            // RESTRICT di sini bakal bikin hapus kategori/organisasi mental —
            // dan mentalnya baru ketahuan di produksi, karena SQLite nggak
            // masang FK ini sama sekali.
            $table->foreign(['equipment_category_id', 'organization_id'], 'calcap_kategori_org_fk')
                ->references(['id', 'organization_id'])
                ->on('equipment_categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Cuma kuncinya yang dicabut. Baris yang udah diselaraskan SENGAJA
        // nggak dibalikin: nilai lamanya itu keadaan yang bikin angka CMC lab
        // lain bisa mendarat di sertifikat, dan rollback nggak berarti minta
        // lubangnya dibuka lagi.
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->dropForeign('calcap_kategori_org_fk');
        });

        Schema::table('equipment_categories', function (Blueprint $table) {
            $table->dropUnique('eqcat_id_org_idx');
        });
    }

    private function punyaIndeks(string $tabel, string $indeks): bool
    {
        return collect(Schema::getIndexes($tabel))
            ->contains(fn (array $i): bool => $i['name'] === $indeks);
    }
};
