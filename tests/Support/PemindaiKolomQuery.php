<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Memindai sumber PHP untuk kolom yang dirujuk rantai kueri, supaya bisa diadu
 * ke skema sungguhan (`Schema::hasColumn`).
 *
 * ## Kenapa
 *
 * SQLite memperlakukan identifier ber-kutip-ganda yang tidak dikenal sebagai
 * string literal: `WHERE "kolom_salah" = 1` tidak error, cuma selalu salah;
 * `ORDER BY "kolom_salah"` dan `SELECT "kolom_salah"` (pluck) juga lolos diam-diam.
 * MySQL menjawab 500 `Unknown column`. Jadi hijau di SQLite tidak membuktikan nama
 * kolomnya benar (CI MySQL 18 Nov 2026: `formulas.equipment_category_id`).
 *
 * ## Yang dipindai
 *
 * Satu "akar" per kueri, lalu semua kolom literal di rantainya:
 *
 *  - akar `DB::table('t')`, `Rule::exists('t', ...)`, `Rule::unique('t', ...)`;
 *  - akar model `Kelas::query()/withTrashed()/onlyTrashed()/whereKey()/where()`
 *    (kelas diselesaikan lewat baris `use` berkas itu, tabelnya dari `getTable()`);
 *  - akar relasi yang dikenal (`->anggota()`, `->item()`, ...) lewat `$relasiTabel`.
 *
 * Kolom yang dibaca: argumen literal pertama `where/orWhere/whereNull/
 * whereNotNull/whereIn/whereNotIn/whereDate/orderBy/orderByDesc/pluck/value`
 * (`tabel.kolom` diperiksa ke tabel yang disebut). Di dalam `whereHas/withExists/
 * withCount/has/with(...)` kolom TANPA awalan tabel dilewati (milik tabel lain, tak
 * bisa ditentukan tanpa menjalankan kode); yang berawalan tetap diperiksa.
 *
 * Ini heuristik berbasis teks, sengaja sederhana. Yang tidak terjangkau (kolom
 * dinamis, `whereRaw`) ditutup test lain: peta tabel/kolom eksplisit.
 */
final class PemindaiKolomQuery
{
    private const METODE_KOLOM = 'where|orWhere|whereNull|whereNotNull|orWhereNull|whereIn|whereNotIn|orWhereIn|whereDate|orderBy|orderByDesc|pluck|value';

    private const METODE_ASING = 'whereHas|orWhereHas|whereDoesntHave|withExists|withCount|has|with';

    /**
     * @param  array<string, string>  $relasiTabel  nama relasi => tabel
     * @return list<array{tabel: string, kolom: string, akar: string}>
     */
    public static function pindai(string $sumber, array $relasiTabel = []): array
    {
        $sumber = self::tanpaKomentar($sumber);
        $model = self::petaModel($sumber);
        $hasil = [];

        foreach (self::akar($sumber, $model, $relasiTabel) as [$offset, $tabel, $label]) {
            $pernyataan = self::pernyataan($sumber, $offset);
            $asing = self::rentangAsing($pernyataan);

            preg_match_all(
                '/->(?:'.self::METODE_KOLOM.")\\(\\s*'([A-Za-z_][A-Za-z0-9_.]*)'/",
                $pernyataan,
                $cocok,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($cocok as $c) {
                $kolom = $c[1][0];
                $posisi = $c[0][1];

                // `pluck`/`value` di dalam argumen metode lain (mis.
                // `whereIn('id', $koleksi->pluck('user_id'))`) itu koleksi, bukan
                // kolom tabel akar: hanya dihitung di tingkat rantai.
                if (preg_match('/^->(?:pluck|value)\(/', $c[0][0]) === 1 && self::kedalaman($pernyataan, $posisi) > 0) {
                    continue;
                }

                if (str_contains($kolom, '.')) {
                    [$tabelKolom, $namaKolom] = explode('.', $kolom, 2);
                    $hasil[] = ['tabel' => $tabelKolom, 'kolom' => $namaKolom, 'akar' => $label];

                    continue;
                }

                if (self::didalam($posisi, $asing)) {
                    continue;
                }

                $hasil[] = ['tabel' => $tabel, 'kolom' => $kolom, 'akar' => $label];
            }

            // `Rule::exists('t', 'kolom')` / `Rule::unique('t', 'kolom')`: argumen kedua.
            if (preg_match("/^Rule::(?:exists|unique)\\('[a-z_]+',\\s*'([a-z_]+)'/", $pernyataan, $r) === 1) {
                $hasil[] = ['tabel' => $tabel, 'kolom' => $r[1], 'akar' => $label];
            }
        }

        return $hasil;
    }

    /** Sumber tanpa komentar (tokenizer PHP): apostrof/titik-koma di komentar tidak boleh mengacaukan penyisiran. */
    private static function tanpaKomentar(string $sumber): string
    {
        $hasil = '';

        foreach (token_get_all($sumber) as $token) {
            if (is_array($token)) {
                $hasil .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
            } else {
                $hasil .= $token;
            }
        }

        return $hasil;
    }

    /** Kedalaman kurung (`(`, `[`, `{`) pada `$posisi`, di luar string. */
    private static function kedalaman(string $teks, int $posisi): int
    {
        $dalam = 0;
        $kutip = null;

        for ($i = 0; $i < $posisi; $i++) {
            $ch = $teks[$i];

            if ($kutip !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $kutip) {
                    $kutip = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $kutip = $ch;
            } elseif ($ch === '(' || $ch === '[' || $ch === '{') {
                $dalam++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $dalam--;
            }
        }

        return $dalam;
    }

    /** @return array<string, class-string<Model>> nama pendek => FQCN, hanya subclass Model */
    private static function petaModel(string $sumber): array
    {
        preg_match_all('/^use ([A-Za-z0-9_\\\\]+);/m', $sumber, $use);
        $peta = [];

        foreach ($use[1] as $fqcn) {
            if (class_exists($fqcn) && is_subclass_of($fqcn, Model::class)) {
                $peta[substr(strrchr('\\'.$fqcn, '\\') ?: '', 1)] = $fqcn;
            }
        }

        return $peta;
    }

    /**
     * @param  array<string, class-string<Model>>  $model
     * @param  array<string, string>  $relasiTabel
     * @return list<array{0: int, 1: string, 2: string}> [offset, tabel, label]
     */
    private static function akar(string $sumber, array $model, array $relasiTabel): array
    {
        $akar = [];

        preg_match_all("/DB::table\\('([a-z_]+)'\\)/", $sumber, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $c) {
            $akar[] = [$c[0][1], $c[1][0], "DB::table('{$c[1][0]}')"];
        }

        preg_match_all("/Rule::(?:exists|unique)\\('([a-z_]+)'/", $sumber, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $c) {
            $akar[] = [$c[0][1], $c[1][0], "Rule::exists/unique('{$c[1][0]}')"];
        }

        preg_match_all('/\b([A-Z][A-Za-z0-9]+)::(?:query|withTrashed|onlyTrashed|whereKey|where)\(/', $sumber, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $c) {
            $fqcn = $model[$c[1][0]] ?? null;

            if ($fqcn !== null) {
                $akar[] = [$c[0][1], (new $fqcn)->getTable(), $c[1][0].'::...'];
            }
        }

        foreach ($relasiTabel as $nama => $tabel) {
            preg_match_all('/->'.preg_quote($nama, '/').'\(\)/', $sumber, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as $c) {
                $akar[] = [$c[1], $tabel, "->{$nama}()"];
            }
        }

        return $akar;
    }

    /** Teks dari `$awal` sampai `;` atau `,` pada kedalaman kurung 0 (atau kurung penutup induk). */
    private static function pernyataan(string $sumber, int $awal): string
    {
        $dalam = 0;
        $panjang = strlen($sumber);
        $kutip = null;

        for ($i = $awal; $i < $panjang; $i++) {
            $ch = $sumber[$i];

            if ($kutip !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $kutip) {
                    $kutip = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $kutip = $ch;

                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $dalam++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $dalam--;
                if ($dalam < 0) {
                    return substr($sumber, $awal, $i - $awal);
                }
            } elseif (($ch === ';' || $ch === ',') && $dalam === 0) {
                return substr($sumber, $awal, $i - $awal);
            }
        }

        return substr($sumber, $awal);
    }

    /** @return list<array{0: int, 1: int}> rentang [awal, akhir) kurung `whereHas(...)` dkk. */
    private static function rentangAsing(string $pernyataan): array
    {
        preg_match_all('/->(?:'.self::METODE_ASING.')\(/', $pernyataan, $m, PREG_OFFSET_CAPTURE);
        $rentang = [];

        foreach ($m[0] as $c) {
            $awal = $c[1] + strlen($c[0]) - 1;
            $dalam = 0;

            for ($i = $awal, $n = strlen($pernyataan); $i < $n; $i++) {
                if ($pernyataan[$i] === '(') {
                    $dalam++;
                } elseif ($pernyataan[$i] === ')') {
                    $dalam--;
                    if ($dalam === 0) {
                        $rentang[] = [$awal, $i];

                        break;
                    }
                }
            }
        }

        return $rentang;
    }

    /** @param  list<array{0: int, 1: int}>  $rentang */
    private static function didalam(int $posisi, array $rentang): bool
    {
        foreach ($rentang as [$awal, $akhir]) {
            if ($posisi > $awal && $posisi < $akhir) {
                return true;
            }
        }

        return false;
    }
}
