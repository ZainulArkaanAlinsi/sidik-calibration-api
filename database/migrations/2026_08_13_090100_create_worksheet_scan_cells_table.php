<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = SATU SEL lembar kerja pada satu kali pindai.
 *
 * Kenapa per sel, bukan satu kolom JSON di `worksheet_scans`: akurasi fitur ini
 * harus diukur PER KOLOM, bukan per halaman. "Akurasi 94%" nggak berarti apa-apa
 * kalau yang 6% itu selalu kolom suhu di Repeat 5. Angka per kolom cuma bisa
 * ditanya kalau selnya berbaris.
 *
 * Kolom `nilai_final` & `cocok` yang bikin tabel ini jadi DATASET: begitu
 * teknisi ngonfirmasi/mbetulin, selisih antara tebakan mesin & angka yang
 * dipakai jadi ground truth. Cuma koreksi yang udah lewat orang yang masuk sini
 * — nggak ada yang dipungut dari tebakan mentah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_scan_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worksheet_scan_id')->constrained()->cascadeOnDelete();

            // Kunci eksplisit: tabel|baris|repeat|field. SATU-SATUNYA cara sel
            // dirujuk — nggak ada tahap mana pun yang boleh ngandelin urutan.
            $table->string('kunci', 120);
            $table->string('tabel_id', 60);
            $table->unsignedSmallInteger('baris_ke');
            $table->unsignedSmallInteger('repeat_no');
            $table->string('field_id', 30);

            // Bukti pembanding, bukan bagian kunci — lihat `TemplateLembarKerja`.
            $table->decimal('titik_ukur', 20, 8)->nullable();
            $table->foreignId('standard_id')->nullable()->constrained()->nullOnDelete();

            $table->string('teks_mentah', 64)->nullable();
            $table->string('teks_normal', 64)->nullable();
            // Presisi ngikut `raw_measurements.pembacaan` biar angka nggak
            // berubah bentuk waktu pindah dari hasil pindai ke data resmi.
            $table->decimal('nilai', 20, 8)->nullable();

            $table->decimal('confidence_ocr', 5, 4)->nullable();
            $table->decimal('confidence_akhir', 5, 4)->nullable();

            // hijau | kuning | merah | kosong
            $table->string('status', 10);
            $table->json('alasan')->nullable();
            $table->json('normalisasi')->nullable();
            $table->json('kotak')->nullable();

            // Ground truth: angka yang AKHIRNYA dipakai teknisi.
            $table->decimal('nilai_final', 20, 8)->nullable();
            $table->boolean('cocok')->nullable();
            $table->foreignId('dikoreksi_oleh')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('dikoreksi_pada')->nullable();

            $table->string('crop_path')->nullable();

            $table->timestamps();

            // Satu sel cuma boleh punya satu baris per pindai. Ini penjaga
            // terakhir "satu sel dua nilai" di tingkat DB — kalau sampai ada bug
            // di pemetaan yang lolos semua penjagaan aplikasi, dia mentok di sini
            // dan gagal keras, bukan diam-diam nimpa.
            $table->unique(['worksheet_scan_id', 'kunci'], 'wsc_scan_kunci_unique');
            $table->index(['worksheet_scan_id', 'status'], 'wsc_scan_status_idx');
            // Buat ngukur akurasi per kolom lintas scan (benchmark & dashboard).
            $table->index(['field_id', 'status'], 'wsc_field_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_scan_cells');
    }
};
