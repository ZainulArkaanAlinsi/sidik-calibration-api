<?php

namespace App\Services\Calibration\Profiles;

/**
 * **Labu Ukur** — lampiran LK-285-IDN no. 18, keluarga Fixed.
 *
 * Mengikuti workbook lab **Rev.7** (LU-200/250/500-1, `SIDIK-IK-CAL-0510_Rev.7`)
 * — keputusan pemilik 8 Okt 2026, diadu di `tests/Unit/VolumetrikRev7WorkbookTest.php`.
 */
class LabuUkurProfile extends FixedVolumetricGlasswareRev7Profile
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

    /** `SERTIFIKAT!E21/N21/S21` workbook Rev.7 Labu Ukur: format `0.00`. */
    public function desimalSertifikat(): ?int
    {
        return 2;
    }

    /** `SERTIFIKAT!Q22` workbook Rev.7 Labu Ukur: format `0.000`. */
    public function desimalU95(): ?int
    {
        return 3;
    }
}
