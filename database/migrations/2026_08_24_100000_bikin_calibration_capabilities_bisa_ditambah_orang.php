<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `calibration_capabilities` berhenti jadi tabel yang cuma bisa di-seed.
 *
 * Sampai sekarang isinya CUMA lampiran akreditasi LK-285-IDN (48 alat, 151
 * rentang) dan satu-satunya cara nambah nama alat itu nulis seeder atau SQL
 * langsung. Keputusan pemilik proyek: admin boleh ngelola dari panel, dan
 * teknisi boleh nambah nama alat sendiri LANGSUNG DIPAKAI tanpa nunggu
 * persetujuan. Empat kolom di bawah yang bikin itu mungkin tanpa ngerusak
 * baris yang udah ada.
 *
 * ## `organization_id` — diturunkan dari kategorinya, BUKAN dipatok 1
 *
 * Pilihannya dua: kasih `default(1)` atau isi dari data yang udah ada. Dipilih
 * yang kedua, dan alasannya bukan kerapian:
 *
 *  - Tiap baris kemampuan WAJIB punya `equipment_category_id` (FK
 *    `constrained()`, NOT NULL sejak migrasi 2026_07_14_120300), dan tiap
 *    `equipment_categories` WAJIB punya `organization_id`. Jadi pemilik yang
 *    BENAR buat tiap baris udah ada di database — nggak perlu ditebak.
 *  - `default(1)` cuma kebetulan benar selama instalasinya satu-PT. Begitu ada
 *    PT kedua, seluruh baris lama nunjuk ke organisasi yang salah, dan yang
 *    kelihatan bukan error: teknisi PT kedua bakal lihat daftar alat PT
 *    pertama di dropdown-nya. Angka CMC PT lain yang nyampe sertifikat itu
 *    temuan audit, bukan bug tampilan.
 *
 * Urutannya: bikin nullable → isi dari kategori → baru dikunci NOT NULL. Kalau
 * ada satu baris aja yang nggak keisi, langkah ketiga GAGAL dan migrasinya
 * berhenti — itu yang diinginkan. Kolom scoping yang diam-diam NULL lebih
 * berbahaya daripada migrasi yang nolak jalan: `where('organization_id', ...)`
 * nggak pernah cocok sama NULL, jadi barisnya bakal ilang dari SEMUA organisasi
 * tanpa satu pun pesan.
 *
 * ## `sumber` — varchar, bukan ENUM
 *
 * Sengaja `string`, walaupun `folder_files.sumber` di sebelah pakai ENUM.
 * Pelajarannya udah dibayar dua kali di repo ini (lihat migrasi 2026_07_30_110000
 * dan 2026_08_07_150000): ENUM cuma ditegakkan MySQL, SQLite (yang dipakai
 * seluruh test) nerima string apa pun. Jadi nilai baru yang kelupaan didaftarin
 * bakal hijau di 700+ test dan baru nolak di produksi, sebagai `Data truncated`
 * di tengah kerjaan teknisi. Daftar nilainya ditegakkan di
 * `CalibrationCapability::SUMBER` + validasi request — di tempat yang test-nya
 * beneran jalan.
 *
 * ## Kenapa `ketidakpastian_terbaik` & satuan jadi nullable
 *
 * Ini yang paling penting dan paling gampang dibaca sebagai pelonggaran asal:
 * baris yang ditambah teknisi TIDAK punya angka CMC, dan nggak boleh dipaksa
 * punya. Diisi 0 berarti lab ngeklaim ketidakpastian terbaik NOL — angka yang
 * mustahil, yang bakal jadi lantai CMC di `GumCalculator::hitungDariKemampuan()`
 * dan bikin U yang terbit lebih KECIL daripada yang diakreditasi, tanpa satu pun
 * error. NULL jujur: "belum ada angkanya". Yang mastiin NULL nggak diam-diam
 * kepakai ada di `CalibrationCapability::punyaCmc()` + penjaga di
 * `GumCalculator::kemampuanUntukTitik()` dan `CalibrationValidator`.
 *
 * ## Soal `down()`
 *
 * `down()` di sini BENERAN ngembaliin, termasuk ngehapus baris yang
 * `ketidakpastian_terbaik`-nya NULL. Itu disengaja dan bukan efek samping:
 * baris kayak gitu MUSTAHIL ada di skema lama (kolomnya NOT NULL), jadi
 * ninggalinnya berarti `ALTER` balik ke NOT NULL bakal gagal — atau lebih
 * buruk, ada yang "mbenerin" dengan ngisi 0 dan lantai CMC-nya jadi nol diam-
 * diam. Rollback migrasi ini = mencabut fiturnya, dan baris yang cuma bisa
 * lahir dari fitur ini ikut tercabut. Baris akreditasi nggak kesentuh sama
 * sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            // Dijaga per kolom, bukan per migrasi — alasan yang sama kayak
            // migrasi konstanta budget 31 Juli: tabel yang pernah keskip
            // sebagian bisa punya sebagian kolom aja, dan kalau seluruh blok
            // diloncati, yang belum ada nggak akan pernah kebikin.
            if (! Schema::hasColumn('calibration_capabilities', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasColumn('calibration_capabilities', 'dibuat_oleh_user_id')) {
                // `nullOnDelete`, bukan cascade: kalau teknisinya resign dan
                // akunnya dihapus, nama alat yang dia tambahin harus TETAP ada.
                // Sesi & sertifikat yang udah nunjuk ke situ nggak boleh ikut
                // ilang cuma gara-gara pegawainya keluar.
                $table->foreignId('dibuat_oleh_user_id')->nullable()->after('keterangan')
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('calibration_capabilities', 'sumber')) {
                $table->string('sumber', 20)->default('akreditasi')->after('dibuat_oleh_user_id');
            }

            if (! Schema::hasColumn('calibration_capabilities', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Diisi dari kategorinya. Subquery berkorelasi, bukan `UPDATE ... JOIN`:
        // sintaks JOIN di UPDATE cuma sah di MySQL, dan test jalan di SQLite —
        // migrasi yang cuma bisa jalan di satu mesin itu bom waktu yang
        // meledaknya pas deploy.
        DB::table('calibration_capabilities')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select organization_id from equipment_categories '
                    .'where equipment_categories.id = calibration_capabilities.equipment_category_id)',
                ),
            ]);

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            // Baru dikunci SESUDAH keisi. Kalau masih ada yang NULL, baris ini
            // yang gagal — dan gagal di sini jauh lebih murah daripada baris
            // kemampuan yang nggak keliatan sama siapa pun di produksi.
            $table->foreignId('organization_id')->nullable(false)->change();

            // Angka CMC boleh kosong buat baris tambahan orang. Lihat docblock
            // di atas soal kenapa NULL, bukan 0.
            $table->decimal('ketidakpastian_terbaik', 20, 8)->nullable()->change();
            $table->string('satuan')->nullable()->change();
            $table->string('satuan_ketidakpastian')->nullable()->change();
        });

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            // Dua penyaring yang hampir tiap query di fitur ini pakai barengan:
            // "kemampuan milik organisasi X di kategori Y".
            $table->index(['organization_id', 'equipment_category_id'], 'calcap_org_kategori_idx');
        });
    }

    public function down(): void
    {
        // Baris tanpa angka CMC dibuang duluan. Mustahil ada di skema lama
        // (kolomnya NOT NULL), jadi ninggalinnya bikin `nullable(false)` di
        // bawah gagal. Lihat docblock kelas ini.
        DB::table('calibration_capabilities')->whereNull('ketidakpastian_terbaik')->delete();

        // FK-nya dilepas PALING DULUAN, sebelum indeksnya.
        //
        // MySQL milih `calcap_org_kategori_idx` sebagai indeks penopang foreign
        // key `organization_id` (kolom pertamanya sama), jadi ngedrop indeksnya
        // lebih dulu kena `1553 Cannot drop index ...: needed in a foreign key
        // constraint`. SQLite nggak peduli urutan — jadi kalau urutannya
        // dibalik, rollback-nya hijau di test dan mental di server.
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['dibuat_oleh_user_id']);
        });

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->dropIndex('calcap_org_kategori_idx');
        });

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->decimal('ketidakpastian_terbaik', 20, 8)->nullable(false)->change();
            $table->string('satuan')->nullable(false)->change();
            $table->string('satuan_ketidakpastian')->nullable(false)->change();
        });

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->dropColumn(['organization_id', 'dibuat_oleh_user_id', 'sumber', 'deleted_at']);
        });
    }
};
