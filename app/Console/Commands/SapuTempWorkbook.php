<?php

namespace App\Console\Commands;

use App\Services\Studio\Workbook\PenyapuTempWorkbook;
use Illuminate\Console\Command;

/**
 * Hapus sisa temp workbook terdekripsi yang yatim (Studio S1b, temuan S0c).
 *
 *   php artisan studio:sapu-temp-workbook            # sisa > 60 menit
 *   php artisan studio:sapu-temp-workbook --umur=0   # semua (hanya bila tidak ada impor berjalan)
 *
 * Terjadwal tiap jam (routes/console.php). Yang disapu hanya isi
 * `config('studio.temp_workbook')`, (tahap C) model hitung di
 * `storage/app/private/studio-hitung` yang tidak dipakai
 * `studio.hitung.umur_cache_hari` hari, dan folder gambar workbook
 * (`workbook-master/{org}/{sha256}-gambar`) yang tidak ditunjuk baris mana
 * pun dan lebih tua dari `--umur`; nama berkas tidak dicetak.
 */
class SapuTempWorkbook extends Command
{
    protected $signature = 'studio:sapu-temp-workbook {--umur= : Umur minimum (menit) sisa yang dihapus; bawaan config studio.umur_sapu_temp_menit}';

    protected $description = 'Hapus sisa temp .xlsm terdekripsi dari impor workbook yang mati di tengah jalan';

    public function handle(PenyapuTempWorkbook $penyapu): int
    {
        $umur = $this->option('umur');

        if ($umur !== null && $umur !== '' && ! ctype_digit((string) $umur)) {
            $this->error("--umur harus bilangan menit, diberi '{$umur}'.");

            return self::FAILURE;
        }

        $hasil = $penyapu->sapu($umur === null || $umur === '' ? null : (int) $umur);

        $this->info("Temp workbook yatim: {$hasil['dihapus']} dihapus, {$hasil['gagal']} gagal.");

        // Tahap C: model hitung lama yang tidak dipakai.
        $cache = $penyapu->sapuCacheHitung();
        $this->info("Model hitung tak terpakai: {$cache['dihapus']} dihapus, {$cache['gagal']} gagal.");

        // Tahap C: folder gambar workbook yang tidak ditunjuk baris mana pun.
        $gambar = $penyapu->sapuGambarYatim($umur === null || $umur === '' ? null : (int) $umur);
        $this->info("Folder gambar yatim: {$gambar['dihapus']} dihapus, {$gambar['gagal']} gagal.");

        return $hasil['gagal'] + $cache['gagal'] + $gambar['gagal'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
