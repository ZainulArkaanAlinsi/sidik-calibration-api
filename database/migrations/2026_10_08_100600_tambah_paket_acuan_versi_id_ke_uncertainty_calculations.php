<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stempel versi data acuan pada tiap hasil hitung.
 *
 * Kenapa kolom, bukan diturunkan dari tanggal kalibrasi + rentang berlaku:
 * rentang berubah SESUDAH hasil dihitung (versi baru menutup `berlaku_sampai`
 * versi lama, versi bisa ditarik). Pencarian ulang hari ini bisa memberi versi
 * lain dari yang benar-benar dipakai. Kenapa bukan di `type_b_components`:
 * tanpa FK tidak ada integritas, tanpa index tidak bisa menjawab "hasil mana
 * yang dihitung dengan versi X" — query inti simulasi (P2) dan penahan terbit.
 *
 * Preseden: `formula_version_id` (2026_07_27_110000) — nullable + nullOnDelete.
 * `null` = dihitung dari berkas JSON, bukan dari versi di database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->unsignedBigInteger('paket_acuan_versi_id')
                ->nullable()
                ->after('formula_version_id');

            $table->index('paket_acuan_versi_id', 'uc_paket_acuan_versi_idx');

            // nullOnDelete, bukan cascade: hasil hitung yang sudah dipakai
            // sertifikat tidak boleh ikut terhapus bersama versinya.
            $table->foreign('paket_acuan_versi_id', 'uc_paket_acuan_versi_fk')
                ->references('id')
                ->on('paket_acuan_versi')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            // SQLite tidak bisa membuang FK lewat NAMA (Laravel membangun ulang
            // tabelnya dan mencocokkan FK lewat kolom). MySQL sebaliknya butuh
            // nama yang dipasang di up() — nama turunan dari kolom
            // (`..._paket_acuan_versi_id_foreign`) tidak pernah ada di sana.
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['paket_acuan_versi_id']);
            } else {
                $table->dropForeign('uc_paket_acuan_versi_fk');
            }

            $table->dropIndex('uc_paket_acuan_versi_idx');
            $table->dropColumn('paket_acuan_versi_id');
        });
    }
};
