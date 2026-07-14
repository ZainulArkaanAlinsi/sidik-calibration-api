<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sertifikat itu immutable — yang udah terbit nggak boleh diedit. Revisi = baris
 * BARU yang nunjuk ke sertifikat asal lewat `revision_of`.
 *
 * `qr_token` (acak, bukan id berurutan) yang dipakai di URL verifikasi publik.
 * Kalau pakai id, orang luar bisa nebak-nebak sertifikat lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calibration_session_id')->constrained();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->foreignId('revision_of')->nullable()->constrained('certificates');

            $table->string('nomor')->nullable();
            $table->string('qr_token')->unique();
            $table->text('qr_payload')->nullable();
            $table->string('pdf_path')->nullable();

            $table->date('diterbitkan_pada')->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->enum('status', ['menunggu_generate', 'terbit', 'gagal'])->default('menunggu_generate');
            $table->text('alasan_revisi')->nullable();
            $table->timestamps();

            // Jaring pengaman terakhir. Anti-dobel nomornya tetap wajib pakai
            // transaction locking waktu generate, bukan ngandelin constraint ini.
            $table->unique(['organization_id', 'nomor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
