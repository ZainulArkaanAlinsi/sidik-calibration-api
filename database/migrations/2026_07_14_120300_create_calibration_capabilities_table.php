<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMC — rentang ukur & ketidakpastian terbaik yang boleh diklaim lab, disalin
 * dari lampiran akreditasi (data-kemampuan-kalibrasi.json). Dipakai buat nolak
 * alat yang di luar kemampuan lab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_category_id')->constrained()->cascadeOnDelete();
            $table->string('nama_alat');
            $table->string('parameter')->nullable();

            // range_min nullable karena banyak kemampuan yang cuma titik tunggal
            // (contoh: buret "25 mL") atau batas bawahnya non-numerik ("ambient"
            // buat oven) — teks aslinya disimpan di range_note biar nggak ilang.
            $table->decimal('range_min', 20, 8)->nullable();
            $table->decimal('range_max', 20, 8)->nullable();
            $table->string('range_note')->nullable();
            $table->string('satuan');

            $table->decimal('ketidakpastian_terbaik', 20, 8);
            $table->string('satuan_ketidakpastian');
            $table->decimal('faktor_cakupan', 5, 2)->default(2);

            $table->text('metode')->nullable();
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_capabilities');
    }
};
