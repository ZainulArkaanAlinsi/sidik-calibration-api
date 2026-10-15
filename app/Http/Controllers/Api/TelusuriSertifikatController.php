<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\User;
use App\Services\TahapPaket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Telusuri No. Sertifikat" (K-50-05) — pengganti Pelacakan paket.
 *
 * Pencarian di SERVER (bukan menyaring daftar yang sudah dimuat HP) berdasarkan
 * nomor sertifikat, termasuk revisinya (`…-R1`). Satu organisasi saja;
 * teknisi cuma melihat sertifikat dari sesinya sendiri, sama dengan
 * `CertificateController::index`.
 */
class TelusuriSertifikatController extends Controller
{
    public function __construct(private readonly TahapPaket $tahap) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        // `%` dan `_` dari input diperlakukan sebagai huruf biasa.
        $kata = '%'.addcslashes($data['q'], '\%_').'%';

        $hasil = Certificate::query()
            ->where('organization_id', $user->organization_id)
            ->where('nomor', 'like', $kata)
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn (Builder $q) => $q->whereHas('session', fn (Builder $s) => $s->where('teknisi_id', $user->id)),
            )
            ->with([
                'session:id,equipment_id,order_item_id',
                'session.equipment' => fn ($q) => $q->withTrashed()->select('id', 'customer_id', 'nama_alat', 'merk', 'model', 'serial_number'),
                'session.equipment.customer:id,nama',
                'session.orderItem',
                'session.orderItem.sesiTerakhir',
                'session.orderItem.sesiTerakhir.certificate',
                'revisiTerakhir',
            ])
            ->orderBy('nomor')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => collect($hasil->items())->map(fn (Certificate $c): array => $this->bentuk($c))->values(),
            'meta' => [
                'total' => $hasil->total(),
                'per_page' => $hasil->perPage(),
                'current_page' => $hasil->currentPage(),
                'last_page' => $hasil->lastPage(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function bentuk(Certificate $c): array
    {
        $alat = $c->session?->equipment;
        $item = $c->session?->orderItem;

        return [
            'id' => $c->id,
            'nomor' => $c->nomor,
            'perusahaan' => $alat?->customer?->nama,
            'alat' => $alat?->nama_alat,
            'merk' => $alat?->merk,
            'model' => $alat?->model,
            'serial_number' => $alat?->serial_number,
            // `status` mentah + `status_dokumen` (berlaku/digantikan/ditarik/
            // dibatalkan/belum_terbit) + `revisi_ke` (null = terbitan pertama).
            'status' => $c->status,
            'status_dokumen' => Certificate::labelDokumen((string) $c->status, $c->revisiTerakhir?->status),
            'revisi_ke' => $c->revisi_ke ?: null,
            'diterbitkan_pada' => $c->diterbitkan_pada?->toDateString(),
            'berlaku_sampai' => $c->berlaku_sampai?->toDateString(),
            'tahap_paket' => $item !== null ? $this->tahap->untukItem($item) : null,
            'pdf_url' => in_array($c->status, [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN, Certificate::STATUS_DITARIK], true)
                ? route('certificates.download', $c)
                : null,
        ];
    }
}
