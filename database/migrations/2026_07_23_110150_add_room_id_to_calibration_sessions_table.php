<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Calibration Location" di sertifikat itu nama RUANGAN (mis. "Lab. Uji A"),
 * bukan enum lab/onsite. Master data ruangannya udah ada dari Minggu 09, tapi
 * sesi belum nunjuk ke sana — jadi lokasi di sertifikat cuma bisa ditulis
 * "Laboratorium" doang. Ini yang nyambungin.
 *
 * Tetap nullable: sesi onsite dikerjain di tempat pelanggan, nggak punya
 * ruangan lab.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga `hasColumn` karena kolomnya bisa udah kepasang duluan di
        // database yang dipakai bareng, sementara tabel `migrations` nggak
        // nyatet migrasi ini pernah jalan. Tanpa penjaga ini `migrate` mati di
        // sini dengan "Duplicate column name", dan SEMUA migrasi sesudahnya —
        // folders, folder_files, audit_logs, formulas, tanda tangan — ikut
        // nggak kepasang. Satu tabrakan kecil bikin belasan fitur nggak punya
        // tabelnya.
        if (Schema::hasColumn('calibration_sessions', 'room_id')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('lokasi')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('calibration_sessions', 'room_id')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }
};
