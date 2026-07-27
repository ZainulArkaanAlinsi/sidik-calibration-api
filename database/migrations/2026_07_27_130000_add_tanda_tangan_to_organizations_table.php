<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gambar tanda tangan penanda tangan sertifikat (fase-2 §3c, keputusan 26 Jul).
 *
 * Kolomnya kepisah dari `logo_path` walaupun bentuknya sama, karena tempat
 * simpannya BEDA dan itu keputusan keamanan, bukan gaya:
 *
 * - `logo_path` → disk **public**. Logo lab itu identitas yang memang dipajang.
 * - `tanda_tangan_path` → disk **local (privat)**. Gambar tanda tangan yang URL-nya
 *   bisa diakses siapa pun = siapa pun bisa nempelin ke dokumen palsu. Nggak ada
 *   alasan sah buat bikin file ini kebuka ke internet: yang butuh cuma dompdf, dan
 *   dompdf jalan di server.
 *
 * Posisi & ukurannya nggak dikasih kolom sendiri — ditaruh di
 * `organizations.settings`, karena itu setelan tampilan yang bisa berubah-ubah,
 * bukan data yang perlu di-query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tanda_tangan_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('tanda_tangan_path');
        });
    }
};
