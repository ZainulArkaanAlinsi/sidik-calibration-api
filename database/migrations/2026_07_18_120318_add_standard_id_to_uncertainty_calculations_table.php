<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titik ukur yang butuh lebih dari satu standar sekaligus (misal pH: buffer 4,
 * 7, 10 masing-masing standar sendiri) nggak bisa nunjuk cuma ke
 * `calibration_sessions.standard_id` — itu satu kolom buat seluruh sesi.
 * Kolom ini nyimpen standar yang BENERAN dipakai di titik ini; kalau titik
 * nggak nentuin sendiri, controller isi ini pakai standar default sesi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->foreignId('standard_id')->nullable()->after('calibration_session_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('standard_id');
        });
    }
};
