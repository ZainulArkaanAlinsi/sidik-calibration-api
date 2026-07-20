<?php

namespace App\Jobs;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
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
        $sesi = CalibrationSession::with([
            'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard',
            'uncertaintyCalculations.standard',
        ])->find($this->calibrationSessionId);

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
            $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke');

            $pdf = Pdf::loadView('sertifikat.pdf', [
                'sertifikat' => $sertifikat,
                'sesi' => $sesi,
                'titik' => $titik,
                // Standar per titik (mis. buffer pH 4/7/10, beda-beda) + standar
                // sesi (mis. Termometer & Sensor Std., kondisi lingkungan) —
                // digabung & di-dedupe biar standar yang sama nggak dobel di PDF.
                'standarDipakai' => $titik->pluck('standard')->filter()
                    ->when($sesi->standard, fn ($c) => $c->push($sesi->standard))
                    ->unique('id'),
                // Nomor IK dari titik pertama yang punya CMC — semua titik dari
                // alat yang sama biasanya satu IK, jadi cukup satu buat dipajang.
                'metodeKalibrasi' => $titik->first(fn ($t) => $t->metode !== null)?->metode,
                // Embed sebagai data URI — paling aman buat dompdf (nggak
                // gantung ke path/symlink). null kalau logonya nggak ketemu.
                'logo' => $this->logoDataUri($sesi->organization),
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

    /**
     * Logo lab sebagai data URI buat kop sertifikat. Prioritas logo milik
     * organisasi (`logo_path` di disk publik, diupload admin lewat panel);
     * kalau kosong/nggak ada, pakai logo bawaan di public/images. null kalau
     * dua-duanya nggak ada — kop-nya balik ke teks doang, PDF tetap kebuat.
     */
    private function logoDataUri(?Organization $organization): ?string
    {
        $path = null;

        if ($organization?->logo_path && Storage::disk('public')->exists($organization->logo_path)) {
            $path = Storage::disk('public')->path($organization->logo_path);
        } elseif (is_file(public_path('images/logo-sidik.png'))) {
            $path = public_path('images/logo-sidik.png');
        }

        if ($path === null) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
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
