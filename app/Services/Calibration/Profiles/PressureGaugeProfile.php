<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarTekanan as Tabel;
use App\Support\TekananMentah as M;

/**
 * Pressure Gauge — lampiran LK-285-IDN no. 23 (0–290 psi, CMC 0,066 psi) dan
 * no. 24 (0–600 bar 0,11 bar; 0–27,5 psi 0,0048 psi), metode
 * SIDIK-IK-CAL-0504_Rev.6.
 *
 * Tiga kalibrator boleh dipakai — DRUCK07G, DRUCK13G, SPMK — dan ketiganya
 * punya workbook master sendiri. Yang memilih teknisi, per sesi, sesuai
 * rentang alatnya; CMC ikut berpindah baris lampiran mengikuti pilihannya
 * (lihat `TekananProfile::CMC_LAMPIRAN`).
 *
 * Jenis tekanan dikunci NON-VAKUM di profil ini. Alat vakum punya nama
 * lampirannya sendiri ([VacuumGaugeProfile], no. 25), dan justru nama itu yang
 * menentukan CMC-nya. Gauge compound (−1…+3 bar) belum punya jawaban di master:
 * pertanyaan lab P-12.
 */
class PressureGaugeProfile extends TekananProfile
{
    public const KODE = 'pressure_gauge';

    public function kode(): string
    {
        return self::KODE;
    }

    /** Persis baris no. 23 lampiran. */
    public function namaAlatKemampuan(): string
    {
        return 'Pressure Gauge';
    }

    /**
     * Ejaan lain yang benar-benar dipakai — termasuk nama PANJANG baris no. 24,
     * supaya alat yang ditautkan ke baris itu tetap mendarat di sini.
     *
     * Sengaja TIDAK ada kata telanjang `Pressure`: `Pressure Transmitter`
     * (no. 22, 4–20 mA) alat lain dengan master lain.
     *
     * @return array<int, string>
     */
    public function aliasNama(): array
    {
        return [
            'Pressure Gauge; Pressure Tranducer; Pressure Recorder; Pressure Safety Valve; Manometer',
            'Pressure Tranducer',
            'Pressure Transducer',
            'Pressure Recorder',
            'Pressure Safety Valve',
            'Manometer',
            'Pressure Module',
            'Pressure Indicator',
        ];
    }

    public function varianDiizinkan(): array
    {
        return [Tabel::DRUCK07G, Tabel::DRUCK13G, Tabel::SPMK];
    }

    public function jenisTekanan(): string
    {
        return M::JENIS_NON_VAKUM;
    }

    public function kodeMetodeIk(): string
    {
        return 'SIDIK-IK-CAL-0504_Rev.6';
    }
}
