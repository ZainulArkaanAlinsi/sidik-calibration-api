<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dari lampiran akreditasi asli (LK 285 IDN_PT Sidik_draft.pdf): masa berlaku
 * akreditasi nongol di KOP TIAP HALAMAN. Sertifikat yang kita terbitin harus
 * nyantumin ini juga, jadi datanya wajib ada di DB — bukan di-hardcode di template.
 *
 * Disimpan sebagai kolom tanggal (bukan di dalam `settings`) supaya bisa diquery:
 * lab yang akreditasinya kadaluarsa nggak boleh nerbitin sertifikat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->date('akreditasi_mulai')->nullable()->after('no_akreditasi');
            $table->date('akreditasi_berakhir')->nullable()->after('akreditasi_mulai');
            $table->string('standar_akreditasi')->nullable()->after('akreditasi_berakhir');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['akreditasi_mulai', 'akreditasi_berakhir', 'standar_akreditasi']);
        });
    }
};
