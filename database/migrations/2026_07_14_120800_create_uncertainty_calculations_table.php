<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil hitung GUM per titik ukur.
 *
 * Angkanya DISIMPAN, bukan dihitung ulang tiap dibaca — sertifikat 5 tahun lalu
 * harus tetap nunjukin angka yang sama walaupun rumus/konstantanya udah berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uncertainty_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_session_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('titik_ke');
            $table->decimal('titik_ukur', 20, 8);
            $table->decimal('rata_rata', 20, 8);
            $table->decimal('error', 20, 8);
            $table->decimal('koreksi', 20, 8);
            $table->decimal('standar_deviasi', 20, 8)->nullable();
            $table->unsignedInteger('jumlah_pengulangan');

            $table->decimal('type_a', 20, 8)->comment('u_a = s / akar(n)');
            // Rincian tiap komponen Type B: ketidakpastian standar, resolusi alat,
            // drift, kondisi lingkungan. JSON karena jumlah komponennya beda-beda
            // per kategori alat; pecah jadi tabel sendiri kalau nanti perlu diquery.
            $table->json('type_b_components')->nullable();
            $table->decimal('type_b', 20, 8);

            $table->decimal('ketidakpastian_gabungan', 20, 8)->comment('u_c');
            $table->decimal('faktor_cakupan_k', 5, 2)->default(2);
            $table->decimal('derajat_kebebasan_efektif', 20, 8)->nullable();
            $table->decimal('ketidakpastian_diperluas', 20, 8)->comment('U = k * u_c');

            $table->decimal('toleransi', 20, 8)->nullable();
            $table->enum('keputusan', ['PASS', 'FAIL']);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['calibration_session_id', 'titik_ke']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uncertainty_calculations');
    }
};
