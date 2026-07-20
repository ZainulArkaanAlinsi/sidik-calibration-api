<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suhu larutan waktu pembacaan diambil.
 *
 * Ini BUKAN duplikat `calibration_sessions.suhu_ruang`. Sesi 2405.13.A jalan di
 * ruangan 21,4 °C, tapi larutannya kebaca 22,2 / 22,2 / 22,1 °C — beda per
 * titik, dan yang nentuin nilai standar itu suhu LARUTAN, bukan suhu ruang.
 * Pakai suhu ruang bikin nilai standarnya meleset.
 *
 * Workbook Excel nyatet suhu di tiap baris pengulangan (kolom sebelah pembacaan),
 * jadi disimpen per pembacaan juga, bukan per titik — biar rata-ratanya bisa
 * dihitung dari data yang sama persis kayak di Excel.
 *
 * Nullable: cuma relevan buat besaran yang nilai standarnya sensitif suhu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->decimal('suhu_larutan', 8, 2)->nullable()->after('pembacaan');
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->dropColumn('suhu_larutan');
        });
    }
};
