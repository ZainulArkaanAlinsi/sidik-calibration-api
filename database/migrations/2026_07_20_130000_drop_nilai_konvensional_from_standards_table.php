<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batalin `nilai_konvensional` + `suhu_referensi` di `standards`.
 *
 * Dua kolom ini ditambahin dengan asumsi nilai sertifikat standar itu ANGKA
 * TETAP per standar. Data kalibrasi asli (sertifikat 012-CAL-524, sesi
 * 2405.13.A) nunjukin asumsi itu salah buat buffer pH: nilainya dikoreksi
 * SUHU dan dihitung per sesi.
 *
 * Sesi itu jalan di 21,4 °C dengan nilai buffer 4.009244572 / 6.9889072 /
 * 9.9788769 — bukan 4 / 7 / 10. Satu kolom statis nggak bisa mewakili angka
 * yang beda tiap sesi ikut suhu ruang.
 *
 * Jalur yang bener udah jalan dari dulu dan NGGAK disentuh migration ini:
 * nilai terkoreksi masuk lewat `uncertainty_calculations.titik_ukur` per titik,
 * dan `GumCalculator::hitungTitik()` ngitung `error = rata_rata - titik_ukur`.
 * Kolom yang dibuang ini nggak pernah dibaca kalkulator sama sekali.
 *
 * Dibuang sekarang mumpung masih kosong (0 baris terisi). Dibiarin, dia bakal
 * ngundang orang ngisi "4.01" lalu ngira itu angka otoritatif — dan itu lebih
 * berbahaya daripada nggak ada kolomnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->dropColumn(['nilai_konvensional', 'suhu_referensi']);
        });
    }

    public function down(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->decimal('nilai_konvensional', 20, 8)->nullable()->after('serial_number');
            $table->decimal('suhu_referensi', 8, 2)->nullable()->after('nilai_konvensional');
        });
    }
};
