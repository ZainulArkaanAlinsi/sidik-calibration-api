<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use RuntimeException;

/**
 * Angka tabel Brookfield Viscometer (lapis 1), dibaca lewat [SumberAcuan] dari
 * `database/data/tabel-standar-viscometer.json`.
 *
 * Digenerate `docs/skrip/gen-tabel-standar-analitik.py viscometer` dari lembar
 * `MPE Visco` master: Tabel D-2 TK (`D6:D17`) dan Tabel D-1 SMC (`D22:D84`) —
 * dulu angka `tk`/`smc` di `ViscometerProfile::TABEL_TK`/`TABEL_SMC`. Hanya
 * ANGKA, sebagai daftar urut baris master; label model/spindle & grup tetap
 * kode (lapis 3) dan dipasangkan per posisi oleh profil.
 */
class TabelStandarViscometer
{
    public const PAKET = 'viscometer';

    private const BERKAS = 'tabel-standar-viscometer.json';

    /** @return list<float> TK urut baris `MPE Visco!D6:D17` */
    public static function tk(): array
    {
        return self::daftar('tk');
    }

    /** @return list<float> SMC urut baris `MPE Visco!D22:D84` */
    public static function smc(): array
    {
        return self::daftar('smc');
    }

    /** @return list<float> */
    private static function daftar(string $kunci): array
    {
        $daftar = self::isi()['brookfield'][$kunci] ?? null;

        if (! is_array($daftar) || $daftar === [] || ! array_is_list($daftar)
            || array_filter($daftar, static fn (mixed $x): bool => ! is_int($x) && ! is_float($x)) !== []) {
            throw new RuntimeException("Tabel viscometer tanpa daftar angka brookfield.{$kunci}.");
        }

        return array_map('floatval', $daftar);
    }

    /** @return array<string, mixed> */
    private static function isi(): array
    {
        $berkas = database_path('data/'.self::BERKAS);

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel viscometer nggak ketemu: {$berkas}");
        }

        return app(SumberAcuan::class)->isi(self::PAKET, $berkas);
    }

    /**
     * Skema Studio Data Acuan paket `viscometer` (satu lembar objek, dua
     * daftar angka urut baris).
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(): array
    {
        $kolom = static fn (string $kode, string $label, string $asal): array => [
            'kode' => $kode,
            'label' => $label,
            'tipe' => 'daftar_angka',
            'satuan' => null,
            'asal' => $asal,
            'perlu_konfirmasi' => false,
        ];

        return [
            'skema_versi' => 1,
            'sumber_json' => self::BERKAS,
            'lembar' => [
                [
                    'kode' => 'brookfield', 'label' => 'Tabel Brookfield (M13-167-B0614) — angka urut baris',
                    'jalur' => 'brookfield', 'jenis' => 'objek', 'kunci' => null,
                    'kolom' => [
                        $kolom('tk', 'TK (Torque Constant), Tabel D-2', 'MPE Visco!D6:D17'),
                        $kolom('smc', 'SMC (Spindle Multiplier Constant), Tabel D-1', 'MPE Visco!D22:D84'),
                    ],
                    'boleh_tambah_baris' => false,
                ],
            ],
        ];
    }
}
