<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nunjuk ke `calibration_capabilities.nama_alat` (mis. "Vernier Caliper",
 * "pH Meter") — BUKAN foreign key, karena satu nama_alat nyebar di banyak
 * baris capability (satu per titik/sub-rentang), jadi nggak ada satu baris
 * yang bisa dijadiin target FK. Dicocokkan by value, sama kayak
 * `equipment_categories.kode` yang juga dicocokkan by value, bukan FK.
 *
 * Nullable & opsional: alat yang belum di-link tetap kalibrasi seperti biasa
 * lewat GumCalculator jalur generik (Type A+B) — TIDAK jatuh ke jalur CMC
 * yang cuma nebak dari kategori (itu sumber bug: satu equipment_category_id
 * nampung banyak jenis alat beda yang rentangnya tumpang tindih).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->string('nama_alat_kemampuan')->nullable()->after('nama_alat');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->dropColumn('nama_alat_kemampuan');
        });
    }
};
