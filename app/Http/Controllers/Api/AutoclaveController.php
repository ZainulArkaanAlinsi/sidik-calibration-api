<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoclaveCalculationRequest;
use App\Services\Calibration\AutoclaveCalculator;
use App\Services\Calibration\AutoclaveInputBuilder;
use Illuminate\Http\JsonResponse;

/**
 * Olah data Autoklaf. Terpisah dari `CalibrationController` karena bentuk
 * datanya (3 disk suhu × titik waktu + 1 titik tekanan berkonversi satuan)
 * nggak muat di model "titik ukur + pengulangan" yang dipakai enam alat lain.
 *
 * Cuma jalur PREVIEW (hitung tanpa simpen) di sini. Jalur SIMPAN sesi Autoklaf
 * ada di `CalibrationController::simpanAutoclave` — di situ semua plumbing sesi
 * (nomor sesi, kondisi lingkungan, notifikasi admin, arsip folder) sudah ada.
 */
class AutoclaveController extends Controller
{
    public function __construct(
        private readonly AutoclaveCalculator $kalkulator,
        private readonly AutoclaveInputBuilder $perakit,
    ) {}

    /**
     * Preview perhitungan: terima data ukur teknisi, gabung tabel kalibrator &
     * CMC dari `config/autoclave.php`, balikin hasil olah data lengkap (Section
     * A/B/C sertifikat + budget ketidakpastian). TANPA nyimpen.
     */
    public function preview(AutoclaveCalculationRequest $request): JsonResponse
    {
        $input = $this->perakit->dari($request->validated());

        return response()->json(['data' => $this->kalkulator->hitung($input)]);
    }
}
