<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft lembar kerja boleh disimpen sebelum tanggal kalibrasinya kepikiran —
 * mis. teknisi nyiapin lembar buat besok, atau nyimpen setengah jalan pas
 * sinyal ilang.
 *
 * Yang WAJIB-nya nggak hilang, cuma pindah tempat: `CalibrationRequest` tetap
 * nolak submit ke antrean approval tanpa tanggal kalibrasi. Bedanya, sekarang
 * yang nahan aturan aplikasi (yang tau ini draft atau bukan), bukan constraint
 * kolom (yang nggak tau apa-apa soal status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->date('tanggal_kalibrasi')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->date('tanggal_kalibrasi')->nullable(false)->change();
        });
    }
};
