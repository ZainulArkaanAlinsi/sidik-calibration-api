<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `folder_files` bisa nunjuk ke lembar kerja, bukan cuma sertifikat.
 *
 * Sebelumnya folder PT cuma keisi sertifikat. Efeknya riwayat kalibrasi satu
 * alat nggak lengkap: sertifikat itu KESIMPULAN, sementara yang ditanya waktu
 * audit justru pembacaan mentahnya — siapa yang ngukur, pakai standar apa,
 * suhu berapa. Itu semua ada di lembar kerja, yang sebelumnya nggak pernah
 * nyampe Folder Manager sama sekali.
 *
 * `path` sengaja DIBIARIN NULL buat baris lembar kerja: yang ditaut itu
 * RECORD-nya, bukan berkas hasil render.
 *
 * Alasannya lembar kerja bisa direvisi. Kalau tiap kirim dirender jadi PDF,
 * satu sesi yang ditolak lalu dikirim ulang ninggalin dua-tiga berkas yang
 * isinya beda-beda, dan nggak ada yang tau mana yang berlaku — folder-nya
 * berhenti ngomong yang bener. Nunjuk ke record bikin isinya selalu ikut
 * keadaan terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('folder_files')
            || Schema::hasColumn('folder_files', 'calibration_session_id')) {
            return;
        }

        Schema::table('folder_files', function (Blueprint $table) {
            $table->foreignId('calibration_session_id')
                ->nullable()
                ->after('certificate_id')
                ->constrained()
                // Sesi dihapus = lembar kerjanya nggak ada lagi, jadi barisnya
                // ikut hilang. Bukan `set null`: baris yang nggak nunjuk ke
                // apa pun itu entri hantu di Folder Manager.
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('folder_files')
            || ! Schema::hasColumn('folder_files', 'calibration_session_id')) {
            return;
        }

        Schema::table('folder_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calibration_session_id');
        });
    }
};
