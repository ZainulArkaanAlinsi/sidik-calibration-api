<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan alat MILIK PELANGGAN (PL_Ubah_Alat "Catatan (opsional)").
 *
 * Kolom sendiri, bukan `equipments.catatan`: yang itu catatan INTERNAL lab dan
 * sengaja tidak pernah dikirim ke pelanggan (`AlatPelangganResource`).
 * Menyuruh pelanggan menyuntingnya berarti membocorkan catatan lab sekaligus
 * membiarkan pelanggan menimpanya — tanpa satu pun error.
 *
 * Additive (aturan Modul Pelanggan butir 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->text('catatan_pelanggan')->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropColumn('catatan_pelanggan');
        });
    }
};
