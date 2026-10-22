<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use RuntimeException;

/**
 * Data acuan Autoklaf (lapis 1): CMC, tiga Temperature Calibrator dan Pressure
 * Disk Logger beserta tabel koreksinya, dibaca lewat [SumberAcuan] dari
 * `database/data/tabel-standar-autoclave.json`.
 *
 * Digenerate `docs/skrip/gen-tabel-standar-analitik.py autoclave` dari
 * `DATABASE!S5:S6` dan lembar `STANDAR KALIBRATOR` master — dulu
 * `config/autoclave.php` (bentuknya dipertahankan: `cmc`, `suhu[n].tabel`
 * {set, koreksi, u95}, `tekanan.tabel` {set, koreksi_up, u95}). Yang tetap
 * config: `resolusi_alat` (cadangan resolusi alat pelanggan).
 */
class TabelStandarAutoclave
{
    public const PAKET = 'autoclave';

    private const BERKAS = 'tabel-standar-autoclave.json';

    /** @return array{suhu: float, tekanan: float} */
    public static function cmc(): array
    {
        return self::isi()['cmc'];
    }

    /**
     * Tiga Temperature Calibrator, urut disk (disk ke-i memakai kalibrator ke-i).
     *
     * @return list<array{nama: string, serial: string, resolusi: float, tabel: list<array{set: float, koreksi: float, u95: float}>}>
     */
    public static function suhu(): array
    {
        return self::isi()['suhu'];
    }

    /** @return array{nama: string, serial: string, resolusi: float, tabel: list<array{set: float, koreksi_up: float, u95: float}>} */
    public static function tekanan(): array
    {
        return self::isi()['tekanan'];
    }

    /** @return array<string, mixed> */
    private static function isi(): array
    {
        $berkas = database_path('data/'.self::BERKAS);

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel autoklaf nggak ketemu: {$berkas}");
        }

        $isi = app(SumberAcuan::class)->isi(self::PAKET, $berkas);

        if (! isset($isi['cmc']['suhu'], $isi['cmc']['tekanan'], $isi['tekanan']['tabel'])
            || ! is_array($isi['suhu'] ?? null) || $isi['suhu'] === []) {
            throw new RuntimeException("Tabel autoklaf rusak: {$berkas}");
        }

        return $isi;
    }

    /**
     * Skema Studio Data Acuan paket `autoclave`.
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(): array
    {
        $kolom = static fn (string $kode, string $label, string $tipe, ?string $satuan, ?string $asal): array => [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'satuan' => $satuan,
            'asal' => $asal,
            'perlu_konfirmasi' => false,
        ];
        $lembar = static fn (string $kode, string $label, string $jalur, string $jenis, ?string $kunci, array $k, bool $tambah = false): array => [
            'kode' => $kode, 'label' => $label, 'jalur' => $jalur, 'jenis' => $jenis, 'kunci' => $kunci,
            'kolom' => $k, 'boleh_tambah_baris' => $tambah,
        ];
        $hasil = [
            $lembar('cmc', 'CMC (DATABASE)', 'cmc', 'objek', null, [
                $kolom('suhu', 'CMC suhu', 'desimal', '°C', 'DATABASE!S5'),
                $kolom('tekanan', 'CMC tekanan', 'desimal', 'bar', 'DATABASE!S6'),
            ]),
        ];

        foreach ([[28, 'D25', 'J25'], [42, 'D39', 'J39'], [56, 'D53', 'J53']] as $i => [$b, $sn, $res]) {
            $n = $i + 1;
            $hasil[] = $lembar("suhu_{$n}", "Temperature Calibrator {$n}", "suhu/{$i}", 'objek', null, [
                $kolom('nama', 'Nama (baris standards)', 'teks', null, null),
                $kolom('serial', 'S/N', 'teks', null, "STANDAR KALIBRATOR!{$sn}"),
                $kolom('resolusi', 'Resolusi', 'desimal', '°C', "STANDAR KALIBRATOR!{$res}"),
            ]);
            $hasil[] = $lembar("suhu_{$n}_tabel", "Temperature Calibrator {$n} — tabel koreksi", "suhu/{$i}/tabel", 'daftar', 'set', [
                $kolom('set', 'Set point', 'desimal', '°C', 'STANDAR KALIBRATOR!D'.$b.':D'.($b + 5)),
                $kolom('koreksi', 'Koreksi', 'desimal', '°C', 'STANDAR KALIBRATOR!E'.$b.':E'.($b + 5)),
                $kolom('u95', 'U95', 'desimal', '°C', 'STANDAR KALIBRATOR!F'.$b.':F'.($b + 5)),
            ]);
        }

        $hasil[] = $lembar('tekanan', 'Pressure Disk Logger', 'tekanan', 'objek', null, [
            $kolom('nama', 'Nama (baris standards)', 'teks', null, null),
            $kolom('serial', 'S/N', 'teks', null, 'STANDAR KALIBRATOR!D5'),
            $kolom('resolusi', 'Resolusi', 'desimal', 'bar', 'STANDAR KALIBRATOR!J5'),
        ]);
        $hasil[] = $lembar('tekanan_tabel', 'Pressure Disk Logger — tabel koreksi (UP)', 'tekanan/tabel', 'daftar', 'set', [
            $kolom('set', 'Set point', 'desimal', 'bar', 'STANDAR KALIBRATOR!D9:D15'),
            $kolom('koreksi_up', 'Koreksi UP', 'desimal', 'bar', 'STANDAR KALIBRATOR!E9:E15'),
            $kolom('u95', 'U95', 'desimal', 'bar', 'STANDAR KALIBRATOR!G9:G15'),
        ]);

        return ['skema_versi' => 1, 'sumber_json' => self::BERKAS, 'lembar' => $hasil];
    }
}
