<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto pelat nama dari aplikasi pelanggan (PL_Form_Alat, PL_Ubah_Alat).
 *
 * Satu tabel untuk tiga pemilik — alat, item permintaan (alat baru), dan
 * koreksi — karena aturannya satu: maks 3 per pemilik, jpg/png/webp ≤ 5 MB,
 * disimpan di disk `arsip` (privat), dan cuma bisa dibuka lewat rute yang
 * memeriksa kepemilikan. `customer_id` ikut disimpan supaya pemeriksaan itu
 * tidak harus menelusuri tiga jenis pemilik yang berbeda.
 *
 * Additive murni (aturan Modul Pelanggan butir 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foto_pelanggan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->morphs('pemilik');
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('ukuran');
            $table->foreignId('diunggah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_pelanggan');
    }
};
