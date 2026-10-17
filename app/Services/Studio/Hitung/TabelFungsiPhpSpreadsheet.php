<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Calculation\CalculationBase;

/**
 * Jalan masuk ke tabel fungsi PhpSpreadsheet (`FunctionArray::$phpSpreadsheetFunctions`,
 * `protected static`) lewat `CalculationBase::getFunctionsAddress()` — satu-satunya
 * cara menukar entri bawaan (STDEV, TINV, NOW, TODAY) karena `addFunction()` menolak
 * nama yang sudah ada. Hanya dipakai `FungsiExcel::pasang()/lepas()`.
 */
final class TabelFungsiPhpSpreadsheet extends CalculationBase
{
    /** @return array<string, array<string, mixed>> referensi ke tabel statis */
    public static function &tabel(): array
    {
        return self::getFunctionsAddress();
    }
}
