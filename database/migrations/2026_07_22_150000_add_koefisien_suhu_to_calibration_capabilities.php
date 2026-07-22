<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koefisien kurva nilai standar terhadap suhu: y = a·x² + b·x + c
 * (x = suhu larutan °C, y = nilai standar pada suhu itu).
 *
 * Kenapa perlu disimpen: nilai buffer pH BERGERAK ngikutin suhu. Label di botol
 * ("pH 4.01") itu nilai pada suhu referensi, BUKAN nilai yang dipakai ngitung.
 * Yang dipakai adalah nilai terkoreksi suhu — mis. buffer pH 4 pada 22.2 °C
 * nilainya 4.0092252.
 *
 * Dipakai buat MEMERIKSA `titik_ukur` yang dikirim klien. Tanpa ini, kalau
 * mobile keliru ngirim nilai nominal (3.99) alih-alih terkoreksi (4.0092),
 * backend nerima apa adanya: CMC tetap ketemu (matching-nya pakai pembulatan),
 * nggak ada error — cuma angka KOREKSI di sertifikat yang salah. Itu jenis
 * kesalahan yang nggak keliatan sampai ada yang mbandingin sama arsip lama.
 *
 * Nullable: cuma titik yang nilainya emang bergantung suhu (buffer pH) yang
 * ngisi. Kosong = pemeriksaan dilewat, perilaku lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->decimal('koef_suhu_a', 20, 10)->nullable()->after('ci_perbedaan_suhu');
            $table->decimal('koef_suhu_b', 20, 10)->nullable()->after('koef_suhu_a');
            $table->decimal('koef_suhu_c', 20, 10)->nullable()->after('koef_suhu_b');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_capabilities', function (Blueprint $table) {
            $table->dropColumn(['koef_suhu_a', 'koef_suhu_b', 'koef_suhu_c']);
        });
    }
};
