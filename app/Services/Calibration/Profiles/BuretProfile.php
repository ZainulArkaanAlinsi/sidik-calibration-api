<?php

namespace App\Services\Calibration\Profiles;

/**
 * **Buret** — lampiran LK-285-IDN no. 13, keluarga Graduated.
 *
 * `Buret Digital` (no. 14) alat LAIN — metode `SIDIK-IK-CAL-0522`, belum
 * punya profil — dan namanya memuat "buret". [namaBukanMilik] yang menahannya
 * supaya tidak mendarat di lembar gravimetri buret kaca.
 */
class BuretProfile extends GraduatedVolumetricGlasswareProfile
{
    public function kode(): string
    {
        return 'buret';
    }

    /** PERSIS nama lampiran no. 13. */
    public function namaAlatKemampuan(): string
    {
        return 'Buret';
    }

    public function aliasNama(): array
    {
        return ['Burette'];
    }

    public function namaBukanMilik(): array
    {
        return ['Buret Digital', 'Digital Buret', 'Burette Digital', 'Digital Burette'];
    }
}
