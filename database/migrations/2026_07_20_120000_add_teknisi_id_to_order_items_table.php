<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan teknisi — ditaruh di ITEM, bukan di order.
 *
 * Satu pengiriman bisa campur disiplin: jangka sorong (panjang) dan pH meter
 * (analitik) di kardus yang sama dikerjain orang berbeda. Nempelin satu teknisi
 * ke seluruh order maksa penyederhanaan yang salah; di level item, kasus simpel
 * (semua alat satu orang) tetap bisa diisi sekali jalan.
 *
 * Beda sama `calibration_sessions.teknisi_id` yang udah ada: yang itu SIAPA YANG
 * SUDAH ngerjain (kepake di sertifikat), yang ini SIAPA YANG DITUGASKAN — ada
 * sebelum sesinya lahir, dan bisa beda kalau kerjaannya dioper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('teknisi_id')
                ->nullable()
                ->after('equipment_id')
                ->constrained('users')
                // Teknisi resign & akunnya dihapus -> penugasannya kosong lagi,
                // ordernya JANGAN ikut kehapus.
                ->nullOnDelete();

            $table->index('teknisi_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['teknisi_id']);
            $table->dropIndex(['teknisi_id']);
            $table->dropColumn('teknisi_id');
        });
    }
};
