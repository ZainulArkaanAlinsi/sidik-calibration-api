<?php

namespace App\Console\Commands;

use App\Support\TriggerAppendOnlyAcuan;
use Illuminate\Console\Command;

/**
 * Pasang ulang trigger append-only `paket_acuan_log` & `simulasi_acuan` (§54)
 * yang belum ada. Idempoten: yang sudah ada tidak disentuh.
 *
 *   php artisan acuan:pasang-trigger
 *
 * Gunanya: migrasi `2026_10_22_120000_…` tidak gagal bila MySQL menolak
 * `CREATE TRIGGER` (1419: binary log tanpa SUPER /
 * `log_bin_trust_function_creators`). Sesudah setelan server dibetulkan,
 * perintah ini memasangnya tanpa menyentuh tabel migrasi. Dipanggil juga di
 * `docker/entrypoint.sh` tiap boot (`|| true`).
 *
 * Pulang FAILURE bila sesudahnya belum keempatnya terpasang.
 */
class PasangTriggerAcuan extends Command
{
    protected $signature = 'acuan:pasang-trigger';

    protected $description = 'Pasang trigger append-only log & simulasi data acuan yang belum ada (idempoten)';

    public function handle(): int
    {
        foreach (TriggerAppendOnlyAcuan::pasang() as $nama => $status) {
            $this->line("{$nama}: {$status}");
        }

        if (TriggerAppendOnlyAcuan::terpasang()) {
            $this->info('Append-only DB: keempat trigger terpasang.');

            return self::SUCCESS;
        }

        $this->error('PERINGATAN: append-only DB BELUM lengkap. Penjaga model tetap aktif; update/delete massal '
            .'tidak tertahan DB. MySQL: setel log_bin_trust_function_creators=1 (atau beri SUPER), lalu jalankan ulang.');

        return self::FAILURE;
    }
}
