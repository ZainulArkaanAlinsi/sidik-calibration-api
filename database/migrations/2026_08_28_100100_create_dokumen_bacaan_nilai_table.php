<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu nilai (field tunggal ATAU sel tabel) pada satu pembacaan,
 * berikut angka yang akhirnya dipakai teknisi.
 *
 * ## `nilai_baca` & `nilai_final` itu TEKS, bukan desimal — ini disengaja
 *
 * `worksheet_scan_cells` meng-cast nilainya ke `float` karena seluruh selnya
 * memang angka: template yang dikenal cuma punya sel pengukuran.
 *
 * Jalur generik membaca apa pun yang ada di kertas — nama alat, nomor seri,
 * tanggal, checkbox, catatan. Dipaksa desimal, `Fluke 123` jadi null dan
 * `2026-08-28` jadi 2026. Yang hilang justru bagian yang bikin form ini ada.
 *
 * Efek keduanya lebih halus tapi sama pentingnya: menyimpan teks apa adanya
 * berarti lapisan ini TIDAK mengambil keputusan presisi. `25,4` tetap `25,4`,
 * bukan dibulatkan ke desimal yang kebetulan dipilih programmer. Keputusan
 * presisi tetap milik jalur `raw_measurements` yang presisinya dicocokkan ke
 * workbook master lab.
 *
 * ## Pasangan (dibaca, dikoreksi) itu isi utamanya
 *
 * `nilai_baca` + `nilai_final` di baris yang sama = catatan koreksinya
 * sekaligus. Nggak perlu tabel koreksi terpisah, dan pasangannya jadi data
 * latih yang paling berharga: sel yang model-nya SALAH itu justru yang paling
 * mahal buat dikumpulkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen_bacaan_nilai', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dokumen_bacaan_id')->constrained('dokumen_bacaan')
                ->cascadeOnDelete();

            // Kunci stabil dari skema (`bagian-0.tabel-0.sel-0-1`). Dari POSISI,
            // bukan label — label di lembar kerja berulang terus (satu lembar
            // Conductivity punya empat kolom "Reading").
            $table->string('kunci', 120);

            // field | sel
            $table->string('jenis', 10);

            $table->string('bagian_nama', 255)->nullable();
            $table->string('label', 255)->nullable();

            // Cuma keisi buat `jenis = sel`.
            $table->unsignedSmallInteger('baris_ke')->nullable();
            $table->unsignedSmallInteger('kolom_ke')->nullable();

            $table->string('tipe', 20)->nullable();
            $table->string('satuan', 40)->nullable();

            // TEKS, bukan desimal — lihat docblock di atas.
            $table->text('nilai_baca')->nullable();
            $table->text('nilai_final')->nullable();

            // static_document | handwriting | unknown
            $table->string('sumber', 20)->default('unknown');

            $table->decimal('keyakinan', 5, 4)->nullable();

            // OK | REVIEW_REQUIRED
            $table->string('status', 20);

            $table->unsignedSmallInteger('halaman')->default(1);
            $table->json('kotak')->nullable();

            // null = belum dikoreksi sama sekali. Dibedain dari `false` (sudah
            // dilihat teknisi, dan ternyata bacaannya salah) — dua-duanya
            // penting dan artinya beda jauh waktu ngukur akurasi.
            $table->boolean('cocok')->nullable();

            $table->foreignId('dikoreksi_oleh')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('dikoreksi_pada')->nullable();

            $table->timestamps();

            // Nama eksplisit — `dokumen_bacaan_nilai` + kolomnya bakal lewat 64
            // karakter kalau dibiarkan auto-generate, dan MySQL nolak di situ
            // (error 1059) sementara SQLite yang dipakai test nggak.
            $table->unique(['dokumen_bacaan_id', 'kunci'], 'dbn_bacaan_kunci_unik');
            $table->index(['dokumen_bacaan_id', 'status'], 'dbn_bacaan_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_bacaan_nilai');
    }
};
