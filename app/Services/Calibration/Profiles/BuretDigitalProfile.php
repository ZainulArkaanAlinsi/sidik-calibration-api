<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\TabelStandarPistonVolume as Tabel;

/**
 * Buret Digital (piston burette) — lampiran LK-285-IDN no. 14: 1–10 mL 0,0044;
 * 10–50 mL 0,023 mL. BUKAN `BuretProfile` (buret kaca, no. 13, gravimetri
 * Cuckow) — profil itu sudah menolak nama ini lewat `namaBukanMilik()`.
 * Sub-jenis Hand Driven / Motor Driven memilih kolom `MPE_buretdigital`.
 */
class BuretDigitalProfile extends PistonVolumeProfile
{
    public const KODE = 'buret_digital';

    public function kode(): string
    {
        return self::KODE;
    }

    public function namaAlatKemampuan(): string
    {
        return 'Buret Digital';
    }

    /** @return array<int, string> */
    public function aliasNama(): array
    {
        return [
            'Digital Buret',
            'Burette Digital',
            'Digital Burette',
            'Piston Burette',
            'Buret Piston',
        ];
    }

    public function jenis(): string
    {
        return Tabel::BURET_DIGITAL;
    }

    public function pilihanSubJenis(): array
    {
        return [
            ['nilai' => Tabel::HAND_DRIVEN, 'label' => 'Hand Driven'],
            ['nilai' => Tabel::MOTOR_DRIVEN, 'label' => 'Motor Driven'],
        ];
    }
}
