<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu PEMASANGAN aplikasi yang boleh dikirimi push notification.
 *
 * Dipakai buat satu kasus yang websocket Reverb nggak bisa tutup: HP dengan
 * aplikasi ketutup total. Selama aplikasinya jalan, kabar sudah sampai lewat
 * channel privat Reverb dan push nggak perlu sama sekali.
 *
 * Kunci uniknya TOKEN, bukan (user, perangkat). Alasannya dari cara FCM
 * bekerja: token bisa berpindah pemilik. Satu HP dipakai gantian dua teknisi —
 * yang kedua login, FCM ngasih token yang sama, dan kalau barisnya dikunci per
 * user, notifikasi teknisi pertama terus mendarat di HP yang sekarang dipegang
 * orang lain. Dengan token sebagai kunci, pendaftaran kedua MEMINDAHKAN
 * kepemilikan, bukan menambah baris kedua.
 *
 * Ini bukan kasus teoretis di sini — HP tes di tim ini memang dipakai gantian.
 *
 * `terakhir_aktif` bukan hiasan: FCM tidak pernah memberi tahu kalau aplikasi
 * dicopot, jadi token mati cuma ketahuan dari respons kirim atau dari umurnya.
 * Tanpa kolom ini, tabelnya menumpuk token mati selamanya dan tiap notifikasi
 * jadi makin lambat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Token FCM panjangnya nggak dijanjikan Google; 255 sudah lebih
            // dari cukup untuk yang beredar sekarang, dan `unique` butuh
            // panjang terbatas di MySQL.
            $table->string('token', 255)->unique();

            // android | ios | windows | macos — dipakai buat memilih bentuk
            // payload, bukan sekadar statistik.
            $table->string('platform', 20);

            // Buat menelusuri laporan "notifikasinya nggak masuk": versi mana
            // yang mendaftar, dan kapan terakhir dia menyapa.
            $table->string('versi_app', 30)->nullable();
            $table->timestamp('terakhir_aktif')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
