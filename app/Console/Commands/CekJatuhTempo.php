<?php

namespace App\Console\Commands;

use App\Services\PengingatJatuhTempo;
use Illuminate\Console\Command;

/**
 * Dijalanin scheduler tiap hari (lihat routes/console.php). Nyari alat yang udah
 * lewat / mendekati jatuh tempo, terus kirim notifikasi ke semua admin lewat
 * lonceng panel. Tanpa ini, alat yang telat kalibrasi cuma ketahuan kalau ada
 * yang kebetulan buka daftar alat.
 *
 * Ambang "mendekati" default diatur per organisasi (organization.settings) —
 * lihat PengingatJatuhTempo. `--hari` cuma buat maksa nilai yang sama ke semua
 * org (mis. sekali jalan manual dari terminal).
 */
class CekJatuhTempo extends Command
{
    protected $signature = 'alat:cek-jatuh-tempo {--hari= : Paksa ambang (hari) ke semua org; kosong = pakai setting tiap org}';

    protected $description = 'Cek alat yang lewat/mendekati jatuh tempo & kabarin admin';

    public function handle(PengingatJatuhTempo $pengingat): int
    {
        $override = $this->option('hari');
        $override = ($override === null || $override === '') ? null : (int) $override;

        $ringkasan = $pengingat->jalankan($override);

        foreach ($ringkasan as $r) {
            $this->info(
                "Org {$r['organization_id']}: {$r['overdue']} lewat, {$r['mendekati']} mendekati "
                ."(H-{$r['ambang_hari']}) → {$r['admin_dikabarin']} admin dikabarin."
            );
        }

        if ($ringkasan === []) {
            $this->info('Nggak ada alat yang perlu dikabarin.');
        }

        return self::SUCCESS;
    }
}
