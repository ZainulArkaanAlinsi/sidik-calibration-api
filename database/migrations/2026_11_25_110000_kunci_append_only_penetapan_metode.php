<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Append-only di tingkat DATABASE untuk `parameter_metode_penetapan` dan
 * `parameter_metode_penetapan_jejak` (tinjauan PR #304, 25 Nov 2026). Penjaga
 * model (`updating`/`deleting` melempar) tidak menahan `DB::table()->update()`,
 * `Model::query()->update()` atau `->delete()` massal; trigger ini yang
 * menahannya. Polanya sama dengan `2026_10_22_120000_kunci_append_only_log_dan_simulasi_acuan`.
 *
 * Yang ditahan:
 *
 *  - `parameter_metode_penetapan` UPDATE: hanya `status`, `keputusan_oleh`,
 *    `tanggal_keputusan`, dan `updated_at` yang boleh berubah (itu yang ditulis
 *    `PenetapanParameterMetode`). Isi baris (`id`, `kunci`, `nomor`, `nilai`,
 *    `berlaku_mulai`, `dicatat_pada`, `catatan`, `created_at`) tidak boleh
 *    berubah sama sekali, termasuk perubahan besar-kecil huruf. Dua aturan
 *    tambahan yang sama dengan service: penetapan yang sudah keluar dari
 *    `menunggu_tm` membekukan `keputusan_oleh` + `tanggal_keputusan` (keputusan
 *    yang sudah dicatat tidak ditimpa), dan `ditarik` adalah status akhir.
 *  - `parameter_metode_penetapan` DELETE: selalu ditolak.
 *  - `parameter_metode_penetapan_jejak` UPDATE dan DELETE: selalu ditolak.
 *
 * Tidak ditahan, dan sengaja: transisi `menunggu_tm` -> `disetujui` yang
 * dilakukan lewat SQL mentah tanpa baris jejak. Yang menjamin pasangan
 * "transisi + jejak" tetap satu transaksi adalah `PenetapanParameterMetode`;
 * trigger tidak bisa menuntut baris di tabel lain.
 *
 * - MySQL: `BEFORE UPDATE` / `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
 *   Perbandingan memakai `<=>` (aman terhadap NULL) dan `CAST(... AS BINARY)`
 *   untuk string, karena kolasi `utf8mb4_unicode_ci` menganggap huruf besar dan
 *   kecil sama.
 * - SQLite (suite test): `RAISE(ABORT, ...)` dengan `IS NOT`.
 *
 * **Pengecualian sadar (sama dengan migrasi 22 Okt):** `oleh_user_id ...
 * nullOnDelete` di tabel jejak. Di MySQL aksi FK berantai TIDAK menyalakan
 * trigger, jadi menghapus akun user tetap mengosongkan `oleh_user_id` (alamat
 * surelnya tetap di `oleh_email`). Di SQLite aksi FK berantai MENYALAKAN
 * trigger, jadi di suite test menghapus user yang punya baris jejak gagal.
 * `TRUNCATE` di MySQL juga tidak menyalakan trigger DELETE; hak itu tidak
 * dimiliki akun aplikasi.
 *
 * MySQL dengan binary log tanpa hak SUPER bisa menolak `CREATE TRIGGER`
 * (1419) atau tanpa hak TRIGGER (1142). Migrasi TIDAK digagalkan karenanya,
 * deploy yang macet lebih mahal, tetapi galatnya ditulis keras ke log dan
 * stderr: penjaga model tetap jalan, penjaga DB yang hilang harus terlihat.
 * Trigger ini BELUM masuk `TriggerAppendOnlyAcuan` (`/api/health`
 * `append_only_db`, `acuan:pasang-trigger`): kalau pemasangan gagal di MySQL,
 * satu-satunya jejaknya log dan stderr migrasi.
 *
 * `down()` hanya menjatuhkan trigger. Data (dan jejak audit di dalamnya) tidak
 * disentuh; yang berkurang adalah penjagaannya.
 */
return new class extends Migration
{
    private const PENETAPAN = 'parameter_metode_penetapan';

    private const JEJAK = 'parameter_metode_penetapan_jejak';

    /** Nama trigger yang dipasang. @var list<string> */
    private const NAMA = ['pmp_tolak_hapus', 'pmp_tolak_ubah_isi', 'pmpj_tolak_ubah', 'pmpj_tolak_hapus'];

    /** Kolom isi yang tidak boleh berubah, dan apakah string (dibanding persis, huruf besar-kecil berbeda). */
    private const KOLOM_ISI = [
        'id' => false,
        'kunci' => true,
        'nomor' => false,
        'nilai' => true,
        'berlaku_mulai' => false,
        'dicatat_pada' => false,
        'catatan' => true,
        'created_at' => false,
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->daftar($driver) as $nama => $sql) {
            try {
                DB::unprepared($sql);
            } catch (QueryException $e) {
                if ($driver === 'sqlite') {
                    throw $e;
                }

                $teks = "PERINGATAN: trigger append-only {$nama} TIDAK terpasang ({$e->getMessage()}). "
                    .'Penjaga model tetap aktif; update/delete mentah ke tabel ini tidak tertahan DB.';
                Log::error($teks);
                fwrite(STDERR, $teks.PHP_EOL);
            }
        }
    }

    public function down(): void
    {
        foreach (self::NAMA as $nama) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$nama}");
        }
    }

    /**
     * @return array<string, string> nama trigger => DDL; kosong untuk driver yang tidak dikenal
     */
    private function daftar(string $driver): array
    {
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return [];
        }

        $mysql = $driver !== 'sqlite';
        $pesanIsi = self::PENETAPAN.' append-only: isi baris dan keputusan yang sudah dicatat tidak boleh diubah';

        $daftar = [];

        // Penetapan: hapus ditolak, ubah isi ditolak.
        $daftar['pmp_tolak_hapus'] = $this->tolakSemua('pmp_tolak_hapus', 'DELETE', self::PENETAPAN, self::PENETAPAN.' append-only: hapus ditolak', $mysql);
        $daftar['pmp_tolak_ubah_isi'] = $mysql
            ? 'CREATE TRIGGER pmp_tolak_ubah_isi BEFORE UPDATE ON '.self::PENETAPAN." FOR EACH ROW\n"
                ."BEGIN\n"
                ."    IF {$this->kondisiUbah(true)} THEN\n"
                ."        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$pesanIsi}';\n"
                ."    END IF;\n"
                .'END'
            : 'CREATE TRIGGER pmp_tolak_ubah_isi BEFORE UPDATE ON '.self::PENETAPAN."\n"
                ."WHEN {$this->kondisiUbah(false)}\n"
                ."BEGIN SELECT RAISE(ABORT, '{$pesanIsi}'); END";

        // Jejak: tulis-sekali.
        $daftar['pmpj_tolak_ubah'] = $this->tolakSemua('pmpj_tolak_ubah', 'UPDATE', self::JEJAK, self::JEJAK.' append-only: ubah ditolak', $mysql);
        $daftar['pmpj_tolak_hapus'] = $this->tolakSemua('pmpj_tolak_hapus', 'DELETE', self::JEJAK, self::JEJAK.' append-only: hapus ditolak', $mysql);

        return $daftar;
    }

    private function tolakSemua(string $nama, string $aksi, string $tabel, string $pesan, bool $mysql): string
    {
        return $mysql
            ? "CREATE TRIGGER {$nama} BEFORE {$aksi} ON {$tabel} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$pesan}'"
            : "CREATE TRIGGER {$nama} BEFORE {$aksi} ON {$tabel} BEGIN SELECT RAISE(ABORT, '{$pesan}'); END";
    }

    /** Syarat penolakan UPDATE penetapan, satu ekspresi boolean untuk `IF` (MySQL) atau `WHEN` (SQLite). */
    private function kondisiUbah(bool $mysql): string
    {
        $beda = static function (string $kolom, bool $string) use ($mysql): string {
            if (! $mysql) {
                return "OLD.{$kolom} IS NOT NEW.{$kolom}";
            }

            return $string
                ? "NOT (CAST(OLD.{$kolom} AS BINARY) <=> CAST(NEW.{$kolom} AS BINARY))"
                : "NOT (OLD.{$kolom} <=> NEW.{$kolom})";
        };

        $syarat = [];

        foreach (self::KOLOM_ISI as $kolom => $string) {
            $syarat[] = $beda($kolom, $string);
        }

        // Keputusan yang sudah dicatat dibekukan begitu status keluar dari `menunggu_tm`.
        $syarat[] = "(OLD.status <> 'menunggu_tm' AND ({$beda('keputusan_oleh', true)} OR {$beda('tanggal_keputusan', false)}))";
        // `ditarik` adalah status akhir.
        $syarat[] = "(OLD.status = 'ditarik' AND NEW.status <> 'ditarik')";

        return implode("\n    OR ", $syarat);
    }
};
