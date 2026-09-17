<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keanggotaan orang di perusahaan pelanggan (03-SDD §4.2).
 *
 * Satu perusahaan punya banyak PIC, dan satu orang bisa jadi anggota lebih dari
 * satu perusahaan (konsultan — REQ-ANG-04). Jadi relasinya tabel sendiri, bukan
 * `users.customer_id`.
 *
 * UNIQUE(customer_id, user_id) menahan keanggotaan kembar. Itu bukan kerapian:
 * dua baris untuk orang yang sama bikin `KonteksPerusahaan` memilih salah
 * satunya sembarangan, dan peran yang berlaku jadi bergantung pada urutan baris.
 *
 * INDEX(user_id, status) yang dipakai hampir tiap request pelanggan — resolusi
 * konteks perusahaan menanyakan "keanggotaan aktif milik orang ini".
 *
 * `status` nonaktif, BUKAN baris dihapus: REQ-AUTH-09 menuntut jejak siapa
 * menonaktifkan siapa dan kapan, dan baris yang hilang nggak bisa menjawab itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('peran', ['pic_utama', 'staf'])->default('staf');
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->foreignId('diundang_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dinonaktifkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dinonaktifkan_pada')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_members');
    }
};
