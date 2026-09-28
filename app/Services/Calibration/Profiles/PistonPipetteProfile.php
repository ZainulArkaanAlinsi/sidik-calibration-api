<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarPistonVolume as Tabel;

/**
 * Piston Pipette (micropipette) — lampiran LK-285-IDN no. 15: 101–1000 µL
 * 1,2 µL; 1–5 mL 4,2 µL; 5–10 mL 8,3 µL. Tanpa sub-jenis; MPE dari kolom
 * tunggal `MPE_pistonpipette` (ISO 8655-2).
 */
class PistonPipetteProfile extends PistonVolumeProfile
{
    public const KODE = 'piston_pipette';

    public function kode(): string
    {
        return self::KODE;
    }

    public function namaAlatKemampuan(): string
    {
        return 'Piston Pipette';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'Micropipette',
            'Micro Pipette',
            'Mikropipet',
            'Mikro Pipet',
            'Pipet Mikro',
            'Pipette Piston',
            'Adjustable Pipette',
        ];
    }

    public function jenis(): string
    {
        return Tabel::PISTON_PIPETTE;
    }

    public function pilihanSubJenis(): array
    {
        return [];
    }
}
