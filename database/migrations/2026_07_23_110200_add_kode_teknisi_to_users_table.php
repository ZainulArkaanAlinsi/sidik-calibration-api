<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Technician ID" di sertifikat itu inisial pendek (mis. `DR`), bukan nama
 * lengkap dan bukan `employee_id` (yang formatnya nomor pegawai internal).
 * Kalau kosong, sertifikat jatuh ke inisial dari nama — lihat
 * `User::kodeTeknisi()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kode_teknisi', 10)->nullable()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kode_teknisi');
        });
    }
};
