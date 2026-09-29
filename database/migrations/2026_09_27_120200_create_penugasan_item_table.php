<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris "+" di layar penugasan: jenis alat & jumlahnya.
 *
 * Persis yang kamu gambarkan — "ada tombol + buat nambah baris, isinya jenis
 * alat sama jumlahnya". Satu baris di sini = satu baris di layar itu.
 *
 * ## Kenapa `jenis_alat` string, bukan cuma `equipment_category_id`
 *
 * Kategori alat (`equipment_categories`) itu master data yang dikelola admin, dan
 * dia tidak selalu punya baris untuk apa yang mau ditugaskan hari ini. Super
 * admin yang harus membuat kategori baru dulu sebelum bisa membagi pekerjaan akan
 * berhenti memakai fitur ini — atau lebih buruk, memilih kategori yang "mirip"
 * dan membuat master data jadi kotor.
 *
 * Jadi dua-duanya ada: `equipment_category_id` kalau kategorinya memang sudah ada
 * (yang membuat laporan per kategori bisa dijumlahkan), dan `jenis_alat` apa
 * adanya kalau belum. Yang WAJIB cuma `jenis_alat` — dia yang dibaca teknisi.
 *
 * ## Kenapa `jumlah_selesai` disimpan, padahal tahap pelacakan diturunkan
 *
 * Beda dari slice C, dan bedanya bukan tidak konsisten. Di pelacakan, tahapnya
 * punya sumber kebenaran lain (`calibration_sessions.status`) sehingga
 * menyalinnya pasti melenceng. Di sini **tidak ada** sumber lain: "10 autoklaf"
 * itu rencana yang tidak terikat ke 10 baris alat tertentu, jadi tidak ada yang
 * bisa dihitung ulang. Angka yang dilaporkan teknisi adalah satu-satunya
 * kebenarannya, dan karena itu dia disimpan — beserta siapa yang melaporkannya
 * dan kapan, di `audit_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penugasan_item', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('penugasan_id')->constrained('penugasan')->cascadeOnDelete();

            // Yang dibaca teknisi. Wajib.
            $table->string('jenis_alat');

            // Opsional — kalau kategorinya memang sudah ada di master data.
            // `nullOnDelete`: kategori yang dipensiunkan tidak boleh menghapus
            // riwayat penugasan yang pernah memakainya.
            $table->foreignId('equipment_category_id')->nullable()
                ->constrained('equipment_categories')->nullOnDelete();

            $table->unsignedSmallInteger('jumlah')->default(1);
            $table->unsignedSmallInteger('jumlah_selesai')->default(0);

            // Tambatan opsional ke paket yang nyata, begitu barangnya masuk lab.
            // Inilah jembatan antara rencana (slice ini) dan barang (slice C).
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index('penugasan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penugasan_item');
    }
};
