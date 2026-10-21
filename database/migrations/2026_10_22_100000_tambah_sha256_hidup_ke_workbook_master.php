<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `--ganti` tidak lagi menghapus permanen (ADR-49-07, rencana
 * studio-kendali-olah-data fase 0, 22 Okt 2026).
 *
 * Dulu unik `(organization_id, sha256)` memaksa `--ganti` menghapus baris lama
 * sebelum pengganti ber-sha sama (xlsm: sha = byte berkas, tidak ikut format
 * kanonik) bisa ditulis. Sekarang baris lama tetap ada sebagai riwayat
 * (`digantikan_oleh_id` = pengganti), jadi keunikan dipindah ke
 * `sha256_hidup`: = `sha256` untuk baris yang masih hidup, NULL untuk baris
 * yang sudah diekstrak ulang. NULL tidak pernah bentrok di indeks unik (MySQL
 * & SQLite), jadi "isi yang sama tidak lahir dua kali di satu lab" tetap
 * ditegakkan database untuk baris hidup.
 *
 * Kenapa kolom, bukan sumbu lain: tidak ada kolom yang bisa membedakan "baris
 * hasil ekstrak ulang" dari pasangannya yang ber-sha sama — `digantikan_oleh_id`
 * juga dipakai riwayat jenis (K-49-13), `diarsipkan_pada` berarti dikeluarkan
 * dari Studio. Additive; data lama diisi `sha256_hidup = sha256`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workbook_master', function (Blueprint $table) {
            $table->char('sha256_hidup', 64)->nullable()->after('sha256');
        });

        DB::table('workbook_master')->update(['sha256_hidup' => DB::raw('sha256')]);

        Schema::table('workbook_master', function (Blueprint $table) {
            $table->dropUnique('wm_org_sha_unik');
            $table->unique(['organization_id', 'sha256_hidup'], 'wm_org_sha_hidup_unik');
            $table->index(['organization_id', 'sha256'], 'wm_org_sha_idx');
        });
    }

    public function down(): void
    {
        // Turun hanya aman bila belum ada riwayat `--ganti` ber-sha kembar —
        // kalau ada, unik lama gagal dibuat dan migrasi berhenti (bukan
        // menghapus riwayat diam-diam).
        Schema::table('workbook_master', function (Blueprint $table) {
            $table->dropUnique('wm_org_sha_hidup_unik');
            $table->dropIndex('wm_org_sha_idx');
            $table->unique(['organization_id', 'sha256'], 'wm_org_sha_unik');
        });

        Schema::table('workbook_master', function (Blueprint $table) {
            $table->dropColumn('sha256_hidup');
        });
    }
};
