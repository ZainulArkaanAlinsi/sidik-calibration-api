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
 * ## Dulu dua test di sini SKIP waktu jalan di MySQL. Sekarang nggak lagi.
 *
 * Katalognya dulu dibaca lewat `sqlite_master`, yang cuma ada di SQLite, jadi
 * suite MySQL melewatinya dengan `markTestSkipped`. Skip itu benar dan jujur —
 * tapi skip yang muncul tiap kali suite jalan melatih orang membaca "2 skipped"
 * sebagai pemandangan biasa, dan lama-lama skip yang BARU (yang mungkin
 * menyembunyikan masalah beneran) ikut tidak terbaca. Itu kelas kegagalan yang
 * sama dengan peringatan palsu yang melatih admin menekan "setujui tetap".
 *
 * Sekarang katalognya dibaca per driver: `sqlite_master` di SQLite,
 * `information_schema` di MySQL/MariaDB. Nol skip di dua suite.
 *
 * ## Apa yang SEBENARNYA dibuktikan di masing-masing driver
 *
 * Ditulis terang supaya pembaca berikutnya nggak salah mengira bobotnya sama:
 *
 * - **Di SQLite — ini penjagaan yang sesungguhnya.** SQLite nggak punya batas
 *   panjang identifier, jadi skema yang melanggar BISA terbentuk di sini, dan
 *   test inilah satu-satunya yang menangkapnya sebelum sampai ke MySQL.
 *
 * - **Di MySQL — ini sabuk kedua, dan memang nyaris pasti lolos.** Skema yang
 *   melanggar nggak akan pernah terbentuk: `migrate` sudah meledak duluan
 *   dengan error 1059, jauh sebelum test pertama jalan. Yang tersisa nilainya
 *   cuma satu hal kecil tapi nyata — memastikan skema yang terpasang memang
 *   ter-migrate (katalognya nggak kosong), yang diadu di
 *   `test_katalog_skema_kebaca_di_driver_ini`.
 */
class SkemaDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** Batas panjang identifier MySQL/MariaDB. */
    private const BATAS_MYSQL = 64;

    /**
     * Nama semua index di skema, apa pun drivernya.
     *
     * @return list<array{nama: string, tabel: string}>
     */
    private function indexDiSkema(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return array_map(
                fn (object $b): array => ['nama' => (string) $b->nama, 'tabel' => (string) $b->tabel],
                DB::select(
                    "SELECT name AS nama, tbl_name AS tabel FROM sqlite_master
                     WHERE type = 'index' AND name NOT LIKE 'sqlite_%'",
                ),
            );
        }

        // `DISTINCT` karena `information_schema.STATISTICS` punya satu baris per
        // KOLOM di dalam index — index dua kolom muncul dua kali, dan tanpa ini
        // pesan gagalnya menyebut nama yang sama berulang.
        return array_map(
            fn (object $b): array => ['nama' => (string) $b->nama, 'tabel' => (string) $b->tabel],
            DB::select(
                'SELECT DISTINCT INDEX_NAME AS nama, TABLE_NAME AS tabel
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()',
            ),
        );
    }

    /**
     * Nama semua tabel di skema, apa pun drivernya.
     *
     * @return list<string>
     */
    private function tabelDiSkema(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return array_map(
                fn (object $b): string => (string) $b->nama,
                DB::select("SELECT name AS nama FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            );
        }

        return array_map(
            fn (object $b): string => (string) $b->nama,
            DB::select(
                "SELECT TABLE_NAME AS nama FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED')",
            ),
        );
    }

    public function test_semua_nama_index_masih_di_bawah_batas_mysql(): void
    {
        $kepanjangan = [];

        foreach ($this->indexDiSkema() as $index) {
            if (strlen($index['nama']) > self::BATAS_MYSQL) {
                $kepanjangan[] = sprintf(
                    '%s (%d char, tabel %s)',
                    $index['nama'],
                    strlen($index['nama']),
                    $index['tabel'],
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
        $kepanjangan = [];

        foreach ($this->tabelDiSkema() as $nama) {
            if (strlen($nama) > self::BATAS_MYSQL) {
                $kepanjangan[] = $nama.' ('.strlen($nama).' char)';
            }
        }

        $this->assertSame([], $kepanjangan, sprintf(
            "Nama tabel ini lewat batas %d karakter MySQL:\n  - %s",
            self::BATAS_MYSQL,
            implode("\n  - ", $kepanjangan),
        ));
    }

    /**
     * Katalog skemanya beneran kebaca di driver yang sedang dipakai.
     *
     * Tanpa ini, dua test di atas jadi hijau palsu yang paling gampang terjadi:
     * query katalog yang salah nama tabel/kolom memulangkan daftar KOSONG,
     * nol nama yang kepanjangan, dan assertion-nya lolos tanpa pernah memeriksa
     * satu index pun. Persis bentuk kegagalan yang dulu bikin index 65 karakter
     * itu lolos ke main.
     */
    public function test_katalog_skema_kebaca_di_driver_ini(): void
    {
        $tabel = $this->tabelDiSkema();
        $index = $this->indexDiSkema();

        // Ambangnya jauh DI BAWAH jumlah sebenarnya (39 tabel per 16 Sep 2026),
        // dan itu disengaja: yang dijaga di sini "katalognya kebaca", bukan
        // "skemanya sebesar ini". Ambang yang mepet bikin test ini merah tiap
        // kali ada tabel dihapus — merah yang nggak menunjukkan apa-apa.
        $this->assertGreaterThan(30, count($tabel), sprintf(
            'Cuma %d tabel kebaca dari katalog driver `%s`. Query katalognya yang rusak, '.
            'bukan skemanya — dan dua test panjang identifier di atas jadi hijau tanpa memeriksa apa pun.',
            count($tabel),
            DB::getDriverName(),
        ));

        $this->assertGreaterThan(20, count($index), sprintf(
            'Cuma %d index kebaca dari katalog driver `%s`. Lihat alasan di test tabel.',
            count($index),
            DB::getDriverName(),
        ));

        $this->assertContains('users', $tabel);
        $this->assertContains('certificates', $tabel);
    }
}
