<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spindle & kecepatan putar (RPM) yang dipakai di satu titik Viscometer.
 *
 * ## Kenapa di sini, bukan di `calibration_sessions.spesifikasi_alat`
 *
 * Batas keberterimaan Viscometer (MPE) BUKAN satu angka per alat — dia dihitung
 * per titik:
 *
 *   Fullscale = TK x SMC x 10000 / RPM
 *   MPE       = 1% x Fullscale + 1% x rata-rata pembacaan
 *
 * `SMC` datang dari spindle yang dipasang, `RPM` dari kecepatan yang dipilih,
 * dan sesi contoh master memakai TIGA spindle berbeda (HA1/HA2/HA7, SMC
 * 1/4/400) dengan DUA kecepatan (63/62 rpm) dalam satu lembar. Ditaruh di
 * kolom sesi, ketiganya kepaksa jadi satu nilai dan MPE dua titik pasti salah —
 * dan salahnya besar: SMC berkisar 0,327 sampai 1280.
 *
 * `TK` (Torque Constant) beda: dia sifat MODEL alatnya, satu per sesi, jadi
 * tetap tinggal di `calibration_sessions.spesifikasi_alat`.
 *
 * ## Kenapa nullable, dan kenapa di baris pembacaan
 *
 * Enam jenis alat lain nggak punya spindle sama sekali, jadi kolomnya kosong
 * buat mereka dan nggak boleh bikin baris ditolak. Disimpan per baris
 * pembacaan — bukan per titik — ngikut cara `titik_ukur` & `standard_id` yang
 * juga sebenarnya milik titik tapi ditulis di tiap barisnya. Satu pola, bukan
 * dua.
 *
 * `rpm` disimpan sebagai desimal, bukan integer: Brookfield DV2T bisa diputar
 * pada 0,01 rpm buat larutan kental, dan pembulatan ke 0 bikin Fullscale
 * meledak jadi tak hingga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            if (! Schema::hasColumn('raw_measurements', 'spindle')) {
                // Kode spindle apa adanya seperti tercetak di daftar Tabel D-1
                // ("HA7", "CPE-51 or CPA-51Z", "LV4 or 4B2"). Yang terpanjang 17
                // karakter; 32 ngasih ruang kalau lab nambah tipe baru.
                $table->string('spindle', 32)->nullable()->after('satuan');
            }

            if (! Schema::hasColumn('raw_measurements', 'rpm')) {
                $table->decimal('rpm', 8, 2)->nullable()->after('spindle');
            }
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->dropColumn(['spindle', 'rpm']);
        });
    }
};
