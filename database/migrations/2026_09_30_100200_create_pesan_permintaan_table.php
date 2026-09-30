<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Percakapan per permintaan kalibrasi: pelanggan ↔ admin lab.
 *
 * Satu utas per permintaan (keputusan pemilik proyek 30 Sep). Pesan TIDAK
 * pernah diedit atau dihapus — ini jejak korespondensi dengan pelanggan, dan
 * "apa yang dijanjikan lab" harus bisa dibaca ulang kalau ada sengketa.
 *
 * `sisi` disimpan, bukan diturunkan dari role pengirim: akun yang kelak
 * dianonimkan atau berganti role tidak boleh membalik arah pesan lama di
 * layar pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesan_permintaan', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('permintaan_kalibrasi_id')
                ->constrained('permintaan_kalibrasi')
                ->cascadeOnDelete();

            $table->foreignId('pengirim_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('sisi', ['pelanggan', 'lab']);
            $table->text('isi');

            $table->timestamps();

            $table->index(['permintaan_kalibrasi_id', 'id'], 'pesan_permintaan_utas_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesan_permintaan');
    }
};
