<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasangan yang KELEWAT dari `2026_07_24_100100` — waktu itu cuma
 * `raw_measurements.input_source` yang dilebarin jadi string, padahal
 * `calibration_sessions.input_method` nyimpen nilai dari daftar yang sama.
 *
 * Akibatnya sesi yang tabelnya dibaca AI ditolak MySQL waktu disimpan:
 *
 *     SQLSTATE[01000]: 1265 Data truncated for column 'input_method' at row 1
 *
 * Teknisi kelihatan "gagal kirim" padahal fotonya sukses dibaca — angkanya udah
 * masuk ke formulir, tinggal disimpen. `CalibrationRequest` sendiri UDAH nerima
 * `ai_vision` (`Rule::in(['manual','ocr','ai_vision'])`), jadi validasinya lolos
 * dan yang nolak justru kolomnya.
 *
 * ## Kenapa 718 test hijau nggak nangkep ini
 *
 * Test jalan di SQLite, dan SQLite **nggak nagih enum sama sekali** — nilai apa
 * pun masuk tanpa keluhan. Enum cuma ditegakkan di MySQL, yang cuma dipakai
 * waktu app-nya beneran dijalanin. Ketemu 7 Agt 2026 dari lapangan, bukan dari
 * test.
 *
 * Nilai 'ocr' TETAP diterima: data lama & app versi lama nggak boleh rusak.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE calibration_sessions MODIFY input_method VARCHAR(20) NOT NULL DEFAULT 'manual'"
            );

            return;
        }

        // SQLite (test) & lainnya: rebuild kolom jadi string biasa — check
        // constraint enum lama ikut kebuang waktu tabelnya dibangun ulang.
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->string('input_method', 20)->default('manual')->change();
        });
    }

    public function down(): void
    {
        // Balikin ke enum lama; sesi 'ai_vision' dianggap 'ocr' biar nggak nolak.
        DB::table('calibration_sessions')->where('input_method', 'ai_vision')->update(['input_method' => 'ocr']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE calibration_sessions MODIFY input_method ENUM('manual','ocr') NOT NULL DEFAULT 'manual'"
            );

            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->enum('input_method', ['manual', 'ocr'])->default('manual')->change();
        });
    }
};
