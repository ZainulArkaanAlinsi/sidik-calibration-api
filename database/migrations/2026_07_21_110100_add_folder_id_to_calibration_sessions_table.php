<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tempat sesi (beserta sertifikatnya) disimpen di pohon folder arsip.
 *
 * Nullable, dan `nullOnDelete` — bukan cascade. Rekaman kalibrasi lab
 * terakreditasi nggak boleh ikut kehapus gara-gara foldernya dihapus; paling
 * jauh dia balik jadi "belum difolderin". Ini lapis kedua: lapis pertama,
 * controller udah nolak hapus folder yang masih ada isinya.
 *
 * Sesi lama (sebelum migrasi ini) nilainya null. Backfill-nya sengaja nggak
 * dijalanin di sini — root folder dibikin saat dibutuhin (`Folder::akarUntuk`),
 * jadi bikin ribuan folder di migrasi cuma buat ngisi kolom itu mubazir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('equipment_id')
                ->constrained('folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropColumn('folder_id');
        });
    }
};
