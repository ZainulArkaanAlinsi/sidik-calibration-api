<?php

namespace App\Services\Calibration\Profiles;

/**
 * Bentuk lembar `SERTIFIKAT` yang SAMA di kelima master Dimensi (K-50-15,
 * pemilik 18 Okt 2026): Micrometer `165`/`166`/`167`, Dial Indicator `159`,
 * Jangka Sorong `153`, Height Gauge `184`, Sieve `169`.
 *
 * Dua hal yang beda dari bentuk mayoritas (`TataLetakSertifikat::
 * MAYORITAS_MASTER`), sama di kelimanya:
 *
 * - label kondisi lingkungan `Environmental Condition` (`165!J12`, `159!J12`,
 *   `153!J11`, `184!J11`, `169!J11`), bukan `Env. Condition`;
 * - judul kolom tabel Standard used TANPA titik dua: `Name | Merk/Type |
 *   Serial Number | Traceable to SI through` (`165!B38:P38`, `159!B37:P37`,
 *   `153!B70:P70`, `184!B43:P43`, `169!B39:P39`).
 *
 * Sisanya (format sel, judul kolom, blok Note) per profil — tiap master beda.
 */
trait SertifikatDimensi
{
    /**
     * @param  array<string, mixed>  $tataLetak  bentuk bersama dari `parent::tataLetakSertifikat()`
     * @return array<string, mixed>
     */
    protected function tataLetakDimensi(array $tataLetak): array
    {
        $tataLetak['kepala'] = array_map(
            static fn (array $baris): array => array_map(
                static fn (?array $sel): ?array => $sel !== null && $sel['kunci'] === 'env_condition'
                    ? [...$sel, 'label' => 'Environmental Condition']
                    : $sel,
                $baris,
            ),
            $tataLetak['kepala'],
        );

        $tataLetak['kolom_standar_digunakan'] = [
            ['kunci' => 'name', 'label' => 'Name'],
            ['kunci' => 'merk_type', 'label' => 'Merk/Type'],
            ['kunci' => 'serial_number', 'label' => 'Serial Number'],
            ['kunci' => 'traceable_to', 'label' => 'Traceable to SI through'],
        ];

        return $tataLetak;
    }
}
