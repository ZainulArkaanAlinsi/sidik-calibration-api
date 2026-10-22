<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use RuntimeException;

/**
 * U95 sertifikat micropipette & labu ukur yang dipakai faktor pengenceran
 * Chlorine Meter dan DO Meter (lapis 1), dibaca lewat [SumberAcuan] dari
 * `database/data/tabel-standar-chlorine-meter.json` /
 * `tabel-standar-do-meter.json`.
 *
 * Kedua berkas digenerate `docs/skrip/gen-tabel-standar-pengenceran.py` dari
 * sel master (`DATABASE!W20:W21` Chlorine, `DATABASE!V17:V18` DO Meter) —
 * dulu konstanta kode di kedua profil. Satuannya mengikuti cara rumus master
 * membaca sel itu (lihat docblock generator): Chlorine menyimpan micropipette
 * dalam mL (`W20/1000`), DO Meter dalam µL (dibagi 1000 di dalam rumus).
 */
class TabelStandarPengenceran
{
    public const CHLORINE = 'chlorine_meter';

    public const DO_METER = 'do_meter';

    private const BERKAS = [
        self::CHLORINE => 'tabel-standar-chlorine-meter.json',
        self::DO_METER => 'tabel-standar-do-meter.json',
    ];

    /** Nilai kolom `pengenceran.<kunci>` paket itu. */
    public static function nilai(string $paket, string $kunci): float
    {
        $isi = self::isi($paket)['pengenceran'] ?? null;

        if (! is_array($isi) || ! is_numeric($isi[$kunci] ?? null)) {
            throw new RuntimeException("Tabel pengenceran {$paket} tanpa angka pengenceran.{$kunci}.");
        }

        return (float) $isi[$kunci];
    }

    /** @return array<string, mixed> */
    private static function isi(string $paket): array
    {
        $nama = self::BERKAS[$paket] ?? throw new RuntimeException("Paket pengenceran tak dikenal: {$paket}");
        $berkas = database_path('data/'.$nama);

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel pengenceran nggak ketemu: {$berkas}");
        }

        return app(SumberAcuan::class)->isi($paket, $berkas);
    }

    /**
     * Skema Studio Data Acuan paket `$paket` (satu lembar objek).
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(string $paket): array
    {
        $kolom = static fn (string $kode, string $label, string $tipe, ?string $satuan, ?string $asal): array => [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'satuan' => $satuan,
            'asal' => $asal,
            'perlu_konfirmasi' => false,
        ];
        $mikro = $paket === self::CHLORINE
            ? $kolom('u95_micropipette_ml', 'U95 micropipette', 'desimal', 'mL', 'DATABASE!W20')
            : $kolom('u95_micropipette_ul', 'U95 micropipette', 'desimal', 'µL', 'DATABASE!V17');
        $labu = $kolom('u95_labu_ukur_ml', 'U95 labu ukur', 'desimal', 'mL', $paket === self::CHLORINE ? 'DATABASE!W21' : 'DATABASE!V18');

        return [
            'skema_versi' => 1,
            'sumber_json' => self::BERKAS[$paket],
            'lembar' => [
                [
                    'kode' => 'pengenceran', 'label' => 'Faktor pengenceran — U95 sertifikat (k=2)', 'jalur' => 'pengenceran',
                    'jenis' => 'objek', 'kunci' => null, 'kolom' => [$mikro, $labu], 'boleh_tambah_baris' => false,
                ],
            ],
        ];
    }
}
