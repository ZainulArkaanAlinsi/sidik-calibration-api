<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoclaveCalculationRequest;
use App\Services\Calibration\AutoclaveCalculator;
use Illuminate\Http\JsonResponse;

/**
 * Olah data Autoklaf. Terpisah dari `CalibrationController` karena bentuk
 * datanya (3 disk suhu × titik waktu + satu titik tekanan berkonversi satuan)
 * nggak muat di model "titik ukur + pengulangan" yang dipakai enam alat lain.
 *
 * Endpoint ini menghitung & mengembalikan hasil — belum menyimpan sesi. Jalur
 * simpan/approve/sertifikat menyusul (lihat docs/perintah-frontend-autoclave.md).
 */
class AutoclaveController extends Controller
{
    public function __construct(
        private readonly AutoclaveCalculator $kalkulator,
    ) {}

    /**
     * Preview perhitungan: terima data ukur teknisi, gabung tabel kalibrator &
     * CMC dari `config/autoclave.php`, balikin hasil olah data lengkap (Section
     * A/B/C sertifikat + budget ketidakpastian).
     */
    public function preview(AutoclaveCalculationRequest $request): JsonResponse
    {
        $input = $this->susunInput($request->validated());

        return response()->json(['data' => $this->kalkulator->hitung($input)]);
    }

    /**
     * Tempel tabel standar (kalibrator) & CMC server-side ke data ukur dari
     * request. Klien nggak pernah ngirim tabel koreksi — itu kebenaran server.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function susunInput(array $data): array
    {
        $config = config('autoclave');

        $input = [
            'set_point' => (float) $data['set_point'],
            'cmc' => $config['cmc'],
        ];

        if (isset($data['suhu'])) {
            $input['suhu'] = [
                'disk' => array_values($data['suhu']['disk'] ?? []),
                'indikator' => $data['suhu']['indikator'] ?? [],
                'suhu_ruang' => $data['suhu']['suhu_ruang'] ?? [],
                'resolusi_alat' => $data['suhu']['resolusi_alat'] ?? $config['resolusi_alat']['suhu'],
                // Tabel per disk mengikuti urutan disk terisi. Disk ke-i pakai
                // kalibrator ke-i (config sudah urut Calibrator 1/2/3).
                'standar' => array_map(
                    fn (array $kal): array => ['resolusi' => $kal['resolusi'], 'tabel' => $kal['tabel']],
                    array_slice($config['suhu'], 0, count($data['suhu']['disk'] ?? [])),
                ),
            ];
        }

        if (isset($data['tekanan'])) {
            $input['tekanan'] = [
                'uut_setting' => (float) $data['tekanan']['uut_setting'],
                'satuan' => $data['tekanan']['satuan'] ?? 'Bar',
                'display' => $data['tekanan']['display'] ?? 'Digital',
                'resolusi_alat' => $data['tekanan']['resolusi_alat'] ?? $config['resolusi_alat']['tekanan'],
                'pembacaan_standar' => $data['tekanan']['pembacaan_standar'] ?? [],
                'standar' => [
                    'resolusi' => $config['tekanan']['resolusi'],
                    'tabel' => $config['tekanan']['tabel'],
                ],
            ];
        }

        return $input;
    }
}
