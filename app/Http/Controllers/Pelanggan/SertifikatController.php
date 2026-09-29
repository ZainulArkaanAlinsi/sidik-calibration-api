<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\SertifikatPelangganResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Services\BerkasPdfSertifikat;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `/sertifikat` — daftar, detail, dan unduh PDF sertifikat milik pelanggan.
 *
 * Daftarnya bawaan cuma sertifikat yang MASIH BERLAKU sebagai dokumen: yang
 * sudah digantikan revisi disembunyikan, supaya pelanggan tidak mengirim
 * nomor lama ke auditornya. `?termasuk_digantikan=1` membuka semuanya.
 */
class SertifikatController extends Controller
{
    public function index(Request $request, Konteks $konteks): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'termasuk_digantikan' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sertifikat = (new LingkupData($konteks))->sertifikat()
            ->when(! $request->boolean('termasuk_digantikan'), fn (Builder $q) => $q->whereNotExists(
                fn (QueryBuilder $pengganti) => $pengganti->selectRaw('1')
                    ->from('certificates as pengganti')
                    ->whereColumn('pengganti.revision_of', 'certificates.id')
                    ->where('pengganti.status', Certificate::STATUS_TERBIT)
            ))
            ->when(filled($data['q'] ?? null), fn (Builder $q) => $q->where(function (Builder $cari) use ($data): void {
                $kata = '%'.$data['q'].'%';
                $cari->where('nomor', 'like', $kata)
                    ->orWhereHas('session.equipment', fn (Builder $a) => $a->withTrashed()
                        ->where(fn (Builder $b) => $b->where('nama_alat', 'like', $kata)
                            ->orWhere('serial_number', 'like', $kata)));
            }))
            ->with(['session:id,equipment_id', 'revisionOf:id,nomor'])
            ->orderByDesc('diterbitkan_pada')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($sertifikat->items())
                ->map(fn (Certificate $c) => (new SertifikatPelangganResource($c))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $sertifikat->total(),
                'per_page' => $sertifikat->perPage(),
                'current_page' => $sertifikat->currentPage(),
                'last_page' => $sertifikat->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Konteks $konteks, string $sertifikat): JsonResponse
    {
        $baris = $this->milikSendiri($konteks, $sertifikat);

        $pengganti = Certificate::query()
            ->where('revision_of', $baris->id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->latest('id')
            ->first(['id', 'nomor']);

        return response()->json([
            'data' => (new SertifikatPelangganResource($baris))->rinci($pengganti)->resolve($request),
        ]);
    }

    /**
     * Unduh PDF — kepemilikan diperiksa ULANG di sini, tidak mengandalkan
     * bahwa ID-nya didapat dari daftar yang sudah tersaring.
     *
     * Tiap unduhan dicatat di riwayat audit sertifikatnya. Pertanyaan "sudah
     * sampai ke pelanggan belum?" selama ini cuma bisa dijawab dari log email;
     * sekarang jawabannya ada di baris yang sama dengan sertifikatnya.
     */
    public function unduh(
        Request $request,
        Konteks $konteks,
        BerkasPdfSertifikat $berkas,
        string $sertifikat,
    ): StreamedResponse {
        $baris = $this->milikSendiri($konteks, $sertifikat);

        abort_unless(filled($baris->pdf_path), 404, 'Sertifikat ini belum punya PDF yang bisa diunduh.');

        $path = $berkas->pastikanAda($baris);

        abort_unless($path !== null, 404, 'Berkas PDF sertifikat ini tidak ditemukan.');

        AuditLog::create([
            'organization_id' => $baris->organization_id,
            'entity_type' => $baris->getTable(),
            'entity_id' => $baris->id,
            'action' => AuditLog::ACTION_DIUNDUH_PELANGGAN,
            'old_data' => null,
            'new_data' => ['customer_id' => $konteks->customerId],
            'changed_by' => $request->user()->id,
            'note' => 'Diunduh dari aplikasi pelanggan',
        ]);

        return Storage::disk('arsip')->download($path, $baris->namaFile('pdf'));
    }

    private function milikSendiri(Konteks $konteks, string $id): Certificate
    {
        return (new LingkupData($konteks))->sertifikat()
            ->whereKey($id)
            ->with(['session:id,equipment_id', 'revisionOf:id,nomor'])
            ->firstOrFail();
    }
}
