<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori alat bisa DINONAKTIFKAN tanpa dihapus (CRUD kategori, 18 Nov 2026).
 *
 * ## Kenapa kolom baru, padahal `deleted_at` sudah ada
 *
 * Tidak ada sumbu lain yang cocok: `peran_sensor`/`sensor_ke`/`tahap` dan
 * `spesifikasi_alat` bicara soal lembar kerja, bukan status kategori. Yang
 * hampir cocok adalah `deleted_at` (soft delete) -- tetapi "dihapus" dan
 * "nonaktif" menjawab pertanyaan berbeda, dan menyatukannya merusak salah
 * satunya:
 *
 * - kategori yang DIHAPUS lunak hilang dari relasi `Equipment::category()`,
 *   sehingga alat lama kehilangan kategorinya di layar dan di sertifikat;
 * - kategori NONAKTIF harus tetap terbaca oleh alat, sesi, dan CMC lamanya --
 *   dia hanya berhenti ditawarkan di form baru.
 *
 * Nullable tanpa default: baris yang sudah ada otomatis aktif, jadi migrasi
 * ini additive dan aman untuk data produksi yang sedang berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_categories', function (Blueprint $table): void {
            $table->timestamp('nonaktif_pada')->nullable()->after('worksheet_schema');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_categories', function (Blueprint $table): void {
            $table->dropColumn('nonaktif_pada');
        });
    }
};
