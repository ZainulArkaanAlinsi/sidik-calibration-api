<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu kali lembar kerja dipindai pakai OCR TEMPLATE LOKAL.
 *
 * Kepisah dari `worksheet_extraction_logs` (jalur AI Vision) dengan sengaja.
 * Dua-duanya nyatet "foto dibaca", tapi yang dipertanggungjawabkan beda: yang
 * lama nyimpen respons model & pemakaian token, yang ini nyimpen GEOMETRI,
 * MUTU FOTO, dan vonis per sel. Dijadikan satu tabel berarti separuh kolomnya
 * selalu null dan nggak ada yang berani ngapus kolom mana pun.
 *
 * Nggak ada foto atau angka pelanggan yang keluar dari infrastruktur lab di
 * jalur ini: OCR-nya jalan di HP, server cuma nerima teks + koordinat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Sesi boleh belum ada: teknisi kadang mindai duluan, sesinya dibikin
            // waktu nekan simpan.
            $table->foreignId('calibration_session_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Template & versi FORMULIR CETAK yang difoto — bukan versi kode.
            $table->string('template_id', 60);
            $table->unsignedSmallInteger('template_versi');

            // Tiga versi yang harus bisa dijawab waktu akurasi turun: cara
            // bacanya, ambang aturannya, dan koordinat lembarnya.
            $table->string('pipeline_versi', 30);
            $table->string('aturan_versi', 30);

            // ok | perlu_review | ditolak_kualitas | template_tidak_dikenali |
            // geometri_meragukan | mapping_gagal
            $table->string('status', 30);
            $table->string('alasan_gagal', 40)->nullable();
            $table->string('pesan', 255)->nullable();

            $table->unsignedSmallInteger('total_sel')->default(0);
            $table->unsignedSmallInteger('sel_hijau')->default(0);
            $table->unsignedSmallInteger('sel_kuning')->default(0);
            $table->unsignedSmallInteger('sel_merah')->default(0);
            $table->unsignedSmallInteger('sel_kosong')->default(0);

            // Metrik mentah dari HP. Disimpen apa adanya walau scan-nya ditolak —
            // justru yang ditolak yang paling berguna buat nyetel ambang.
            $table->json('kualitas')->nullable();
            $table->json('geometri')->nullable();
            $table->json('perangkat')->nullable();

            // Kiriman mentah HP & hasil olahan server. Dua-duanya disimpen: tanpa
            // yang mentah, hasil yang aneh nggak bisa ditelusuri ke bacaan
            // aslinya; tanpa yang olahan, layar review harus ngitung ulang.
            $table->json('payload')->nullable();
            $table->json('hasil')->nullable();

            $table->string('citra_path')->nullable();
            $table->string('citra_warp_path')->nullable();

            // Waktu FOTO diambil menurut HP — beda dari `created_at` (waktu
            // server nerima). Bedanya nyata kalau teknisi mindai di lokasi tanpa
            // sinyal lalu ngirim sesampainya di lab.
            $table->timestamp('diambil_pada')->nullable();

            $table->timestamps();

            // Nama index ditulis eksplisit — auto-generate gampang lewat 64
            // karakter dan MySQL nolak di situ (error 1059), sementara SQLite
            // yang dipakai test nggak punya batas itu. Pola yang sama kayak
            // `wel_sesi_created_at_idx` di migrasi worksheet_extraction_logs.
            $table->index(['calibration_session_id', 'created_at'], 'ws_sesi_created_idx');
            $table->index(['template_id', 'status'], 'ws_template_status_idx');
            $table->index(['organization_id', 'created_at'], 'ws_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_scans');
    }
};
