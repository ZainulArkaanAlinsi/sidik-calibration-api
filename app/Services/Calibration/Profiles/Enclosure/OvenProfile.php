<?php

namespace App\Services\Calibration\Profiles\Enclosure;

/**
 * Profil kalibrasi enclosure jenis Oven. Seluruh logika di
 * [EnclosureProfileBase]; anak ini cuma nyuplai identitasnya — kode stabil &
 * ejaan `nama_alat_kemampuan` yang PERSIS cocok dengan baris CMC
 * `database/data/kemampuan-kalibrasi.json`.
 */
class OvenProfile extends EnclosureProfileBase
{
    public function kode(): string
    {
        return 'oven';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Oven';
    }
}
