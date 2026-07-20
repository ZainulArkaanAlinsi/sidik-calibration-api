<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sebelum adjustment" (as-found) itu pembacaan yang sama-sama dicatat teknisi
 * di worksheet asli tapi TIDAK ikut perhitungan GUM resmi (cuma dokumentasi
 * kondisi alat sebelum diutak-atik) — dibedain dari "sesudah adjustment" lewat
 * kolom ini, bukan tabel terpisah, biar query & audit trail-nya tetap satu tempat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->enum('tahap', ['sebelum_adjustment', 'sesudah_adjustment'])
                ->default('sesudah_adjustment')
                ->after('pembacaan_ke');
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->dropColumn('tahap');
        });
    }
};
