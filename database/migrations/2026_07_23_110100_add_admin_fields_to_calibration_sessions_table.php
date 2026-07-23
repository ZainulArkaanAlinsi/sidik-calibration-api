<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field administratif sertifikat — diisi ADMIN, nggak muncul di layar teknisi
 * (spesifikasi poin 1 & 12A). Teknisi cuma ngirim data pengukuran mentah.
 *
 * `suhu_ketidakpastian` / `kelembaban_ketidakpastian` dipisah dari nilainya
 * karena sertifikat nyetak kondisi lingkungan lengkap dengan rentangnya:
 * "T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,7%". Tanpa kolom ini, angka ± itu
 * cuma bisa diketik manual tiap terbit — persis yang mau dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->foreignId('calibration_method_id')->nullable()->after('standard_id')
                ->constrained('calibration_methods')->nullOnDelete();
            // "Thermohygro Used": alat pengukur suhu/kelembaban ruang waktu
            // kalibrasi dikerjain. Beda dari `standard_id` (standar acuan
            // besaran yang dikalibrasi), tapi sama-sama masuk daftar
            // "Standard Used" di sertifikat.
            $table->foreignId('thermohygro_standard_id')->nullable()->after('calibration_method_id')
                ->constrained('standards')->nullOnDelete();

            $table->decimal('suhu_ketidakpastian', 8, 2)->nullable()->after('suhu_ruang');
            $table->decimal('kelembaban_ketidakpastian', 8, 2)->nullable()->after('kelembaban');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calibration_method_id');
            $table->dropConstrainedForeignId('thermohygro_standard_id');
            $table->dropColumn(['suhu_ketidakpastian', 'kelembaban_ketidakpastian']);
        });
    }
};
