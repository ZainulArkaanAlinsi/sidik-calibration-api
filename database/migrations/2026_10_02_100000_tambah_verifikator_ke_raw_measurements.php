<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa & kapan pembacaan hasil pindai dikonfirmasi manusia.
 *
 * Temuan B03 (paket 30 Sep). `raw_measurements.is_verified` cuma boolean, dan
 * `POST /calibrations/{id}/measurements/verify` menyetelnya massal tanpa jejak
 * pelaku. Aturan "angka hasil kamera wajib dikonfirmasi manusia" jadi tidak
 * bisa dibuktikan ke asesor. Kolom ini juga dibaca `PemisahanWewenang`: yang
 * mengonfirmasi angka sesi tidak boleh menyetujuinya sendiri (K-30-03).
 *
 * Additive. Baris lama tetap NULL — konfirmasi sebelum kolom ini ada memang
 * tidak tercatat pelakunya, dan menebaknya sekarang lebih buruk daripada
 * mengakuinya kosong.
 *
 * FK tanpa `nullOnDelete`, sama dengan `calibration_sessions.teknisi_id`: akun
 * yang pernah mengonfirmasi angka sertifikat tidak boleh hilang dari jejaknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->foreignId('verified_by')->nullable()->after('is_verified')->constrained('users');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
