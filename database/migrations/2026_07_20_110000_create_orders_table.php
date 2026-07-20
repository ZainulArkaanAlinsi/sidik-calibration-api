<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order kalibrasi — permintaan pelanggan, dicatat di meja penerimaan.
 *
 * Ini yang jadi dasar cetak work order. Dibikin SEBELUM ada sesi kalibrasi:
 * alat diterima dulu, work order dicetak, baru teknisi ngerjain. Makanya daftar
 * alatnya disimpen di `order_items`, bukan diturunin dari sesi — kalau
 * diturunin, work order baru bisa dicetak setelah kalibrasinya jalan, kebalik
 * dari urutan kerja aslinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained();
            // Petugas yang nerima alat di meja depan. Nullable karena order lama
            // hasil backfill nggak punya jejak siapa yang nerima.
            $table->foreignId('diterima_oleh')->nullable()->constrained('users');

            $table->string('nomor');
            $table->date('tanggal_masuk');
            $table->date('tanggal_janji_selesai')->nullable();

            $table->enum('status', ['baru', 'diproses', 'selesai', 'dibatalkan'])->default('baru');
            $table->text('catatan')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Nomor order unik PER organisasi, ngikutin pola nomor_sesi &
            // nomor sertifikat — dua PT boleh sama-sama punya ORD/2026/07/0001.
            $table->unique(['organization_id', 'nomor']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
