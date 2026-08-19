<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rentang ukur, kapasitas maksimum, dan resolusi alat SEPERTI YANG DIBACA
 * TEKNISI dari badan alatnya.
 *
 * Selama ini tiga baris itu ditarik otomatis dari master `equipments`
 * (`equipment.range_resolusi`, sumber `otomatis`, read-only di layar). Alasannya
 * sama persis kayak `alat_model`/`alat_merk` di migrasi 2026_07_29_120000, dan
 * kesimpulannya juga sama: master itu punya admin, diisi waktu alat
 * didaftarkan, dan sering nggak cocok sama unit fisik yang datang.
 *
 * Buat Spectrophotometer bedanya kelihatan langsung: satu alat punya DUA skala
 * (`0–100 %T` dan `200–700 nm`, resolusi `0,001 %T` dan `0,01 nm`), sementara
 * `equipments` cuma punya satu `satuan` + satu pasang `range_min`/`range_max`.
 * Apa pun yang dipilih otomatis dari situ pasti salah separuh.
 *
 * Bentuknya JSON, bukan kolom per baris: yang ada di lembar kerja beda-beda per
 * alat (pH cuma satu baris, spektro tiga baris dua satuan), dan menambah kolom
 * tiap kali ada bentuk baru bikin tabel ini numpuk kolom yang cuma kepakai satu
 * profil. Kuncinya ditentuin bentuk lembar kerja dari backend, jadi HP nggak
 * pernah ngarang kunci sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('calibration_sessions', 'spesifikasi_alat')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->json('spesifikasi_alat')->nullable()->after('alat_merk');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('calibration_sessions', 'spesifikasi_alat')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn('spesifikasi_alat');
        });
    }
};
