<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Studio\BacaDrafAcuanRequest;
use App\Http\Requests\Studio\SimpanDrafAcuanRequest;
use App\Services\Studio\Draf\DrafAcuanDitolak;
use App\Services\Studio\Draf\SuntinganDrafService;
use Closure;
use Illuminate\Http\JsonResponse;

/**
 * Simpan/muat ulang draf data acuan dari Studio (rencana A1,
 * `_agents_workspace/studio-draft-sync-a1/02-rencana.md`).
 *
 * Parameter rute `{workbook}`/`{versi}` TIDAK di-bind ke model: pengikatan model
 * tanpa `WorkbookController::pastikanDiStudio` membedakan 404 (tidak ada) dari
 * jawaban lain (ada, milik lab lain) -- celah lintas organisasi, karena
 * `SubstituteBindings` jalan sebelum gerbang `studio`. Pencarian workbook ada di
 * request (`authorize()`: organisasi dulu, baru validasi); pencarian versi di
 * service (org + paket + jenis `data`).
 *
 * Galat 4xx/409/500 service: `{message, kode, rincian?}` (pola `DataAcuanAlurController`).
 * `nilai` suntingan selalu STRING kanonik, jadi float tidak pernah lewat
 * `json_encode` (serialize_precision) di sini.
 */
class DrafAcuanController extends Controller
{
    /**
     * GET /api/studio/workbook/{workbook}/draf-dasar -- hash `dasar`/`peta`/`workbook`
     * yang dibutuhkan POST pertama, proyeksi sel dari dasar, dan ringkasan draf yang
     * sudah ada. Tidak menulis.
     */
    public function dasar(BacaDrafAcuanRequest $request, SuntinganDrafService $service): JsonResponse
    {
        return $this->jawab(fn (): JsonResponse => $this->ok($service->dasar($request->workbookStudio())));
    }

    /**
     * POST /api/studio/workbook/{workbook}/draf -- 201 `draf_dibuat`,
     * 200 `draf_sudah_ada` (isi persis sama dengan draf yang ada: ID sama,
     * tanpa tulis). Bentuk `{data: {status, versi, hash, suntingan}}`.
     */
    public function simpan(SimpanDrafAcuanRequest $request, SuntinganDrafService $service): JsonResponse
    {
        return $this->jawab(function () use ($request, $service): JsonResponse {
            $hasil = $service->buat(
                $request->workbookStudio(),
                (int) $request->user()->id,
                $request->validated(),
            );

            return $this->ok($hasil['data'], $hasil['baru'] ? 201 : 200);
        });
    }

    /** GET /api/studio/workbook/{workbook}/draf/{versi} -- 200 `draf_dimuat`. Tidak menulis. */
    public function tampil(BacaDrafAcuanRequest $request, SuntinganDrafService $service): JsonResponse
    {
        return $this->jawab(fn (): JsonResponse => $this->ok($service->muat($request->workbookStudio(), $request->versiId())));
    }

    /**
     * PUT /api/studio/workbook/{workbook}/draf/{versi} -- 200 `draf_diperbarui`,
     * atau 200 `tidak_berubah` (tanpa tulis/audit). Bentuk sama dengan POST.
     */
    public function perbarui(SimpanDrafAcuanRequest $request, SuntinganDrafService $service): JsonResponse
    {
        return $this->jawab(fn (): JsonResponse => $this->ok($service->perbarui(
            $request->workbookStudio(),
            $request->versiId(),
            (int) $request->user()->id,
            $request->validated(),
        )));
    }

    /** @param  Closure(): JsonResponse  $aksi */
    private function jawab(Closure $aksi): JsonResponse
    {
        try {
            return $aksi();
        } catch (DrafAcuanDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'kode' => $e->kode,
                ...($e->rincian === [] ? [] : ['rincian' => $e->rincian]),
            ], $e->status);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
