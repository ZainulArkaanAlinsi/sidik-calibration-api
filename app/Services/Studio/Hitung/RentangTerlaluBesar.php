<?php

namespace App\Services\Studio\Hitung;

use RuntimeException;

/**
 * Rentang yang hendak dibaca mesin lebih besar dari `studio.hitung.maks_sel_rentang`
 * (`KalkulasiBerpagar`). Dilempar SEBELUM PhpSpreadsheet merinci alamatnya;
 * `MesinHitung` menjadikannya galat sel (nilai cache Excel dipakai), bukan galat
 * request — satu rumus asli dengan rentang dinamis raksasa tidak menjatuhkan
 * seluruh hitung.
 */
final class RentangTerlaluBesar extends RuntimeException
{
    public function __construct(public readonly int $jumlahSel, public readonly int $batas)
    {
        parent::__construct("rentang {$jumlahSel} sel melebihi batas {$batas} sel");
    }
}
