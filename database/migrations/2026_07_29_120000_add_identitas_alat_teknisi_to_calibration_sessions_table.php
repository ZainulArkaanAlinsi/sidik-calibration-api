<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas alat & pemilik SEPERTI YANG DICATAT TEKNISI di lembar kerja.
 *
 * Lima kolom ini sengaja NGGAK numpang ke `equipments` / `customers`. Master
 * data itu punya admin, diisi waktu alat didaftarkan — sering dari email
 * pelanggan, dan sering beda sama unit fisik yang beneran datang (tipe sama
 * seri beda, merk kekosongan, PT-nya udah pindah alamat).
 *
 * Yang sah di dokumen kalibrasi adalah yang DIBACA teknisi dari badan alatnya.
 * Kalau lembar kerja cuma nyalin master, salah ketik di master kebawa sampai
 * sertifikat dan nggak ada yang bisa mbetulin dari lapangan. Sebaliknya, kalau
 * isian teknisi dipakai nimpa master, satu kunjungan bisa ngerusak data alat
 * yang dipakai sesi-sesi lain.
 *
 * Jadi: dua-duanya disimpan, per sesi. Snapshot sertifikat ngutamain isian
 * teknisi dan jatuh ke master cuma kalau kosong (lihat
 * `CertificateSnapshotBuilder`).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KOLOM = [
        'alat_model',
        'alat_serial_number',
        'alat_merk',
        'pemilik_nama',
        'pemilik_alamat',
    ];

    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            foreach (self::KOLOM as $kolom) {
                if (Schema::hasColumn('calibration_sessions', $kolom)) {
                    continue;
                }

                // `alamat` bisa panjang (nama gedung + blok + kota); sisanya
                // pendek. Semuanya nullable: lembar kerja boleh dikirim belum
                // lengkap — penjagaannya di penerbitan sertifikat.
                $kolom === 'pemilik_alamat'
                    ? $table->text($kolom)->nullable()
                    : $table->string($kolom)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $ada = array_filter(
                self::KOLOM,
                fn (string $k): bool => Schema::hasColumn('calibration_sessions', $k),
            );

            if ($ada !== []) {
                $table->dropColumn(array_values($ada));
            }
        });
    }
};
