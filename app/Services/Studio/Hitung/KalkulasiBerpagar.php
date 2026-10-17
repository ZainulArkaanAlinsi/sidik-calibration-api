<?php

namespace App\Services\Studio\Hitung;

use Closure;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Mesin kalkulasi PhpSpreadsheet 5.10 berpagar (Studio tahap C, tinjau C #1).
 *
 * `extractCellRange()` adalah pintu tunggal PhpSpreadsheet untuk MEMBACA sel
 * dari lembar: rujukan sel tunggal, rentang `A1:B5`, kolom/baris penuh, nama
 * terdefinisi, INDIRECT dan OFFSET semuanya lewat sini. Di pintu itu dipasang:
 *
 *  1. batas waktu — diperiksa setiap kali sel/rentang dibaca, jadi satu rumus
 *     berat yang membaca banyak rentang terputus DI TENGAH sel (`GagalHitung`
 *     504), bukan baru sesudah sel itu selesai;
 *  2. ukuran rentang — rentang di atas `$maksSel` ditolak SEBELUM
 *     PhpSpreadsheet merinci alamatnya (rincian `A1:XFD1048576` = 17 miliar
 *     string → PHP Fatal memori; INDIRECT bahkan MEMBUAT sel untuk tiap
 *     alamat) → `RentangTerlaluBesar`, dijadikan galat sel oleh `MesinHitung`;
 *  3. memori — `PagarMemori::periksa()` sebelum dan sesudah rentang dirinci
 *     (`GagalHitung` 422 `hitung_terlalu_berat`).
 *
 * Spreadsheet menyimpan mesinnya di properti privat tanpa setter, jadi
 * `pasang()` menukarnya lewat closure terikat — dan langsung memeriksa
 * `Calculation::getInstance()` memulangkan mesin ini, supaya perubahan struktur
 * PhpSpreadsheet saat upgrade gagal berisik, bukan diam-diam tanpa pagar.
 */
final class KalkulasiBerpagar extends Calculation
{
    private float $tenggat = INF;

    private int $maksSel = PHP_INT_MAX;

    public static function pasang(Spreadsheet $ss, float $tenggat, int $maksSel): self
    {
        $mesin = new self($ss);
        $mesin->tenggat = $tenggat;
        $mesin->maksSel = max(1, $maksSel);

        Closure::bind(function (Calculation $baru): void {
            $this->calculationEngine = $baru;
        }, $ss, Spreadsheet::class)($mesin);

        if (Calculation::getInstance($ss) !== $mesin) {
            throw new RuntimeException('Pagar mesin hitung tidak terpasang (struktur PhpSpreadsheet berubah?).');
        }

        return $mesin;
    }

    /**
     * @return mixed[]
     */
    public function extractCellRange(string &$range = 'A1', ?Worksheet $worksheet = null, bool $resetLog = true, bool $createCell = false): array
    {
        if (microtime(true) > $this->tenggat) {
            throw GagalHitung::terlaluLama();
        }

        $jumlah = self::jumlahSel($range);

        if ($jumlah > $this->maksSel) {
            throw new RentangTerlaluBesar($jumlah, $this->maksSel);
        }

        // 3. memori (`PagarMemori`): sebelum dan sesudah rentang dirinci.
        PagarMemori::periksa();
        $hasil = parent::extractCellRange($range, $worksheet, $resetLog, $createCell);
        PagarMemori::periksa();

        return $hasil;
    }

    /**
     * Jumlah sel yang akan dirinci PhpSpreadsheet untuk `$range` (blok dipisah
     * spasi/koma dijumlah; tiap blok = kotak dua ujung pertamanya, sama dengan
     * `Coordinate::extractAllCellReferencesInRange`). Ujung yang tidak terbaca
     * dihitung 1 — PhpSpreadsheet sendiri gagal merinci blok seperti itu.
     */
    public static function jumlahSel(string $range): int
    {
        if (str_contains($range, '!')) {
            [, $range] = Worksheet::extractSheetTitle($range, true, true);
        }

        $range = (string) $range;

        if (! str_contains($range, ':')) {
            return max(1, substr_count($range, ',') + substr_count(trim($range), ' ') + 1);
        }

        $total = 0;

        foreach (preg_split('/[ ,]+/', trim(str_replace('$', '', strtoupper($range)))) ?: [] as $blok) {
            $ujung = explode(':', $blok);

            if (count($ujung) < 2) {
                $total++;

                continue;
            }

            try {
                [$k1, $b1] = Coordinate::indexesFromString($ujung[0]);
                [$k2, $b2] = Coordinate::indexesFromString($ujung[1]);
            } catch (Throwable) {
                $total++;

                continue;
            }

            $total += (abs($b2 - $b1) + 1) * (abs($k2 - $k1) + 1);
        }

        return $total;
    }
}
