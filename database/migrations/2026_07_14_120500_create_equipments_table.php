<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alat milik pelanggan yang dikalibrasi (UUC).
 *
 * Status di DB cuma `aktif`/`nonaktif`. Nilai ketiga yang dipakai mobile —
 * `overdue` — SENGAJA nggak disimpen: itu turunan dari tanggal_jatuh_tempo,
 * jadi kalau disimpen bakal basi tiap ganti hari. Dihitung waktu dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_category_id')->constrained();

            $table->string('nama_alat');
            $table->string('merk')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number');
            $table->string('no_identifikasi')->nullable();

            $table->decimal('range_min', 20, 8)->nullable();
            $table->decimal('range_max', 20, 8)->nullable();
            $table->string('satuan')->nullable();
            $table->decimal('resolusi', 20, 8)->nullable();
            $table->decimal('toleransi', 20, 8)->nullable();

            $table->string('lokasi')->nullable();
            $table->date('tanggal_kalibrasi_terakhir')->nullable();
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->text('catatan')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'serial_number']);
            $table->index('tanggal_jatuh_tempo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipments');
    }
};
