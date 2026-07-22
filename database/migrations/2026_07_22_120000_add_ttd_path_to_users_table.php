<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gambar tanda tangan buat dicetak di sertifikat.
 *
 * Nempel ke USER, bukan ke organisasi: yang tanda tangan itu orangnya
 * (penanda tangan sertifikat = admin yang meng-approve, jabatannya dari kolom
 * `department`). Kalau penanda tangannya ganti orang, tanda tangannya ikut
 * ganti sendiri tanpa perlu ngutak-atik pengaturan lab.
 *
 * Nullable — sertifikat tetap terbit tanpa gambar TTD, cuma nama & jabatan
 * yang tercetak di atas garis (perilaku yang udah jalan selama ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ttd_path')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ttd_path');
        });
    }
};
