<?php

namespace App\Services\Studio\PetaSel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Alamat `Lembar!A1` atau `Lembar!A1:C3`. Nama lembar boleh berspasi
 * (`INPUT DATA`); pemisahnya `!` TERAKHIR.
 */
final class Alamat
{
    /**
     * @return array{lembar: string, rentang: bool, k1: int, b1: int, k2: int, b2: int}|null
     */
    public static function urai(string $alamat): ?array
    {
        $pos = strrpos($alamat, '!');

        if ($pos === false || $pos === 0) {
            return null;
        }

        $lembar = substr($alamat, 0, $pos);
        $sel = substr($alamat, $pos + 1);

        if (preg_match('/^([A-Z]{1,3})([1-9]\d{0,6})(?::([A-Z]{1,3})([1-9]\d{0,6}))?$/', $sel, $m) !== 1) {
            return null;
        }

        $k1 = Coordinate::columnIndexFromString($m[1]);
        $b1 = (int) $m[2];
        $rentang = isset($m[3]) && $m[3] !== '';
        $k2 = $rentang ? Coordinate::columnIndexFromString($m[3]) : $k1;
        $b2 = $rentang ? (int) $m[4] : $b1;

        if ($k2 < $k1 || $b2 < $b1) {
            return null;
        }

        return ['lembar' => $lembar, 'rentang' => $rentang, 'k1' => $k1, 'b1' => $b1, 'k2' => $k2, 'b2' => $b2];
    }

    public static function sel(string $lembar, int $kolom, int $baris): string
    {
        return $lembar.'!'.Coordinate::stringFromColumnIndex($kolom).$baris;
    }
}
