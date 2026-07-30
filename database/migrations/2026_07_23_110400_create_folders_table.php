<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folder Manager (spesifikasi poin 3 & 7) — tempat dokumen kalibrasi disusun
 * rapi, dikelompokkan per PT/klien.
 *
 * Folder `sistem` KEBENTUK SENDIRI waktu sertifikat terbit (PT → tahun), jadi
 * strukturnya tumbuh ngikutin data tanpa ada yang bikin manual. Folder `manual`
 * bebas dibikin admin buat arsip lain. Bedanya penting: folder sistem nggak
 * boleh dihapus/direname sembarangan — file di dalamnya nunjuk ke sertifikat
 * resmi yang masih hidup.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dijaga `hasTable`: tabelnya bisa udah kepasang duluan di database
        // yang dipakai bareng sementara tabel `migrations` nggak nyatetnya.
        // Tanpa penjaga ini `migrate` mati di sini dan SEMUA migrasi sesudahnya
        // ikut nggak kepasang.
        if (Schema::hasTable('folders')) {
            return;
        }
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Hapus folder induk = hapus seluruh isinya. Sengaja cascade: folder
            // yatim di tengah pohon lebih berbahaya daripada hilang sekalian.
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            // Keisi cuma di folder akar milik satu PT — ini yang bikin
            // pengelompokan per klien otomatis & nggak gampang salah ketik.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('nama');
            $table->enum('tipe', ['sistem', 'manual'])->default('manual');
            $table->text('keterangan')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'parent_id']);
            $table->index(['organization_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
