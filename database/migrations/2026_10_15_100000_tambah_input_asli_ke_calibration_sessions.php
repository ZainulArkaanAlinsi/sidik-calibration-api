<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isian ASLI teknisi, dibekukan saat lembar pertama kali dikirim (K-50-01).
 *
 * Sejak Tolak dicabut, Master Data mengoreksi lembar teknisi sendiri lewat
 * `PUT`. Jalur simpan itu MENGHAPUS lalu menulis ulang `raw_measurements`
 * (`CalibrationController::isiUlangPengukuran()` — tabelnya tanpa
 * SoftDeletes), dan `hasil_autoclave` ditimpa utuh. Jejak koreksi di
 * `audit_logs` menyimpan lama → baru per penyimpanan, tapi isian pertama
 * teknisi cuma bisa dirangkai ulang dengan memutar balik semua koreksi —
 * dan satu baris audit yang hilang memutus rantainya.
 *
 * Kenapa SATU kolom JSON, bukan tabel versi: yang hilang cuma satu keadaan
 * (isian pertama). Setiap keadaan sesudahnya sudah tercatat lengkap di
 * `audit_logs` (siapa, kapan, alasan, lama → baru); tabel versi akan
 * menggandakan jejak itu. Kolomnya ditulis SEKALI dan tidak pernah ditimpa.
 *
 * Additive, nullable: sesi lama tetap sah tanpa isian asli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->json('input_asli')->nullable()->after('hasil_autoclave');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->dropColumn('input_asli');
        });
    }
};
