<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folder arsip — folder BENERAN yang bisa dibikin, dinamain, dan dipindah
 * sendiri sama user, sedalam apa pun.
 *
 * Beda dari Arsip lama yang cuma nurunin hierarki dari relasi
 * (Customer → Equipment → Sesi) dan mentok 3 level. Yang itu tetap ada dan
 * nggak diubah — ini nambah lapisan susunan bebas di atasnya.
 *
 * ## Kenapa `customer_id` ikut di TIAP folder, bukan cuma di root
 *
 * Biar berkas nggak mungkin nyasar ke perusahaan lain. Kalau kepemilikan cuma
 * nempel di root, buat tahu satu subfolder punya siapa harus manjat parent
 * satu-satu — dan tiap pengecekan izin jadi query berantai yang gampang
 * kelewat. Dengan kolom ini, "folder ini punya PT siapa" kejawab dalam satu
 * baris, dan pindah folder tinggal dicek `customer_id`-nya sama atau nggak.
 *
 * Konsekuensinya: folder NGGAK BISA dipindah lintas perusahaan. Itu memang
 * disengaja — sertifikat lab terakreditasi kekunci ke pemiliknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Null = folder akar perusahaan. Restrict, bukan cascade: folder
            // berisi nggak boleh ikut kehapus diam-diam — controller yang wajib
            // nolak duluan dengan pesan yang kebaca user.
            $table->foreignId('parent_id')->nullable()->constrained('folders')->restrictOnDelete();

            $table->string('nama');

            // Folder akar dibikin sistem, bukan user. Nggak bisa dihapus,
            // dinamain ulang, atau dipindah — dia titik jatuh otomatis buat
            // sesi baru, jadi kalau ilang, berkas baru nggak punya tempat.
            $table->boolean('is_root')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Nama unik per induk, bukan global — "2026" boleh ada di tiap
            // perusahaan. `customer_id` ikut dikunci supaya dua root perusahaan
            // beda (dua-duanya parent_id null) nggak saling tabrak.
            $table->unique(['customer_id', 'parent_id', 'nama']);
            $table->index(['organization_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
