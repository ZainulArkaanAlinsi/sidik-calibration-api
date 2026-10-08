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

    /**
     * Desimal cetak per NOMINAL — `SERTIFIKAT!E21/N21/S21` keempat workbook
     * Rev.7 (keputusan pemilik 8 Okt 2026: angka ikut workbook 100%).
     *
     * @var array<string, int>
     */
    public const DESIMAL_PER_NOMINAL = ['0.5' => 3, '2' => 2, '3' => 3, '4' => 4];

    /**
     * Nominal di luar keempat workbook: `0.000` (PV 0,5 & PV 3, format
     * terbanyak). U95 (`Q22`) `0.0000` di keempatnya, sama dengan bawaan
     * keluarga Fixed. Pertanyaan lab volumetric no. 21.
     */
    public function desimalSertifikat(): ?int
    {
        return 3;
    }

    /**
     * PV 0,5 `0.000`, PV 2 `0.00`, PV 3 `0.000`, PV 4 `0.0000`. Hook per baris
     * yang sama dibaca sertifikat (`CertificateSnapshotBuilder::hasil()`) dan
     * layar HP (`CalibrationResource::petakanTitik()`), jadi keduanya sepakat.
     */
    public function desimalSertifikatTitik(float $titikUkur): ?int
    {
        foreach (self::DESIMAL_PER_NOMINAL as $nominal => $desimal) {
            if (abs($titikUkur - (float) $nominal) < 1e-9) {
                return $desimal;
            }
        }

        return null;
    }
}
