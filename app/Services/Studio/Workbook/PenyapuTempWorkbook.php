<?php

namespace App\Services\Studio\Workbook;

use App\Models\WorkbookMaster;
use App\Services\Studio\Hitung\SumberModelHitung;
use Illuminate\Support\Facades\Storage;

/**
 * Menyapu sisa temp workbook terdekripsi yang yatim (Studio S1b, temuan S0c).
 *
 * Jalur normal `PembacaXlsmWorkbook` menghapus subfolder temp-nya sendiri di
 * `finally`, dan shutdown handler menutup jalur fatal. Yang tersisa cuma proses
 * yang DIBUNUH (kill, timeout kontainer) — ZIP polos berisi seluruh workbook,
 * termasuk sheet DATABASE, tertinggal di disk. Penyapu ini menghapus apa pun di
 * akar temp yang lebih tua dari batas umur (bawaan 60 menit), jadi impor yang
 * sedang berjalan tidak tersentuh.
 *
 * Dipanggil `studio:sapu-temp-workbook` (terjadwal tiap jam) dan di awal tiap
 * `studio:impor-workbook --xlsm`.
 */
final class PenyapuTempWorkbook
{
    /**
     * @return array{dihapus: int, gagal: int}
     */
    public function sapu(?int $umurMenit = null): array
    {
        $akar = PembacaXlsmWorkbook::akarTemp();
        $umurMenit ??= (int) config('studio.umur_sapu_temp_menit', 60);
        $batas = time() - max(0, $umurMenit) * 60;

        if (! is_dir($akar)) {
            return ['dihapus' => 0, 'gagal' => 0];
        }

        $dihapus = 0;
        $gagal = 0;

        foreach (scandir($akar) ?: [] as $nama) {
            if ($nama === '.' || $nama === '..' || $nama === '.gitignore') {
                continue;
            }

            $jalur = $akar.DIRECTORY_SEPARATOR.$nama;
            $waktu = @filemtime($jalur);

            if ($waktu === false || $waktu > $batas) {
                continue;
            }

            if (is_dir($jalur)) {
                PembacaXlsmWorkbook::hapusTemp($jalur);
                $hilang = ! is_dir($jalur);
            } else {
                $hilang = @unlink($jalur) || ! file_exists($jalur);
            }

            $hilang ? $dihapus++ : $gagal++;
        }

        return ['dihapus' => $dihapus, 'gagal' => $gagal];
    }

    /**
     * Tahap C: model hitung tersimpan (`SumberModelHitung`, terenkripsi) yang
     * tidak dipakai `$umurHari` hari (bawaan `studio.hitung.umur_cache_hari`).
     * Dibaca = disentuh, jadi workbook yang aktif dipakai tidak pernah tersapu.
     * Workbook tanpa sandi dibangun ulang otomatis dari berkas asli `.enc`;
     * workbook bersandi butuh `studio:siapkan-hitung` (sandi) sesudahnya.
     *
     * @return array{dihapus: int, gagal: int}
     */
    public function sapuCacheHitung(?int $umurHari = null): array
    {
        $umurHari ??= (int) config('studio.hitung.umur_cache_hari', 30);
        $disk = Storage::disk(SumberModelHitung::DISK);
        $batas = time() - max(0, $umurHari) * 86400;
        $dihapus = 0;
        $gagal = 0;

        foreach ($disk->files(SumberModelHitung::FOLDER) as $jalur) {
            if ($disk->lastModified($jalur) > $batas) {
                continue;
            }

            $disk->delete($jalur) ? $dihapus++ : $gagal++;
        }

        return ['dihapus' => $dihapus, 'gagal' => $gagal];
    }

    /**
     * Tahap C (tinjau C #5): folder gambar `workbook-master/{org}/{sha256}-gambar`
     * yang tidak lagi ditunjuk baris `workbook_master` mana pun di lab itu —
     * sisa `--ganti` (baris lama dihapus, sha-nya beda) atau impor yang batal
     * sesudah gambarnya tertulis. Hanya folder yang isinya lebih tua dari
     * `$umurMenit` (bawaan `studio.umur_sapu_temp_menit`): gambar ditulis
     * SEBELUM barisnya, jadi impor yang sedang berjalan tidak tersentuh.
     *
     * @return array{dihapus: int, gagal: int}
     */
    public function sapuGambarYatim(?int $umurMenit = null): array
    {
        $umurMenit ??= (int) config('studio.umur_sapu_temp_menit', 60);
        $disk = Storage::disk(ImporWorkbook::DISK_BERKAS);
        $batas = time() - max(0, $umurMenit) * 60;
        $dihapus = 0;
        $gagal = 0;

        foreach ($disk->directories('workbook-master') as $folderLab) {
            $organisasiId = basename($folderLab);

            if (! ctype_digit($organisasiId)) {
                continue;
            }

            foreach ($disk->directories($folderLab) as $folder) {
                if (preg_match('/^([0-9a-f]{64})-gambar$/', basename($folder), $m) !== 1) {
                    continue;
                }

                $terbaru = 0;

                foreach ($disk->files($folder) as $berkas) {
                    $terbaru = max($terbaru, $disk->lastModified($berkas));
                }

                if ($terbaru > $batas) {
                    continue;
                }

                $dipakai = WorkbookMaster::query()
                    ->where('organization_id', (int) $organisasiId)
                    ->where('sha256', $m[1])
                    ->exists();

                if ($dipakai) {
                    continue;
                }

                $disk->deleteDirectory($folder) ? $dihapus++ : $gagal++;
            }
        }

        return ['dihapus' => $dihapus, 'gagal' => $gagal];
    }
}
