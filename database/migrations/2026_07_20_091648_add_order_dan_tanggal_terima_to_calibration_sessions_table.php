<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->string('nomor_order')->nullable()->after('nomor_sesi');
            // Tanggal alat DITERIMA dari customer — beda dari tanggal_kalibrasi
            // (kapan alat beneran dikalibrasi). Bisa beberapa hari sebelumnya.
            $table->date('tanggal_terima')->nullable()->after('tanggal_kalibrasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn(['nomor_order', 'tanggal_terima']);
        });
    }
};
