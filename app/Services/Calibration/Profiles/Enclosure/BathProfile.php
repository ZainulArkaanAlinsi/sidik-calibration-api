<?php

namespace App\Services\Calibration\Profiles\Enclosure;

/**
 * Profil kalibrasi enclosure jenis Bath. Seluruh logika di
 * [EnclosureProfileBase]; anak ini cuma nyuplai identitasnya — kode stabil &
 * ejaan `nama_alat_kemampuan` yang PERSIS cocok dengan baris CMC
 * `database/data/kemampuan-kalibrasi.json` — dan satu aturan hitung jenisnya.
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

    /**
     * Efek radiasi dan pembebanan Bath = 0 (master Enclosure 12 Nov 2026):
     * Yokogawa `PERHITUNGAN U95%!N31 = IF($U$21="bath",0,0.6)` dan
     * `N32 = IF($U$21="bath",0,20/100*'PERHITUNGAN FC'!R42)`; Recorder
     * `DATABASE!V7:W7` (Bath 0/0). Catatan sumber sel ikut ke jejak sesi
     * (`bath_radiasi_pembebanan_nol`) lewat [EnclosureCalculator].
     */
    protected function radiasiPembebananNol(): bool
    {
        return true;
    }
}
