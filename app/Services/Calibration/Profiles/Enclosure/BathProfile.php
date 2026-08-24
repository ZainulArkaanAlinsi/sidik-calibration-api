<?php

namespace App\Services\Calibration\Profiles\Enclosure;

/**
 * Profil kalibrasi enclosure jenis Bath. Seluruh logika di
 * [EnclosureProfileBase]; anak ini cuma nyuplai identitasnya — kode stabil &
 * ejaan `nama_alat_kemampuan` yang PERSIS cocok dengan baris CMC
 * `database/data/kemampuan-kalibrasi.json`.
 */
class BathProfile extends EnclosureProfileBase
{
    public function kode(): string
    {
        return 'bath';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Bath';
    }
}
