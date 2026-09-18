<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiga kolom identitas yang dibutuhkan akun pelanggan (03-SDD §4.1).
 *
 * Ketiganya nullable, jadi 100% additive: baris `users` yang sudah ada sama
 * sekali nggak tersentuh, dan app internal nggak melihat perubahan apa pun.
 *
 * `dianonimkan_pada` dipakai REQ-AUTH-11 (hapus akun). Yang dihapus cuma data
 * pribadinya; rekaman lab — alat, permintaan, sertifikat — tetap utuh (BR-07),
 * dan kolom ini yang menandai barisnya sudah dianonimkan supaya nggak ada yang
 * mengira akun itu masih dipakai orang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telepon', 30)->nullable()->after('email');
            $table->string('jabatan', 100)->nullable()->after('department');
            $table->timestamp('dianonimkan_pada')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telepon', 'jabatan', 'dianonimkan_pada']);
        });
    }
};
