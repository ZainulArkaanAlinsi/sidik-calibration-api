<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua data dari sertifikat kalibrasi standar yang selama ini cuma hidup di
 * Excel, padahal dipakai buat ngitung hasil resmi.
 *
 * 1. `koefisien_suhu` — larutan buffer pH nilainya BERUBAH ngikutin suhu
 *    larutan. Sertifikat Merck ngasih persamaan kuadrat per buffer, mis. buffer
 *    pH 4: y = 3e-5·x² − 0,0023x + 4,0455. Tanpa ini, nilai standar di lembar
 *    perhitungan cuma bisa ditulis manual — dan itu sumber salah ketik yang
 *    langsung nyasar ke angka koreksi di sertifikat.
 *
 * 2. `parameter_kondisi` — thermohygro punya DUA parameter (suhu &
 *    kelembaban), masing-masing punya nilai terindeks, koreksi, dan U95%
 *    sendiri di sertifikatnya. Kolom `ketidakpastian` yang lama cuma muat satu
 *    angka, jadi nggak cukup.
 *
 * Kenapa JSON, bukan tabel sendiri: dua-duanya atribut sertifikat yang selalu
 * 1:1 sama standarnya dan selalu dibaca utuh bareng barisnya — nggak pernah
 * dicari/diurutkan sendirian. Bentuknya dikunci di `Standard`, bukan bebas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->json('koefisien_suhu')->nullable()->after('drift')
                ->comment('{a,b,c} buat y = a·x² + b·x + c — nilai standar pada suhu x');
            $table->json('parameter_kondisi')->nullable()->after('koefisien_suhu')
                ->comment('{suhu:{indexed_value,correction,u95}, kelembaban:{...}} dari sertifikat thermohygro');
        });
    }

    public function down(): void
    {
        Schema::table('standards', function (Blueprint $table) {
            $table->dropColumn(['koefisien_suhu', 'parameter_kondisi']);
        });
    }
};
