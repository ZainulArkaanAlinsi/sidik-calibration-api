<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field worksheet pH yang belum tertampung (lihat foto worksheet Kalibrasi pH
 * Meter LK-285-IDN & sheet `PERHITUNGAN`/`FORM VALIDASI` di workbook trial).
 *
 * KONDISI LINGKUNGAN dulu cuma 1 angka (`suhu_ruang`, `kelembaban` = rata-rata).
 * Worksheet asli nyatet Awal + Akhir, plus KOREKSI (dari sertifikat thermohygro)
 * & U95% (ketidakpastian kondisi lingkungan). `suhu_ruang`/`kelembaban` lama
 * TETAP dipakai sebagai rata-rata (kompatibel mundur) — kolom baru ini
 * nambahin, bukan ngeganti.
 *
 * `raw_measurements.suhu` = suhu larutan tiap pembacaan pH (worksheet pH nyatet
 * pasangan pH/°C per pengulangan). Nullable — alat non-pH nggak ngisi.
 *
 * Semua nullable & aditif: sesi lama + alat kategori lain nggak kesentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            // Kondisi lingkungan Awal/Akhir (rata-rata tetap di `suhu_ruang`/`kelembaban`).
            $table->decimal('suhu_ruang_awal', 8, 2)->nullable()->after('kelembaban');
            $table->decimal('suhu_ruang_akhir', 8, 2)->nullable()->after('suhu_ruang_awal');
            $table->decimal('kelembaban_awal', 8, 2)->nullable()->after('suhu_ruang_akhir');
            $table->decimal('kelembaban_akhir', 8, 2)->nullable()->after('kelembaban_awal');

            // Koreksi dari sertifikat thermohygro (nilai benar = rata-rata + koreksi).
            $table->decimal('suhu_ruang_koreksi', 8, 4)->nullable()->after('kelembaban_akhir');
            $table->decimal('kelembaban_koreksi', 8, 4)->nullable()->after('suhu_ruang_koreksi');

            // U95% kondisi lingkungan — dihitung server: 2·√((U_TH/2)²+(|awal−akhir|/2)²).
            $table->decimal('suhu_ruang_u95', 8, 4)->nullable()->after('kelembaban_koreksi');
            $table->decimal('kelembaban_u95', 8, 4)->nullable()->after('suhu_ruang_u95');

            // Label alat pemantau ruangan yang dipakai (mis. "TH-3") — sesuai
            // baris "Thermohygro Used" di worksheet.
            $table->string('thermohygro')->nullable()->after('kelembaban_u95');
        });

        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->decimal('suhu', 8, 2)->nullable()->after('pembacaan');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'suhu_ruang_awal', 'suhu_ruang_akhir', 'kelembaban_awal', 'kelembaban_akhir',
                'suhu_ruang_koreksi', 'kelembaban_koreksi', 'suhu_ruang_u95', 'kelembaban_u95',
                'thermohygro',
            ]);
        });

        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->dropColumn('suhu');
        });
    }
};
