<?php

namespace App\Support;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Standar yang dicentang "Dipakai" di lembar kerja — dari payload, atau dari
 * sesi yang sudah tersimpan kalau payload-nya tidak membawa centangan.
 *
 * Satu pintu untuk dua pemakai yang WAJIB sepakat:
 * `CalibrationController::standarTurunan()` menurunkan kalibrator sesi dari
 * sini untuk dihitung, dan `CalibrationRequest` menolak kiriman yang
 * turunannya mustahil (mis. dua kalibrator Enclosure tercentang). Kalau
 * keduanya membaca sumber yang berbeda, validasi meloloskan kiriman yang lalu
 * tidak terhitung — persis kejadian sesi Oven produksi `KAL/2026/08/0002`
 * (3 Okt 2026): terkirim, 220 pembacaan, nol titik terhitung.
 */
final class StandarDicentang
{
    /** @return Collection<int, Standard> */
    public static function dari(Request $request, Equipment $alat): Collection
    {
        $id = [];

        if ($request->has('standar_dicek')) {
            foreach ((array) $request->input('standar_dicek', []) as $baris) {
                if ((bool) ($baris['dipakai'] ?? true)) {
                    $id[] = (int) $baris['standard_id'];
                }
            }
        } elseif ($request->filled('calibration_session_id')) {
            $sesi = CalibrationSession::find($request->integer('calibration_session_id'));

            $id = $sesi === null ? [] : $sesi->standarDicek()
                ->wherePivot('dipakai', true)
                ->pluck('standards.id')
                ->all();
        }

        if ($id === []) {
            return new Collection;
        }

        // Disaring ke organisasi pemilik alat: ID standar datang dari payload,
        // dan tanpa saringan ini sesi bisa menunjuk kalibrator milik lab lain.
        return Standard::query()
            ->whereIn('id', $id)
            ->where('organization_id', $alat->organization_id)
            ->get();
    }
}
