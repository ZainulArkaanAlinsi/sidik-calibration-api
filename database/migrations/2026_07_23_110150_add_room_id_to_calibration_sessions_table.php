<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Calibration Location" di sertifikat itu nama RUANGAN (mis. "Lab. Uji A"),
 * bukan enum lab/onsite. Master data ruangannya udah ada dari Minggu 09, tapi
 * sesi belum nunjuk ke sana — jadi lokasi di sertifikat cuma bisa ditulis
 * "Laboratorium" doang. Ini yang nyambungin.
 *
 * Tetap nullable: sesi onsite dikerjain di tempat pelanggan, nggak punya
 * ruangan lab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('lokasi')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }
};
