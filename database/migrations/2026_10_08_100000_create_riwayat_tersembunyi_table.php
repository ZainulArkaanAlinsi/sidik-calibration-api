<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi yang disembunyikan satu akun dari layar Riwayat-nya sendiri
 * (keputusan pemilik proyek, 8 Okt 2026 — `docs/permintaan-user-7.md` §47).
 *
 * Ini PREFERENSI TAMPILAN, bukan data lab. Satu baris = "akun ini tidak mau
 * melihat sesi itu di daftarnya". Sesi, pembacaan, sertifikat, dan jejak audit
 * tidak disentuh sama sekali, dan akun lain tetap melihat sesinya. Karena itu
 * tabelnya terpisah — bukan kolom di `calibration_sessions`: kolom di sana
 * berarti satu nilai untuk SEMUA orang, dan mengubahnya ikut tercatat
 * `Diaudit` sebagai perubahan data sesi.
 *
 * Tanpa `organization_id`: barisnya diraih lewat user & sesi, dan
 * penyembunyiannya hanya bisa dibuat lewat endpoint yang lebih dulu memeriksa
 * sesi itu seorganisasi dengan pemanggil.
 *
 * Additive saja — tidak ada tabel lama yang berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_tersembunyi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('calibration_session_id')->constrained('calibration_sessions')->cascadeOnDelete();
            $table->timestamps();

            // Menyembunyikan dua kali tidak boleh melahirkan dua baris; endpoint
            // POST-nya idempoten bersandar pada indeks ini juga.
            $table->unique(['user_id', 'calibration_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_tersembunyi');
    }
};
