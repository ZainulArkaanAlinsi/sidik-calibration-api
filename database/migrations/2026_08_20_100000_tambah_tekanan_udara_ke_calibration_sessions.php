<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tekanan udara ruangan sebagai parameter kondisi lingkungan KETIGA, sejajar
 * suhu & kelembaban.
 *
 * Sembilan alat sebelumnya cuma mencatat dua parameter, dan itu cukup karena
 * pembacaannya nggak dipengaruhi tekanan barometrik. Gas Detector beda:
 * sensor elektrokimianya membaca TEKANAN PARSIAL gas, jadi konsentrasi yang
 * ditampilkan bergerak mengikuti tekanan ruangan. Master
 * `Gas Detector Uli Skin (std Rigaz).xlsm` menaruhnya sebagai baris ketiga di
 * blok "PERHITUNGAN KONDISI LINGKUNGAN" (`PERHITUNGAN!B21`) DAN sebagai
 * komponen budget sendiri di kelima blok gas (`Ketidakpastian Pengaruh
 * Tekanan`, ci = 0,98 % × titik).
 *
 * Satuannya **hPa** — itu yang tercetak di lembar kerja dan di sertifikat
 * kalibrasi thermobarometer Lutron-nya. Budget mengonversi ke kPa (÷10) di
 * dalam `GasDetectorProfile::komponenBudget()`, bukan di sini: yang disimpan
 * angka yang ditulis teknisi, sama seperti `suhu_awal`/`kelembaban_awal`.
 *
 * Nullable dan tetap nullable: sembilan alat lain nggak mencetak kolom ini,
 * dan yang nggak mencetak nggak boleh dipaksa ngisi. `KondisiLingkungan`
 * mengembalikan null buat sesi tanpa pembacaan tekanan, persis seperti
 * perlakuannya ke suhu/kelembaban yang kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga per kolom, sama alasannya kayak migrasi 2026_07_23_120000: di
        // database yang dipakai bareng, satu kolom bisa udah kepasang duluan.
        Schema::table('calibration_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('calibration_sessions', 'tekanan_awal')) {
                $table->decimal('tekanan_awal', 8, 2)->nullable()->after('kelembaban_akhir');
            }
            if (! Schema::hasColumn('calibration_sessions', 'tekanan_akhir')) {
                $table->decimal('tekanan_akhir', 8, 2)->nullable()->after('tekanan_awal');
            }
            // Nilai terkoreksi + U95%-nya, sejajar `suhu_ruang`/`kelembaban`.
            // Diisi `KondisiLingkungan::terapkan()`, bukan diketik teknisi.
            if (! Schema::hasColumn('calibration_sessions', 'tekanan_udara')) {
                $table->decimal('tekanan_udara', 8, 2)->nullable()->after('tekanan_akhir');
            }
            if (! Schema::hasColumn('calibration_sessions', 'tekanan_ketidakpastian')) {
                $table->decimal('tekanan_ketidakpastian', 8, 4)->nullable()->after('tekanan_udara');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'tekanan_awal', 'tekanan_akhir', 'tekanan_udara', 'tekanan_ketidakpastian',
            ]);
        });
    }
};
