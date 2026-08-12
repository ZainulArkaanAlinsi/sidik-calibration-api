<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standar acuan yang dicentang teknisi disimpan di barisnya sendiri.
 *
 * Sebelum ini pilihan standar per titik cuma nyangkut di
 * `uncertainty_calculations`, dan baris itu CUMA kebentuk buat titik yang
 * berhasil dihitung. Konsekuensinya: draft yang belum kehitung — justru bentuk
 * paling umum dari lembar setengah jadi — nggak nyimpen centang standarnya di
 * mana pun. Teknisi yang mbuka draft-nya lagi dapat centang kosong, ngirim
 * ulang tanpa standar, dan di admin sesinya keblokir `titik_kosong` karena
 * nggak ada satu titik pun yang bisa dihitung tanpa nilai acuan.
 *
 * Nullable, dan sengaja: baris yang teknisi emang belum milih standarnya tetap
 * sah tersimpan. `nullOnDelete` ngikut perlakuan yang sama di tempat lain —
 * standar yang dihapus nggak boleh ngebawa pembacaan mentahnya ikut hilang,
 * karena angka itu rekaman apa yang beneran kebaca di lapangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->foreignId('standard_id')
                ->nullable()
                ->after('titik_ukur')
                ->constrained('standards')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('raw_measurements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('standard_id');
        });
    }
};
