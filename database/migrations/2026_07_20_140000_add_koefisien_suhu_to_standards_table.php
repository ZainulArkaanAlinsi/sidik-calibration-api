<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koefisien kurva suhu standar — bentuk yang BENER dari yang tadinya dicoba
 * pakai satu kolom `nilai_konvensional` (dibatalin di migration sebelumnya).
 *
 * Nilai buffer pH berubah ikut suhu. Sertifikat Merck ngasih TABEL nilai per
 * suhu (0, 5, 10, ..., 50 °C), dan workbook `Master Olah Data_pH for trial.xlsm`
 * (sheet `Nilai koefisien Sensitifitas`) ngeregresi tabel itu jadi kurva
 * kuadratik:
 *
 *     y = a·x² + b·x + c        x = suhu larutan (°C), y = nilai pH standar
 *
 * Tiga koefisien inilah yang bikin nilainya bisa dihitung di suhu berapa pun,
 * bukan cuma di suhu yang kebetulan ada di tabel. Rumusnya diambil apa adanya
 * dari Excel — BUKAN regresi baru yang dihitung ulang di sini.
 *
 * Nullable: cuma standar yang nilainya sensitif suhu (buffer pH) yang punya.
 * Gauge block & anak timbangan nggak kepake ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            // Presisi tinggi: koefisien kuadratnya sekecil 3e-05, dan salah
            // pembulatan di sini kebawa ke nilai standar tiap titik ukur.
            $table->decimal('koef_suhu_a', 20, 12)->nullable()->after('serial_number');
            $table->decimal('koef_suhu_b', 20, 12)->nullable()->after('koef_suhu_a');
            $table->decimal('koef_suhu_c', 20, 12)->nullable()->after('koef_suhu_b');
        });
    }

    public function down(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->dropColumn(['koef_suhu_a', 'koef_suhu_b', 'koef_suhu_c']);
        });
    }
};
