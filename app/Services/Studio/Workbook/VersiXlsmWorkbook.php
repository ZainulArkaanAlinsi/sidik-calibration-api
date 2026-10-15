<?php

namespace App\Services\Studio\Workbook;

use App\Models\WorkbookMaster;

/**
 * Pasangan workbook csv → versi `.xlsm`-nya di lab yang sama (tahap A).
 *
 * Sesudah `.xlsm` diimpor, baris csv dengan nama/profil setara TETAP ada
 * (sumbernya beda, riwayatnya beda). API daftar memberi `versi_xlsm_id` supaya
 * klien bisa membuka versi xlsm bila ada — tanpa menyalin aturan pencocokan
 * ini ke klien.
 *
 * Setara bila, di organisasi yang sama:
 *  1. kunci nama sama — huruf kecil, setiap deretan selain huruf/angka jadi
 *     satu spasi (`Master_Olah_Data_Suhu___Kelembapan` =
 *     `Master Olah Data Suhu & Kelembapan`; `1_2_Master…__140-2500L_` =
 *     `1.2 Master… (140-2500L)`); atau
 *  2. `keluarga ?? kode_profil` sama (tidak null) DAN kunci yang satu adalah
 *     akhiran kunci yang lain pada batas kata, minimal dua kata
 *     (`Fixed_Volumetric_Glassware_2026` ↔
 *     `(NEW) Master Olah Data_Fixed Volumetric Glassware_2026`).
 *
 * Beberapa xlsm setara (impor ulang berkas yang isinya berubah) → yang
 * terbaru (id terbesar).
 */
final class VersiXlsmWorkbook
{
    /**
     * id workbook → id workbook xlsm yang sebaiknya dibuka. Baris xlsm
     * memetakan dirinya sendiri; baris csv tanpa pasangan tidak ada di peta.
     *
     * @return array<int, int>
     */
    public function peta(int $organisasiId): array
    {
        // Arsip (dikeluarkan dari Studio, K-49-13) tidak pernah jadi pasangan: rutenya 404.
        $semua = WorkbookMaster::query()
            ->where('organization_id', $organisasiId)
            ->whereNull('diarsipkan_pada')
            ->orderByDesc('id')
            ->get(['id', 'nama', 'sumber', 'kode_profil', 'keluarga']);

        $xlsm = $semua->where('sumber', WorkbookMaster::SUMBER_XLSM)->values();
        $peta = [];

        foreach ($xlsm as $x) {
            $peta[$x->id] = $x->id;
        }

        if ($xlsm->isEmpty()) {
            return $peta;
        }

        foreach ($semua->where('sumber', WorkbookMaster::SUMBER_CSV) as $c) {
            // `$xlsm` urut id menurun: yang pertama cocok = impor terbaru.
            $pasangan = $xlsm->first(static fn (WorkbookMaster $x): bool => self::setara($c, $x));

            if ($pasangan !== null) {
                $peta[$c->id] = $pasangan->id;
            }
        }

        return $peta;
    }

    public static function setara(WorkbookMaster $a, WorkbookMaster $b): bool
    {
        $ka = self::kunci($a->nama);
        $kb = self::kunci($b->nama);

        if ($ka === '' || $kb === '') {
            return false;
        }

        if ($ka === $kb) {
            return true;
        }

        $profilA = $a->keluarga ?? $a->kode_profil;
        $profilB = $b->keluarga ?? $b->kode_profil;

        if ($profilA === null || $profilA !== $profilB) {
            return false;
        }

        [$pendek, $panjang] = strlen($ka) <= strlen($kb) ? [$ka, $kb] : [$kb, $ka];

        return substr_count($pendek, ' ') >= 1 && str_ends_with($panjang, ' '.$pendek);
    }

    public static function kunci(string $nama): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($nama)));
    }
}
