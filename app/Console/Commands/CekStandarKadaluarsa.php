<?php

namespace App\Console\Commands;

use App\Services\PengingatStandar;
use Illuminate\Console\Command;

/**
 * Dijalanin scheduler tiap pagi (lihat routes/console.php). Nyari sertifikat
 * standar acuan yang mendekati / udah kadaluarsa, terus kabarin admin.
 *
 * Kenapa ini beda urgensinya dari `alat:cek-jatuh-tempo`: standar yang habis bikin
 * `POST /calibrations` nolak `422`. Jadi kalau kelewat, teknisi berangkat ke
 * lapangan, ngisi lembar kerja, terus mentok waktu nyimpen — kerjaannya hangus.
 *
 * Ambangnya ikut setting tiap organisasi (`reminder_hari_sebelum`, default 30),
 * angka yang SAMA dengan badge `warning` di `/standards`. `--hari` cuma buat maksa
 * nilai yang sama ke semua org waktu jalan manual dari terminal.
 */
class CekStandarKadaluarsa extends Command
{
    protected $signature = 'standar:cek-kadaluarsa {--hari= : Paksa ambang (hari) ke semua org; kosong = pakai setting tiap org}';

    protected $description = 'Cek sertifikat standar acuan yang mendekati/udah kadaluarsa & kabarin admin';

    public function handle(PengingatStandar $pengingat): int
    {
        $override = $this->option('hari');
        $override = ($override === null || $override === '') ? null : (int) $override;

        $ringkasan = $pengingat->jalankan($override);

        foreach ($ringkasan as $r) {
            $this->info(
                "Org {$r['organization_id']}: {$r['kadaluarsa']} kadaluarsa, {$r['mendekati']} mendekati "
                ."(H-{$r['ambang_hari']}) → {$r['admin_dikabarin']} admin dikabarin, "
                ."{$r['admin_dilewat']} dilewat (isinya sama & masih masa tenang)."
            );
        }

        if ($ringkasan === []) {
            $this->info('Nggak ada standar acuan yang perlu dikabarin.');
        }

        return self::SUCCESS;
    }
}
