<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Isi edit satu sel, ditafsirkan seperti mengetik di Excel (kontrak
 * `/hitung`, docs/kontrak-api.md §18):
 *
 *  - `""` → kosongkan sel;
 *  - diawali `=` → rumus (sintaks Inggris, pemisah argumen koma);
 *  - diawali `'` → teks apa adanya tanpa apostrof (seperti Excel);
 *  - angka bertitik desimal (`12.5`, `-3`, `1e-3`, `.5`) → angka;
 *    akhiran `%` → dibagi 100;
 *  - `TRUE` / `FALSE` (huruf apa pun) → boolean;
 *  - selain itu → teks.
 *
 * Tidak menebak tanggal atau pemisah ribuan lokal: "14/10/2026" dan "1,5"
 * tetap teks — klien yang ingin angka mengirim titik desimal.
 */
final class IsiSel
{
    public const KOSONG = 'kosong';

    private const POLA_ANGKA = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/';

    private function __construct(
        public readonly string $tipe,
        public readonly mixed $nilai,
    ) {}

    public static function dariKetikan(string $isi): self
    {
        if ($isi === '') {
            return new self(self::KOSONG, null);
        }

        if (str_starts_with($isi, '=')) {
            return new self(DataType::TYPE_FORMULA, $isi);
        }

        if (str_starts_with($isi, "'")) {
            return new self(DataType::TYPE_STRING, substr($isi, 1));
        }

        $polos = trim($isi);

        if (preg_match(self::POLA_ANGKA, $polos) === 1) {
            $angka = (float) $polos;

            if (is_finite($angka)) {
                return new self(DataType::TYPE_NUMERIC, $angka);
            }
        }

        if (str_ends_with($polos, '%') && preg_match(self::POLA_ANGKA, substr($polos, 0, -1)) === 1) {
            return new self(DataType::TYPE_NUMERIC, (float) substr($polos, 0, -1) / 100);
        }

        if (strcasecmp($polos, 'TRUE') === 0) {
            return new self(DataType::TYPE_BOOL, true);
        }

        if (strcasecmp($polos, 'FALSE') === 0) {
            return new self(DataType::TYPE_BOOL, false);
        }

        return new self(DataType::TYPE_STRING, $isi);
    }
}
