<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lembar kerja pH nyatet SUHU LARUTAN di tiap pembacaan, sebelah kolom pH-nya —
 * bukan cuma suhu ruangan sekali di header.
 *
 * Alasannya fisika: pembacaan pH itu bergantung suhu. Nilai buffer 7,00 di 25°C
 * beda dari nilai yang sama di 20°C. Tanpa kolom ini, pembacaan yang diambil di
 * suhu berbeda kelihatan seolah-olah setara, dan itu nggak bisa dijelasin lagi
 * waktu diaudit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->decimal('suhu', 8, 2)->nullable()->after('pembacaan');
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table) {
            $table->dropColumn('suhu');
        });
    }
};
