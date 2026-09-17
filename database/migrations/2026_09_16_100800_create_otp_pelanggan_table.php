<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OTP 6 digit buat verifikasi email & atur ulang sandi pelanggan.
 *
 * TIDAK ADA di 03-SDD versi 0.1 — ditambahkan 16 Sep 2026 dan disusulkan ke
 * §4.2 dokumen itu, bukan dibiarkan jadi tabel siluman.
 *
 * `kode_hash`, bukan kodenya. OTP itu kredensial berumur pendek, dan tabel yang
 * menyimpannya polos berarti siapa pun yang bisa membaca database bisa
 * mengambil alih akun mana pun tanpa menyentuh email korban.
 *
 * `percobaan` + `dikunci_sampai` menegakkan REQ-AUTH-02 (salah 5x → kunci 15
 * menit) DI BARIS OTP-nya, bukan cuma di rate limiter per IP. Bedanya
 * menentukan: throttle per IP dilewati dengan ganti jaringan, penguncian per
 * akun tidak.
 *
 * `dipakai_pada` bikin OTP sekali pakai. Tanpa itu, kode yang benar tetap sah
 * sampai kedaluwarsa — jadi yang sempat melihatnya sekali (bahu, notifikasi di
 * layar kunci, email yang ter-forward) masih bisa memakainya lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_pelanggan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('tujuan', ['verifikasi_email', 'atur_ulang_sandi']);
            $table->string('kode_hash');
            $table->timestamp('kedaluwarsa_pada');
            $table->unsignedTinyInteger('percobaan')->default(0);
            $table->timestamp('dikunci_sampai')->nullable();
            $table->timestamp('dipakai_pada')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tujuan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_pelanggan');
    }
};
