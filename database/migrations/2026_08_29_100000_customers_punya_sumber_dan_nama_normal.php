<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelanggan bisa lahir dari HP teknisi, jadi asal-usulnya harus kelihatan.
 *
 * Sampai sekarang `customers` cuma bisa diisi admin lewat panel. Begitu teknisi
 * boleh mendaftarkan PT baru dari lapangan (keputusan pemilik proyek, sejalan
 * dengan K3/K4 buat nama alat), baris pelanggan berhenti seragam: sebagian
 * datang dari meja admin yang punya surat jalan & NPWP di tangan, sebagian dari
 * teknisi yang cuma pegang papan nama di gerbang pabrik.
 *
 * Dua kolom pertama yang bikin bedanya kelihatan — tanpa itu admin nggak punya
 * cara tahu baris mana yang perlu dirapikan.
 *
 * `nama_normal` beda urusan: dia penjaga kembar. Unique index yang sudah ada
 * jalan di `nama` MENTAH, jadi "PT. Maju Jaya" lolos di sebelah "PT Maju Jaya"
 * dan lab bangun dua folder arsip buat satu perusahaan yang sama. Kolom ini
 * yang bikin kemiripan itu bisa dicari sebelum barisnya lahir.
 *
 * Sengaja **tidak** unique. Dua PT yang beneran beda memang boleh punya nama
 * yang turun ke bentuk normal yang sama, dan menolaknya di tingkat database
 * bikin teknisi mentok di lapangan tanpa jalan keluar. Penjagaannya di endpoint
 * — kandidat ditunjukkan dulu, dan kembar cuma lahir kalau ada yang menekan
 * "ini perusahaan lain".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Baris yang sudah ada semuanya lahir dari panel admin/seeder —
            // waktu migrasi ini jalan, jalur teknisi belum ada sama sekali.
            $table->string('sumber')->default(Customer::SUMBER_ADMIN)->after('nama');

            $table->foreignId('dibuat_oleh_user_id')
                ->nullable()
                ->after('sumber')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('nama_normal')->nullable()->after('nama');
            $table->index(['organization_id', 'nama_normal']);

            // Id tempat dari direktori luar, buat pelanggan yang dipilih dari
            // hasil pencarian direktori. Gunanya satu: perusahaan yang sama
            // dipilih dua teknisi dari direktori yang sama bisa dikenali PERSIS,
            // tanpa mengadu ejaan nama. Kosong buat pelanggan yang diketik
            // tangan — dan itu mayoritas baris lama.
            $table->string('direktori_ref')->nullable()->after('dibuat_oleh_user_id');
            $table->index(['organization_id', 'direktori_ref']);
        });

        // Baris lama diisi lewat model yang sama dengan yang mengisi baris baru.
        // Kalau aturan normalisasinya suatu saat berubah, kolom ini WAJIB
        // diturunkan ulang — dua aturan yang beda di satu kolom bikin penjaga
        // kembarnya bohong buat sebagian data.
        Customer::withTrashed()
            ->select(['id', 'nama'])
            ->chunkById(500, function ($daftar) {
                foreach ($daftar as $pelanggan) {
                    Customer::withTrashed()
                        ->whereKey($pelanggan->id)
                        ->update(['nama_normal' => Customer::normalkanNama($pelanggan->nama)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['dibuat_oleh_user_id']);
            $table->dropIndex(['organization_id', 'nama_normal']);
            $table->dropIndex(['organization_id', 'direktori_ref']);
            $table->dropColumn(['sumber', 'dibuat_oleh_user_id', 'nama_normal', 'direktori_ref']);
        });
    }
};
