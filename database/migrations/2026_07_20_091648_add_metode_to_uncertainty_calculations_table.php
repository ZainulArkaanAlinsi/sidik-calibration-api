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
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            // Nomor & revisi IK (instruksi kerja) yang dipakai buat titik ini,
            // mis. "SIDIK-IK-CAL-0506_Rev.6" — dari CalibrationCapability yang
            // dicocokkan GumCalculator::kemampuanUntukTitik(). Null kalau titik
            // ini lewat jalur generik (nggak ada CMC yang cocok).
            $table->string('metode')->nullable()->after('standard_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->dropColumn('metode');
        });
    }
};
