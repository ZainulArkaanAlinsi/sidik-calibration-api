<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak tiap kali AI Vision baca lembar kerja dari foto (nggantiin OCR).
 *
 * Tujuannya dua: (1) audit — bisa dibuktiin dari mana angka di sertifikat
 * berasal; (2) bahan tuning — dengan nyimpen respons mentah model + koreksi
 * teknisi (selisih antara tebakan AI vs angka yang akhirnya dikonfirmasi),
 * prompt & contoh few-shot bisa diperbaiki dari data lapangan asli.
 *
 * Ekstraksi jalan SEBELUM raw_measurements dibikin (teknisi konfirmasi dulu),
 * jadi `raw_measurement_id` sengaja nullable dan diisi belakangan kalau perlu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_extraction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_session_id')->nullable()
                ->constrained()->nullOnDelete();
            // Siapa yang motret — buat batasin akses & audit per teknisi.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('model');
            $table->string('status')->default('sukses'); // sukses | gagal | ditolak
            // Respons mentah dari Claude (isi teks / error) — apa adanya.
            $table->json('raw_model_response')->nullable();
            // Hasil parse jadi struktur yang dikirim ke mobile.
            $table->json('extracted')->nullable();
            // Diisi belakangan: selisih tebakan AI vs angka final teknisi.
            $table->json('technician_corrections')->nullable();
            // Pemakaian token buat pantau biaya.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error')->nullable();

            $table->timestamps();

            // Nama index WAJIB ditulis eksplisit. Kalau dibiarin auto-generate,
            // Laravel bikin `worksheet_extraction_logs_calibration_session_id_
            // created_at_index` = 65 karakter, dan MySQL nolak di 64 (error 1059).
            // Efeknya jahat: CREATE TABLE-nya sukses, ADD INDEX-nya gagal, jadi
            // tabelnya ketinggalan tapi migrasinya nggak kecatat — `migrate`
            // berikutnya mentok "table already exists" terus.
            //
            // Ini nggak ketangkep test karena phpunit.xml jalan di SQLite
            // in-memory, yang nggak punya batas panjang identifier. Yang kena
            // cuma MySQL — alias dev & produksi.
            $table->index(['calibration_session_id', 'created_at'], 'wel_sesi_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_extraction_logs');
    }
};
