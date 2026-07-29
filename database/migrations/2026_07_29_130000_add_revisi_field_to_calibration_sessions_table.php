<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom mana yang diminta admin dibetulin waktu nolak lembar kerja.
 *
 * `catatan_revisi` yang udah ada itu PROSA — bagus buat manusia, nggak bisa
 * dipakai mesin. Teknisi yang nerima "serial number nggak kebaca, env condition
 * juga kosong" tetap harus nyisir sendiri formulirnya nyari dua kolom itu di
 * antara puluhan kolom lain.
 *
 * Kolom ini nyimpen kode kolomnya (`alat_serial_number`, `suhu_awal`, ...)
 * supaya layar teknisi bisa nyorot persis yang diminta. Prosa tetap dikirim
 * juga — kode doang kehilangan alasannya, dan "kenapa" itu yang bikin teknisi
 * nggak ngulang kesalahan yang sama minggu depan.
 *
 * Nullable & default null: nolak tanpa nunjuk kolom tertentu tetap sah (mis.
 * "hasilnya nggak masuk akal, ulangi seluruh titik 7").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('calibration_sessions', 'revisi_field')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->json('revisi_field')->nullable()->after('catatan_revisi');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('calibration_sessions', 'revisi_field')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn('revisi_field');
        });
    }
};
