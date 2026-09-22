<?php

namespace App\Services\Calibration\Profiles;

/** **Labu Ukur** — lampiran LK-285-IDN no. 18, keluarga Fixed. */
class LabuUkurProfile extends FixedVolumetricGlasswareProfile
{
    public function kode(): string
    {
        return 'labu_ukur';
    }

    /** PERSIS nama lampiran no. 18. */
    public function namaAlatKemampuan(): string
    {
        return 'Labu Ukur';
    }

    public function aliasNama(): array
    {
        return ['Volumetric Flask'];
    }
}
