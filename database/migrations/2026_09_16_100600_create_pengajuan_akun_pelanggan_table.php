<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean pengajuan akun pelanggan yang mendaftar sendiri (REQ-AUTH-02/04/05).
 *
 * Nama & alamat perusahaan disimpan DI SINI, bukan langsung jadi baris
 * `customers`. Sebabnya R-D02, risiko kritis: orang mengaku sebagai PT X lalu
 * melihat sertifikat PT X. Yang mereka ketik itu KLAIM, dan baru jadi data lab
 * sesudah admin menautkannya ke pelanggan yang benar — atau membuat yang baru
 * dengan sadar.
 *
 * `customer_id` nullable dan baru terisi saat disetujui; itu yang membedakan
 * klaim dari keputusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_akun_pelanggan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nama_perusahaan', 150);
            $table->string('alamat_perusahaan')->nullable();
            $table->string('jabatan', 100)->nullable();
            $table->enum('status', ['menunggu', 'disetujui', 'ditolak'])->default('menunggu');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('diputus_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputus_pada')->nullable();
            $table->text('alasan_tolak')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_akun_pelanggan');
    }
};
