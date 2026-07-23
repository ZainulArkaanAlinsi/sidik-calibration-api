<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi Folder Manager. Satu baris = satu file.
 *
 * File `sertifikat` NGGAK nyalin PDF-nya — cuma nunjuk ke baris `certificates`
 * yang udah ada. Sertifikat itu dokumen resmi yang immutable; kalau di sini
 * disalin, dua salinan bisa beda isi dan yang mana yang sah jadi nggak jelas.
 * Yang disalin cuma file `unggahan` (dokumen pendukung dari admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            // Keisi buat file yang sumbernya sertifikat. Sertifikat kehapus →
            // barisnya ikut hilang, biar nggak ada entri nunjuk ke dokumen hantu.
            $table->foreignId('certificate_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nama');
            $table->enum('sumber', ['sertifikat', 'unggahan'])->default('unggahan');
            // Cuma keisi buat `unggahan` — file sertifikat pathnya diambil dari
            // baris certificates biar selalu ikut yang terbaru.
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('ukuran')->nullable();
            $table->text('keterangan')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'folder_id']);
            // Satu sertifikat cuma boleh nongol sekali per folder — bikin
            // penautan otomatis waktu terbit aman diulang (job bisa di-retry).
            $table->unique(['folder_id', 'certificate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_files');
    }
};
