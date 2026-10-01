<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resi pengiriman (diisi pelanggan) dan jadwal teknisi (diisi admin) untuk
 * permintaan yang sudah DITERIMA — PL_Daftar_Permintaan "Menunggu alat tiba",
 * "Teknisi dijadwalkan" (§42). Semua nullable; nol kolom lama disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permintaan_kalibrasi', function (Blueprint $table): void {
            $table->string('kurir', 60)->nullable()->after('order_id');
            $table->string('nomor_resi', 80)->nullable()->after('kurir');
            $table->timestamp('resi_diisi_pada')->nullable()->after('nomor_resi');
            $table->dateTime('jadwal_pada')->nullable()->after('resi_diisi_pada');
            $table->string('jadwal_lokasi')->nullable()->after('jadwal_pada');
            $table->text('jadwal_catatan')->nullable()->after('jadwal_lokasi');
            $table->timestamp('alat_tiba_pada')->nullable()->after('jadwal_catatan');
        });
    }

    public function down(): void
    {
        Schema::table('permintaan_kalibrasi', function (Blueprint $table): void {
            $table->dropColumn([
                'kurir', 'nomor_resi', 'resi_diisi_pada', 'jadwal_pada', 'jadwal_lokasi', 'jadwal_catatan', 'alat_tiba_pada',
            ]);
        });
    }
};
