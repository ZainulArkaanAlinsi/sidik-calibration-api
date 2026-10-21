<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Append-only di tingkat DATABASE untuk `paket_acuan_log` dan `simulasi_acuan`
 * (tinjauan 22 Okt 2026). Penjaga model (`updating`/`deleting`) tidak menahan
 * `query()->update()` / `->delete()` massal — trigger ini yang menahannya.
 *
 * - MySQL: `BEFORE UPDATE` / `BEFORE DELETE` → `SIGNAL SQLSTATE '45000'`.
 * - SQLite (suite test): `RAISE(ABORT, …)`.
 *
 * **Pengecualian sadar:** `organization_id … cascadeOnDelete`. Di MySQL aksi
 * FK berantai TIDAK menyalakan trigger, jadi menghapus organisasi tetap ikut
 * menghapus log & simulasinya (keputusan skema 22 Okt; dicatat di
 * `docs/permintaan-user-7.md` §54). Di SQLite aksi FK berantai MENYALAKAN
 * trigger, jadi di suite test menghapus organisasi yang punya baris ini gagal.
 *
 * MySQL dengan binary log tanpa hak SUPER bisa menolak `CREATE TRIGGER`
 * (1419) atau tanpa hak TRIGGER (1142). Migrasi TIDAK digagalkan karenanya —
 * deploy yang macet lebih mahal — tetapi galatnya ditulis keras ke log dan
 * stderr: penjaga model tetap jalan, penjaga DB yang hilang harus terlihat.
 */
return new class extends Migration
{
    private const TABEL = ['paket_acuan_log' => 'pal', 'simulasi_acuan' => 'sa'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (self::TABEL as $tabel => $awal) {
            foreach (['UPDATE' => 'ubah', 'DELETE' => 'hapus'] as $aksi => $kata) {
                $nama = "{$awal}_tolak_{$kata}";
                $pesan = "{$tabel} append-only: {$kata} ditolak";

                $sql = match ($driver) {
                    'mysql', 'mariadb' => "CREATE TRIGGER {$nama} BEFORE {$aksi} ON {$tabel} FOR EACH ROW "
                        ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$pesan}'",
                    'sqlite' => "CREATE TRIGGER {$nama} BEFORE {$aksi} ON {$tabel} "
                        ."BEGIN SELECT RAISE(ABORT, '{$pesan}'); END",
                    default => null,
                };

                if ($sql === null) {
                    continue;
                }

                try {
                    DB::unprepared($sql);
                } catch (QueryException $e) {
                    if ($driver === 'sqlite') {
                        throw $e;
                    }

                    $teks = "PERINGATAN: trigger append-only {$nama} TIDAK terpasang ({$e->getMessage()}). "
                        .'Penjaga model tetap aktif; update/delete massal ke tabel ini tidak tertahan DB.';
                    Log::error($teks);
                    fwrite(STDERR, $teks.PHP_EOL);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $awal) {
            foreach (['ubah', 'hapus'] as $kata) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$awal}_tolak_{$kata}");
            }
        }
    }
};
