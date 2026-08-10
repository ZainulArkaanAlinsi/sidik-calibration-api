<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `uncertainty_calculations.keputusan` boleh NULL — artinya "alat ini memang
 * nggak divonis", bukan "vonisnya belum diisi".
 *
 * Nggak semua jenis alat punya batas keberterimaan. Master Olah Data
 * Conductivity Meter, misalnya, nggak punya satu pun sel yang mbandingin hasil
 * sama batas toleransi, dan kedua sheet sertifikatnya cuma nyetak kolom
 * `Correction` + `U95%` lalu berhenti. Alat kayak gitu dilaporin apa adanya —
 * ngasih PASS/FAIL berarti ngarang kriteria kelulusan buat alat pelanggan.
 *
 * Sebelum ini kolomnya NOT NULL, jadi `GumCalculator` mau nggak mau harus
 * ngeluarin salah satu dari dua vonis. Buat alat tanpa toleransi, `toleransi`
 * ke-cast jadi `0.0` dan `|error| + U <= 0` itu mustahil — hasilnya **FAIL**,
 * diam-diam, di kolom yang paling dipercaya orang.
 *
 * `calibration_sessions.keputusan` udah nullable dari awal (migrasi
 * 2026_07_14_120600), jadi yang perlu disusulin cuma tabel titiknya.
 *
 * Nggak ada baris lama yang berubah: yang udah keisi PASS/FAIL tetap apa
 * adanya, dan keempat alat terseed (pH, Turbidimeter, Chlorine, Refractometer)
 * semuanya punya `toleransi`, jadi jalur null-nya nggak kesentuh sama mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uncertainty_calculations', function (Blueprint $table): void {
            $table->enum('keputusan', ['PASS', 'FAIL'])->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris ber-`keputusan` NULL harus dibereskan dulu sebelum kolomnya
        // bisa dibikin NOT NULL lagi — dan mau diisi PASS atau FAIL itu
        // keputusan metrologi, bukan keputusan migrasi. Sengaja nggak ditebak
        // di sini; rollback-nya bakal gagal keras kalau masih ada yang NULL,
        // dan itu lebih baik daripada diam-diam nyetempel FAIL.
        Schema::table('uncertainty_calculations', function (Blueprint $table): void {
            $table->enum('keputusan', ['PASS', 'FAIL'])->nullable(false)->change();
        });
    }
};
