<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiga field "langkah awal" worksheet yang selama ini nggak bisa diisi orang.
 *
 * Worksheet kalibrasi itu FORMULIR yang diisi manual (atau dibaca dari foto),
 * bukan tampilan yang isinya diturunkan sistem. Tiga kolom di bawah sebelumnya
 * dipaksa backend, jadi hasil input nggak bisa sama persis sama kertasnya:
 *
 * - `nomor_sertifikat` : kolom "Certificate Number". Sebelumnya SELALU
 *   digenerate backend (CAL/YYYY/MM/NNNN). Lab udah punya penomoran sendiri
 *   (mis. 012-CAL-524) dan itu yang tercetak di arsip mereka. Kalau kosong,
 *   backend tetap ngasih nomor otomatis — jadi alur lama nggak berubah.
 *
 * - `dihitung_oleh` : kolom "Calculated by". Di sertifikat isinya INISIAL
 *   (mis. "NR"), dan orangnya bisa beda dari yang nandatangani. Sebelumnya
 *   nggak ada tempatnya sama sekali.
 *
 * - `metode_kalibrasi` : kolom "Calibration Method". Sebelumnya cuma bisa
 *   diturunkan dari nomor IK punya CMC. Kalau diisi, yang ini yang menang —
 *   teknisi kadang perlu nyatet revisi metode yang beda dari default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->string('nomor_sertifikat')->nullable()->after('nomor_order');
            $table->string('dihitung_oleh')->nullable()->after('nomor_sertifikat');
            $table->string('metode_kalibrasi')->nullable()->after('dihitung_oleh');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn(['nomor_sertifikat', 'dihitung_oleh', 'metode_kalibrasi']);
        });
    }
};
