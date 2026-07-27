<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan pengiriman sertifikat lewat email (fase-2 §3d).
 *
 * Tabelnya ADA karena permintaannya eksplisit: *"pengirimannya perlu tercatat buat
 * audit — siapa ngirim sertifikat ke siapa, kapan."* Itu bukan tambahan, itu separuh
 * alasan endpoint-nya ditaruh di backend dan bukan di mobile.
 *
 * Tabel terpisah, BUKAN kolom `dikirim_pada` di `certificates`: kirim ulang itu
 * kejadian normal (pelanggan kehilangan filenya, ganti PIC, alamatnya salah ketik).
 * Kolom tunggal cuma nyimpen yang terakhir, dan "kapan kita ngirim ke PIC yang lama"
 * jadi nggak bisa dijawab lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // `cascadeOnDelete` di sini aman & bener: kalau sertifikatnya kehapus,
            // catatan pengirimannya nggak punya arti lagi. Beda dari hasil hitung
            // yang harus tetap ada walau versi rumusnya kehapus.
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();

            // Penerimanya disimpen APA ADANYA (JSON array), bukan di-relasi ke
            // `customers`: yang dikirimin bisa PIC yang nggak kedaftar sebagai
            // pelanggan, dan yang harus bisa dibuktikan itu alamat yang BENERAN
            // dipakai waktu itu — bukan alamat pelanggan yang mungkin udah diubah
            // sesudahnya.
            $table->json('ke');
            $table->json('cc')->nullable();

            $table->string('status', 16);
            // Pesan error dipotong di aplikasi; kolomnya dilonggarin karena pesan
            // SMTP bisa panjang dan yang bikin bisa didiagnosa justru ekornya.
            $table->text('error')->nullable();

            $table->foreignId('dikirim_oleh')->nullable()->constrained('users')->nullOnDelete();

            // `created_at` doang — catatan pengiriman nggak pernah diubah.
            $table->timestamp('created_at')->useCurrent();

            // Nama index dieksplisitkan & pendek: MySQL nolak >64 karakter, dan
            // `certificate_email_logs_certificate_id_created_at_index` itu 54 —
            // masih lolos, tapi nama otomatis di tabel bernama panjang kayak ini
            // gampang kelewat begitu kolomnya nambah.
            $table->index(['certificate_id', 'created_at'], 'cemail_sertifikat_idx');
            $table->index(['organization_id', 'created_at'], 'cemail_org_waktu_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_email_logs');
    }
};
