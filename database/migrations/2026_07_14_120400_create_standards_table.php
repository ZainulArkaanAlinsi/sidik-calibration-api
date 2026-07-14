<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alat standar/acuan milik lab. Ketidakpastiannya = komponen Type B terbesar
 * di perhitungan GUM, jadi tanpa tabel ini rumusnya nggak lengkap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nama');
            $table->string('merk')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();

            $table->string('no_sertifikat')->nullable();
            $table->string('tertelusur_ke')->nullable();
            // Lewat tanggal ini, standarnya nggak boleh dipakai kalibrasi.
            $table->date('berlaku_sampai')->nullable();

            $table->decimal('ketidakpastian', 20, 8)->nullable();
            $table->string('satuan_ketidakpastian')->nullable();
            $table->decimal('faktor_cakupan', 5, 2)->default(2);
            $table->decimal('drift', 20, 8)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standards');
    }
};
