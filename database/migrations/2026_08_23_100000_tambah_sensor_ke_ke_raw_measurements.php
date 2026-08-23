<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sumbu SENSOR di `raw_measurements` — buat kalibrasi ENCLOSURE, satu-satunya
 * jenis alat yang tiap titiknya GRID, bukan satu deret pembacaan.
 *
 * ## Kenapa perlu kolom baru
 *
 * Sepuluh alat lain single-channel: `titik_ke` = titik ukur, `pembacaan_ke` =
 * pengulangan 1..n. Enclosure menaruh 9 termokopel di dalam chamber, tiap
 * termokopel dibaca 5×, plus baris Indikator enclosure & Suhu Ruang — tiga
 * "peran" pembacaan yang beda dalam satu set point. `pembacaan_ke` saja nggak
 * cukup misahin "termokopel ke-3 pengulangan ke-2" dari "Indikator pengulangan
 * ke-2".
 *
 *   peran_sensor  sensor_ke   arti
 *   termokopel    1–9         termokopel ke-N di dalam enclosure
 *   indikator     null        pembacaan Indikator enclosure (satu kanal)
 *   suhu_ruang    null        pembacaan suhu ruang (satu kanal)
 *
 * ## Kenapa nullable & aditif
 *
 * Sepuluh profil lain nggak nyentuh kolom ini — sama pola dengan `spindle`/`rpm`
 * (cuma Viscometer) dan `tahap` (cuma alat ber-adjustment). Kolom kosong buat
 * mereka, nggak mengubah apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->unsignedTinyInteger('sensor_ke')->nullable()->after('pembacaan_ke');
            // 'termokopel' (dipasangkan sensor_ke 1–9) | 'indikator' | 'suhu_ruang'.
            $table->string('peran_sensor', 20)->nullable()->after('sensor_ke');
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->dropColumn(['sensor_ke', 'peran_sensor']);
        });
    }
};
