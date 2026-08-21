<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode kalibrasi & tipe sensor — dua hal yang menentukan ANGKA di sesi TITS
 * (Temperature Indikator Tanpa Sensor, alat ke-11).
 *
 * ## Kenapa di sesi, bukan di `equipments`
 *
 * Satu indikator suhu bisa dikalibrasi DUA KALI dengan dua mode: `measure` (UUT
 * membaca sinyal yang di-source kalibrator) dan `source` (UUT men-source,
 * kalibrator yang membaca). Lab menerbitkan dua sertifikat dengan dua nomor,
 * dan arah perhitungan koreksinya BERBALIK antara keduanya — di mode measure
 * `Standard = setpoint + koreksi kalibrator`, di mode source
 * `Standard = rata-rata bacaan + koreksi kalibrator` dengan setpoint justru
 * berperan sebagai UUT. Ketuker, seluruh kolom `Correction` di sertifikat
 * terbalik tandanya tanpa satu pun angka kelihatan janggal.
 *
 * Karena itu mode milik SESI. Ditaruh di master alat, sesi kedua akan menimpa
 * mode sesi pertama dan sertifikat yang sudah terbit kehilangan konteksnya.
 *
 * `tipe_sensor` (Type K/J/N/B/T/R/S atau RTD) ikut di sini karena alasan yang
 * sama: alat yang sama bisa datang lagi dengan tipe termokopel berbeda, dan
 * tiap tipe punya tabel koreksi kalibrator, drift, dan CMC sendiri.
 *
 * ## Kenapa kolom, bukan kunci di `spesifikasi_alat`
 *
 * `spesifikasi_alat` isinya angka yang DIBACA dari badan alat (rentang,
 * kapasitas, resolusi). Dua kolom ini bukan itu — dua-duanya keputusan cara
 * kerja yang harus bisa dicari, divalidasi, dan dilaporkan per sesi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('calibration_sessions', 'mode_kalibrasi')) {
                $table->string('mode_kalibrasi', 20)->nullable()->after('spesifikasi_alat');
            }

            if (! Schema::hasColumn('calibration_sessions', 'tipe_sensor')) {
                $table->string('tipe_sensor', 20)->nullable()->after('mode_kalibrasi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->dropColumn(['mode_kalibrasi', 'tipe_sensor']);
        });
    }
};
