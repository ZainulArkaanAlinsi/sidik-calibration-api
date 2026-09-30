<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alat-alat dalam satu permintaan kalibrasi.
 *
 * Satu baris = satu alat, dari salah satu dari dua sumber:
 *
 *  - alat yang SUDAH terdaftar atas pelanggan itu → `equipment_id` terisi;
 *  - alat BARU yang diketik pelanggan di formulir → `alat_baru` (JSON) terisi,
 *    `equipment_id` kosong sampai admin menerima ajuannya.
 *
 * ## Kenapa alat baru disimpan sebagai JSON, bukan langsung baris `equipments`
 *
 * Baris `equipments` yang lahir dari ajuan yang kemudian DITOLAK adalah alat
 * sampah di daftar lab — dan karena `equipments` dipakai profil kalibrasi,
 * dropdown teknisi, serta alarm jatuh tempo, alat sampah itu ikut menyalakan
 * alarm. Jadi alat baru hanya lahir saat admin menerima, dengan kategori yang
 * dipilih admin (kategori wajib di `equipments`, dan pelanggan tidak tahu
 * katalog kategori lab). Sesudah itu `equipment_id` diisi dan JSON-nya
 * dibiarkan: itu catatan apa yang PELANGGAN ketik, sebelum admin merapikan.
 *
 * `order_item_id` terisi juga saat diterima, supaya dari ajuan bisa dilacak
 * sampai ke baris paketnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan_kalibrasi_item', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('permintaan_kalibrasi_id')
                ->constrained('permintaan_kalibrasi')
                ->cascadeOnDelete();

            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->json('alat_baru')->nullable();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();

            $table->timestamps();

            // Penyaring "alat ini sedang diajukan?" (pengingat jatuh tempo).
            $table->index('equipment_id', 'permintaan_item_alat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_kalibrasi_item');
    }
};
