<?php

use App\Services\Studio\Workbook\JenisWorkbook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio: SATU master aktif per jenis/varian alat (keputusan pemilik K-49-13,
 * 15 Okt 2026). Impor master baru untuk jenis yang sama MENGGANTI yang aktif;
 * yang lama tetap ada sebagai riwayat tersembunyi (bisa dibuka), tidak dihapus.
 * Olah data/pekerjaan tidak masuk Studio — yang terlanjur masuk diarsipkan
 * (`studio:rapikan-workbook --arsipkan`), baris & berkasnya tidak dihapus.
 *
 * Additive, di migrasi terpisah (DB lokal Studio sudah menjalankan migrasi
 * workbook sebelumnya):
 *  - `kunci_jenis`: `{keluarga|kode_profil}:{varian}` (`KunciJenisWorkbook`),
 *    ditetapkan saat impor, bisa ditimpa admin.
 *  - `aktif`: `true` = master aktif jenisnya; NULL = bukan (riwayat/arsip).
 *    SENGAJA true|NULL, bukan true|false: unik (organization_id, kunci_jenis,
 *    aktif) hanya bisa menegakkan "satu aktif per jenis" di MySQL DAN SQLite
 *    karena NULL tidak pernah bentrok di indeks unik — false akan bentrok
 *    begitu ada dua riwayat. Model memetakan false → NULL.
 *  - `versi`: urutan impor di dalam jenis (1 = paling awal), dihitung dari id.
 *  - `digantikan_oleh_id`: baris riwayat → baris yang menggantikannya.
 *  - `diarsipkan_pada`: dikeluarkan dari Studio (daftar, detail, hitung → 404).
 *
 * Data lama (`JenisWorkbook::tataAwal`): semua baris dapat kunci dari namanya;
 * di jenis yang sama (kembaran csv dari .xlsm yang sama, nama ternormalisasi
 * sama) xlsm yang aktif, sisanya riwayat darinya. Indeks unik dibuat SESUDAH
 * data ditata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workbook_master', function (Blueprint $table) {
            $table->string('kunci_jenis', 160)->nullable()->after('keluarga');
            $table->boolean('aktif')->nullable()->default(true)->after('kunci_jenis');
            $table->unsignedInteger('versi')->default(1)->after('aktif');
            $table->foreignId('digantikan_oleh_id')->nullable()->after('versi')
                ->constrained('workbook_master', 'id', 'wm_digantikan_fk')->nullOnDelete();
            $table->timestamp('diarsipkan_pada')->nullable()->after('digantikan_oleh_id');
        });

        JenisWorkbook::tataAwal();

        Schema::table('workbook_master', function (Blueprint $table) {
            $table->unique(['organization_id', 'kunci_jenis', 'aktif'], 'wm_org_jenis_aktif_unik');
        });
    }

    public function down(): void
    {
        Schema::table('workbook_master', function (Blueprint $table) {
            $table->dropUnique('wm_org_jenis_aktif_unik');
            // SQLite hanya bisa membuang foreign key lewat kolomnya; MySQL lewat namanya.
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? ['digantikan_oleh_id'] : 'wm_digantikan_fk');
            $table->dropColumn(['kunci_jenis', 'aktif', 'versi', 'digantikan_oleh_id', 'diarsipkan_pada']);
        });
    }
};
