<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar notifikasi BARU `tahap_alat` -- kabar perubahan tahap alat di paket
 * (`KabarTahapAlat`, 13 Nov 2026).
 *
 * Satu-satunya migrasi di pekerjaan pelacakan pelanggan, dan tak terhindarkan:
 * `PreferensiNotifikasi` menyimpan SATU SAKLAR = SATU KOLOM boolean di
 * `preferensi_notifikasi_anggota` (dibaca `penerima()`, ditulis `simpan()`,
 * divalidasi `PreferensiNotifikasiController` dari daftar `BAWAAN`). Kategori
 * baru tanpa kolom berarti `simpan()` gagal dan `penerima()` menghasilkan SQL
 * yang salah. Sumbu lain di repo ini (`peran_sensor`/`sensor_ke`/`tahap`,
 * `spesifikasi_alat`) milik data kalibrasi, bukan preferensi pengguna.
 *
 * ADDITIVE murni: kolom boolean, default MENYALA, baris lama ikut menyala.
 * Aplikasi pelanggan yang sudah terpasang mengabaikan kunci baru di
 * `GET /preferensi-notifikasi`. Di MySQL 8 penambahan kolom ber-default pada
 * tabel sekecil ini selesai seketika (tabelnya satu baris per anggota).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('preferensi_notifikasi_anggota', 'tahap_alat')) {
            return;
        }

        Schema::table('preferensi_notifikasi_anggota', function (Blueprint $table): void {
            $table->boolean('tahap_alat')->default(true)->after('ringkasan_email_mingguan');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('preferensi_notifikasi_anggota', 'tahap_alat')) {
            return;
        }

        Schema::table('preferensi_notifikasi_anggota', function (Blueprint $table): void {
            $table->dropColumn('tahap_alat');
        });
    }
};
