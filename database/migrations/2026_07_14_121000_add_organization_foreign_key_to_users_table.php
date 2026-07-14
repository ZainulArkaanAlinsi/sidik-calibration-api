<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FK yang ditunda waktu tabel organizations belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Akun yang dibikin sebelum tabel organizations ada nunjuk ke id 1 / null.
        // Rapiin dulu, kalau nggak FK-nya nolak.
        $organizationId = DB::table('organizations')->min('id');

        DB::table('users')->update(['organization_id' => $organizationId]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });
    }
};
