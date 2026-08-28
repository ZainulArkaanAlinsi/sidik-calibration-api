<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu kali lembar kerja dibaca lewat JALUR GENERIK.
 *
 * ## Kenapa tabel sendiri, bukan numpang `worksheet_scans`
 *
 * Dua-duanya nyatet "foto dibaca", tapi yang dipertanggungjawabkan beda.
 * `worksheet_scans` nyimpen geometri, mutu foto, dan vonis per sel dari lembar
 * yang templatenya SUDAH DIKENAL — kolomnya `template_id`, `template_versi`,
 * `aturan_versi`. Jalur generik nggak punya satu pun dari itu: dia justru ada
 * buat lembar yang templatenya nggak ada.
 *
 * Dijadikan satu tabel berarti separuh kolomnya selalu null di tiap baris, dan
 * nggak ada yang berani ngapus kolom mana pun — alasan yang sama yang bikin
 * `worksheet_scans` dipisah dari `worksheet_extraction_logs`.
 *
 * ## Baris yang GAGAL pun disimpan
 *
 * Sama alasannya kayak `worksheet_scans`: yang gagal justru paling berguna
 * buat nyetel ambang dan ngerti lembar mana yang belum kebaca. Keberadaan
 * barisnya BUKAN tanda hasilnya kepakai — statusnya yang nentuin.
 *
 * ## Yang TIDAK terjadi di sini
 *
 * Baris di tabel ini bukan hasil pengukuran. `raw_measurements` tetap lahir
 * dari `POST/PUT /calibrations` sesudah teknisi mengoreksi. Kamera mempercepat
 * input, bukan jadi syaratnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen_bacaan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Sesi boleh belum ada: teknisi bisa membaca lembar duluan, sesinya
            // dibikin waktu menekan simpan.
            $table->foreignId('calibration_session_id')->nullable()
                ->constrained()->nullOnDelete();

            // Nama alat yang DIPILIH teknisi — konteks, bukan penentu. Disimpan
            // terpisah dari yang TERBACA di kertas supaya bedanya bisa dilihat
            // belakangan: kalau sering beda, petunjuknya yang bikin salah.
            $table->string('nama_alat_konteks', 255)->nullable();

            // Yang benar-benar terbaca dari kertasnya.
            $table->string('judul', 255)->nullable();
            $table->string('nama_alat', 255)->nullable();
            $table->string('kode_dokumen', 100)->nullable();
            $table->string('revisi', 40)->nullable();

            // Keyakinan tingkat dokumen, 0..1. Presisi 4 desimal: ini skor
            // model, bukan hasil ukur — jadi dia TIDAK ikut aturan presisi
            // workbook master, dan sengaja nggak pernah nyampe sertifikat.
            $table->decimal('keyakinan', 5, 4)->nullable();

            // ok | perlu_review | gagal | ditolak | tak_terbaca | dimatikan
            $table->string('status', 30);
            $table->string('pesan', 255)->nullable();

            $table->unsignedSmallInteger('jumlah_field')->default(0);
            $table->unsignedSmallInteger('jumlah_sel')->default(0);
            $table->unsignedSmallInteger('perlu_review')->default(0);

            // Peringatan dari model & pengurai — mis. alat pilihan teknisi beda
            // dari yang terbaca. Ditampilkan ke teknisi, jadi harus ikut
            // tersimpan biar layar review masih bisa dibuka lagi nanti.
            $table->json('peringatan')->nullable();

            // Skema form utuh. Disimpan supaya layar review bisa dibuka ulang
            // tanpa memanggil AI lagi — pembacaan ulang itu mahal, dan lebih
            // buruk lagi: hasilnya bisa BEDA dari yang barusan dikoreksi
            // teknisi.
            $table->json('skema')->nullable();

            // Model AI yang dipakai + pemakaian tokennya. Yang pertama buat
            // menjawab "kenapa hasil bulan ini beda", yang kedua buat biaya.
            $table->string('model', 100)->nullable();
            $table->json('usage')->nullable();

            $table->string('citra_path')->nullable();

            $table->timestamps();

            // Nama index DITULIS EKSPLISIT: auto-generate gampang lewat 64
            // karakter dan MySQL nolak di situ (error 1059), sementara SQLite
            // yang dipakai test nggak punya batas itu — jadi bugnya cuma
            // muncul di produksi. Pola yang sama kayak `ws_org_created_idx`.
            $table->index(['organization_id', 'created_at'], 'db_org_created_idx');
            $table->index(['calibration_session_id', 'created_at'], 'db_sesi_created_idx');
            $table->index(['organization_id', 'status'], 'db_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_bacaan');
    }
};
