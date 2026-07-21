<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konstanta budget ketidakpastian yang dideklarasi lab per titik ukur — dipakai
 * `GumCalculator` buat NGITUNG U95% (bukan cuma nempel CMC), lalu ambil
 * max(U_hitung, CMC). Sumbernya sheet `PERHITUNGAN U95%` di workbook pH.
 *
 * Cuma keisi buat titik yang budget-nya emang dirinci (mis. buffer pH 4/7/10).
 * Kosong = alat balik ke jalur CMC-langsung / generik (kompatibel mundur).
 *
 *   u_temperature      : UTemperature gabungan sertifikat termometer+sensor (°C)
 *   ci_suhu            : koef. sensitivitas suhu buffer @ suhu referensi
 *   u_perbedaan_suhu   : U95% pengaruh perbedaan suhu (konstanta lab)
 *   ci_perbedaan_suhu  : koef. sensitivitas komponen perbedaan suhu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->decimal('u_temperature', 20, 8)->nullable()->after('faktor_cakupan');
            $table->decimal('ci_suhu', 20, 8)->nullable()->after('u_temperature');
            $table->decimal('u_perbedaan_suhu', 20, 8)->nullable()->after('ci_suhu');
            $table->decimal('ci_perbedaan_suhu', 20, 8)->nullable()->after('u_perbedaan_suhu');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->dropColumn(['u_temperature', 'ci_suhu', 'u_perbedaan_suhu', 'ci_perbedaan_suhu']);
        });
    }
};
