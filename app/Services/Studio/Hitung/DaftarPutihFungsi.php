<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use Throwable;

/**
 * Daftar putih fungsi lembar untuk mesin hitung Studio (tahap C).
 *
 * Hanya fungsi MURNI (hasil cuma bergantung pada argumen) yang boleh. Yang
 * sengaja TIDAK ada di daftar, dan karena itu ditolak `422` bila muncul di
 * rumus edit (dan tidak pernah dievaluasi bila ada di workbook — nilai cache
 * Excel yang dipakai, sel dilaporkan di `galat`):
 *
 *  - jaringan / sistem: WEBSERVICE (PhpSpreadsheet sungguh melakukan HTTP),
 *    FILTERXML, ENCODEURL, CALL, REGISTER, REGISTER.ID, RTD, HYPERLINK, IMAGE;
 *  - membocorkan lingkungan: INFO, CELL (termasuk CELL("filename") → path disk);
 *  - tidak deterministik: RAND, RANDBETWEEN, RANDARRAY (tidak bisa diadu ke Excel);
 *  - sumber data luar: GETPIVOTDATA, CUBE*, fungsi `__xludf.` (UDF/makro);
 *  - memeriksa "apakah sel berumus": ISFORMULA, FORMULATEXT — mode sel kotor
 *    memasang rumus tak kotor sebagai konstan bernilai cache (`ModelHitung`).
 *
 * NOW/TODAY boleh: keduanya dibekukan ke `waktu_acuan` (`FungsiExcel`).
 * INDIRECT/OFFSET boleh tetapi dinamis — `GrafDependensi` menganggapnya kotor
 * pada setiap edit.
 */
final class DaftarPutihFungsi
{
    /** @var list<string> */
    public const FUNGSI = [
        // logika
        'IF', 'IFS', 'IFERROR', 'IFNA', 'AND', 'OR', 'NOT', 'XOR', 'TRUE', 'FALSE', 'SWITCH',
        // informasi
        'ISERROR', 'ISERR', 'ISNA', 'ISBLANK', 'ISNUMBER', 'ISTEXT', 'ISNONTEXT', 'ISLOGICAL', 'ISEVEN', 'ISODD',
        'ISREF', 'NA', 'ERROR.TYPE', 'TYPE', 'N', 'T',
        // rujukan & pencarian
        'VLOOKUP', 'HLOOKUP', 'LOOKUP', 'XLOOKUP', 'INDEX', 'MATCH', 'XMATCH', 'CHOOSE', 'INDIRECT', 'OFFSET',
        'ROW', 'ROWS', 'COLUMN', 'COLUMNS', 'ADDRESS', 'TRANSPOSE', 'ANCHORARRAY',
        // matematika
        'ABS', 'SQRT', 'POWER', 'EXP', 'LN', 'LOG', 'LOG10', 'PI', 'SIN', 'COS', 'TAN', 'ASIN', 'ACOS', 'ATAN', 'ATAN2',
        'SINH', 'COSH', 'TANH', 'DEGREES', 'RADIANS', 'ROUND', 'ROUNDUP', 'ROUNDDOWN', 'MROUND', 'CEILING',
        'CEILING.MATH', 'CEILING.PRECISE', 'FLOOR', 'FLOOR.MATH', 'FLOOR.PRECISE', 'INT', 'TRUNC', 'MOD', 'SIGN',
        'SUM', 'SUMIF', 'SUMIFS', 'SUMPRODUCT', 'SUMSQ', 'PRODUCT', 'QUOTIENT', 'FACT', 'COMBIN', 'EVEN', 'ODD',
        'GCD', 'LCM', 'SQRTPI', 'SUMX2MY2', 'SUMX2PY2', 'SUMXMY2', 'SUBTOTAL', 'AGGREGATE',
        // statistik
        'AVERAGE', 'AVERAGEA', 'AVERAGEIF', 'AVERAGEIFS', 'COUNT', 'COUNTA', 'COUNTBLANK', 'COUNTIF', 'COUNTIFS',
        'MAX', 'MAXA', 'MAXIFS', 'MIN', 'MINA', 'MINIFS', 'MEDIAN', 'MODE', 'MODE.SNGL', 'LARGE', 'SMALL',
        'RANK', 'RANK.EQ', 'RANK.AVG', 'PERCENTILE', 'PERCENTILE.INC', 'QUARTILE', 'QUARTILE.INC',
        'STDEV', 'STDEV.S', 'STDEV.P', 'STDEVP', 'STDEVA', 'STDEVPA', 'VAR', 'VAR.S', 'VAR.P', 'VARP', 'VARA', 'VARPA',
        'DEVSQ', 'AVEDEV', 'GEOMEAN', 'HARMEAN', 'TRIMMEAN',
        'TINV', 'T.INV', 'T.INV.2T', 'TDIST', 'T.DIST', 'T.DIST.2T', 'T.DIST.RT',
        'NORMSINV', 'NORM.S.INV', 'NORMINV', 'NORM.INV', 'NORMSDIST', 'NORM.S.DIST', 'NORMDIST', 'NORM.DIST',
        'CHIINV', 'CHISQ.INV', 'CHISQ.INV.RT', 'FINV', 'F.INV', 'F.INV.RT', 'CONFIDENCE', 'CONFIDENCE.NORM', 'CONFIDENCE.T',
        'SLOPE', 'INTERCEPT', 'FORECAST', 'FORECAST.LINEAR', 'TREND', 'LINEST', 'CORREL', 'PEARSON', 'RSQ', 'STEYX',
        // teks
        'LEFT', 'RIGHT', 'MID', 'LEN', 'TRIM', 'UPPER', 'LOWER', 'PROPER', 'CONCATENATE', 'CONCAT', 'TEXTJOIN',
        'TEXT', 'VALUE', 'NUMBERVALUE', 'FIXED', 'DOLLAR', 'REPT', 'SUBSTITUTE', 'REPLACE', 'FIND', 'SEARCH',
        'EXACT', 'CHAR', 'CODE', 'UNICHAR', 'UNICODE', 'CLEAN',
        // tanggal & waktu
        'DATE', 'DATEVALUE', 'DAY', 'MONTH', 'YEAR', 'HOUR', 'MINUTE', 'SECOND', 'TIME', 'TIMEVALUE', 'NOW', 'TODAY',
        'WEEKDAY', 'WEEKNUM', 'ISOWEEKNUM', 'EDATE', 'EOMONTH', 'DATEDIF', 'DAYS', 'DAYS360', 'NETWORKDAYS',
        'WORKDAY', 'YEARFRAC',
    ];

    /** Fungsi yang memaksa sel dianggap kotor pada setiap edit (rujukan tidak terbaca statis). */
    public const DINAMIS = ['INDIRECT', 'OFFSET'];

    /** Fungsi volatil yang SELALU dihitung (bergantung waktu acuan). */
    public const VOLATIL = ['NOW', 'TODAY'];

    private function __construct() {}

    /** Nama fungsi dari token `Function` parser PhpSpreadsheet (`_XLFN.STDEV.S(` → `STDEV.S`). */
    public static function namaDariToken(string $token): string
    {
        $nama = strtoupper(rtrim(trim($token), '('));
        $nama = ltrim($nama, '@');

        foreach (['_XLFN.', '_XLWS.'] as $awalan) {
            if (str_starts_with($nama, $awalan)) {
                $nama = substr($nama, strlen($awalan));
            }
        }

        return $nama;
    }

    public static function boleh(string $nama): bool
    {
        static $peta = null;
        $peta ??= array_fill_keys(self::FUNGSI, true);

        return isset($peta[$nama]);
    }

    /**
     * Fungsi di rumus yang tidak ada di daftar putih. Rumus yang tidak bisa
     * diurai sama sekali → `null`.
     *
     * @return list<string>|null
     */
    public static function terlarang(string $rumus): ?array
    {
        $token = self::token($rumus);

        if ($token === null) {
            return null;
        }

        $hasil = [];

        foreach ($token as $t) {
            if (($t['type'] ?? null) === 'Function' && is_string($t['value'] ?? null)) {
                $nama = self::namaDariToken($t['value']);

                if (! self::boleh($nama)) {
                    $hasil[$nama] = true;
                }
            }
        }

        return array_keys($hasil);
    }

    /**
     * Token RPN parser PhpSpreadsheet, atau null bila rumus gagal diurai.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function token(string $rumus): ?array
    {
        static $pengurai = null;
        $pengurai ??= new Calculation;

        try {
            $hasil = $pengurai->parseFormula($rumus);
        } catch (Throwable) {
            return null;
        }

        return is_array($hasil) ? array_values($hasil) : null;
    }
}
