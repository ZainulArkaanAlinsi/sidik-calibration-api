<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama tempat kalibrasi dikerjakan, buat sesi `onsite`.
 *
 * Kolom `lokasi` cuma bisa jawab `lab` atau `onsite`, sementara sertifikat
 * nyetak `Insitu (PT. LDC)` — nama tempatnya ikut, dan itu yang bikin dokumen
 * bisa ditelusuri balik ke kunjungan mana.
 *
 * Nggak diturunkan dari pelanggan pemilik alat: satu kunjungan bisa dikerjakan
 * di pabrik lain milik grup yang sama, dan yang sah di dokumen kalibrasi adalah
 * tempat alatnya BENERAN diukur — sama alasannya kayak `alat_model`/`alat_merk`
 * di migrasi 2026_07_29_120000.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('calibration_sessions', 'lokasi_nama')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->string('lokasi_nama')->nullable()->after('lokasi');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('calibration_sessions', 'lokasi_nama')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn('lokasi_nama');
        });
    }
};
