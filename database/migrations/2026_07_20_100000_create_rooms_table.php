<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruangan lab tempat kalibrasi dikerjain.
 *
 * Ini BUKAN duplikat `calibration_sessions.lokasi` — kolom itu enum lab/onsite,
 * cuma misahin "di lab" sama "di tempat pelanggan". Yang dibutuhin master data
 * ini pertanyaan lain: ruangan MANA di dalam lab, dan syarat suhu/kelembabannya
 * berapa. Rentang syaratnya ditaruh di sini biar kondisi ruang yang dicatat di
 * sesi bisa diadu sama batas resminya waktu audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('kode');
            $table->string('nama');
            $table->string('lokasi')->nullable();

            // Batas syarat ruangan, bukan hasil ukur. Nullable karena nggak semua
            // ruangan (gudang, ruang terima alat) punya syarat terkendali.
            $table->decimal('suhu_min', 8, 2)->nullable();
            $table->decimal('suhu_max', 8, 2)->nullable();
            $table->decimal('kelembaban_min', 8, 2)->nullable();
            $table->decimal('kelembaban_max', 8, 2)->nullable();

            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Kode ruangan unik PER organisasi, bukan global — dua lab beda boleh
            // sama-sama punya "R-01".
            $table->unique(['organization_id', 'kode']);
            $table->index(['organization_id', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
