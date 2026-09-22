<?php

namespace App\Services\Calibration\Profiles;

/** **Pipet Volume** — lampiran LK-285-IDN no. 20, keluarga Fixed. */
class PipetVolumeProfile extends FixedVolumetricGlasswareProfile
{
    public function kode(): string
    {
        return 'pipet_volume';
    }

    /** PERSIS nama lampiran no. 20. */
    public function namaAlatKemampuan(): string
    {
        return 'Pipet Volume';
    }

    public function aliasNama(): array
    {
        return ['Volumetric Pipette', 'Pipet Gondok'];
    }
}
