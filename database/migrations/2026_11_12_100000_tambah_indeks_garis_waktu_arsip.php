<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua indeks untuk garis waktu arsip dan filter tanggal Riwayat. HANYA indeks
 * (DDL), tidak menyentuh data satu baris pun; `down()` menghapus keduanya.
 *
 * Dibuktikan lewat EXPLAIN pada MySQL 8.0 lokal dengan 10.000 sertifikat/sesi
 * sintetis (laporan lengkap di badan PR). Intinya:
 *
 *   certificates (organization_id, diterbitkan_pada)
 *     garis waktu, tingkat hari: tipe `ref` pada indeks nomor (5.571 baris
 *     dibaca, temporary + filesort) -> `range` pada indeks ini (68 baris);
 *     median 109 ms -> 2 ms (kelompok), 187 ms -> 5 ms (item berhalaman).
 *     Tingkat bulan 147 -> 22 ms, tingkat tahun 77 -> 47 ms.
 *
 *   calibration_sessions (organization_id, tanggal_kalibrasi)
 *     Riwayat `dari`/`sampai` satu bulan: indeks nomor_sesi dibaca penuh
 *     (5.491 baris) -> `range` (297 baris); hitung total 132 -> 1,2 ms, daftar
 *     17 -> 3,5 ms.
 *
 * Yang SENGAJA tidak dibuat, meski diusulkan sebagai kandidat:
 *  - `orders (organization_id, nomor)` -- sudah ada sebagai UNIQUE
 *    `orders_organization_id_nomor_unique` (2026_07_20_110000).
 *  - `calibration_sessions (organization_id, nomor_order)` -- hanya mempercepat
 *    pencarian EKSAK (270 ms -> 1 ms), sedangkan aplikasi mencari nomor order
 *    dengan `LIKE '%kata%'` yang tidak bisa memakai indeks, dan tidak ada kode
 *    lain yang mencari `nomor_order = ?`. Indeks tanpa pembaca hanya menambah
 *    biaya tulis.
 *
 * Tabel produksi saat dirancang kecil (certificates ~17, calibration_sessions
 * ~25 baris), jadi pembuatan indeksnya sekejap dan tidak mengunci lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->index(['organization_id', 'diterbitkan_pada'], 'sertifikat_org_terbit_idx');
        });

        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->index(['organization_id', 'tanggal_kalibrasi'], 'sesi_org_tanggal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->dropIndex('sesi_org_tanggal_idx');
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropIndex('sertifikat_org_terbit_idx');
        });
    }
};
