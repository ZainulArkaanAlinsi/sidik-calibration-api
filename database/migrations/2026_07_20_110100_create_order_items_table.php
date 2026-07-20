<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alat-alat yang ikut dianter dalam satu order.
 *
 * Satu order boleh banyak alat: klien biasanya nganter 5 alat sekaligus. Kalau
 * dipaksa satu order satu alat, admin harus bikin 5 order buat satu pengiriman
 * dan work order-nya kecetak 5 lembar.
 *
 * Kondisi terima dicatat di sini, bukan di sesi — ini keterangan waktu alat
 * DITERIMA (lecet, tanpa tutup, dsb), yang harus kerekam walaupun kalibrasinya
 * belum mulai atau malah batal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Nama tabelnya WAJIB ditulis: inflector Laravel nganggep "equipment"
            // uncountable, jadi constrained() bakal nyari tabel `equipment`.
            $table->foreignId('equipment_id')->constrained('equipments');

            $table->text('kondisi_terima')->nullable();
            $table->string('kelengkapan')->nullable();
            $table->text('catatan')->nullable();

            $table->timestamps();

            // Alat yang sama nggak boleh kedaftar dua kali di satu order —
            // itu selalu salah input, dan bikin work order nampilin baris dobel.
            $table->unique(['order_id', 'equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
