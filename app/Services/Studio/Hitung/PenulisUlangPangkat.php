<?php

namespace App\Services\Studio\Hitung;

/**
 * Operator `^` Excel → `SIDIK.PANGKAT(a,b)` (Studio tahap C).
 *
 * Kenapa: PhpSpreadsheet menghitung `^` dengan `**` PHP (pow) di metode privat
 * `Calculation::executeNumericBinaryOperation` — tidak bisa ditimpa. Excel
 * tidak memakai pow: bukti COM (300 nilai acak per pangkat, `excel-audit.md`)
 * pangkat bulat = kuadrat-berulang (identik 300/300 untuk 2,3,4,5,6,-2,-3; pow
 * hanya 102–299), 0,5 = √, pangkat negatif = 1/(x^|y|), selain itu exp(y·ln x)
 * (295–299/300). `FungsiExcel::pangkat()` meniru itu; rumus yang memakai `^`
 * ditulis ulang supaya memanggilnya.
 *
 * Caranya aman: rumus diurai parser PhpSpreadsheet sendiri (token RPN, presedensi
 * & asosiativitas sudah diputuskan parser), dibangun ulang jadi teks dengan
 * SETIAP operator dibungkus kurung, `^` diganti panggilan fungsi. Token yang
 * tidak dikenal (larik konstan, rujukan kolom/baris utuh, rujukan terstruktur)
 * → rumus TIDAK ditulis ulang (null) dan tetap memakai `^` bawaan; harness
 * paritas yang melaporkan bila itu memberi beda.
 */
final class PenulisUlangPangkat
{
    public const FUNGSI = 'SIDIK.PANGKAT';

    private const OPERATOR_BINER = ['+', '-', '*', '/', '&', '=', '<>', '<', '>', '<=', '>='];

    private function __construct() {}

    /** Rumus dengan `^` ditulis ulang, atau null bila tidak perlu / tidak bisa. */
    public static function tulisUlang(string $rumus): ?string
    {
        $polos = preg_replace('/"(?:[^"]|"")*"/', '""', $rumus) ?? $rumus;

        if (! str_contains($polos, '^')) {
            return null;
        }

        $token = DaftarPutihFungsi::token(str_starts_with($rumus, '=') ? $rumus : '='.$rumus);

        if ($token === null || $token === []) {
            return null;
        }

        $tumpuk = [];
        $jumlahArg = null;

        foreach ($token as $t) {
            $jenis = (string) ($t['type'] ?? '');
            $nilai = $t['value'] ?? null;

            switch (true) {
                case $jenis === 'Cell Reference' || $jenis === 'Defined Name':
                    $tumpuk[] = ['ref', (string) $nilai];
                    break;

                case $jenis === 'Value':
                    $teks = self::nilai($nilai);

                    if ($teks === null) {
                        return null;
                    }

                    $tumpuk[] = ['nilai', $teks];
                    break;

                case $jenis === 'Constant':
                    $tumpuk[] = ['nilai', strtoupper((string) ($t['reference'] ?? $nilai))];
                    break;

                case $jenis === 'Empty Argument':
                    $tumpuk[] = ['kosong', ''];
                    break;

                case $jenis === 'Unary Operator':
                    $a = array_pop($tumpuk);

                    if ($a === null) {
                        return null;
                    }

                    $tumpuk[] = match ((string) $nilai) {
                        '~' => ['ops', '(-'.$a[1].')'],
                        '%' => ['ops', '('.$a[1].'%)'],
                        default => null,
                    };

                    if (end($tumpuk) === null) {
                        return null;
                    }

                    break;

                case $jenis === 'Binary Operator':
                    $b = array_pop($tumpuk);
                    $a = array_pop($tumpuk);

                    if ($a === null || $b === null) {
                        return null;
                    }

                    $op = (string) $nilai;
                    $hasil = match (true) {
                        $op === '^' => ['ops', self::FUNGSI.'('.$a[1].','.$b[1].')'],
                        $op === ':' && $a[0] === 'ref' && $b[0] === 'ref' => ['ref', $a[1].':'.self::tanpaLembarSama($a[1], $b[1])],
                        $op === '∪' => ['ref', '('.$a[1].','.$b[1].')'],
                        $op === '∩' => ['ref', '('.$a[1].' '.$b[1].')'],
                        in_array($op, self::OPERATOR_BINER, true) => ['ops', '('.$a[1].$op.$b[1].')'],
                        default => null,
                    };

                    if ($hasil === null) {
                        return null;
                    }

                    $tumpuk[] = $hasil;
                    break;

                case str_starts_with($jenis, 'Operand Count for Function'):
                    $jumlahArg = (int) $nilai;
                    break;

                case $jenis === 'Function':
                    $nama = rtrim((string) $nilai, '(');

                    if ($jumlahArg === null || str_contains(strtoupper($nama), 'MKMATRIX') || count($tumpuk) < $jumlahArg) {
                        return null;
                    }

                    $arg = $jumlahArg > 0 ? array_splice($tumpuk, -$jumlahArg) : [];
                    $tumpuk[] = ['ops', $nama.'('.implode(',', array_column($arg, 1)).')'];
                    $jumlahArg = null;
                    break;

                default:
                    // Rujukan kolom/baris utuh, rujukan terstruktur, larik, dst.
                    return null;
            }
        }

        if (count($tumpuk) !== 1) {
            return null;
        }

        $baru = '='.$tumpuk[0][1];

        return DaftarPutihFungsi::token($baru) === null ? null : $baru;
    }

    private static function nilai(mixed $v): ?string
    {
        if (is_int($v)) {
            return (string) $v;
        }

        if (is_float($v)) {
            for ($digit = 1; $digit <= 17; $digit++) {
                $teks = sprintf('%.'.$digit.'G', $v);

                if ((float) $teks === $v) {
                    return $teks;
                }
            }

            return sprintf('%.17G', $v);
        }

        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }

        if (! is_string($v)) {
            return null;
        }

        // Teks literal datang berkutip dengan kutip-dalam yang sudah dibuka (`"x"y"`).
        if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
            return '"'.str_replace('"', '""', substr($v, 1, -1)).'"';
        }

        // Kode galat (#N/A, #REF!, …) dan angka sebagai teks.
        return $v;
    }

    /** `'L'!B5` setelah `'L'!A1:` → `B5` (rentang satu lembar). */
    private static function tanpaLembarSama(string $kiri, string $kanan): string
    {
        $pisah = static fn (string $r): array => ($i = strrpos($r, '!')) === false ? ['', $r] : [substr($r, 0, $i), substr($r, $i + 1)];
        [$lk] = $pisah($kiri);
        [$lb, $ab] = $pisah($kanan);

        return $lb !== '' && $lb === $lk ? $ab : $kanan;
    }
}
