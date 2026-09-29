<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan teknisi — personal & grup (poin 8).
 *
 * Yang diminta: super admin membagi pekerjaan ke teknisi, bisa ke satu orang
 * atau ke beberapa orang sekaligus, dengan baris "+" untuk jenis alat &
 * jumlahnya, plus tanggal target.
 *
 * ## Kenapa tabel baru, padahal `order_items.teknisi_id` sudah ada
 *
 * Dua hal yang kelihatan mirip tapi menjawab pertanyaan berbeda, dan
 * menggabungkannya akan merusak salah satunya:
 *
 * - `order_items.teknisi_id` = "alat NOMOR SERI INI dikerjakan siapa". Menunjuk
 *   barang yang sudah ada di meja, satu baris satu alat.
 * - `penugasan` = "minggu ini kamu kerjakan 10 autoklaf dan 4 timbangan".
 *   Menunjuk RENCANA, dan alat konkretnya belum tentu sudah masuk lab.
 *
 * Penugasan yang dipaksa masuk `order_items` mengharuskan barangnya ada dulu —
 * padahal pembagian kerja justru dibuat sebelum barangnya datang. Sebaliknya,
 * `order_items` yang dipaksa jadi rencana kehilangan nomor serinya.
 *
 * Keduanya terhubung lewat `penugasan_item.order_id` (opsional): begitu paketnya
 * masuk, penugasan bisa ditambatkan ke paket yang nyata.
 *
 * ## Kenapa `tipe` disimpan padahal bisa dihitung dari jumlah anggota
 *
 * Satu anggota bisa berarti "penugasan personal" atau "penugasan grup yang
 * anggotanya baru satu". Bedanya kelihatan di layar: yang grup punya ketua, dan
 * penyelesaiannya dilaporkan per kelompok. Menghitungnya dari `COUNT(*)`
 * membuat grup satu orang berubah bentuk sendiri begitu anggota keduanya belum
 * ditambahkan — dan berubah lagi waktu ada yang keluar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penugasan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('judul');
            $table->enum('tipe', ['personal', 'grup'])->default('personal');

            // Target, bukan tenggat mati. Lab ini tidak menolak pekerjaan yang
            // lewat tanggal — yang dibutuhkan cuma tahu mana yang lewat.
            $table->date('tanggal_target')->nullable();

            $table->enum('status', ['aktif', 'selesai', 'dibatalkan'])->default('aktif');
            $table->text('catatan')->nullable();

            // Siapa yang membagi. `nullOnDelete` bukan `cascade`: akun yang
            // dihapus tidak boleh ikut menghapus riwayat pembagian kerjanya —
            // penugasan yang lenyap membuat teknisi kehilangan daftar
            // pekerjaannya tanpa penjelasan.
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('diselesaikan_pada')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Dua saringan yang selalu dipakai bersamaan di layar super admin:
            // per lab, per status, disortir target.
            $table->index(['organization_id', 'status', 'tanggal_target'], 'penugasan_papan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penugasan');
    }
};
