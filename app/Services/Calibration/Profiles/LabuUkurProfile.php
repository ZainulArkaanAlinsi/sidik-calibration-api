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

    // Desimal cetak (HP, sertifikat, peringatan "angka cetak bergeser") ikut
    // master Fixed Studio 143 (K-50-15, pemilik 21 Okt 2026: master menang):
    // `N21`/`S21` & `Q22` `0.0000` = bawaan keluarga (4 / 4). Format `0.00` /
    // `0.000` salinan workbook LU-200/250/500 tidak dipakai lagi.
}
