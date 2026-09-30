<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferensi notifikasi aplikasi pelanggan (layar PL_Preferensi).
 *
 * Kuncinya `customer_member_id`, BUKAN `user_id`: satu orang bisa jadi anggota
 * beberapa perusahaan (konsultan, REQ-ANG-04), dan setelan "matikan pengingat"
 * untuk pabrik A tidak boleh ikut mematikan pengingat pabrik B. Layarnya
 * sendiri menulis "setelan ini khusus untuk PT ...".
 *
 * Baris dibuat malas (saat pertama kali disetel). Anggota tanpa baris memakai
 * bawaan di `PreferensiNotifikasi::BAWAAN` — dengan begitu anggota lama tidak
 * perlu backfill, dan bawaan yang berubah kelak tidak menimpa pilihan orang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferensi_notifikasi_anggota', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_member_id')->constrained('customer_members')->cascadeOnDelete();

            $table->boolean('pengingat_jadwal')->default(true);
            $table->boolean('status_permintaan')->default(true);
            $table->boolean('pesan_lab')->default(true);
            // Disimpan untuk layarnya; pengirim ringkasan email mingguan belum
            // ada, jadi saklar ini belum menggerakkan apa pun (dicatat di
            // docs/perintah-frontend-permintaan.md).
            $table->boolean('ringkasan_email_mingguan')->default(false);

            $table->timestamps();

            $table->unique('customer_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preferensi_notifikasi_anggota');
    }
};
