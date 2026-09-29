<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa saja yang kena satu penugasan.
 *
 * Tabel pivot, tapi **bukan** pivot kosong: dia menyimpan `peran` dan
 * `dilihat_pada`, dan dua kolom itu yang membuat penugasan grup berguna.
 *
 * ## `peran`: kenapa ada ketua
 *
 * Penugasan grup tanpa ketua berakhir sebagai pekerjaan yang semua orang
 * anggap sedang dikerjakan orang lain. Satu nama yang bertanggung jawab
 * melaporkan progres membuat penugasan grup bisa ditagih; tanpa itu yang bisa
 * ditagih cuma "grupnya", dan grup tidak menjawab telepon.
 *
 * Penugasan personal tetap punya satu baris di sini dengan `peran = ketua` —
 * jadi tidak ada cabang kode "kalau personal, ambil dari kolom lain".
 *
 * ## `dilihat_pada`: kenapa bukan cuma notifikasi
 *
 * Notifikasi terkirim ≠ notifikasi terbaca. Pertanyaan yang benar-benar
 * ditanyakan super admin adalah "dia udah tau belum?", dan satu-satunya jawaban
 * yang jujur untuk itu adalah kapan teknisinya membuka penugasannya. Tanpa kolom
 * ini, penugasan yang tidak dikerjakan tidak bisa dibedakan dari penugasan yang
 * tidak pernah sampai — dan dua hal itu tindak lanjutnya berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penugasan_teknisi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('penugasan_id')->constrained('penugasan')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('peran', ['ketua', 'anggota'])->default('anggota');
            $table->timestamp('dilihat_pada')->nullable();

            $table->timestamps();

            // Satu orang tidak bisa masuk dua kali ke penugasan yang sama.
            // Tanpa ini, tombol "tambah anggota" yang dipencet dua kali karena
            // jaringan lambat membuat namanya muncul dobel di layar teknisi —
            // dan jumlah anggota yang dihitung ikut salah.
            $table->unique(['penugasan_id', 'user_id']);

            // "Penugasan apa saja yang jadi milikku" — kueri paling sering di
            // apk teknisi, dijalankan tiap kali layar tugas dibuka.
            $table->index(['user_id', 'penugasan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penugasan_teknisi');
    }
};
