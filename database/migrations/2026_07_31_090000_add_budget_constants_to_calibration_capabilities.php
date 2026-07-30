<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konstanta budget ketidakpastian per baris kemampuan kalibrasi.
 *
 * Empat angka ini yang bikin `GumCalculator` bisa nyusun budget 5 komponen
 * seperti lembar olah data lab, bukan cuma ngelaporin CMC + pengulangan:
 *
 * - `u_temperature`     UTemperature (°C) = √((U_thermo/2)² + (U_sensor/2)²),
 *                       diturunin dari sertifikat thermometer & sensor standar.
 * - `ci_suhu`           Koefisien sensitivitas suhu buat titik ini — seberapa
 *                       besar 1 °C mengubah nilai besaran yang diukur. Beda per
 *                       titik: pH 4 jauh lebih nggak sensitif daripada pH 10.
 * - `u_perbedaan_suhu`  Pengaruh perbedaan suhu antara larutan & alat ukurnya.
 * - `ci_perbedaan_suhu` Koefisien sensitivitasnya.
 *
 * Semuanya NULLABLE dan itu disengaja. Kemampuan yang belum keisi konstantanya
 * tetap jalan lewat jalur lama (CMC + Type A) — lihat
 * `CalibrationCapability::punyaBudgetPenuh()`. Jadi kategori alat yang budget
 * lengkapnya belum pernah diturunkan nggak ikut berubah perilakunya, dan
 * pengisiannya bisa bertahap per kategori.
 *
 * Angkanya keputusan METROLOGI, bukan teknis: dia datang dari sertifikat
 * kalibrasi alat standar lab dan dari analisis sensitivitas per titik ukur.
 * Jangan diisi dengan nebak — kosong lebih jujur daripada salah.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KOLOM = ['u_temperature', 'ci_suhu', 'u_perbedaan_suhu', 'ci_perbedaan_suhu'];

    public function up(): void
    {
        if (! Schema::hasTable('calibration_capabilities')) {
            return;
        }

        Schema::table('calibration_capabilities', function (Blueprint $table) {
            // Dijaga per kolom, bukan per migrasi: tabel yang keskip penjaga
            // `hasTable` di masa lalu bisa punya sebagian kolom aja, dan kalau
            // seluruh blok diloncati yang belum ada nggak akan pernah kebikin.
            // Pelajaran ini mahal — lihat migrasi folders 29 & 30 Juli.
            $sesudah = 'faktor_cakupan';

            foreach (self::KOLOM as $kolom) {
                if (! Schema::hasColumn('calibration_capabilities', $kolom)) {
                    $table->decimal($kolom, 20, 8)->nullable()->after($sesudah);
                }

                $sesudah = $kolom;
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('calibration_capabilities')) {
            return;
        }

        $ada = array_values(array_filter(
            self::KOLOM,
            fn (string $k): bool => Schema::hasColumn('calibration_capabilities', $k),
        ));

        if ($ada === []) {
            return;
        }

        Schema::table('calibration_capabilities', function (Blueprint $table) use ($ada) {
            $table->dropColumn($ada);
        });
    }
};
