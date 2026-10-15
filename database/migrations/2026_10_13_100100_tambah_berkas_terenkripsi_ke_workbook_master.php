<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidik Studio S1b, tinjau #5: penanda apakah berkas `.xlsm` asli yang disimpan
 * (K-49-11, "apa adanya") memang terenkripsi. Sumber ZIP polos tetap disimpan
 * apa adanya, tetapi tidak boleh kelihatan sama dengan yang terenkripsi.
 *
 * Migrasi terpisah, bukan disisipkan ke `create_workbook_master_tables`: DB
 * lokal Studio sudah menjalankan migrasi itu, dan menambah kolom di sini tidak
 * menuntut tabelnya dibuang. Additive, nullable (null = tidak ada berkas asli).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workbook_master', function (Blueprint $table) {
            $table->boolean('berkas_terenkripsi')->nullable()->after('berkas_path');
        });
    }

    public function down(): void
    {
        Schema::table('workbook_master', function (Blueprint $table) {
            $table->dropColumn('berkas_terenkripsi');
        });
    }
};
