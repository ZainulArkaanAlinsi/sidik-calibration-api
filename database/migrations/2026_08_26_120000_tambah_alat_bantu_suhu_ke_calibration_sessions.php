<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiga kolom sesi untuk alat ke-18…20 (Thermocouple, Termometer Gelas,
 * Thermohygrometer).
 *
 * ## `alat_bantu` — dan kenapa satu kolom, bukan dua
 *
 * Ketiga master menyebut blok "Alat Bantu" di kop budget-nya, dan isinya
 * sumber suhu yang dipakai sesi itu:
 *
 *   Thermocouple  Dryblock `A` (Isotech Fast Cal Low) atau `B` (Techne Tecal 700xs)
 *   Gelas         Oilbath `satu` atau `dua`
 *   Thermohygro   Climatic chamber — TIDAK dipilih teknisi, lihat di bawah
 *
 * Satu kolom karena satu arti: unit mana yang memanaskan sesi ini. Dua kolom
 * (`dryblock` + `oilbath`) akan membuat tiap baris punya satu kolom yang selalu
 * NULL, dan kolom yang selalu null mengundang query yang lupa menyaring alatnya.
 *
 * Yang dipilih menentukan DUA komponen budget — variasi aksial & antar-lubang
 * untuk dryblock, variasi spasial & stabilitas untuk oilbath — dan angkanya
 * berbeda antar unit. Salah pilih tidak memunculkan error, cuma U95 yang
 * diturunkan dari unit yang tidak dipakai.
 *
 * **Thermohygro sengaja tidak memakai kolom ini.** Chamber-nya (Biobase 50–90
 * %RH / GEA 30–49 %RH) diturunkan dari SET POINT, bukan dipilih teknisi: yang
 * menentukan kemampuan fisik chamber-nya, dan satu sesi memakai dua-duanya
 * sekaligus. Menaruhnya di kolom sesi berarti satu nilai untuk dua chamber —
 * dan yang kalah adalah lima titik kelembapan yang budget-nya jadi salah.
 *
 * ## `tipe_pencelupan` — tercetak di sertifikat, bukan catatan
 *
 * `SERTIFIKAT!E19 = 'Tipe Thermometer Glass :'` dengan isi Partial / Total /
 * Complete Immersion. Dia menentukan berapa dalam termometer dicelup, jadi
 * sertifikat yang menyebut tipe salah menggambarkan kondisi ukur yang tidak
 * pernah terjadi.
 *
 * ## `titik_es` — komponen budget, bukan pra-syarat
 *
 * `PERHITUNGAN U95%!N29 = 'PERHITUNGAN FC'!Q46/2`, dan `Q46 = Tmax − Tmin` dari
 * tiga pembacaan titik es 30 menit. Disimpan JSON karena yang dibutuhkan
 * ketiganya, bukan ringkasannya: rentang dihitung ulang tiap sesi dibuka lagi,
 * dan menyimpan hasil jadi berarti sesi lama tidak ikut berubah waktu rumusnya
 * dibetulkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('calibration_sessions', 'alat_bantu')) {
                $table->string('alat_bantu', 20)->nullable()->after('tipe_sensor');
            }

            if (! Schema::hasColumn('calibration_sessions', 'tipe_pencelupan')) {
                $table->string('tipe_pencelupan', 30)->nullable()->after('alat_bantu');
            }

            if (! Schema::hasColumn('calibration_sessions', 'titik_es')) {
                $table->json('titik_es')->nullable()->after('tipe_pencelupan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calibration_sessions', function (Blueprint $table): void {
            foreach (['alat_bantu', 'tipe_pencelupan', 'titik_es'] as $kolom) {
                if (Schema::hasColumn('calibration_sessions', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
