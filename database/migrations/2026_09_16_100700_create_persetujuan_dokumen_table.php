<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan persetujuan kebijakan privasi & syarat ketentuan (REQ-PRV-01).
 *
 * VERSInya ikut disimpan, dan itu intinya. "Pengguna sudah setuju" tanpa
 * menyebut setuju pada versi yang mana nggak bisa dipertanggungjawabkan begitu
 * dokumennya direvisi — dan UU PDP menuntut persetujuan atas isi yang jelas.
 * Waktu dokumennya naik versi, barisnya BERTAMBAH, bukan diperbarui.
 *
 * Append-only, jadi sengaja TANPA trait `Diaudit`: barisnya sendiri sudah jejak
 * audit. Mengauditnya berarti menyimpan hal yang sama dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persetujuan_dokumen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('jenis', ['kebijakan_privasi', 'syarat_ketentuan']);
            $table->string('versi', 40);
            $table->timestamp('disetujui_pada');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_dokumen');
    }
};
