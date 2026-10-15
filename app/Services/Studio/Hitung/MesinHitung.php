<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Mesin hitung workbook Studio (tahap C) — PhpSpreadsheet 5.10 dengan empat
 * penyetelan yang dibuktikan di `C-anak-timbangan/calculation-architecture.md`:
 *
 *  1. HANYA SEL KOTOR dihitung (`GrafDependensi::kotor`); semua rumus lain
 *     diisi ke cache kalkulasi PhpSpreadsheet dengan nilai cache Excel, jadi
 *     rumus kotor yang merujuknya membaca angka Excel apa adanya. Mode
 *     `penuh` (harness paritas & test) menghitung SEMUA rumus tanpa cache.
 *  2. Branch pruning ON; sel yang gagal "internal error" (IF bersarang,
 *     temuan orkestrator AA34/AA35) dihitung ulang sekali dengan pruning OFF.
 *  3. STDEV/TINV/NOW/TODAY lewat `FungsiExcel` (dipasang & dilepas di sini),
 *     bersama pagar argumen skalar `BatasFungsiExcel` (batas Excel di runtime
 *     fungsi, juga untuk rumus asli yang menerima nilai sunting).
 *  4. Batas waktu kooperatif: diperiksa sebelum tiap sel DAN tiap kali sel/rentang
 *     dibaca di dalam evaluasi (`KalkulasiBerpagar`) → `GagalHitung` 504.
 *  5. Rentang di atas `studio.hitung.maks_sel_rentang` tidak dirinci
 *     (`KalkulasiBerpagar`): sel itu masuk `galat`, nilai cache Excel dipakai.
 *  6. Pagar memori lunak (`PagarMemori`): diperiksa sebelum tiap sel, tiap kali
 *     sel/rentang dibaca, dan per unsur di pembungkus fungsi teks →
 *     `GagalHitung` 422 `hitung_terlalu_berat` sebelum `memory_limit` tercapai.
 *
 * Rumus yang memakai fungsi di luar daftar putih tidak pernah dievaluasi:
 * nilai cache Excel-nya dipakai dan selnya masuk `galat`.
 */
final class MesinHitung
{
    /**
     * @param  list<array{judul: string, alamat: string, isi: IsiSel}>  $edit
     * @param  float  $tenggat  `microtime(true)` batas akhir
     */
    public function hitung(ModelHitung $model, GrafDependensi $graf, array $edit, float $waktuAcuanSerial, bool $penuh, float $tenggat): HasilHitung
    {
        // Mode kotor: rumus tak kotor dipasang sebagai konstan bernilai cache Excel.
        $dihitung = null;

        if (! $penuh) {
            $selDiedit = array_map(static fn (array $e): string => ModelHitung::kunci($e['judul'], $e['alamat']), $edit);
            $rumusDiedit = array_map(
                static fn (array $e): string => ModelHitung::kunci($e['judul'], $e['alamat']),
                array_filter($edit, static fn (array $e): bool => $e['isi']->tipe === DataType::TYPE_FORMULA),
            );
            $dihitung = array_fill_keys($graf->kotor(array_values(array_unique($selDiedit)), array_values(array_unique($rumusDiedit))), true);
        }

        $ss = $model->keSpreadsheet($dihitung);
        PagarMemori::pasang();

        try {
            KalkulasiBerpagar::pasang($ss, $tenggat, (int) config('studio.hitung.maks_sel_rentang', 100000));

            return $this->jalankan($ss, $model, $graf, $edit, $waktuAcuanSerial, $penuh, $tenggat);
        } finally {
            PagarMemori::lepas();
            Calculation::getInstanceOrNull($ss)?->flushInstance();
            $ss->disconnectWorksheets();
        }
    }

    /**
     * @param  list<array{judul: string, alamat: string, isi: IsiSel}>  $edit
     */
    private function jalankan(Spreadsheet $ss, ModelHitung $model, GrafDependensi $graf, array $edit, float $waktu, bool $penuh, float $tenggat): HasilHitung
    {
        $selDiedit = [];
        $rumusDiedit = [];
        $terlarangEdit = [];

        foreach ($edit as $e) {
            $kunci = ModelHitung::kunci($e['judul'], $e['alamat']);
            $selDiedit[] = $kunci;
            $ws = $ss->getSheetByNameOrThrow($e['judul']);
            $isi = $e['isi'];

            if ($isi->tipe === IsiSel::KOSONG) {
                if ($ws->getCellCollection()->has($e['alamat'])) {
                    $ws->getCellCollection()->delete($e['alamat']);
                }

                continue;
            }

            $nilai = $isi->tipe === DataType::TYPE_FORMULA ? (PenulisUlangPangkat::tulisUlang((string) $isi->nilai) ?? $isi->nilai) : $isi->nilai;
            $ws->getCell($e['alamat'])->setValueExplicit($nilai, $isi->tipe);

            if ($isi->tipe === DataType::TYPE_FORMULA) {
                $rumusDiedit[] = $kunci;
            }
        }

        $selDiedit = array_values(array_unique($selDiedit));
        $rumusDiedit = array_values(array_unique($rumusDiedit));
        $cache = $this->cacheExcel($model);

        if ($penuh) {
            $kotor = array_values(array_unique(array_merge(
                array_values(array_diff($graf->rumus, $selDiedit)),
                $rumusDiedit,
            )));
        } else {
            $kotor = $graf->kotor($selDiedit, $rumusDiedit);
        }

        $calc = Calculation::getInstance($ss);
        $calc->setCalculationCacheEnabled(true);
        $calc->clearCalculationCache();
        $calc->setBranchPruningEnabled(true);
        $isiKotor = array_fill_keys($kotor, true);
        $isiDiedit = array_fill_keys($selDiedit, true);

        // Rumus tak kotor (dan rumus bertanda terlarang) → nilai cache Excel.
        foreach ($cache as $kunci => $nilai) {
            if ($nilai === null || isset($isiDiedit[$kunci])) {
                continue;
            }

            if (! isset($isiKotor[$kunci]) || isset($graf->terlarang[$kunci])) {
                $calc->saveValueToCache($kunci, Calculation::wrapResult($nilai));
            }
        }

        $hasil = [];
        $galat = [];
        $gagal = [];
        // Serial waktu acuan datang dalam sistem 1900; workbook kalender 1904 bergeser 1462 hari.
        FungsiExcel::pasang($model->kalender === '1904' ? $waktu - 1462.0 : $waktu, $tenggat);

        try {
            foreach ($kotor as $kunci) {
                if (microtime(true) > $tenggat) {
                    throw GagalHitung::terlaluLama();
                }

                PagarMemori::periksa();
                [$judul, $alamat] = ModelHitung::urai($kunci);

                if (isset($graf->terlarang[$kunci])) {
                    $hasil[$kunci] = $cache[$kunci] ?? null;
                    $galat[$kunci] = 'Fungsi di luar daftar putih ('.implode(', ', $graf->terlarang[$kunci]).') — nilai cache Excel dipakai.';
                    $gagal[] = $kunci;

                    continue;
                }

                $ws = $ss->getSheetByName($judul);

                if ($ws === null || ! $ws->getCellCollection()->has($alamat)) {
                    continue;
                }

                $cell = $ws->getCell($alamat);

                try {
                    $hasil[$kunci] = $cell->getCalculatedValue();
                } catch (Throwable $e) {
                    // PhpSpreadsheet membungkus pengecualian dari dalam evaluasi (bisa berlapis).
                    self::lemparBilaGagalHitung($e);
                    $besar = self::cari($e, RentangTerlaluBesar::class);

                    if ($besar !== null) {
                        $hasil[$kunci] = $this->cadangan($calc, $kunci, $cache);
                        $galat[$kunci] = 'Mesin tidak menghitung sel ini ('.$besar->getMessage().') — nilai cache Excel dipakai.';
                        $gagal[] = $kunci;

                        continue;
                    }

                    if (stripos($e->getMessage(), 'internal error') === false) {
                        $hasil[$kunci] = $this->cadangan($calc, $kunci, $cache);
                        $galat[$kunci] = 'Mesin gagal menghitung sel ini ('.self::ringkas($e->getMessage()).') — nilai cache Excel dipakai.';
                        $gagal[] = $kunci;

                        continue;
                    }

                    // PhpSpreadsheet 5.10: IF bersarang + branch pruning → "internal error".
                    $calc->setBranchPruningEnabled(false);

                    try {
                        $hasil[$kunci] = $cell->getCalculatedValue();
                    } catch (Throwable $e2) {
                        self::lemparBilaGagalHitung($e2);
                        $hasil[$kunci] = $this->cadangan($calc, $kunci, $cache);
                        $galat[$kunci] = 'Mesin gagal menghitung sel ini ('.self::ringkas($e2->getMessage()).') — nilai cache Excel dipakai.';
                        $gagal[] = $kunci;
                    } finally {
                        $calc->setBranchPruningEnabled(true);
                    }
                }
            }
        } finally {
            FungsiExcel::lepas();
        }

        // Sel yang dihitung dari nilai lama sel gagal: dilaporkan, tidak disembunyikan.
        if ($gagal !== []) {
            foreach (array_keys($graf->penutupan($gagal)) as $k) {
                if (isset($isiKotor[$k]) && ! isset($galat[$k])) {
                    $galat[$k] = 'Dihitung dari nilai lama sel yang gagal dihitung.';
                }
            }
        }

        return new HasilHitung($hasil, $galat, $kotor);
    }

    /**
     * Sel gagal: nilai cache Excel dipasang ke cache kalkulasi supaya rumus
     * kotor yang merujuknya tidak mencoba (dan gagal) lagi.
     *
     * @param  array<string, mixed>  $cache
     */
    private function cadangan(Calculation $calc, string $kunci, array $cache): mixed
    {
        $nilai = $cache[$kunci] ?? null;

        if ($nilai !== null) {
            $calc->saveValueToCache($kunci, Calculation::wrapResult($nilai));
        }

        return $nilai;
    }

    /** @return array<string, mixed> kunci rumus → nilai cache Excel */
    private function cacheExcel(ModelHitung $model): array
    {
        $hasil = [];

        foreach ($model->lembar as $l) {
            foreach ($l['sel'] as $alamat => $sel) {
                if ($sel[0] === DataType::TYPE_FORMULA) {
                    $hasil[ModelHitung::kunci($l['judul'], (string) $alamat)] = $sel[2];
                }
            }
        }

        return $hasil;
    }

    /**
     * `GagalHitung` dari dalam evaluasi (batas waktu 504, pagar memori 422)
     * tetap galat REQUEST, bukan galat sel — PhpSpreadsheet membungkusnya.
     */
    private static function lemparBilaGagalHitung(Throwable $e): void
    {
        $waktu = self::cari($e, GagalHitung::class);

        if ($waktu !== null) {
            throw $waktu;
        }
    }

    /**
     * @template T of Throwable
     *
     * @param  class-string<T>  $kelas
     * @return T|null
     */
    private static function cari(Throwable $e, string $kelas): ?Throwable
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof $kelas) {
                return $x;
            }
        }

        return null;
    }

    private static function ringkas(string $pesan): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $pesan) ?? '', 0, 160);
    }
}
