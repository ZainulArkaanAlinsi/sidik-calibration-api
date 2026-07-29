<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom yang ada di Lembar Kerja pH Meter (SIDIK-FM-CAL-0509_Rev.4) tapi belum
 * ada tempatnya di database.
 *
 * "Env. Condition" di lembar kerja dicatat DUA KALI — di awal kerja (First) dan
 * di akhir (End) — bukan sekali. Itu bukan pengulangan yang mubazir: kalau suhu
 * ruang bergeser jauh selama kalibrasi, hasilnya patut dipertanyakan, dan
 * satu-satunya cara tau ya kalau dua-duanya dicatat.
 *
 * `suhu_ruang` & `kelembaban` yang lama tetap ada sebagai nilai yang DICETAK di
 * sertifikat (satu angka). Kalau teknisi cuma ngisi awal & akhir, angka cetaknya
 * diambil dari rata-ratanya — lihat CalibrationController::atributDariRequest().
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga PER KOLOM, bukan sekali buat seluruh migrasi. Di database yang
        // dipakai bareng, sebagian kolom ini bisa udah kepasang duluan —
        // dan kalau penjaganya dipasang di luar (`return` kalau salah satu
        // ada), kolom yang BELUM ada ikut nggak kebikin, lalu error-nya pindah
        // ke tempat lain yang jauh lebih susah dilacak.
        Schema::table('calibration_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('calibration_sessions', 'suhu_awal')) {
                $table->decimal('suhu_awal', 8, 2)->nullable()->after('suhu_ketidakpastian');
            }
            if (! Schema::hasColumn('calibration_sessions', 'suhu_akhir')) {
                $table->decimal('suhu_akhir', 8, 2)->nullable()->after('suhu_awal');
            }
            if (! Schema::hasColumn('calibration_sessions', 'kelembaban_awal')) {
                $table->decimal('kelembaban_awal', 8, 2)->nullable()->after('kelembaban_ketidakpastian');
            }
            if (! Schema::hasColumn('calibration_sessions', 'kelembaban_akhir')) {
                $table->decimal('kelembaban_akhir', 8, 2)->nullable()->after('kelembaban_awal');
            }

            // Kolom "Catatan:" di lembar kerja — kondisi lapangan yang nggak
            // muat di kolom lain (mis. "elektroda kotor, dibersihkan dulu").
            // Beda dari `catatan_revisi` yang isinya teguran admin ke teknisi.
            if (! Schema::hasColumn('calibration_sessions', 'catatan_teknisi')) {
                $table->text('catatan_teknisi')->nullable()->after('catatan_revisi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir', 'catatan_teknisi',
            ]);
        });
    }
};
