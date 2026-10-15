<?php

namespace App\Console\Commands;

use App\Models\WorkbookMaster;
use App\Services\Studio\Hitung\GagalHitung;
use App\Services\Studio\Hitung\SumberModelHitung;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bangun ulang model hitung `/hitung` (Studio tahap C) dari berkas asli yang
 * tersimpan — dipakai bila cache-nya tersapu atau kunci aplikasi berganti.
 *
 *   php artisan studio:siapkan-hitung 12 15        # id workbook
 *   php artisan studio:siapkan-hitung --semua      # semua workbook xlsm/xlsx
 *
 * Berkas tanpa sandi (`.enc`) tidak butuh apa-apa. Berkas bersandi pemilik
 * meminta sandi SEKALI (prompt tersembunyi, tidak dicetak/dilog/disimpan),
 * dipakai untuk semua berkas bersandi di daftar.
 */
class SiapkanHitungStudio extends Command
{
    protected $signature = 'studio:siapkan-hitung
        {workbook?* : Id workbook}
        {--semua : Semua workbook bersumber xlsm/xlsx}';

    protected $description = 'Bangun ulang model hitung Lembar Hitung Studio dari berkas asli tersimpan';

    public function handle(SumberModelHitung $sumber): int
    {
        ini_set('memory_limit', '1500M');
        $query = WorkbookMaster::query()->where('sumber', WorkbookMaster::SUMBER_XLSM)->orderBy('id');
        $id = array_map('intval', (array) $this->argument('workbook'));

        if (! $this->option('semua')) {
            if ($id === []) {
                $this->error('Sebutkan id workbook, atau --semua.');

                return self::FAILURE;
            }

            $query->whereIn('id', $id);
        }

        $daftar = $query->get();
        $perluSandi = $daftar->contains(static fn (WorkbookMaster $w): bool => $w->berkasAsliTersimpan()
            && ! str_ends_with((string) $w->berkas_path, '.enc')
            && Storage::disk((string) $w->berkas_disk)->exists((string) $w->berkas_path)
            && PembacaXlsmWorkbook::bersandi(Storage::disk((string) $w->berkas_disk)->path((string) $w->berkas_path)));
        $sandi = $perluSandi ? (string) $this->secret('Sandi workbook (tidak ditampilkan, tidak disimpan)') : null;
        $gagal = 0;

        try {
            foreach ($daftar as $w) {
                try {
                    [$model, $graf] = $sumber->siapkan($w, $sandi);
                    $this->line("#{$w->id}: siap — ".count($graf->rumus).' rumus, '.count($model->lembar).' lembar.');
                } catch (GagalHitung $e) {
                    $gagal++;
                    $this->error("#{$w->id}: {$e->kode}");
                } catch (GagalBacaWorkbook $e) {
                    $gagal++;
                    $this->error("#{$w->id}: {$e->kode}");
                }
            }
        } finally {
            unset($sandi);
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
