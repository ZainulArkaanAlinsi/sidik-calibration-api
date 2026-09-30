<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi & pembatalan sertifikat (§38 `docs/permintaan-user-7.md`).
 *
 * Satu pelebaran ENUM + lima kolom nullable. Nol kolom lama yang berubah
 * isinya: `revision_of` & `alasan_revisi` sudah ada sejak 14 Jul, cuma belum
 * pernah ditulis kode produksi.
 *
 * ## Kenapa ENUM-nya di-`change()`, bukan dibiarkan
 *
 * `certificates.status` ENUM sungguhan di MySQL — menulis `dibatalkan` tanpa
 * pelebaran ini kena `Data truncated`. Di SQLite Laravel menulisnya sebagai
 * `varchar check (...)`, jadi test ikut menolak; `change()` membangun ulang
 * kolomnya di dua mesin itu sekaligus.
 *
 * ## `revisi_ke` disimpan, bukan diturunkan
 *
 * Bisa dihitung dengan menelusuri `revision_of` ke belakang, tapi layar daftar
 * butuh angkanya untuk tiap baris — satu kolom kecil lebih murah daripada
 * N penelusuran. Nilainya ditulis sekali waktu barisnya lahir dan tidak pernah
 * berubah.
 *
 * ## `down()` menolak kalau sudah ada yang dibatalkan
 *
 * Menyempitkan ENUM sementara barisnya masih `dibatalkan` membuat MySQL
 * menulis string kosong — sertifikat batal diam-diam kehilangan statusnya.
 * Itu dokumen terkendali; yang mau rollback harus memutuskan nasibnya sendiri.
 */
return new class extends Migration
{
    private const STATUS_BARU = ['menunggu_generate', 'terbit', 'gagal', 'dibatalkan'];

    private const STATUS_LAMA = ['menunggu_generate', 'terbit', 'gagal'];

    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->enum('status', self::STATUS_BARU)->default('menunggu_generate')->change();
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('revisi_ke')->default(0)->after('revision_of');
            $table->timestamp('dibatalkan_pada')->nullable()->after('status');
            $table->foreignId('dibatalkan_oleh')->nullable()->after('dibatalkan_pada')
                ->constrained('users')->nullOnDelete();
            $table->text('alasan_pembatalan')->nullable()->after('dibatalkan_oleh');
            // Dibaca pelanggan & tidak pernah memuat alasan internal (D4).
            // Satu kolom untuk dua peristiwa: pada revisi dia menjelaskan apa
            // yang diperbaiki, pada pembatalan dia menjelaskan apa yang terjadi.
            $table->text('catatan_pelanggan')->nullable()->after('alasan_revisi');
        });
    }

    public function down(): void
    {
        $jumlah = DB::table('certificates')->where('status', 'dibatalkan')->count();

        if ($jumlah > 0) {
            throw new RuntimeException(
                "Rollback dibatalkan: {$jumlah} sertifikat berstatus `dibatalkan`. Menyempitkan ENUM ".
                'sekarang menghapus status itu dari dokumen terkendali. Putuskan dulu nasibnya.'
            );
        }

        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['revisi_ke', 'dibatalkan_pada', 'alasan_pembatalan', 'catatan_pelanggan']);
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->enum('status', self::STATUS_LAMA)->default('menunggu_generate')->change();
        });
    }
};
