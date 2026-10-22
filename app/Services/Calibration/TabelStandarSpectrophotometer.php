<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use RuntimeException;

/**
 * Tabel celah Spectral Bandwidth Spectrophotometer (lapis 1), dibaca lewat
 * [SumberAcuan] dari `database/data/tabel-standar-spectrophotometer.json`.
 *
 * Digenerate `docs/skrip/gen-tabel-standar-analitik.py spectrophotometer` dari
 * `PERHITUNGAN U95%!N10:O27` master (18 pasangan [nilai acuan, nilai terbaca]
 * dalam nm; sumber tercetak di `N31`: SNSU PK.F-01:2020 Panduan Kalibrasi
 * Spektrofotometer UV-Vis) — dulu konstanta kode
 * `SpectrophotometerCalculator::GAP_SPECTRAL_BANDWIDTH`. Disimpan sebagai
 * PASANGAN, bukan rata-ratanya, supaya angka budget tetap tertelusur ke baris.
 */
class TabelStandarSpectrophotometer
{
    public const PAKET = 'spectrophotometer';

    private const BERKAS = 'tabel-standar-spectrophotometer.json';

    /**
     * Pasangan `[nilai acuan, nilai terbaca]` (nm), urut baris master.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function celahSpectralBandwidth(): array
    {
        $baris = self::isi()['celah_spectral_bandwidth'] ?? null;

        if (! is_array($baris) || $baris === [] || ! array_is_list($baris)) {
            throw new RuntimeException('Tabel spectrophotometer tanpa celah_spectral_bandwidth.');
        }

        return array_map(static function (mixed $b): array {
            if (! is_array($b) || ! is_numeric($b['acuan_nm'] ?? null) || ! is_numeric($b['terbaca_nm'] ?? null)) {
                throw new RuntimeException('Tabel spectrophotometer: baris celah_spectral_bandwidth rusak.');
            }

            return [(float) $b['acuan_nm'], (float) $b['terbaca_nm']];
        }, $baris);
    }

    /** @return array<string, mixed> */
    private static function isi(): array
    {
        $berkas = database_path('data/'.self::BERKAS);

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel spectrophotometer nggak ketemu: {$berkas}");
        }

        return app(SumberAcuan::class)->isi(self::PAKET, $berkas);
    }

    /**
     * Skema Studio Data Acuan paket `spectrophotometer` (satu lembar daftar,
     * baris dikenali dari posisi — urutan = baris master).
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(): array
    {
        $kolom = static fn (string $kode, string $label, string $asal): array => [
            'kode' => $kode,
            'label' => $label,
            'tipe' => 'desimal',
            'satuan' => 'nm',
            'asal' => $asal,
            'perlu_konfirmasi' => false,
        ];

        return [
            'skema_versi' => 1,
            'sumber_json' => self::BERKAS,
            'lembar' => [
                [
                    'kode' => 'celah_spectral_bandwidth', 'label' => 'Celah Spectral Bandwidth (SNSU PK.F-01:2020)',
                    'jalur' => 'celah_spectral_bandwidth', 'jenis' => 'daftar', 'kunci' => null,
                    'kolom' => [
                        $kolom('acuan_nm', 'Nilai acuan', 'PERHITUNGAN U95%!N10:N27'),
                        $kolom('terbaca_nm', 'Nilai terbaca', 'PERHITUNGAN U95%!O10:O27'),
                    ],
                    'boleh_tambah_baris' => false,
                ],
            ],
        ];
    }
}
