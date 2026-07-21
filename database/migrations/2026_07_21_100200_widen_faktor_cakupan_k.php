<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `faktor_cakupan_k` dulu decimal(5,2) — cukup buat k=2.00 (jalur lama), tapi
 * budget pH ngitung k dari t-student (mis. 1.968535 vs 1.970732 per titik) dan
 * dua desimal ngebuletin semua ke 1.97, ngilangin bedanya di jejak audit.
 * (U-nya sendiri udah bener — dihitung full-precision sebelum disimpan.)
 * Dilebarin ke decimal(8,6) biar k yang beneran kepake tetap kesimpen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->decimal('faktor_cakupan_k', 8, 6)->default(2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->decimal('faktor_cakupan_k', 5, 2)->default(2)->change();
        });
    }
};
