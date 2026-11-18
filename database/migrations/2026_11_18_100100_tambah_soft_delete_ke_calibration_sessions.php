<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arsip draf lembar kerja (CRUD, 18 Nov 2026): satu kolom `deleted_at`.
 *
 * Draf yang diarsipkan TIDAK dihapus -- `raw_measurements`,
 * `uncertainty_calculations`, dan seluruh isi sesinya tetap ada (AGENTS.md
 * §Peran butir 5, ISO/IEC 17025 7.5.2). Kolom ini hanya membuat sesinya hilang
 * dari semua kueri Eloquent biasa lewat `SoftDeletingScope`, dan bisa
 * dipulihkan.
 *
 * Alasan, pelaku, dan waktunya TIDAK disimpan di kolom baru: itu hidup di
 * `audit_logs` (aksi `diarsipkan`), tempat yang memang dibaca asesor, supaya
 * tidak ada dua sumber yang bisa berbeda.
 *
 * Nullable tanpa default, jadi sesi yang sudah ada tidak berubah satu baris
 * pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
