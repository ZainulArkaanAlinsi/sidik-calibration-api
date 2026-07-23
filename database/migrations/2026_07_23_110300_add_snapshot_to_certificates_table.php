<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `snapshot` = seluruh isi sertifikat, dibekukan waktu terbit.
 *
 * Alasannya sama kayak `uncertainty_calculations` nyimpen angka hasil hitung:
 * sertifikat itu dokumen resmi yang udah dipegang pelanggan. Kalau alamat PT
 * diganti atau alat dijual tahun depan, PDF/Excel yang dicetak ulang harus
 * tetap nunjukin isi yang SAMA PERSIS kayak waktu terbit — bukan hasil join
 * ulang ke data master yang udah berubah.
 *
 * PDF, Excel, halaman verifikasi QR, dan API semuanya baca dari sini, jadi
 * empat-empatnya nggak mungkin beda isi.
 *
 * `validasi` nyimpen hasil pemeriksaan ulang perhitungan waktu terbit
 * (spesifikasi poin 11) — jejak bahwa angkanya udah dicek sistem, bukan cuma
 * disalin dari input mentah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('qr_payload');
            $table->json('validasi')->nullable()->after('snapshot');
            $table->string('xlsx_path')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'validasi', 'xlsx_path']);
        });
    }
};
