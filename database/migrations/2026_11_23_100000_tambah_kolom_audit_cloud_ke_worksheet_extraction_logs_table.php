<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit OCR awan (Google Vision) di `worksheet_extraction_logs`.
 *
 * Tiga kolom nullable, murni additive: baris lama (jalur LLM) tidak berubah.
 * Kolomnya sengaja kolom sungguhan, bukan kunci di JSON `raw_model_response`:
 * laporan biaya ("berapa unit bulan ini") adalah `SUM(unit_vision)`, dan
 * AGENTS.md melarang pembaca SQL-JSON-path pada kolom JSON.
 *
 *  - `unit_vision`        jumlah unit tertagih yang DICATAT server (1 fitur x 1
 *                         gambar; asumsi, belum dibuktikan dengan panggilan nyata).
 *                         NULL = tidak pernah sampai ke Google (ditolak/ dibatasi).
 *  - `ukuran_gambar_byte` ukuran berkas yang dikirim. Gambarnya sendiri TIDAK
 *                         disimpan di mana pun.
 *  - `durasi_ms`          lama panggilan ke Google.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheet_extraction_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('worksheet_extraction_logs', 'unit_vision')) {
                $table->unsignedSmallInteger('unit_vision')->nullable();
            }
            if (! Schema::hasColumn('worksheet_extraction_logs', 'ukuran_gambar_byte')) {
                $table->unsignedInteger('ukuran_gambar_byte')->nullable();
            }
            if (! Schema::hasColumn('worksheet_extraction_logs', 'durasi_ms')) {
                $table->unsignedInteger('durasi_ms')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('worksheet_extraction_logs', function (Blueprint $table) {
            foreach (['unit_vision', 'ukuran_gambar_byte', 'durasi_ms'] as $kolom) {
                if (Schema::hasColumn('worksheet_extraction_logs', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
