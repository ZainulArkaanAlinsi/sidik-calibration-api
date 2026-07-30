<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buang kolom teks `thermohygro` peninggalan skema lama.
 *
 * Kolom ini NGGAK PERNAH ADA di migrasi mana pun — dia cuma nyangkut di
 * database dev yang umurnya lebih tua dari relasi `thermohygro()`. Efeknya
 * fatal dan sunyi: di Eloquent atribut menang atas relasi kalau namanya sama,
 * jadi `$sesi->thermohygro` balikin STRING `"TH-3"`, bukan model `Standard`.
 * `GET /calibrations` mati 500 di `CalibrationResource` ("Attempt to read
 * property id on string") dan `PerhitunganBuilder` ikut ambruk — sementara
 * seluruh test hijau, karena database test dibangun dari migrasi yang memang
 * nggak punya kolom itu.
 *
 * Makanya penjagaannya `hasColumn`: di CI dan database bersih migrasi ini
 * nggak ngapa-ngapain, di database dev yang kena baru dia bekerja.
 *
 * Nilai lamanya nggak dibuang gitu aja — dicocokkan dulu ke `standards.nama`
 * (isinya persis kode unit lab, `TH-3`) dan disimpan ke
 * `thermohygro_standard_id`, kolom yang sekarang jadi sumber kebenarannya.
 * Yang nggak ketemu pasangannya ditinggal null: lebih baik kosong daripada
 * nunjuk standar yang salah di dokumen kalibrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('calibration_sessions', 'thermohygro')) {
            return;
        }

        $tertinggal = DB::table('calibration_sessions')
            ->select('id', 'thermohygro')
            ->whereNull('thermohygro_standard_id')
            ->whereNotNull('thermohygro')
            ->where('thermohygro', '<>', '')
            ->get();

        foreach ($tertinggal as $sesi) {
            $standardId = DB::table('standards')
                ->where('nama', trim((string) $sesi->thermohygro))
                ->value('id');

            if ($standardId !== null) {
                DB::table('calibration_sessions')
                    ->where('id', $sesi->id)
                    ->update(['thermohygro_standard_id' => $standardId]);
            }
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->dropColumn('thermohygro');
        });
    }

    /**
     * Kolomnya balik, isinya nggak — teks aslinya udah nggak ada di mana pun
     * sesudah `up()`. Sengaja: bikin kolom ini terisi lagi cuma bakal
     * ngidupin bug bayangan yang sama.
     */
    public function down(): void
    {
        if (Schema::hasColumn('calibration_sessions', 'thermohygro')) {
            return;
        }

        Schema::table('calibration_sessions', function (Blueprint $table) {
            $table->string('thermohygro')->nullable();
        });
    }
};
