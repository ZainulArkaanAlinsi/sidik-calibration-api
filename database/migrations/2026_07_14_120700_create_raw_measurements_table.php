<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = SATU pembacaan (bukan JSON array per titik ukur).
 *
 * Alasannya: Type A butuh standar deviasi dari tiap pengulangan, dan tiap angka
 * hasil OCR bawa skor keyakinan + fotonya sendiri. Kalau ditumpuk jadi JSON,
 * dua-duanya nggak bisa diaudit ("angka mana yang OCR-nya ragu?").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_session_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('titik_ke');
            $table->unsignedInteger('pembacaan_ke');
            $table->decimal('titik_ukur', 20, 8);
            $table->decimal('pembacaan', 20, 8);
            $table->string('satuan');

            $table->enum('input_source', ['manual', 'ocr'])->default('manual');
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->text('ocr_raw_text')->nullable();
            $table->string('photo_path')->nullable();
            // Wajib true sebelum sesi boleh disubmit — kamera mempercepat input,
            // bukan menggantikan verifikasi manusia.
            $table->boolean('is_verified')->default(false);

            $table->timestamps();

            $table->index(['calibration_session_id', 'titik_ke']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_measurements');
    }
};
