<?php

namespace App\Jobs;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Support\KirimNotifikasi;
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
            'uncertaintyCalculations.standard', 'rawMeasurements',
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
            $pdf = Pdf::loadView('sertifikat.pdf', self::dataView(
                $sertifikat,
                $sesi,
                // Embed sebagai data URI — paling aman buat dompdf (nggak
                // gantung ke path/symlink). null kalau logonya nggak ketemu.
                $this->logoDataUri($sesi->organization),
            ));

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

            // Admin dikabarin — sebelumnya kegagalan ini cuma ketahuan kalau ada
            // yang kebetulan buka layar sertifikatnya. Sesi udah disetujui tapi
            // pelanggan nggak pernah dapat PDF-nya, dan nggak ada yang sadar.
            KirimNotifikasi::keAdmin(
                $sesi->organization_id,
                'sertifikat.gagal',
                "Sertifikat {$sertifikat->nomor} gagal dibuat",
                'Sesi '.$sesi->nomor_sesi.' · coba terbitkan ulang dari layar sertifikat.',
                ['jenis' => 'sertifikat', 'id' => $sertifikat->id],
            );

            throw $e;
        }
    }

    /**
     * Semua variabel yang dibutuhin `sertifikat.pdf`, dibikin DI SATU TEMPAT.
     *
     * Dipublikin karena seeder data asli juga nge-render view yang sama. Dulu
     * seeder nyalin isi method ini; begitu view dikasih variabel baru
     * (`standarDipakai`, `metodeKalibrasi`), salinannya ketinggalan dan
     * seeder-nya mati dengan "Undefined variable" — sementara jalur produksi
     * kelihatan sehat. Satu sumber begini bikin nyimpang kayak gitu mustahil.
     *
     * @return array<string, mixed>
     */
    public static function dataView(Certificate $sertifikat, CalibrationSession $sesi, ?string $logo): array
    {
        $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke');

        return [
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
            // Tabel pembacaan Before/After adjustment (worksheet pH). Kosong buat
            // alat yang cuma nyimpen satu tahap tanpa rincian pembacaan.
            'bacaSebelum' => self::ringkasPembacaan($sesi, 'sebelum_adjustment', $titik),
            'bacaSesudah' => self::ringkasPembacaan($sesi, 'sesudah_adjustment', $titik),
            // Kondisi lingkungan rinci ada kalau awal/akhir keisi (worksheet pH).
            'adaLingkungan' => $sesi->suhu_ruang_awal !== null || $sesi->kelembaban_awal !== null,
            'logo' => $logo,
        ];
    }

    /**
     * Ringkas pembacaan mentah satu tahap (sebelum/sesudah adjustment) jadi baris
     * per titik: pembacaan tiap pengulangan + suhunya, rata-rata, koreksi, STDEV.
     * Buat tahap sesudah, dijoin sama hasil hitung ketidakpastiannya (U95%, k,
     * keputusan). Balik koleksi kosong kalau tahap itu nggak punya pembacaan.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\UncertaintyCalculation>  $titikCalc
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private static function ringkasPembacaan(CalibrationSession $sesi, string $tahap, $titikCalc): \Illuminate\Support\Collection
    {
        if (! $sesi->relationLoaded('rawMeasurements')) {
            return collect();
        }

        return $sesi->rawMeasurements
            ->where('tahap', $tahap)
            ->groupBy('titik_ke')
            ->map(function ($rows) use ($titikCalc, $tahap): array {
                $rows = $rows->sortBy('pembacaan_ke')->values();
                $nilai = $rows->pluck('pembacaan')->map(fn ($v): float => (float) $v)->values();
                $n = $nilai->count();
                $rata = $n > 0 ? $nilai->sum() / $n : 0.0;
                $titikUkur = (float) $rows->first()->titik_ukur;
                $stdev = $n > 1
                    ? sqrt($nilai->map(fn (float $x): float => ($x - $rata) ** 2)->sum() / ($n - 1))
                    : 0.0;
                $suhu = $rows->pluck('suhu')->filter(fn ($v): bool => $v !== null);

                $calc = $tahap === 'sesudah_adjustment'
                    ? $titikCalc->firstWhere('titik_ke', (int) $rows->first()->titik_ke)
                    : null;

                return [
                    'titik_ke' => (int) $rows->first()->titik_ke,
                    'titik_ukur' => $titikUkur,
                    'pembacaan' => $nilai->all(),
                    'suhu_rata' => $suhu->isNotEmpty() ? $suhu->sum() / $suhu->count() : null,
                    'rata_rata' => $rata,
                    'koreksi' => $rata - $titikUkur,
                    'stdev' => $stdev,
                    'u95' => $calc?->ketidakpastian_diperluas,
                    'k' => $calc?->faktor_cakupan_k,
                    'keputusan' => $calc?->keputusan,
                ];
            })
            ->sortBy('titik_ke')
            ->values();
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
