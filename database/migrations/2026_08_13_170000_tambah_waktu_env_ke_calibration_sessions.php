<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jam pencatatan "Environment Condition" — kolom `Time` di lembar kerja.
 *
 * Tabel Env. Condition di cetakan Spectrophotometer (SIDIK-FM-CAL-0511_Rev.5)
 * punya TIGA kolom: `Time`, `Temperature`, `Humidity`, masing-masing dua baris
 * (`First` & `End`). Yang sudah ada di database cuma dua yang belakangan, jadi
 * jam yang ditulis teknisi di kertas selama ini nggak punya tempat dan hilang
 * begitu lembar kerjanya dipindahkan ke aplikasi.
 *
 * Kenapa jamnya penting dan bukan hiasan: suhu & kelembaban awal-akhir cuma
 * bisa dinilai kalau ketahuan rentang waktunya. Beda 1,5 °C dalam 20 menit
 * artinya lain sama beda 1,5 °C dalam 6 jam, dan auditor yang membandingkan
 * lembar kerja dengan sertifikat menanyakan itu dari kertasnya.
 *
 * Nullable, dan tetap nullable: lembar lama nggak punya isinya, dan lembar alat
 * lain (pH/Turbidimeter/Chlorine/Refractometer/Conductivity) belum tentu
 * mencetak kolom `Time` — yang nggak mencetak nggak boleh dipaksa ngisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga per kolom, sama alasannya kayak migrasi 2026_07_23_120000: di
        // database yang dipakai bareng, satu kolom bisa udah kepasang duluan.
        Schema::table('calibration_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('calibration_sessions', 'waktu_awal')) {
                $table->time('waktu_awal')->nullable()->after('kelembaban_akhir');
            }
            if (! Schema::hasColumn('calibration_sessions', 'waktu_akhir')) {
                $table->time('waktu_akhir')->nullable()->after('waktu_awal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn(['waktu_awal', 'waktu_akhir']);
        });
    }
};
