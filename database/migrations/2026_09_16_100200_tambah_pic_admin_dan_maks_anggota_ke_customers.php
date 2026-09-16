<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `customers` dapat PIC admin default dan batas jumlah anggota (03-SDD §4.1).
 *
 * `pic_admin_id` = admin lab yang jadi penanggung jawab pelanggan ini. Bukan
 * pemilik data — cuma tujuan default notifikasi supaya pengajuan & pesan nggak
 * selalu menyiram semua admin. `nullOnDelete`, bukan `cascadeOnDelete`: admin
 * yang keluar dari lab nggak boleh ikut menghapus baris pelanggannya.
 *
 * `maks_anggota` default 50 mengikuti REQ-ANG-01. Disimpan per pelanggan, bukan
 * dipatok konstanta, karena SRS-nya sendiri menulis "bisa diubah admin" —
 * perusahaan besar dengan banyak PIC memang ada.
 *
 * Nilai `sumber` = `pelanggan` TIDAK butuh migrasi: `customers.sumber` itu
 * kolom `string`, bukan ENUM (lihat 2026_08_29_100000). Yang ditambah cuma
 * konstanta di model. SDD §4.1 menulisnya seolah ENUM — itu yang keliru,
 * bukan kodenya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $kolom = $table->foreignId('pic_admin_id')->nullable()->after('email');

            // Foreign key-nya cuma dipasang di MySQL/MariaDB, dan itu bukan
            // kemalasan.
            //
            // SQLite nggak bisa MELEPAS kolom yang ikut dalam definisi foreign
            // key — itu batasan `ALTER TABLE ... DROP COLUMN`-nya sendiri, dan
            // nggak ada PRAGMA yang menolongnya. Dipasang di sini, `down()`
            // bakal selalu gagal di suite SQLite dengan "unknown column
            // pic_admin_id in foreign key definition", dan migrasi ini jadi
            // nggak bisa di-rollback di tempat test hariannya jalan.
            //
            // Yang dijaga FK ini integritas data PRODUKSI — admin yang dihapus
            // nggak boleh meninggalkan `pic_admin_id` yang menunjuk ke baris
            // yang sudah nggak ada. Dan produksi jalan di MySQL.
            if (DB::getDriverName() !== 'sqlite') {
                $kolom->constrained('users')->nullOnDelete();
            }

            $table->unsignedSmallInteger('maks_anggota')->default(50)->after('pic_admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Cerminan `up()`: di SQLite FK-nya memang nggak pernah dipasang,
            // jadi nggak ada yang perlu dilepas.
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['pic_admin_id']);
            }

            $table->dropColumn(['pic_admin_id', 'maks_anggota']);
        });
    }
};
