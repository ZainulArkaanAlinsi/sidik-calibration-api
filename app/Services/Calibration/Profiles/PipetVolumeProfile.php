<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\VolumetricGlasswareCalculator as V;

/**
 * **Pipet Volume** — lampiran LK-285-IDN no. 20, keluarga Fixed.
 *
 * Mengikuti workbook lab **Rev.7** (berkas "Pipet Ukur PV 0,5/2/3/4" yang
 * sebenarnya Pipet Volume: `INPUT DATA!E6 = 5`, CMC `CMC_pipetvolume`) —
 * keputusan pemilik 8 Okt 2026, diadu di `tests/Unit/VolumetrikRev7WorkbookTest.php`.
 */
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

    /**
     * `SERTIFIKAT!E21/N21/S21` workbook Rev.7 PV 0,5 & PV 3: format `0.000`.
     * Formatnya TIDAK seragam antar workbook (PV 2 `0.00`, PV 4 `0.0000`) —
     * pertanyaan lab volumetric no. 21. U95 (`Q22`) `0.0000` di keempatnya,
     * sama dengan bawaan keluarga Fixed.
     */
    public function desimalSertifikat(): ?int
    {
        return 3;
    }

    protected function parameterHitung(): array
    {
        return V::parameterRev7();
    }
}
