<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `faktor_cakupan_k` disimpen 8 desimal, bukan 2.
 *
 * `k` bukan lagi konstanta 2: sejak budget penuh jalan, dia dihitung dari
 * t-student pada derajat kebebasan efektif — mis. 1.9706525944 buat veff
 * 223.13. Kolom `decimal(5,2)` motong itu jadi **1.97**.
 *
 * `ketidakpastian_diperluas` disimpen terpisah jadi angka yang dilaporkan
 * nggak salah. Tapi `k` yang kesimpen jadi bohong: siapa pun yang ngalikan
 * `k × u_c` dari baris ini bakal dapat 0.02110 — beda dari 0.02110895 yang
 * beneran dipakai, dan itu persis jenis ketidakcocokan yang bikin orang ragu
 * sama angkanya waktu diaudit.
 *
 * 8 desimal jauh lebih dari cukup: t-student di veff ratusan berubah di
 * desimal ke-5 ke bawah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('uncertainty_calculations', 'faktor_cakupan_k')) {
            return;
        }

        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->decimal('faktor_cakupan_k', 12, 8)->default(2)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('uncertainty_calculations', 'faktor_cakupan_k')) {
            return;
        }

        Schema::table('uncertainty_calculations', function (Blueprint $table) {
            $table->decimal('faktor_cakupan_k', 5, 2)->default(2)->change();
        });
    }
};
