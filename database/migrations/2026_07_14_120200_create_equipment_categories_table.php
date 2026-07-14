<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori = kelompok pengukuran di lampiran akreditasi (suhu-dan-kelembapan,
 * massa, volume, ...). `kode` inilah yang dipakai mobile di field `kategori`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kode');
            $table->string('nama');
            // Kolom worksheet baku per kategori — diisi waktu masuk fitur kalibrasi.
            $table->json('worksheet_schema')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_categories');
    }
};
