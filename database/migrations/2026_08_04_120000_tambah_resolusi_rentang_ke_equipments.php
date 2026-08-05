<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resolusi yang BERUBAH per rentang ukur.
 *
 * `equipments.resolusi` cuma muat SATU angka, dan itu asumsi yang diam-diam
 * salah buat sebagian alat. Turbidimeter PT Sidik resolusinya:
 *
 *   < 10 NTU     -> 0,01
 *   10 - <100    -> 0,1
 *   >= 100 NTU   -> 1
 *
 * Karena cuma kesimpen `0,01`, seluruh tabel hasil kepaksa 2 desimal — titik
 * 100 NTU kecetak `100,00` / `101,00` padahal alatnya SECARA FISIK nggak bisa
 * nampilin dua desimal di rentang itu. Di sertifikat terakreditasi, nulis
 * digit yang alatnya nggak punya itu ngaku-ngaku ketelitian, dan itu temuan
 * audit — bukan sekadar beda selera tampilan. Excel master lab nulisnya `101`,
 * dan Excel-nya yang bener.
 *
 * pH nggak kena sama sekali: resolusinya emang 0,01 di seluruh rentang, jadi
 * kolom ini dibiarkan NULL dan perilakunya persis kayak sebelumnya. Itu juga
 * alasan kolomnya nullable, bukan diisi default — alat yang resolusinya
 * seragam nggak boleh ikut berubah gara-gara perbaikan ini.
 *
 * Bentuk isinya: daftar terurut dari rentang terkecil, `maks` eksklusif,
 * `maks: null` artinya "sampai tak hingga".
 *
 *   [{"maks": 10, "resolusi": 0.01},
 *    {"maks": 100, "resolusi": 0.1},
 *    {"maks": null, "resolusi": 1}]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->json('resolusi_rentang')
                ->nullable()
                ->after('resolusi');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->dropColumn('resolusi_rentang');
        });
    }
};
