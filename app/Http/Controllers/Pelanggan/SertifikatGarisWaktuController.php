<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\SertifikatGarisWaktuResource;
use App\Models\Certificate;
use App\Services\GarisWaktu\GarisWaktuSertifikat;
use App\Services\GarisWaktu\LingkupGarisWaktu;
use App\Services\Pencarian\CariDokumenLab;
use App\Support\Pelanggan\Konteks;
use App\Support\Pelanggan\LingkupData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /pelanggan/v1/sertifikat/garis-waktu` -- sertifikat perusahaan pemanggil
 * dikelompokkan TAHUN > BULAN > PEKAN > HARI menurut tanggal terbit.
 *
 * Kueri dasarnya `LingkupData::sertifikat()` (customer_id + organization_id dari
 * `KonteksPerusahaan`, bukan dari request), jadi sertifikat perusahaan lain
 * tidak pernah ikut terhitung. Seperti daftar `/sertifikat`, yang sudah
 * digantikan revisi disembunyikan kecuali `termasuk_digantikan=1`.
 *
 * `q` memakai pencarian terpadu `CariDokumenLab`, dipagari ke perusahaan ini
 * (nomor order hanya dari order miliknya). Daftar `/sertifikat?q` TIDAK
 * diubah.
 */
class SertifikatGarisWaktuController extends Controller
{
    public function __construct(
        private readonly GarisWaktuSertifikat $garis,
        private readonly CariDokumenLab $cari,
    ) {}

    public function __invoke(Request $request, Konteks $konteks): JsonResponse
    {
        $data = $request->validate(LingkupGarisWaktu::aturan() + [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'termasuk_digantikan' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $lingkup = LingkupGarisWaktu::dariInput($data);
        $q = trim((string) ($data['q'] ?? ''));
        $lingkupData = new LingkupData($konteks);

        $dasar = $lingkupData->sertifikat()
            ->when(! $request->boolean('termasuk_digantikan'), fn (Builder $kueri) => $kueri->belumDigantikan())
            ->when($q !== '', fn (Builder $kueri) => $this->cari->sertifikat(
                $kueri,
                $lingkupData->perusahaan()->organization_id,
                $q,
                urut: false,
                pelangganId: $konteks->customerId,
            ));

        return response()->json($this->garis->jawaban(
            $dasar,
            $lingkup,
            (int) (($data['per_page'] ?? null) ?: 20),
            SertifikatGarisWaktuResource::RELASI,
            fn (Certificate $c): array => (new SertifikatGarisWaktuResource($c))->resolve($request),
        ));
    }
}
