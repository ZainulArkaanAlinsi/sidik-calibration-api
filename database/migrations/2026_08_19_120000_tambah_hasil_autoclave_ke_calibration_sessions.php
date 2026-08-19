<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot hasil olah data Autoklaf, disimpan utuh sebagai JSON di sesinya.
 *
 * Kenapa satu kolom JSON, bukan lewat `raw_measurements` + `uncertainty_calculations`
 * kayak tujuh alat lain: bentuk data Autoklaf (3 disk suhu × titik waktu +
 * Indikator + Suhu Ruang + 1 titik tekanan berkonversi satuan, plus metrik
 * Kestabilan/Keseragaman/Variasi) nggak muat di model "titik ukur + pengulangan".
 * Memaksanya masuk ke situ bakal ngebuang data (disk, titik waktu, SS/KS/VK) atau
 * ngisi kolom yang artinya beda. Jadi hasilnya — persis keluaran
 * `AutoclaveCalculator` (input mentah + Section A/B/C + budget) — dibekukan di
 * sini, dan `CertificateSnapshotBuilder` bacanya dari sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->json('hasil_autoclave')->nullable()->after('spesifikasi_alat');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            $table->dropColumn('hasil_autoclave');
        });
    }
};
