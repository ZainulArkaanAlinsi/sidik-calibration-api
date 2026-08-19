<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai sertifikat standar — pasangan yang selama ini hilang dari
 * `ketidakpastian`.
 *
 * Error kalibrasi dihitung dari pembacaan alat dikurangi NILAI SERTIFIKAT
 * standar, bukan nilai nominalnya. Buat buffer pH ini kelihatan jelas: kalau
 * sertifikat Merck bilang buffer "pH 4" itu sebenernya 4.01, ngitung pakai 4.00
 * bikin error tiap titik meleset 0.01 — dan itu kebawa ke sertifikat terbit.
 *
 * `suhu_referensi` ikut karena nilai buffer pH BERUBAH sama suhu; sertifikatnya
 * ngasih tabel (nilai di 20 °C, 25 °C, 30 °C). Tanpa nyimpen suhunya, angkanya
 * kehilangan arti — 4.01 di suhu berapa?
 *
 * Dua-duanya nullable: standar panjang & massa nggak butuh ini, dan angkanya
 * WAJIB diisi dari dokumen sertifikat asli, bukan ditebak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->decimal('nilai_konvensional', 20, 8)->nullable()->after('serial_number');
            $table->decimal('suhu_referensi', 8, 2)->nullable()->after('nilai_konvensional');
        });
    }

    public function down(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->dropColumn(['nilai_konvensional', 'suhu_referensi']);
        });
    }
};
