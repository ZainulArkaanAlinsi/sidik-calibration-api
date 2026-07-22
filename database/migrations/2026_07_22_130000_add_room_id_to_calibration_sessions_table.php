<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruangan lab tempat kalibrasi dikerjain.
 *
 * Beda dari kolom `lokasi` yang udah ada: `lokasi` cuma `lab` vs `onsite`
 * (dikerjain di lab kita atau di tempat pelanggan). `room_id` nunjuk ruangan
 * SPESIFIK di dalam lab — worksheet nyatet "Lab. Uji A", dan itu yang tercetak
 * di sertifikat sebagai lokasi kalibrasi.
 *
 * Nullable + `nullOnDelete`: sesi lama nggak punya ruangan, dan ruangan yang
 * dihapus nggak boleh nyeret rekaman kalibrasi ikut hilang — paling jauh
 * sesinya balik jadi "ruangan nggak tercatat".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('equipment_id')
                ->constrained('rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropColumn('room_id');
        });
    }
};
