<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi data dari pelanggan (§42, PL_Ubah_Alat & PL_Sertifikat).
 *
 * Antrean SENDIRI, bukan "permintaan jenis koreksi" (§41.4): permintaan
 * melahirkan order, koreksi melahirkan perubahan data alat atau revisi
 * sertifikat. Penanganannya beda, pemutusnya beda, dan mencampur dua antrean
 * membuat keduanya lebih sulit dibaca.
 *
 * `perubahan` menyimpan nilai LAMA sekaligus BARU saat diajukan — ISO/IEC
 * 17025 §7.5.2: yang diminta pelanggan dan yang tercetak waktu itu sama-sama
 * tercatat, walau datanya berubah lagi sesudahnya.
 *
 * Additive murni (aturan Modul Pelanggan butir 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('koreksi_pelanggan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('jenis', ['alat', 'sertifikat']);
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained('certificates')->nullOnDelete();
            $table->json('perubahan');
            $table->text('catatan')->nullable();
            $table->enum('status', ['menunggu', 'diterima', 'ditolak'])->default('menunggu');
            // Dibaca pelanggan. Nama peninjau TIDAK pernah dikirim ke pelanggan.
            $table->text('tanggapan')->nullable();
            $table->foreignId('ditinjau_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditinjau_pada')->nullable();
            // Koreksi sertifikat yang diterima melahirkan revisi (§38).
            $table->foreignId('certificate_revisi_id')->nullable()->constrained('certificates')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at'], 'koreksi_antrean_idx');
            $table->index(['customer_id', 'status', 'created_at'], 'koreksi_pelanggan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('koreksi_pelanggan');
    }
};
