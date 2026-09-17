<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode undangan buat pelanggan lama (REQ-AUTH-06, 03-SDD §4.2).
 *
 * Yang disimpan `kode_hash`, BUKAN kodenya. Kode undangan itu kredensial: siapa
 * pun yang memegangnya jadi anggota perusahaan itu. Menyimpannya polos berarti
 * satu kebocoran tabel ini = akses ke data setiap pelanggan yang undangannya
 * belum kepakai.
 *
 * `email` ikut disimpan dan diikat waktu ditukar: kode yang benar tapi dipakai
 * email lain tetap ditolak. Tanpa itu, kode yang ter-forward ke orang lain
 * memberi akses ke perusahaan yang salah — dan dari sisi sistem kelihatan sah.
 *
 * Sekali pakai (`dipakai_pada`), 7 hari (`kedaluwarsa_pada`), bisa dibatalkan
 * (`dibatalkan_pada`). Tiga kolom terpisah, bukan satu kolom status, karena
 * ketiganya menjawab pertanyaan berbeda waktu ditelusuri: kapan dipakai, kapan
 * hangus sendiri, dan kapan dicabut orang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('undangan_pelanggan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('kode_hash');
            $table->enum('peran', ['pic_utama', 'staf'])->default('staf');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('kedaluwarsa_pada');
            $table->timestamp('dipakai_pada')->nullable();
            $table->foreignId('dipakai_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undangan_pelanggan');
    }
};
