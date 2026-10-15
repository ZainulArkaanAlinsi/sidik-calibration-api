<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\DefinedName;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bentuk ringkas workbook untuk mesin hitung (Studio tahap C): nilai, rumus,
 * nilai cache Excel, atribut rumus larik, status lembar, nama terdefinisi,
 * dan kalender tanggal — TANPA gaya. Hanya larik PHP skalar, jadi aman
 * di-`unserialize` dengan `allowed_classes => false` dan tidak terikat versi
 * kelas PhpSpreadsheet.
 *
 * Dibangun sekali per sha256 berkas asli (`SumberModelHitung`, saat impor atau
 * saat cache kosong), lalu tiap request `/hitung` membangun Spreadsheet ramping
 * baru darinya (`keSpreadsheet()`, ~0,5 s untuk 34 rb sel) — edit request tidak
 * pernah menyentuh model tersimpan.
 *
 * Sel: `[tipe, nilai, cache, atribut, evaluasi]` dengan `tipe` DataType
 * PhpSpreadsheet (`n`, `s`, `b`, `e`, `f`), `nilai` rumus (dengan `=`) untuk
 * `f`, `cache` nilai terakhir yang dihitung Excel (`f` saja), `atribut` hanya
 * rumus larik (`['t' => 'array', 'ref' => …]`), `evaluasi` rumus yang
 * dievaluasi bila beda dari `nilai` (`PenulisUlangPangkat`), selain itu null.
 */
final class ModelHitung
{
    /**
     * Naikkan bila bentuk larik berubah — cache lama otomatis tidak terpakai.
     * 2 (14 Okt 2026): elemen ke-5 sel = rumus evaluasi (`^` → SIDIK.PANGKAT).
     */
    public const VERSI = 2;

    /**
     * @param  list<array{judul: string, status: string, sel: array<string, array{0: string, 1: mixed, 2: mixed, 3: array<string, string>|null}>}>  $lembar
     * @param  list<array{nama: string, lembar: string|null, nilai: string, lokal: bool, cakupan: string|null}>  $nama
     */
    public function __construct(
        public readonly array $lembar,
        public readonly array $nama,
        public readonly string $kalender,
    ) {}

    public static function dariSpreadsheet(Spreadsheet $ss): self
    {
        $lembar = [];

        foreach ($ss->getAllSheets() as $ws) {
            $sel = [];
            $koleksi = $ws->getCellCollection();

            foreach ($koleksi->getCoordinates() as $alamat) {
                $cell = $koleksi->get($alamat);

                if ($cell === null) {
                    continue;
                }

                $tipe = $cell->getDataType();
                $nilai = self::polos($cell->getValue());

                if ($tipe === DataType::TYPE_FORMULA) {
                    $rumus = (string) $nilai;
                    $atribut = $cell->getFormulaAttributes();
                    $rumus = str_starts_with($rumus, '=') ? $rumus : '='.$rumus;
                    $sel[$alamat] = [
                        DataType::TYPE_FORMULA,
                        $rumus,
                        self::polos($cell->getOldCalculatedValue()),
                        is_array($atribut) && ($atribut['t'] ?? null) === 'array' ? array_map('strval', $atribut) : null,
                        PenulisUlangPangkat::tulisUlang($rumus),
                    ];

                    continue;
                }

                if ($nilai === null || $nilai === '') {
                    continue;
                }

                $sel[$alamat] = [self::tipeKonstan($tipe, $nilai), $nilai, null, null, null];
            }

            $lembar[] = ['judul' => $ws->getTitle(), 'status' => $ws->getSheetState(), 'sel' => $sel];
        }

        $nama = [];

        foreach ($ss->getDefinedNames() as $dn) {
            $nama[] = [
                'nama' => $dn->getName(),
                'lembar' => $dn->getWorksheet()?->getTitle(),
                'nilai' => $dn->getValue(),
                'lokal' => $dn->getLocalOnly(),
                'cakupan' => $dn->getScope()?->getTitle(),
            ];
        }

        return new self($lembar, $nama, (string) $ss->getExcelCalendar());
    }

    /**
     * Spreadsheet ramping baru untuk satu kali hitung. Nilai cache Excel ikut
     * dipasang (`setCalculatedValue`) — jalur cadangan PhpSpreadsheet untuk
     * rujukan workbook luar memakainya.
     *
     * `$rumusDihitung` (mode sel kotor): rumus yang TIDAK ada di daftar ini
     * dipasang sebagai KONSTAN bernilai cache Excel-nya. Setara dengan mengisi
     * cache kalkulasi, tetapi rujukan ke sel itu jadi baca nilai langsung —
     * tanpa `Cell::getCalculatedValue()` per sel yang dirujuk VLOOKUP/rentang
     * (biaya terbesar hitung kotor di workbook kalibrasi). Rumus tanpa nilai
     * cache tetap rumus. Null = semua rumus tetap rumus (mode penuh).
     *
     * @param  array<string, true>|null  $rumusDihitung  kunci `Judul!A1`
     */
    public function keSpreadsheet(?array $rumusDihitung = null): Spreadsheet
    {
        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);
        $ss->setExcelCalendar((int) $this->kalender);
        $peta = [];

        foreach ($this->lembar as $l) {
            $ws = new Worksheet($ss, $l['judul']);
            $ss->addSheet($ws);
            $ws->setSheetState($l['status']);
            $peta[$l['judul']] = $ws;

            foreach ($l['sel'] as $alamat => $s) {
                [$tipe, $nilai, $cache, $atribut] = $s;
                $cell = $ws->getCell((string) $alamat);

                if ($tipe === DataType::TYPE_FORMULA && $rumusDihitung !== null && $cache !== null
                    && ! isset($rumusDihitung[$l['judul'].'!'.$alamat])) {
                    $cell->setValueExplicit($cache, self::tipeKonstan(DataType::TYPE_STRING, $cache, true));

                    continue;
                }

                $cell->setValueExplicit($tipe === DataType::TYPE_FORMULA ? ($s[4] ?? $nilai) : $nilai, $tipe);

                if ($tipe === DataType::TYPE_FORMULA) {
                    $cell->setCalculatedValue($cache, false);

                    if ($atribut !== null) {
                        $cell->setFormulaAttributes($atribut);
                    }
                }
            }
        }

        foreach ($this->nama as $n) {
            $ss->addDefinedName(DefinedName::createInstance(
                $n['nama'],
                $n['lembar'] !== null ? ($peta[$n['lembar']] ?? null) : null,
                $n['nilai'],
                $n['lokal'],
                $n['cakupan'] !== null ? ($peta[$n['cakupan']] ?? null) : null,
            ));
        }

        if ($ss->getSheetCount() > 0) {
            $ss->setActiveSheetIndex(0);
        }

        return $ss;
    }

    /** @return array<string, mixed> */
    public function keLarik(): array
    {
        return ['versi' => self::VERSI, 'lembar' => $this->lembar, 'nama' => $this->nama, 'kalender' => $this->kalender];
    }

    /** @param  array<string, mixed>  $larik */
    public static function dariLarik(array $larik): ?self
    {
        if (($larik['versi'] ?? null) !== self::VERSI || ! is_array($larik['lembar'] ?? null) || ! is_array($larik['nama'] ?? null)) {
            return null;
        }

        return new self($larik['lembar'], $larik['nama'], (string) ($larik['kalender'] ?? '1900'));
    }

    /** Kunci sel lintas lembar, sama dengan kunci cache Calculation PhpSpreadsheet. */
    public static function kunci(string $judul, string $alamat): string
    {
        return $judul.'!'.$alamat;
    }

    /** @return array{0: string, 1: string} [judul, alamat] */
    public static function urai(string $kunci): array
    {
        $i = (int) strrpos($kunci, '!');

        return [substr($kunci, 0, $i), substr($kunci, $i + 1)];
    }

    private static function polos(mixed $v): mixed
    {
        return $v instanceof RichText ? $v->getPlainText() : $v;
    }

    private static function tipeKonstan(string $tipe, mixed $nilai, bool $kodeGalatJadiGalat = false): string
    {
        return match (true) {
            is_bool($nilai) => DataType::TYPE_BOOL,
            is_int($nilai), is_float($nilai) => DataType::TYPE_NUMERIC,
            $tipe === DataType::TYPE_ERROR => DataType::TYPE_ERROR,
            // Nilai cache rumus berupa galat (#N/A …) tetap galat saat dijadikan konstan.
            $kodeGalatJadiGalat && is_string($nilai) && in_array($nilai, ExcelError::ERROR_CODES, true) => DataType::TYPE_ERROR,
            default => DataType::TYPE_STRING,
        };
    }
}
