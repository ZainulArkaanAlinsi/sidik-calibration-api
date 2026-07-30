<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selaraskan tabel `folders` lama dengan bentuk yang dipakai kode sekarang.
 *
 * Kenapa perlu: `create_folders_table` dijaga `hasTable` supaya `migrate`
 * nggak mati di database yang tabelnya udah kepasang duluan. Konsekuensinya
 * tabel LAMA dibiarin apa adanya — dan bentuk lamanya nggak punya `tipe`
 * maupun `keterangan`, dua kolom yang ada di `Folder::$fillable`.
 *
 * Akibatnya sunyi dan mahal: `FolderOrganizer` bikin subfolder tahun waktu
 * sertifikat terbit, insert-nya kena "Unknown column 'tipe'", dan itu ketangkep
 * `try/catch` di `GenerateCertificate` yang cuma nulis Log::warning — sertifikat
 * tetap terbit, tapi NGGAK PERNAH nyampe ke Folder Manager. Di layar admin
 * folder pelanggannya kelihatan kosong terus tanpa ada error apa pun.
 *
 * `is_root` (kolom bentuk lama) SENGAJA nggak dibuang: nilainya dipakai buat
 * nebak `tipe` di bawah, dan nggak ada kode yang bentrok sama namanya. Boleh
 * dibuang nanti lewat migrasi sendiri kalau semua database dev udah selaras.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('folders')) {
            return;
        }

        $adaIsRoot = Schema::hasColumn('folders', 'is_root');

        Schema::table('folders', function (Blueprint $table) {
            if (! Schema::hasColumn('folders', 'tipe')) {
                $table->enum('tipe', ['sistem', 'manual'])->default('manual')->after('nama');
            }

            if (! Schema::hasColumn('folders', 'keterangan')) {
                $table->text('keterangan')->nullable()->after('tipe');
            }
        });

        // Folder akar per PT itu bikinan sistem (kebentuk sendiri waktu
        // sertifikat pertama terbit), bukan bikinan admin. Tanpa backfill ini
        // semuanya kebaca `manual` dan jadi bisa dihapus/direname sembarangan —
        // padahal isinya nunjuk ke sertifikat resmi yang masih hidup.
        if ($adaIsRoot) {
            DB::table('folders')->where('is_root', true)->update(['tipe' => 'sistem']);
        } else {
            DB::table('folders')->whereNull('parent_id')->whereNotNull('customer_id')
                ->update(['tipe' => 'sistem']);
        }
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            foreach (['tipe', 'keterangan'] as $kolom) {
                if (Schema::hasColumn('folders', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
