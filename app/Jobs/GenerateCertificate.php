<?php

namespace App\Jobs;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Terbitin sertifikat dari sesi kalibrasi yang udah disetujui.
 *
 * Jalan di QUEUE (async) — pembuatan PDF bisa lama, jadi jangan nahan request
 * approve. Sesuai kontrak: sehabis approve, `certificate_id` boleh masih null
 * sesaat sampai job ini kelar.
 *
 * FAIL tetap diterbitin — hasil "tidak laik pakai" itu sertifikat yang sah.
 */
class GenerateCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $calibrationSessionId,
        public ?int $issuedBy = null,
    ) {}

    public function handle(): void
    {
        $sesi = CalibrationSession::with(['equipment.customer', 'organization', 'uncertaintyCalculations'])
            ->find($this->calibrationSessionId);

        if (! $sesi || $sesi->status !== CalibrationSession::STATUS_DISETUJUI) {
            return;
        }

        // Idempoten: kalau udah pernah terbit, jangan bikin dobel (job bisa
        // ke-retry, atau admin approve dua kali).
        if ($sesi->certificate()->where('status', Certificate::STATUS_TERBIT)->exists()) {
            return;
        }

        $sertifikat = DB::transaction(function () use ($sesi): Certificate {
            $nomor = $this->nomorBerikutnya($sesi->organization_id);
            $token = $this->tokenUnik();

            return $sesi->certificate()->updateOrCreate(
                ['calibration_session_id' => $sesi->id],
                [
                    'organization_id' => $sesi->organization_id,
                    'issued_by' => $this->issuedBy,
                    'nomor' => $nomor,
                    'qr_token' => $token,
                    'qr_payload' => url("/verify/{$token}"),
                    'diterbitkan_pada' => now(),
                    // Interval kalibrasi baku 1 tahun.
                    'berlaku_sampai' => now()->addYear(),
                    'status' => Certificate::STATUS_MENUNGGU_GENERATE,
                ],
            );
        });

        try {
            $pdf = Pdf::loadView('sertifikat.pdf', [
                'sertifikat' => $sertifikat,
                'sesi' => $sesi,
                'titik' => $sesi->uncertaintyCalculations->sortBy('titik_ke'),
            ]);

            $path = "certificates/{$sertifikat->qr_token}.pdf";
            Storage::disk('local')->put($path, $pdf->output());

            $sertifikat->update([
                'pdf_path' => $path,
                'status' => Certificate::STATUS_TERBIT,
            ]);
        } catch (\Throwable $e) {
            // Status `gagal` bikin tombol retry muncul di mobile, bukan diem-diem
            // ngilang. Sesi tetap `disetujui`.
            $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

            throw $e;
        }
    }

    /** Nomor sertifikat urut per organisasi per bulan: CAL/2026/07/0001. */
    private function nomorBerikutnya(int $organizationId): string
    {
        $prefix = sprintf('CAL/%s/', now()->format('Y/m'));

        $terakhir = Certificate::where('organization_id', $organizationId)
            ->where('nomor', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('nomor')
            ->value('nomor');

        $urutan = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    private function tokenUnik(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (Certificate::where('qr_token', $token)->exists());

        return $token;
    }
}
