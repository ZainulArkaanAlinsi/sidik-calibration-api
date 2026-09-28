<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Support\TekananMentah as M;

/**
 * Vacuum Gauge — lampiran LK-285-IDN no. 25 (−24,2 … 0 inHg, CMC 0,43 inHg),
 * metode SIDIK-IK-CAL-0534_Rev.1.
 *
 * Tidak punya workbook sendiri: master DRUCK07G dan DRUCK13G sama-sama memuat
 * cabang "Vacum" (`INPUT_DATA!E6 = 1`) yang memilih CMC baris ini. Nilai
 * CMC-nya di sel master memang panjang (1,4561477 kPa; 0,2111962854… Psi) —
 * itu 0,43 inHg dikonversi, BUKAN angka hasil tempel seperti dicurigai
 * panduan eksternal.
 *
 * Catatan: master mencetak metode dari dropdown (`Z12`), dan workbook contoh
 * DRUCK07G memilih IK-0504. Lampiran menyebut IK-0534 untuk vacuum gauge; yang
 * dipakai di sini lampiran. Pertanyaan lab P-13.
 */
class VacuumGaugeProfile extends TekananProfile
{
    public const KODE = 'vacuum_gauge';

    public function kode(): string
    {
        return self::KODE;
    }

    public function namaAlatKemampuan(): string
    {
        return 'Vacuum Gauge';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'Vakum Gauge',
            'Vacuum Meter',
            'Vakum Meter',
            'Vacuometer',
        ];
    }

    /**
     * SPMK tidak ikut: tabel koreksinya mulai dari 0 bar, jadi setiap titik
     * vakum di luar rentangnya dan diblokir.
     */
    public function varianDiizinkan(): array
    {
        return [Tabel::DRUCK07G, Tabel::DRUCK13G];
    }

    public function jenisTekanan(): string
    {
        return M::JENIS_VAKUM;
    }

    public function kodeMetodeIk(): string
    {
        return 'SIDIK-IK-CAL-0534_Rev.1';
    }
}
