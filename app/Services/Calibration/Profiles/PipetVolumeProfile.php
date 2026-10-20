<?php

namespace App\Services\Calibration\Profiles;

/**
 * **Pipet Volume** — lampiran LK-285-IDN no. 20, keluarga Fixed.
 *
 * Mengikuti workbook lab **Rev.7** (berkas "Pipet Ukur PV 0,5/2/3/4" yang
 * sebenarnya Pipet Volume: `INPUT DATA!E6 = 5`, CMC `CMC_pipetvolume`) —
 * keputusan pemilik 8 Okt 2026, diadu di `tests/Unit/VolumetrikRev7WorkbookTest.php`.
 */
class PipetVolumeProfile extends FixedVolumetricGlasswareRev7Profile
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

    // Desimal cetak (HP, sertifikat, peringatan "angka cetak bergeser") ikut
    // master Fixed Studio 143 (K-50-15, pemilik 21 Okt 2026: master menang):
    // `N21`/`S21` & `Q22` `0.0000` = bawaan keluarga (4 / 4), untuk SEMUA
    // nominal. Format per nominal salinan workbook PV 0,5/2/3/4 (3/2/3/4)
    // tidak dipakai lagi.
}
