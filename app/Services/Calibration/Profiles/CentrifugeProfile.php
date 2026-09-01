<?php

namespace App\Services\Calibration\Profiles;

/**
 * Profil **Centrifuge** — lampiran akreditasi LK-285-IDN kelompok "Waktu dan
 * Frekuensi" no. 38, pita CMC 60–200 rpm → 1,5 rpm dan 200–9000 rpm → 1,6 rpm.
 *
 * Seluruh mesin hitungnya di [ProfilPutaran]. Yang membedakannya dari
 * Tachometer cuma pita CMC di atas dan judul lembarnya — sheet `PERHITUNGAN`
 * kedua workbook master identik baris demi baris.
 *
 * ## Blok 1 masternya memakai satu sel, bukan MAX
 *
 * Empat dari lima blok budget di `Master Olda Centrifuge.xlsm` cocok dengan
 * hitungan kita sampai 5·10⁻⁶. Yang meleset cuma blok 1, dan penyebabnya
 * tunggal: komponen pengulangannya menunjuk `PERHITUNGAN!G34` — SATU sel —
 * sementara sepuluh blok lain di kedua workbook memakai `MAX(...)` sebaris
 * penuh. Workbook Tachometer memakai `MAX(G34:L34)` di blok yang sama persis,
 * jadi bentuk yang benar terbukti dari master itu sendiri.
 *
 * Akibatnya kecil tapi searah: U95 blok 1 naik dari 2,617 ke 2,618 rpm.
 */
class CentrifugeProfile extends ProfilPutaran
{
    /** Batas atas pita CMC tertinggi Centrifuge di lampiran akreditasi. */
    private const BATAS_AKREDITASI_RPM = 9000.0;

    public function kode(): string
    {
        return 'centrifuge';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Centrifuge';
    }

    /**
     * @return list<string>
     */
    public function aliasNama(): array
    {
        // `Centrifuge Machine` & `Sentrifus` — ejaan yang muncul di kolom
        // `nama_alat` alat pelanggan untuk mesin yang sama.
        return ['Centrifuge Machine', 'Sentrifus'];
    }

    protected function judulLembar(): string
    {
        return 'Calibration Work Sheet - Centrifuge';
    }

    /**
     * Lampiran LK-285-IDN no. 38 berhenti di 9000 rpm — lihat
     * `ProfilPutaran::peringatanSesi()`.
     *
     * @return array{float, int}
     */
    protected function batasAkreditasi(): array
    {
        return [self::BATAS_AKREDITASI_RPM, 38];
    }
}
