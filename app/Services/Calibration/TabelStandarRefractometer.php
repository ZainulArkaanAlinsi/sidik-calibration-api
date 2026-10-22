<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use RuntimeException;

/**
 * Tabel koreksi suhu Refractometer (lapis 1), dibaca lewat [SumberAcuan] dari
 * `database/data/tabel-standar-refractometer.json`.
 *
 * Digenerate `docs/skrip/gen-tabel-standar-analitik.py refractometer` dari
 * lembar `Tab Konversi Temperatur` master (B4:H116) — dulu konstanta kode
 * `RefractometerProfile::KOEF_SUHU` / `SUHU_TABEL_MIN` / `SUHU_TABEL_MAKS`.
 * Kode memodelkan tabel itu sebagai garis `deviasi = -k·(T-20)`; generator
 * mengadu ke-113 barisnya ke garis tersebut dan menolak tabel yang tidak
 * linear lagi.
 *
 * Yang TETAP kode: suhu acuan 20 °C (definisi n20D, literal di rumus
 * `G4 = F4+((B4-20)*k)`) dan resolusi cadangan 0,0001 n20D (`INPUT DATA!E16`
 * milik alat pelanggan).
 */
class TabelStandarRefractometer
{
    public const PAKET = 'refractometer';

    private const BERKAS = 'tabel-standar-refractometer.json';

    /** Nilai `koreksi_suhu.<kunci>`. */
    public static function koreksiSuhu(string $kunci): float
    {
        $isi = self::isi()['koreksi_suhu'] ?? null;

        if (! is_array($isi) || ! is_numeric($isi[$kunci] ?? null)) {
            throw new RuntimeException("Tabel refractometer tanpa angka koreksi_suhu.{$kunci}.");
        }

        return (float) $isi[$kunci];
    }

    /** @return array<string, mixed> */
    private static function isi(): array
    {
        $berkas = database_path('data/'.self::BERKAS);

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel refractometer nggak ketemu: {$berkas}");
        }

        return app(SumberAcuan::class)->isi(self::PAKET, $berkas);
    }

    /**
     * Skema Studio Data Acuan paket `refractometer` (satu lembar objek).
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(): array
    {
        $kolom = static fn (string $kode, string $label, string $satuan, string $asal): array => [
            'kode' => $kode,
            'label' => $label,
            'tipe' => 'desimal',
            'satuan' => $satuan,
            'asal' => $asal,
            'perlu_konfirmasi' => false,
        ];

        return [
            'skema_versi' => 1,
            'sumber_json' => self::BERKAS,
            'lembar' => [
                [
                    'kode' => 'koreksi_suhu', 'label' => 'Tab Konversi Temperatur (koreksi ke 20 °C)', 'jalur' => 'koreksi_suhu',
                    'jenis' => 'objek', 'kunci' => null,
                    'kolom' => [
                        $kolom('koef_n20d_per_c', 'Koefisien suhu n20D', 'n20D/°C', 'Tab Konversi Temperatur!H4'),
                        $kolom('koef_brix_per_c', 'Koefisien suhu °Brix', '°Brix/°C', 'Tab Konversi Temperatur!E4'),
                        $kolom('suhu_tabel_min_c', 'Suhu terendah tabel', '°C', 'Tab Konversi Temperatur!B4'),
                        $kolom('suhu_tabel_maks_c', 'Suhu tertinggi tabel', '°C', 'Tab Konversi Temperatur!B116'),
                    ],
                    'boleh_tambah_baris' => false,
                ],
            ],
        ];
    }
}
