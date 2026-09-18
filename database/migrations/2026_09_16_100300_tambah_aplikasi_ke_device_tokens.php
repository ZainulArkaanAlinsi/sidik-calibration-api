<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `device_tokens.aplikasi` memisahkan perangkat app internal dari app pelanggan.
 *
 * Tanpa kolom ini, push "sertifikat terbit" buat pelanggan bisa mendarat di HP
 * teknisi dan sebaliknya — dua aplikasi berbeda, dua Firebase app berbeda, satu
 * tabel token. Default `internal` supaya seluruh baris yang sudah ada tetap
 * benar tanpa backfill.
 *
 * Kolomnya BARU, jadi ENUM bisa dideklarasikan langsung di sini — nggak butuh
 * `ALTER ... MODIFY` seperti dua kolom `users`, dan jalan apa adanya di SQLite
 * maupun MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->enum('aplikasi', ['internal', 'pelanggan'])->default('internal')->after('platform');
            $table->index(['user_id', 'aplikasi']);
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'aplikasi']);
            $table->dropColumn('aplikasi');
        });
    }
};
