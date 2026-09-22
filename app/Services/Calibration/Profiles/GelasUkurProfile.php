<?php

namespace App\Services\Calibration\Profiles;

/** **Gelas Ukur** — lampiran LK-285-IDN no. 17, keluarga Graduated. */
class GelasUkurProfile extends GraduatedVolumetricGlasswareProfile
{
    public function kode(): string
    {
        return 'gelas_ukur';
    }

    /** PERSIS nama lampiran no. 17. */
    public function namaAlatKemampuan(): string
    {
        return 'Gelas Ukur';
    }

    public function aliasNama(): array
    {
        return ['Measuring Cylinder', 'Graduated Cylinder'];
    }
}
