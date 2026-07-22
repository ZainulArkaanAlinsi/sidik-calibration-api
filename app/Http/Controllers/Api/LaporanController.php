<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalibrationSession;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan kalibrasi dengan filter Pelanggan / Tanggal / Teknisi / Jenis Alat
 * (Permintaan Backend Fase 2 §5).
 *
 * Rekapnya SENGAJA dirakit di sini, bukan di HP. Bukan soal susah — soal angka:
 * kalau mobile yang ngerangkum, hasilnya bisa beda tipis dari server
 * (pembulatan, zona waktu), dan buat lab terakreditasi dua laporan dengan angka
 * beda itu temuan.
 *
 * Cakupan datanya ngikutin aturan yang sama kayak endpoint lain: teknisi cuma
 * dapat sesi miliknya sendiri, admin & viewer dapat seluruh lab. Nggak ada
 * query param yang bisa ngelewatin itu.
 */
class LaporanController extends Controller
{
    /** Baris maksimum yang boleh diekspor sekali jalan. */
    private const MAKS_EKSPOR = 5000;

    /** Laporan versi JSON berpaginasi — buat ditampilin di layar. */
    public function kalibrasi(Request $request): JsonResponse
    {
        $this->validasiFilter($request);

        $sesi = $this->query($request)->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($sesi->items())->map($this->baris(...))->all(),
            'meta' => [
                'current_page' => $sesi->currentPage(),
                'last_page' => $sesi->lastPage(),
                'per_page' => $sesi->perPage(),
                'total' => $sesi->total(),
                // Ringkasan dihitung dari SELURUH hasil filter, bukan cuma
                // halaman yang lagi dibuka — kalau nggak, angka "12 PASS" di
                // footer berubah tiap ganti halaman dan bikin bingung.
                'ringkasan' => $this->ringkasan($request),
            ],
        ]);
    }

    /**
     * Ekspor hasil filter yang sama sebagai file.
     *
     * `format=pdf` (dompdf) atau `format=csv`. CSV kebuka langsung di Excel;
     * `.xlsx` asli butuh paket tambahan yang belum dipasang di project ini.
     */
    public function export(Request $request): StreamedResponse|Response
    {
        $this->validasiFilter($request);

        $request->validate([
            'format' => ['required', Rule::in(['pdf', 'csv'])],
        ], [
            'format.in' => 'Format cuma boleh `pdf` atau `csv`. `.xlsx` asli belum didukung — CSV kebuka di Excel.',
        ]);

        $jumlah = (clone $this->query($request))->count();

        // Tanpa batas ini, filter kosong di lab yang udah jalan bertahun-tahun
        // bakal narik puluhan ribu baris sekali jalan dan bikin server megap.
        if ($jumlah > self::MAKS_EKSPOR) {
            abort(422, "Hasilnya {$jumlah} baris, maksimum ".self::MAKS_EKSPOR
                .' buat sekali ekspor. Persempit rentang tanggalnya.');
        }

        $baris = $this->query($request)->get()->map($this->baris(...));
        $namaFile = 'laporan-kalibrasi-'.now()->format('Ymd-His');

        return $request->string('format')->value() === 'pdf'
            ? $this->eksporPdf($baris, $request, $namaFile)
            : $this->eksporCsv($baris, $namaFile);
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $baris */
    private function eksporPdf($baris, Request $request, string $namaFile): Response
    {
        $pdf = Pdf::loadView('laporan.kalibrasi', [
            'baris' => $baris,
            'ringkasan' => $this->ringkasan($request),
            'organisasi' => $request->user()->organization,
            'filter' => $request->only(['dari', 'sampai', 'pelanggan_id', 'teknisi_id', 'kategori']),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($namaFile.'.pdf');
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $baris */
    private function eksporCsv($baris, string $namaFile): StreamedResponse
    {
        $judul = [
            'Nomor Sesi', 'Tanggal Kalibrasi', 'Pelanggan', 'Alat', 'No. Seri',
            'Kategori', 'Teknisi', 'Status', 'Keputusan', 'No. Sertifikat', 'Terbit',
        ];

        return response()->streamDownload(function () use ($baris, $judul): void {
            $out = fopen('php://output', 'wb');

            // BOM UTF-8 — tanpa ini Excel di Windows nampilin "PT Ãœ" buat nama
            // pelanggan yang ada karakter non-ASCII-nya.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $judul);

            foreach ($baris as $b) {
                fputcsv($out, [
                    $b['nomor_sesi'], $b['tanggal_kalibrasi'], $b['pelanggan'], $b['alat'],
                    $b['serial_number'], $b['kategori'], $b['teknisi'], $b['status'],
                    $b['keputusan'], $b['nomor_sertifikat'], $b['diterbitkan_pada'],
                ]);
            }

            fclose($out);
        }, $namaFile.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<CalibrationSession> */
    private function query(Request $request): Builder
    {
        $user = $request->user();

        return CalibrationSession::query()
            ->with(['equipment.customer', 'equipment.category', 'teknisi', 'certificate'])
            ->where('organization_id', $user->organization_id)
            // Teknisi cuma dapat sesinya sendiri — sama kayak /calibrations.
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn ($q) => $q->where('teknisi_id', $user->id),
            )
            ->when($request->filled('dari'), fn ($q) => $q->whereDate('tanggal_kalibrasi', '>=', $request->date('dari')))
            ->when($request->filled('sampai'), fn ($q) => $q->whereDate('tanggal_kalibrasi', '<=', $request->date('sampai')))
            ->when($request->filled('teknisi_id'), fn ($q) => $q->where('teknisi_id', $request->integer('teknisi_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('keputusan'), fn ($q) => $q->where('keputusan', $request->string('keputusan')))
            ->when($request->filled('pelanggan_id'), fn ($q) => $q->whereHas(
                'equipment',
                fn ($e) => $e->where('customer_id', $request->integer('pelanggan_id')),
            ))
            ->when($request->filled('kategori'), fn ($q) => $q->whereHas(
                'equipment.category',
                fn ($c) => $c->where('kode', $request->string('kategori')),
            ))
            ->orderByDesc('tanggal_kalibrasi')
            ->orderByDesc('id');
    }

    /**
     * Satu baris laporan. Sengaja datar (bukan bersarang) — dipakai bareng sama
     * JSON, CSV, dan PDF, jadi bentuknya harus sama buat ketiganya.
     *
     * @return array<string, mixed>
     */
    private function baris(CalibrationSession $sesi): array
    {
        return [
            'id' => $sesi->id,
            'nomor_sesi' => $sesi->nomor_sesi,
            'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi?->format('Y-m-d'),
            'pelanggan' => $sesi->equipment?->customer?->nama,
            'alat' => $sesi->equipment?->nama_alat,
            'serial_number' => $sesi->equipment?->serial_number,
            'kategori' => $sesi->equipment?->category?->nama,
            'teknisi' => $sesi->teknisi?->name,
            'status' => $sesi->status,
            'keputusan' => $sesi->keputusan,
            'nomor_sertifikat' => $sesi->certificate?->nomor,
            'diterbitkan_pada' => $sesi->certificate?->diterbitkan_pada?->format('Y-m-d'),
        ];
    }

    /** @return array<string, int> */
    private function ringkasan(Request $request): array
    {
        $dasar = fn (): Builder => $this->query($request);

        return [
            'total' => $dasar()->count(),
            'pass' => $dasar()->where('keputusan', 'PASS')->count(),
            'fail' => $dasar()->where('keputusan', 'FAIL')->count(),
            'disetujui' => $dasar()->where('status', CalibrationSession::STATUS_DISETUJUI)->count(),
            'bersertifikat' => $dasar()->whereHas('certificate', fn ($c) => $c->where('status', 'terbit'))->count(),
        ];
    }

    private function validasiFilter(Request $request): void
    {
        $request->validate([
            'dari' => ['sometimes', 'date'],
            'sampai' => ['sometimes', 'date', 'after_or_equal:dari'],
            'pelanggan_id' => ['sometimes', 'integer'],
            'teknisi_id' => ['sometimes', 'integer'],
            'kategori' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string'],
            'keputusan' => ['sometimes', Rule::in(['PASS', 'FAIL'])],
        ], [
            'sampai.after_or_equal' => 'Tanggal "sampai" nggak boleh lebih awal dari "dari".',
        ]);
    }
}
