<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu event kalibrasi 1 alat — pusat pipeline.
 *
 * Nilai `status` & `keputusan` ngikutin docs/kontrak-api.md (bukan istilah
 * Inggris di ERD awal), karena mobile ngoding persis string ini.
 *
 * `keputusan` (PASS/FAIL) sengaja DIPISAH dari `status`: sesi FAIL tetap boleh
 * disetujui dan terbit sertifikat — statusnya aja beda, bukan diblokir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Nama tabelnya WAJIB ditulis: inflector Laravel nganggep "equipment"
            // uncountable, jadi constrained() bakal nyari tabel `equipment` (tanpa s).
            $table->foreignId('equipment_id')->constrained('equipments');
            $table->foreignId('teknisi_id')->constrained('users');
            $table->foreignId('standard_id')->nullable()->constrained();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');

            $table->string('nomor_sesi')->nullable();
            $table->enum('input_method', ['manual', 'ocr'])->default('manual');
            $table->enum('status', ['draft', 'menunggu_approval', 'disetujui', 'perlu_revisi'])->default('draft');
            $table->enum('keputusan', ['PASS', 'FAIL'])->nullable();

            $table->date('tanggal_kalibrasi');
            $table->enum('lokasi', ['lab', 'onsite'])->default('lab');
            $table->decimal('suhu_ruang', 8, 2)->nullable();
            $table->decimal('kelembaban', 8, 2)->nullable();

            $table->text('catatan_revisi')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'nomor_sesi']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_sessions');
    }
};
