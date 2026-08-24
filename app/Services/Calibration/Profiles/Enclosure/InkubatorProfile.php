<?php

namespace App\Services\Calibration\Profiles\Enclosure;

/**
 * Profil kalibrasi enclosure jenis Inkubator. Seluruh logika di
 * [EnclosureProfileBase]; anak ini cuma nyuplai identitasnya — kode stabil &
 * ejaan `nama_alat_kemampuan` yang PERSIS cocok dengan baris CMC
 * `database/data/kemampuan-kalibrasi.json`.
 */
class InkubatorProfile extends EnclosureProfileBase
{
    public function kode(): string
    {
        return 'inkubator';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Inkubator';
    }

    /**
     * Lampiran akreditasi no. 8 nulis "Inkubator" (Indonesia, lihat
     * [namaAlatKemampuan]); merk alatnya sendiri hampir selalu nulis
     * "Incubator". Cuma jenis enclosure ini yang ejaannya bercabang — Oven,
     * Furnace, Bath, & Refrigerator ejaannya tunggal, jadi keempatnya nggak
     * override [aliasNama].
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Incubator'];
    }
}
