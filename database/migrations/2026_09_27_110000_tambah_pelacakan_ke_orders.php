<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelacakan paket alat — "kayak lacak paket di online shop, tapi lebih detail".
 *
 * ## Yang TIDAK ditambahkan di sini, dan itu keputusan intinya
 *
 * Naluri pertama untuk fitur ini adalah satu kolom `order_items.tahap` yang
 * menyimpan delapan tahap perjalanan alat. Itu **ditolak**, dan alasannya layak
 * ditulis panjang karena kolom itu terlihat sangat wajar.
 *
 * Enam dari delapan tahapnya sudah punya sumber kebenaran di tempat lain:
 *
 *   | Tahap                    | Sudah terjawab oleh                          |
 *   |--------------------------|----------------------------------------------|
 *   | sedang dikalibrasi       | `calibration_sessions.status = draft`         |
 *   | menunggu pemeriksaan     | `= menunggu_approval`                         |
 *   | menunggu pengesahan      | `= menunggu_pengesahan`                       |
 *   | perlu diulang            | `= perlu_revisi`                              |
 *   | sertifikat terbit        | `certificates` ada & `status = terbit`        |
 *   | penerbitan gagal         | `certificates.status = gagal`                 |
 *
 * Kolom `tahap` yang menyalin itu akan **melenceng**. Bukan mungkin — pasti,
 * dan lewat jalan yang tidak kelihatan: admin mengembalikan sesi ke teknisi
 * lewat `reject()`, sesi turun ke `perlu_revisi`, dan `tahap` tetap "menunggu
 * pemeriksaan" karena `reject()` tidak tahu ada kolom itu. Yang dilihat
 * pelanggan lalu berbeda dari yang sebenarnya terjadi, tanpa satu pun error.
 * Menutupnya berarti setiap tempat yang mengubah status sesi harus ingat
 * memperbarui `tahap` juga — dan "harus ingat" adalah bentuk kegagalan yang
 * paling mahal di sistem ini.
 *
 * Jadi yang disimpan CUMA dua tahap yang tidak punya sumber lain, karena
 * dua-duanya peristiwa fisik di meja depan yang tidak tercermin di data mana
 * pun: **alat sudah siap diambil**, dan **alat sudah diserahkan ke pelanggan**.
 * Enam sisanya diturunkan `App\Services\TahapPaket` waktu dibaca.
 *
 * "Diterima" juga tidak disimpan — `orders.tanggal_masuk` sudah menjawabnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // ENUM dua nilai, bukan delapan. Sengaja — lihat docblock di atas.
            // Nullable berarti "belum sampai tahap fisik apa pun", dan tahapnya
            // diturunkan dari sesi kalibrasi.
            $table->enum('tahap_fisik', ['siap_diambil', 'diserahkan'])->nullable()->after('teknisi_id');
            $table->timestamp('tahap_fisik_pada')->nullable()->after('tahap_fisik');
            $table->foreignId('tahap_fisik_oleh')->nullable()->after('tahap_fisik_pada')->constrained('users');

            // Siapa yang menerima alat waktu diserahkan, apa adanya seperti yang
            // ditulis petugas depan. Bukan relasi ke `users` — yang mengambil
            // biasanya kurir atau staf pelanggan yang tidak punya akun, dan
            // memaksanya jadi akun berarti petugas depan mengarang data supaya
            // formulirnya bisa disimpan.
            $table->string('diserahkan_kepada')->nullable()->after('tahap_fisik_oleh');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Pelanggan menyembunyikan paket yang sudah selesai dari layar
            // pelacakannya. BUKAN soft delete: barisnya tetap utuh, tetap
            // terbaca admin, tetap ikut laporan. Yang berubah cuma satu daftar
            // di satu layar.
            //
            // Kenapa bukan `softDeletes` yang sudah ada di tabel ini: `deleted_at`
            // berarti "pesanan ini dibatalkan/salah input" untuk seluruh sistem.
            // Pelanggan yang merapikan layarnya sendiri tidak boleh bisa
            // menghilangkan pesanan dari pembukuan lab.
            $table->timestamp('disembunyikan_pada')->nullable()->after('catatan');

            // Layar pelacakan pelanggan selalu menyaring tiga hal ini bersamaan.
            $table->index(['customer_id', 'disembunyikan_pada', 'tanggal_masuk'], 'order_lacak_pelanggan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tahap_fisik_oleh');
            $table->dropColumn(['tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('order_lacak_pelanggan_idx');
            $table->dropColumn('disembunyikan_pada');
        });
    }
};
