<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OCR di HP diganti AI Vision di server. `input_source` yang dulu enum
 * ('manual','ocr') dilebarin jadi string biar muat nilai baru 'ai_vision'
 * tanpa harus utak-atik enum tiap nambah sumber input.
 *
 * Nilai 'ocr' TETAP diterima — data lama & app mobile versi lama nggak boleh
 * langsung rusak; yang berubah cuma sumber angkanya sekarang dari Claude Vision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE raw_measurements MODIFY input_source VARCHAR(20) NOT NULL DEFAULT 'manual'"
            );

            return;
        }

        // SQLite (test) & lainnya: rebuild kolom jadi string biasa — check
        // constraint enum lama ikut kebuang waktu tabelnya dibangun ulang.
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->string('input_source', 20)->default('manual')->change();
        });
    }

    public function down(): void
    {
        // Balikin ke enum lama; baris 'ai_vision' dianggap 'ocr' biar nggak nolak.
        DB::table('raw_measurements')->where('input_source', 'ai_vision')->update(['input_source' => 'ocr']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE raw_measurements MODIFY input_source ENUM('manual','ocr') NOT NULL DEFAULT 'manual'"
            );

            return;
        }

        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->enum('input_source', ['manual', 'ocr'])->default('manual')->change();
        });
    }
};
