<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarPistonVolume as Tabel;

/**
 * Dispensett (bottle-top dispenser) — lampiran LK-285-IDN no. 16: 1–10 mL
 * 0,0082; 10–50 mL 0,042; 50–100 mL 0,083 mL. Sub-jenis Single Stroke /
 * Multi Stroke memilih KOLOM tabel MPE (`MPE_dispenset` kolom 2/3); nominal
 * kecil Single Stroke di master bertanda "-" = tidak ada MPE, dan vonisnya
 * tidak terbit.
 */
class DispensettProfile extends PistonVolumeProfile
{
    public const KODE = 'dispensett';

    public function kode(): string
    {
        return self::KODE;
    }

    public function namaAlatKemampuan(): string
    {
        return 'Dispensett';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'Dispenset',
            'Dispensette',
            'Bottle Top Dispenser',
            'Bottle-top Dispenser',
        ];
    }

    public function jenis(): string
    {
        return Tabel::DISPENSETT;
    }

    public function pilihanSubJenis(): array
    {
        return [
            ['nilai' => Tabel::SINGLE_STROKE, 'label' => 'Single Stroke (output diatur alat)'],
            ['nilai' => Tabel::MULTI_STROKE, 'label' => 'Multi Stroke (output diatur pengguna)'],
        ];
    }
}
