<?php

namespace App\Services\Calibration\Profiles;

/** **Pipet Ukur** — lampiran LK-285-IDN no. 19, keluarga Graduated. */
class PipetUkurProfile extends GraduatedVolumetricGlasswareProfile
{
    public function kode(): string
    {
        return 'pipet_ukur';
    }

    /** PERSIS nama lampiran no. 19. */
    public function namaAlatKemampuan(): string
    {
        return 'Pipet Ukur';
    }

    public function aliasNama(): array
    {
        return ['Graduated Pipette', 'Measuring Pipette'];
    }
}
