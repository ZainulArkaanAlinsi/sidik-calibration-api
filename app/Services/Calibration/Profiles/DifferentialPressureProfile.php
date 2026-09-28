<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Support\TekananMentah as M;

/**
 * Differential Pressure — lampiran LK-285-IDN no. 26 (−10 … 10 mbar, CMC
 * 0,0093 mbar), metode SIDIK-IK-CAL-0532_Rev.0, kalibrator Additel
 * ADT681-05-DP5-MBAR.
 *
 * Dua hal yang beda dari saudaranya, dan dua-duanya disalin dari sel:
 *
 * - SATU kolom koreksi standar (`Tabel_u95_additel` kolom 2) dipakai arah UP
 *   dan DOWN sekaligus (`AF = M+AD+AE`, `AG = N+AD+AE`, `AE` kosong).
 * - U95 kalibrator dibaca di set point INDEKS TERBESAR (`VLOOKUP(AC38, …, 3)`),
 *   bukan MAX per titik.
 *
 * Tabel kalibratornya punya set point `0` DUA KALI (baris 8 dan 19). Pencarian
 * indeks terdekat mengambil yang pertama — `TabelStandarTekanan::indeksTerdekat`.
 */
class DifferentialPressureProfile extends TekananProfile
{
    public const KODE = 'differential_pressure';

    public function kode(): string
    {
        return self::KODE;
    }

    public function namaAlatKemampuan(): string
    {
        return 'Differential Pressure';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            // Sengaja TANPA "Differential Pressure Transmitter": transmitter
            // dibaca dalam mA, lampiran no. 22, master lain.
            'Differential Pressure Gauge',
            'Diff. Pressure Gauge',
            'Magnehelic',
        ];
    }

    public function varianDiizinkan(): array
    {
        return [Tabel::DIFFERENTIAL];
    }

    public function jenisTekanan(): string
    {
        return M::JENIS_NON_VAKUM;
    }

    public function kodeMetodeIk(): string
    {
        return 'SIDIK-IK-CAL-0532_Rev.0';
    }
}
