<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lapis DATABASE append-only untuk `paket_acuan_log` dan `simulasi_acuan`
 * (§54): empat trigger `BEFORE UPDATE`/`BEFORE DELETE`. Definisinya sama
 * persis dengan migrasi `2026_10_22_120000_kunci_append_only_log_dan_simulasi_acuan`
 * (migrasi sengaja tidak memanggil kelas ini — migrasi dibekukan; kesamaan
 * namanya dijaga `PasangTriggerAcuanTest`).
 *
 * Lapis ini BERGANTUNG pada izin server: MySQL dengan binary log menolak
 * `CREATE TRIGGER` (1419) kecuali `log_bin_trust_function_creators=1` atau
 * pengguna punya SUPER. Migrasi tidak gagal karenanya, jadi keadaannya harus
 * bisa dibaca dari luar — `terpasang()` dilaporkan `/api/health`
 * (`append_only_db`), dan `pasang()` (perintah `acuan:pasang-trigger`)
 * mencoba lagi sesudah setelan server dibetulkan. Penjaga model
 * (`updating`/`deleting` melempar) tetap jalan apa pun keadaannya.
 */
final class TriggerAppendOnlyAcuan
{
    public const KUNCI_CACHE = 'health.append_only_db';

    private const TABEL = ['paket_acuan_log' => 'pal', 'simulasi_acuan' => 'sa'];

    private const AKSI = ['UPDATE' => 'ubah', 'DELETE' => 'hapus'];

    /**
     * @return list<array{nama: string, tabel: string, aksi: string, pesan: string}>
     */
    public static function daftar(): array
    {
        $daftar = [];

        foreach (self::TABEL as $tabel => $awal) {
            foreach (self::AKSI as $aksi => $kata) {
                $daftar[] = [
                    'nama' => "{$awal}_tolak_{$kata}",
                    'tabel' => $tabel,
                    'aksi' => $aksi,
                    'pesan' => "{$tabel} append-only: {$kata} ditolak",
                ];
            }
        }

        return $daftar;
    }

    /** Nama trigger yang benar-benar ada di database koneksi bawaan. @return list<string> */
    public static function yangAda(): array
    {
        $driver = DB::connection()->getDriverName();
        $tabel = array_keys(self::TABEL);

        $baris = match ($driver) {
            'mysql', 'mariadb' => DB::select(
                'SELECT TRIGGER_NAME AS nama, EVENT_OBJECT_TABLE AS tabel, EVENT_MANIPULATION AS aksi '
                .'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN (?, ?)',
                $tabel,
            ),
            'sqlite' => DB::select(
                "SELECT name AS nama, tbl_name AS tabel, NULL AS aksi FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN (?, ?)",
                $tabel,
            ),
            default => [],
        };

        $harapan = collect(self::daftar())->keyBy('nama');
        $ada = [];

        foreach ($baris as $b) {
            $h = $harapan->get($b->nama);

            if ($h !== null && $h['tabel'] === $b->tabel && ($b->aksi === null || strtoupper($b->aksi) === $h['aksi'])) {
                $ada[] = $b->nama;
            }
        }

        sort($ada);

        return $ada;
    }

    /** True hanya bila keempat trigger (dua tabel × update/delete) ada. */
    public static function terpasang(): bool
    {
        return count(self::yangAda()) === count(self::daftar());
    }

    /**
     * Untuk `/api/health` (publik, tanpa auth): satu query murah, dicache 5
     * menit. Galat baca (database belum siap) dijawab `false` TANPA dicache —
     * endpoint diagnosis tidak boleh 500 karena hal yang dia diagnosis.
     */
    public static function terpasangTercache(): bool
    {
        $nilai = Cache::get(self::KUNCI_CACHE);

        if (is_bool($nilai)) {
            return $nilai;
        }

        try {
            $nilai = self::terpasang();
        } catch (Throwable) {
            return false;
        }

        Cache::put(self::KUNCI_CACHE, $nilai, now()->addMinutes(5));

        return $nilai;
    }

    /**
     * Pasang trigger yang belum ada; yang sudah ada tidak disentuh (idempoten).
     *
     * @return array<string, string> nama → 'sudah_ada' | 'dipasang' | 'gagal: <pesan>'
     */
    public static function pasang(): array
    {
        $driver = DB::connection()->getDriverName();
        $ada = self::yangAda();
        $hasil = [];

        foreach (self::daftar() as $t) {
            if (in_array($t['nama'], $ada, true)) {
                $hasil[$t['nama']] = 'sudah_ada';

                continue;
            }

            $sql = match ($driver) {
                'mysql', 'mariadb' => "CREATE TRIGGER {$t['nama']} BEFORE {$t['aksi']} ON {$t['tabel']} FOR EACH ROW "
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$t['pesan']}'",
                'sqlite' => "CREATE TRIGGER {$t['nama']} BEFORE {$t['aksi']} ON {$t['tabel']} "
                    ."BEGIN SELECT RAISE(ABORT, '{$t['pesan']}'); END",
                default => null,
            };

            if ($sql === null) {
                $hasil[$t['nama']] = "gagal: driver {$driver} tidak didukung";

                continue;
            }

            try {
                DB::unprepared($sql);
                $hasil[$t['nama']] = 'dipasang';
            } catch (QueryException $e) {
                $hasil[$t['nama']] = 'gagal: '.$e->getMessage();
            }
        }

        Cache::forget(self::KUNCI_CACHE);

        return $hasil;
    }
}
