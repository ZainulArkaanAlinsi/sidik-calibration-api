<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pagar buat kelas bug yang pernah lolos ke main: nama identifier kepanjangan.
 *
 * Kejadiannya begini. Migrasi worksheet_extraction_logs naruh
 * `$table->index(['calibration_session_id', 'created_at'])` tanpa nama eksplisit,
 * jadi Laravel bikin sendiri:
 *
 *     worksheet_extraction_logs_calibration_session_id_created_at_index  (65 char)
 *
 * MySQL nolak identifier di atas 64 karakter (error 1059). Yang bikin jahat:
 * `CREATE TABLE`-nya lolos dulu, baru `ADD INDEX`-nya gagal — jadi tabelnya
 * ketinggalan di DB tapi migrasinya nggak kecatat, dan `php artisan migrate`
 * sesudahnya mentok "table already exists" selamanya. Setup dari nol di MySQL
 * jadi nggak bisa jalan sama sekali.
 *
 * Kenapa 303 test waktu itu tetep ijo: phpunit.xml jalan di SQLite in-memory,
 * dan SQLite nggak punya batas panjang identifier. Jadi seluruh test suite nggak
 * pernah nyentuh masalahnya, padahal dev & produksi dua-duanya MySQL.
 *
 * Test ini nutup celah itu TANPA perlu MySQL: nama index yang di-generate Laravel
 * itu sama di semua driver, jadi manjangnya bisa diukur dari skema SQLite.
 */
class SkemaDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** Batas panjang identifier MySQL/MariaDB. */
    private const BATAS_MYSQL = 64;

    public function test_semua_nama_index_masih_di_bawah_batas_mysql(): void
    {
        // Katalognya dibaca lewat `sqlite_master`, jadi tes ini cuma sah di
        // SQLite. Yang dijaga sendiri batas identifier MySQL — dicek dari
        // skema SQLite karena di situlah suite hariannya jalan.
        //
        // Kembaran terbalik dari `OcrMeasurementTest::test_kolom_sumber_input_
        // bukan_enum_sempit`, yang cuma sah di MySQL. Dua-duanya perlu, dan
        // dua-duanya harus BILANG kalau lagi nggak jalan — bukan meledak.
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Katalog `sqlite_master` cuma ada di SQLite.');
        }

        $kepanjangan = [];

        $indexes = DB::select(
            "SELECT name, tbl_name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%'",
        );

        foreach ($indexes as $index) {
            if (strlen($index->name) > self::BATAS_MYSQL) {
                $kepanjangan[] = sprintf(
                    '%s (%d char, tabel %s)',
                    $index->name,
                    strlen($index->name),
                    $index->tbl_name,
                );
            }
        }

        $this->assertSame([], $kepanjangan, sprintf(
            "Nama index ini lewat batas %d karakter MySQL, jadi `php artisan migrate`\n".
            "bakal gagal di MySQL walau test ini lolos di SQLite. Kasih nama pendek\n".
            "eksplisit di migrasinya, mis. \$table->index([...], 'nama_pendek_idx'):\n  - %s",
            self::BATAS_MYSQL,
            implode("\n  - ", $kepanjangan),
        ));
    }

    public function test_semua_nama_tabel_masih_di_bawah_batas_mysql(): void
    {
        // Katalognya dibaca lewat `sqlite_master`, jadi tes ini cuma sah di
        // SQLite. Yang dijaga sendiri batas identifier MySQL — dicek dari
        // skema SQLite karena di situlah suite hariannya jalan.
        //
        // Kembaran terbalik dari `OcrMeasurementTest::test_kolom_sumber_input_
        // bukan_enum_sempit`, yang cuma sah di MySQL. Dua-duanya perlu, dan
        // dua-duanya harus BILANG kalau lagi nggak jalan — bukan meledak.
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Katalog `sqlite_master` cuma ada di SQLite.');
        }

        $kepanjangan = [];

        $tabel = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        );

        foreach ($tabel as $t) {
            if (strlen($t->name) > self::BATAS_MYSQL) {
                $kepanjangan[] = $t->name.' ('.strlen($t->name).' char)';
            }
        }

        $this->assertSame([], $kepanjangan);
    }
}
