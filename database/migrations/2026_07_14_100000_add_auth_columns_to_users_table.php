<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // FK-nya nyusul waktu tabel organizations dibikin (lihat ERD Awal).
            $table->unsignedBigInteger('organization_id')->nullable()->after('id')->index();
            $table->enum('role', ['admin', 'teknisi', 'viewer'])->default('teknisi')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['organization_id', 'role', 'is_active']);
        });
    }
};
