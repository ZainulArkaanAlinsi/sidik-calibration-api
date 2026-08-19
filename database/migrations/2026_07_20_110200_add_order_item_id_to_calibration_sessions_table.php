<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nyambungin sesi kalibrasi ke alat yang diterima lewat order.
 *
 * Nunjuk ke `order_items`, bukan langsung ke `orders`: yang dikerjain teknisi
 * itu SATU alat dari pengiriman, bukan pengirimannya. Order-nya tetap kejangkau
 * lewat item. Nggak dibikin unik — satu item bisa punya lebih dari satu sesi
 * kalau hasilnya ditolak dan harus diulang.
 *
 * `nomor_order` yang lama SENGAJA dipertahanin dan belum dibuang. Dia teks
 * bebas yang selama ini jadi penghubung rapuh; kolom ini penggantinya, tapi data
 * lama harus di-backfill dulu sebelum yang lama aman dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('equipment_id')
                ->constrained('order_items')
                // Order dihapus -> item ikut kehapus, tapi sesinya JANGAN.
                // Sesi yang udah terbit sertifikatnya harus tetap ada walaupun
                // ordernya dibatalin — itu bukti kalibrasi yang dicari asesor.
                ->nullOnDelete();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropIndex(['order_item_id']);
            $table->dropColumn('order_item_id');
        });
    }
};
