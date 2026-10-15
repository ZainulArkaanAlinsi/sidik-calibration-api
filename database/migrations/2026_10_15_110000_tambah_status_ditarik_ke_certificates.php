<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarik sertifikat ke lembar kerja (K-50-03, 15 Okt 2026).
 *
 * Additive: satu nilai ENUM baru (`ditarik`) + tiga kolom nullable. Tidak ada
 * kolom lama yang berubah isinya dan tidak ada yang di-drop. Polanya sama dengan
 * `2026_10_01_100000_revisi_dan_pembatalan_sertifikat`: `certificates.status`
 * ENUM sungguhan di MySQL, jadi menulis `ditarik` tanpa pelebaran ini kena
 * `Data truncated`.
 *
 * Sertifikat yang ditarik TIDAK dihapus: baris, PDF, dan QR-nya tetap ada (QR
 * lama menampilkan "ditarik, digantikan <nomor>-R1"). Nomornya tetap miliknya,
 * tidak pernah dipakai ulang.
 *
 * `down()` menolak kalau masih ada yang `ditarik` — menyempitkan ENUM akan
 * menulis string kosong ke dokumen terkendali.
 */
return new class extends Migration
{
    private const STATUS_BARU = ['menunggu_generate', 'terbit', 'gagal', 'dibatalkan', 'ditarik'];

    private const STATUS_LAMA = ['menunggu_generate', 'terbit', 'gagal', 'dibatalkan'];

    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->enum('status', self::STATUS_BARU)->default('menunggu_generate')->change();
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->timestamp('ditarik_pada')->nullable()->after('alasan_pembatalan');
            $table->foreignId('ditarik_oleh')->nullable()->after('ditarik_pada')
                ->constrained('users')->nullOnDelete();
            $table->text('alasan_ditarik')->nullable()->after('ditarik_oleh');
        });
    }

    public function down(): void
    {
        $jumlah = DB::table('certificates')->where('status', 'ditarik')->count();

        if ($jumlah > 0) {
            throw new RuntimeException(
                "Rollback dibatalkan: {$jumlah} sertifikat berstatus `ditarik`. Menyempitkan ENUM ".
                'sekarang menghapus status itu dari dokumen terkendali. Putuskan dulu nasibnya.'
            );
        }

        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ditarik_oleh');
            $table->dropColumn(['ditarik_pada', 'alasan_ditarik']);
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->enum('status', self::STATUS_LAMA)->default('menunggu_generate')->change();
        });
    }
};
