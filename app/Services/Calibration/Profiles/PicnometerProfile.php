<?php

namespace App\Services\Calibration\Profiles;

/**
 * **Picnometer** — lampiran LK-285-IDN no. 21, keluarga Fixed.
 *
 * Keluarganya DUGAAN pengembang: piknometer bertanda satu seperti labu ukur,
 * tapi tidak satu pun workbook menyebutnya. Pertanyaan lab no. 6.
 */
class PicnometerProfile extends FixedVolumetricGlasswareProfile
{
    public function kode(): string
    {
        return 'picnometer';
    }

    /** PERSIS nama lampiran no. 21. */
    public function namaAlatKemampuan(): string
    {
        return 'Picnometer';
    }

    public function aliasNama(): array
    {
        return ['Piknometer', 'Pycnometer'];
    }
}
