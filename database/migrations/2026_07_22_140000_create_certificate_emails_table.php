<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan pengiriman sertifikat lewat email.
 *
 * Ada tabelnya sendiri, bukan cuma ditulis ke log, karena ini pertanyaan yang
 * ditanya asesor: sertifikat nomor sekian dikirim ke siapa, oleh siapa, kapan.
 * Log aplikasi kepangkas/kehapus berkala; jejak audit nggak boleh ikut hilang.
 *
 * `dikirim_oleh` pakai nullOnDelete — akun yang dihapus nggak boleh ngilangin
 * catatan bahwa pengiriman itu pernah terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dikirim_oleh')->nullable()->constrained('users')->nullOnDelete();

            // Disimpen apa adanya waktu dikirim — kalau alamat pelanggan berubah
            // belakangan, catatan ini harus tetap nunjukin ke mana dulu dikirim.
            $table->json('penerima');
            $table->json('cc')->nullable();

            $table->timestamp('dikirim_pada');
            $table->timestamps();

            $table->index('certificate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_emails');
    }
};
