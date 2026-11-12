<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArsipGarisWaktuItemResource;
use App\Models\Certificate;
use App\Models\User;
use App\Services\GarisWaktu\GarisWaktuSertifikat;
use App\Services\GarisWaktu\LingkupGarisWaktu;
use App\Services\Pencarian\CariDokumenLab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /arsip/garis-waktu` -- arsip sertifikat dikelompokkan HARI -> PEKAN ->
 * BULAN -> TAHUN menurut tanggal terbit, sebagai TAMPILAN. Folder fisik arsip
 * (PT > Tahun > Bulan > Jenis) tidak berubah.
 *
 * Penelusuran bertingkat lewat `tingkat` + induknya (`LingkupGarisWaktu`);
 * `customer_id` dan `q` (pencarian terpadu `CariDokumenLab`) menyempitkan
 * semua tingkat. Satu organisasi saja; teknisi cuma melihat sertifikat dari
 * sesinya sendiri -- sama dengan Telusuri dan `GET /certificates`.
 */
class ArsipGarisWaktuController extends Controller
{
    public function __construct(
        private readonly GarisWaktuSertifikat $garis,
        private readonly CariDokumenLab $cari,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(LingkupGarisWaktu::aturan() + [
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $lingkup = LingkupGarisWaktu::dariInput($data);
        $user = $request->user();
        $q = trim((string) ($data['q'] ?? ''));

        $dasar = Certificate::query()
            ->where('certificates.organization_id', $user->organization_id)
            ->whereIn('certificates.status', [
                Certificate::STATUS_TERBIT,
                Certificate::STATUS_DIBATALKAN,
                Certificate::STATUS_DITARIK,
            ])
            ->when(
                $user->role === User::ROLE_TEKNISI,
                fn (Builder $kueri) => $kueri->whereHas('session', fn (Builder $s) => $s->where('teknisi_id', $user->id)),
            )
            // Pemilik dibaca dari ALAT sesinya, termasuk yang sudah dihapus
            // lunak: sertifikat yang pernah terbit tetap milik pelanggannya.
            ->when(
                filled($data['customer_id'] ?? null),
                fn (Builder $kueri) => $kueri->whereHas('session.equipment', fn (Builder $a) => $a
                    ->withTrashed()
                    ->where('customer_id', (int) $data['customer_id'])),
            )
            ->when(
                $q !== '',
                fn (Builder $kueri) => $this->cari->sertifikat($kueri, $user->organization_id, $q, urut: false),
            );

        return response()->json($this->garis->jawaban(
            $dasar,
            $lingkup,
            (int) (($data['per_page'] ?? null) ?: 20),
            ArsipGarisWaktuItemResource::relasi(),
            fn (Certificate $c): array => (new ArsipGarisWaktuItemResource($c))->resolve($request),
        ));
    }
}
