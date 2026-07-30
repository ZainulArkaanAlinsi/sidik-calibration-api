<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `folders.customer_id` boleh NULL — folder manual nggak punya pelanggan.
 *
 * Gejalanya: bikin folder dari layar Arsip mati dengan
 * `SQLSTATE[HY000] 1364 Field 'customer_id' doesn't have a default value`.
 *
 * ## Kenapa kejadian
 *
 * Ini lanjutan langsung dari perbaikan folders 29 Juli, dan penyebabnya sama:
 * tabel `folders` di database dev **udah ada duluan** dengan bentuk yang lebih
 * tua, jadi penjaga `hasTable` di `create_folders_table` ngeloncatin seluruh
 * migrasinya. Migrasi itu mendefinisikan `customer_id` sebagai
 * `->nullable()->constrained()->nullOnDelete()`, tapi yang kepasang di dev
 * tetap versi lama: `NOT NULL`, tanpa default.
 *
 * Migrasi penyelaras 29 Juli (`...selaraskan_kolom_folders_yang_ketinggalan`)
 * nambahin `tipe` & `keterangan` yang hilang, tapi dia nyocokin **keberadaan**
 * kolom lewat `hasColumn` — bukan **definisinya**. Kolom yang ada tapi salah
 * bentuk lolos semua pemeriksaan itu.
 *
 * Jadi pelajaran yang udah ditulis di dokumen serah terima ternyata baru
 * setengah diterapkan: "tiap tabel yang keskip penjaga mesti dicocokin
 * kolomnya satu-satu" itu berarti tipe, nullability, dan default — bukan cuma
 * ada/nggak ada.
 *
 * ## Kenapa nullable, bukan diisi default
 *
 * Folder ada dua macam: `sistem` (kebentuk sendiri dari data, nempel ke satu
 * pelanggan) dan `manual` (bikinan admin buat ngerapiin, nggak mewakili
 * pelanggan mana pun). `customer_id` NULL itu maknanya "folder ini bukan milik
 * pelanggan tertentu" — bukan data yang kurang. Nambal pakai default angka
 * bikin folder manual kelihatan punya pelanggan yang nggak pernah dipilih
 * siapa pun, dan itu nyasar ke tempat yang lebih susah dilacak: berkas resmi
 * nempel ke PT yang salah.
 *
 * ## Yang SENGAJA nggak diikutin di sini
 *
 * Dua beda lain ketemu waktu ngebandingin, dan dua-duanya DIBIARIN:
 *
 * - `customer_id` on delete: migrasi bilang `SET NULL`, dev-nya `CASCADE`
 * - `parent_id` on delete: migrasi bilang `CASCADE`, dev-nya `RESTRICT`
 *
 * Keduanya ngubah APA YANG KEHAPUS, dan itu keputusan produk, bukan
 * penyelarasan skema. Efeknya juga dorman: `customers` & `folders` dua-duanya
 * pakai SoftDeletes, jadi `ON DELETE` di level database praktis nggak pernah
 * kepanggil di operasi normal. Ditulis di sini biar yang berikutnya nggak
 * ngira skemanya udah 100% cocok.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->perluDiubah()) {
            return;
        }

        Schema::table('folders', function (Blueprint $table) {
            // FK-nya nggak disentuh: MySQL ngizinin MODIFY nullability tanpa
            // ngedrop foreign key, dan FK ke kolom nullable itu sah. Nyebut
            // `constrained()` di sini malah bikin dia nyoba nambah FK kedua.
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sengaja no-op. Balikin ke NOT NULL bakal mecahin bikin-folder-manual
        // lagi, dan gagal total kalau udah ada baris yang customer_id-nya NULL
        // — yaitu tiap folder manual yang kebikin sesudah migrasi ini jalan.
        // Rollback yang bikin data nggak muat lebih jelek daripada nggak ada
        // rollback.
    }

    /**
     * Cuma jalan di database yang beneran salah bentuk.
     *
     * `getColumns()` dipakai, bukan query `information_schema`, biar
     * pemeriksaannya sama-sama jalan di MySQL (dev/produksi) dan SQLite (test).
     * Di database yang dibangun dari migrasi, `customer_id` udah nullable dari
     * awal, jadi migrasi ini no-op di CI.
     */
    private function perluDiubah(): bool
    {
        if (! Schema::hasTable('folders') || ! Schema::hasColumn('folders', 'customer_id')) {
            return false;
        }

        foreach (Schema::getColumns('folders') as $kolom) {
            if ($kolom['name'] === 'customer_id') {
                return $kolom['nullable'] === false;
            }
        }

        return false;
    }
};
