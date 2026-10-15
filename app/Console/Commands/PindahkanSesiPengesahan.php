<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CalibrationSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pindahkan sesi `menunggu_pengesahan` ke `menunggu_approval` (K-50-07).
 *
 * Pengesahan dicabut 15 Okt 2026: `approve()` menerbitkan langsung dan tidak ada
 * lagi rute `sahkan`. Sesi yang telanjur antre di `menunggu_pengesahan` tidak
 * boleh tertinggal tanpa pintu keluar — mereka kembali ke antrean approval, dan
 * Master Data menyetujuinya dengan alur baru (No. Sertifikat + Order Number).
 *
 * Default DRY-RUN: hanya menampilkan jumlah per organisasi & nomor sesi. Ubah
 * data hanya dengan `--jalankan`, dan itu perintah terpisah dari dry-run
 * (docs/aturan-akses-database.md butir 4). Tiap sesi yang dipindah mencatat satu
 * baris `audit_logs` (status lama → baru + alasan); kolom pengajuan lama
 * (`diajukan_*`, `penandatangan_user_id`) dibiarkan sebagai riwayat.
 */
class PindahkanSesiPengesahan extends Command
{
    protected $signature = 'kalibrasi:pindahkan-pengesahan
        {--jalankan : Tulis perubahan. Tanpa flag ini hanya dry-run}';

    protected $description = 'Pindahkan sesi menunggu_pengesahan ke menunggu_approval (pengesahan dicabut, K-50-07)';

    public function handle(): int
    {
        $sesi = CalibrationSession::query()
            ->where('status', CalibrationSession::STATUS_MENUNGGU_PENGESAHAN)
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'nomor_sesi']);

        if ($sesi->isEmpty()) {
            $this->info('Tidak ada sesi berstatus menunggu_pengesahan.');

            return self::SUCCESS;
        }

        $this->table(
            ['organization_id', 'jumlah', 'nomor sesi'],
            $sesi->groupBy('organization_id')->map(fn ($g, $org): array => [
                $org, $g->count(), $g->pluck('nomor_sesi')->take(20)->implode(', '),
            ])->values()->all(),
        );

        if (! $this->option('jalankan')) {
            $this->warn("DRY-RUN: {$sesi->count()} sesi akan dipindah ke menunggu_approval. Jalankan ulang dengan --jalankan.");

            return self::SUCCESS;
        }

        $dipindah = 0;

        foreach ($sesi as $baris) {
            DB::transaction(function () use ($baris, &$dipindah): void {
                $terkini = CalibrationSession::whereKey($baris->id)->lockForUpdate()->first();

                if ($terkini?->status !== CalibrationSession::STATUS_MENUNGGU_PENGESAHAN) {
                    return;
                }

                CalibrationSession::whereKey($terkini->id)->update([
                    'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                    'updated_at' => now(),
                ]);

                $terkini->catatAudit(
                    AuditLog::ACTION_DIUBAH,
                    ['status' => CalibrationSession::STATUS_MENUNGGU_PENGESAHAN],
                    ['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL],
                    'Pengesahan dicabut (K-50-07): sesi dikembalikan ke antrean approval.',
                );

                $dipindah++;
            });
        }

        $this->info("{$dipindah} sesi dipindah ke menunggu_approval.");

        return self::SUCCESS;
    }
}
