<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `folder_files.sumber` nerima `lembar_kerja`.
 *
 * Migrasi sebelumnya (`...tautkan_lembar_kerja_ke_folder_files`) nambahin
 * kolom `calibration_session_id`, tapi kelewat: `sumber` itu **ENUM** yang cuma
 * kenal `sertifikat` & `unggahan`. Nulis `lembar_kerja` ke situ kena
 * `SQLSTATE[01000] Data truncated`.
 *
 * Ini nggak ketangkep test sama sekali, dan alasannya perlu dicatat: test jalan
 * di **sqlite in-memory** (`phpunit.xml`), dan sqlite nggak punya tipe ENUM —
 * dia nyimpen string apa adanya. Jadi seluruh suite hijau sementara MySQL asli
 * nolak nulisnya. Ketahuannya cuma waktu rantainya dijalanin lawan `sidik_db`.
 *
 * Lebih runcing lagi: penautannya dibungkus `try/catch` yang cuma nulis
 * `Log::warning` (biar lembar kerja teknisi nggak gagal kekirim gara-gara
 * urusan rak berkas). Betul buat teknisi, tapi artinya kegagalan ini **nggak
 * kelihatan di mana pun** — nggak ada error, lembarnya kekirim normal, cuma
 * nggak pernah nyampe folder PT.
 *
 * ENUM dipertahanin (nggak diubah jadi varchar) supaya database tetap yang
 * nolak nilai ngawur. Yang salah bukan pilihan tipenya — daftar nilainya yang
 * ketinggalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->perluDiubah()) {
            return;
        }

        DB::statement(
            "ALTER TABLE folder_files MODIFY COLUMN sumber
             ENUM('sertifikat', 'unggahan', 'lembar_kerja')
             NOT NULL DEFAULT 'unggahan'",
        );
    }

    public function down(): void
    {
        if (! $this->perluDiubah()) {
            return;
        }

        // Baris lembar kerja dibuang dulu — kalau ditinggal, ENUM-nya nyempit
        // sementara barisnya masih nyimpen nilai yang udah nggak sah lagi.
        DB::table('folder_files')->where('sumber', 'lembar_kerja')->delete();

        DB::statement(
            "ALTER TABLE folder_files MODIFY COLUMN sumber
             ENUM('sertifikat', 'unggahan')
             NOT NULL DEFAULT 'unggahan'",
        );
    }

    /**
     * Cuma MySQL/MariaDB yang punya ENUM. Di sqlite (test) & pgsql,
     * `ALTER ... MODIFY` bukan sintaks yang sah dan kolomnya udah nerima
     * string apa adanya — jadi nggak ada yang perlu diubah.
     */
    private function perluDiubah(): bool
    {
        return Schema::hasTable('folder_files')
            && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
