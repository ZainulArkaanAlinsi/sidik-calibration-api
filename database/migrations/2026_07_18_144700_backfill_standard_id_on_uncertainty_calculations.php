<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data, bukan skema — kolom `standard_id` (ditambah di migrasi
 * sebelumnya) kosong buat semua baris yang dibuat sebelum kolom itu ada,
 * walaupun standar yang beneran dipakai masih bisa ditelusuri dari
 * `calibration_sessions.standard_id` sesi asalnya. Tanpa ini, sesi lama
 * nunjukin `titik[].standar_acuan: null` walaupun sebenarnya sesi itu punya
 * standar acuan yang jelas — celah jejak audit yang gampang dihindarin.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Subquery korelasi, bukan JOIN-UPDATE — sintaks JOIN-UPDATE-nya MySQL
        // beda sama SQLite (dipakai test, lihat phpunit.xml), subquery
        // korelasi begini jalan di dua-duanya.
        DB::statement(<<<'SQL'
            UPDATE uncertainty_calculations
            SET standard_id = (
                SELECT standard_id FROM calibration_sessions
                WHERE calibration_sessions.id = uncertainty_calculations.calibration_session_id
            )
            WHERE standard_id IS NULL
        SQL);
    }

    /**
     * Nggak dibalikin — begitu ke-backfill, nggak ada cara mbedain "diisi
     * dari sesi asalnya lewat migrasi ini" sama "emang dari awal dikirim
     * per titik", jadi rollback bakal ngilangin data yang bener juga.
     */
    public function down(): void {}
};
