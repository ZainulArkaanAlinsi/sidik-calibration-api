<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instruksi Kerja (IK) kalibrasi — yang dicetak di sertifikat sebagai
 * "Calibration Method", mis. `SIDIK-IK-CAL-0506_Rev.6`.
 *
 * Dipisah jadi tabel sendiri (bukan string bebas di sesi) karena revisi IK
 * berubah tiap beberapa waktu, dan sertifikat lama WAJIB tetap nunjuk revisi
 * yang berlaku waktu kalibrasinya dikerjain. Ganti revisi = baris baru, bukan
 * edit baris lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Boleh kosong: satu IK bisa kepake lintas kategori alat.
            $table->foreignId('equipment_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kode')->comment('mis. SIDIK-IK-CAL-0506');
            $table->string('revisi')->nullable()->comment('mis. 6 — dicetak jadi _Rev.6');
            $table->string('nama')->nullable()->comment('Judul IK, buat dropdown admin');
            $table->date('berlaku_mulai')->nullable();
            $table->boolean('aktif')->default(true);
            $table->text('keterangan')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Kode yang sama boleh muncul lagi asal revisinya beda.
            $table->unique(['organization_id', 'kode', 'revisi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_methods');
    }
};
