<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom "Usage Check" di lembar kerja: daftar standar yang tersedia, dicentang
 * mana yang beneran dipakai di sesi ini.
 *
 * Kenapa nggak cukup ngandelin `uncertainty_calculations.standard_id`: baris itu
 * cuma ada buat titik yang BERHASIL dihitung. Standar yang cuma dipakai buat
 * ngecek (mis. RTD Sensor buat baca suhu larutan) nggak pernah muncul di sana,
 * padahal dia tetap harus tercatat sebagai standar yang dipakai — itu bagian
 * dari ketertelusuran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_session_standard', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_id')->constrained();

            // false = tersedia di daftar tapi NGGAK dicentang teknisi. Disimpen
            // apa adanya, bukan barisnya dihapus: "diperiksa lalu nggak dipakai"
            // itu informasi yang beda dari "nggak pernah ditanya".
            $table->boolean('dipakai')->default(true);
            $table->string('keterangan')->nullable();

            $table->timestamps();

            // Nama index-nya ditulis eksplisit: nama bawaan Laravel buat dua
            // kolom panjang ini kena batas 64 karakter punya MySQL.
            $table->unique(['calibration_session_id', 'standard_id'], 'sesi_standar_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_session_standard');
    }
};
