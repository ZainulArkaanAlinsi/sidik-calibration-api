<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format yang dikirim ke pelanggan: `pdf`, `xlsx`, atau `tautan`.
 *
 * Ikut dicatat karena catatan pengiriman ini bukti — "sertifikat sudah kami
 * kirim" beda artinya kalau yang dikirim ternyata cuma tautan verifikasi, bukan
 * dokumennya. Tanpa kolom ini dua kejadian itu kelihatan sama persis di riwayat.
 *
 * Default `pdf`: semua baris yang udah ada dikirim waktu PDF satu-satunya
 * pilihan, jadi nilainya bukan tebakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('certificate_email_logs', 'format')) {
            return;
        }

        Schema::table('certificate_email_logs', function (Blueprint $table) {
            $table->string('format', 8)->default('pdf')->after('cc');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_email_logs', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
