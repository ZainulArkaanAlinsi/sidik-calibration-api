<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan kalibrasi dari pelanggan (paket 29 Sep, layar PL_Ajukan).
 *
 * ## Kenapa bukan langsung `orders`
 *
 * `orders` itu catatan meja penerimaan: alatnya SUDAH diterima lab, nomornya
 * resmi, dan dia menurunkan pelacakan paket & penugasan. Pelanggan yang menekan
 * "Ajukan" belum membawa apa-apa ke meja. Kalau ajuannya langsung jadi order,
 * antrean lab berisi janji yang belum ditinjau siapa pun — dan order yang
 * ditolak harus dihapus atau dibatalkan, mengotori nomor urut yang dilihat
 * asesor. Jadi ajuan hidup di tabel sendiri; order baru lahir SAAT admin
 * menerimanya (keputusan pemilik proyek 30 Sep: tidak pernah otomatis).
 *
 * Tabel ini additive murni (AGENTS.md §Modul Pelanggan butir 6): tidak ada
 * kolom lama yang disentuh.
 *
 * ## Kolom yang sengaja tidak ada
 *
 * Tidak ada `deleted_at`. Ajuan yang dibatalkan atau ditolak tetap baris
 * biasa dengan status-nya; menghapusnya berarti membuang jejak siapa meminta
 * apa dan kenapa ditolak, dan alasan penolakan dibaca pelanggan.
 *
 * `order_id` baru terisi waktu diterima, dan `nullOnDelete`: order yang kelak
 * dibatalkan/dihapus tidak boleh ikut melenyapkan catatan bahwa pelanggan
 * pernah mengajukan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan_kalibrasi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Siapa yang menekan Ajukan. `nullOnDelete`: akun yang dihapus
            // (PenganonimAkun) tidak boleh menghapus ajuan perusahaannya.
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();

            // PMT/2026/09/0001 — urut per organisasi per bulan, sama polanya
            // dengan ORD/ dan CAL/. UNIQUE menahan nomor kembar kalau dua
            // pelanggan menekan Ajukan pada detik yang sama.
            $table->string('nomor', 32);

            $table->enum('status', ['baru', 'diterima', 'ditolak', 'dibatalkan'])->default('baru');

            // Hanya dua cara, sesuai keputusan pemilik proyek. Desain awal
            // (PL_Ajukan) menyebut "teknisi datang ke lokasi"; yang dipakai lab
            // adalah diantar sendiri atau dijemput.
            $table->enum('metode_pengantaran', ['diantar_sendiri', 'diambil_lab']);

            // Rentang yang DIINGINKAN. Lab yang memutuskan tanggal pastinya,
            // jadi dua-duanya opsional dan tidak mengikat.
            $table->date('tanggal_diinginkan_dari')->nullable();
            $table->date('tanggal_diinginkan_sampai')->nullable();

            $table->text('catatan')->nullable();

            // Dibaca pelanggan apa adanya — wajib terisi kalau status `ditolak`
            // (dijaga di service, bukan di sini: MySQL tidak punya CHECK yang
            // setara di semua versi yang dipakai lab).
            $table->text('alasan_penolakan')->nullable();

            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'nomor']);
            // Antrean admin: per lab, per status, terbaru di atas.
            $table->index(['organization_id', 'status', 'created_at'], 'permintaan_antrean_idx');
            // Daftar milik satu pelanggan.
            $table->index(['customer_id', 'status', 'created_at'], 'permintaan_pelanggan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_kalibrasi');
    }
};
