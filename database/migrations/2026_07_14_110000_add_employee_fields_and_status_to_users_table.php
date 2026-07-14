<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nyesuain users sama kontrak API mobile versi 14 Jul: teknisi login pakai ID
 * pegawai, dan akun hasil daftar mandiri harus nunggu approval admin — jadi
 * `is_active` (boolean) nggak cukup, butuh status 3 nilai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable karena baris lama belum punya ID pegawai; di endpoint
            // register & approval tetap divalidasi wajib + unik.
            $table->string('employee_id')->nullable()->unique()->after('organization_id');
            $table->string('department')->nullable()->after('name');
            $table->enum('status', ['aktif', 'pending', 'nonaktif'])->default('pending')->after('role');
        });

        // Pindahin data lama biar akun yang tadinya aktif nggak ikut kekunci.
        DB::table('users')->where('is_active', true)->update(['status' => 'aktif']);
        DB::table('users')->where('is_active', false)->update(['status' => 'nonaktif']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
        });

        DB::table('users')->update(['is_active' => false]);
        DB::table('users')->where('status', 'aktif')->update(['is_active' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employee_id', 'department', 'status']);
        });
    }
};
