<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Services\PenjagaOrganisasi;
use App\Services\RevisiSertifikat;
use App\Services\SinkronJadwalAlat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ubah tanggal jatuh tempo alat (K-50-08) — admin & super admin, alasan wajib.
 *
 * Dua jalur, dan jawabannya menyebut mana yang terpakai (`jalur`):
 *
 *  - `jadwal_alat` — alat TIDAK punya sertifikat aktif bertanggal: yang diubah
 *    cuma jadwal (`equipments.tanggal_jatuh_tempo`). Langsung berlaku, dicatat
 *    di audit (lama → baru + alasan).
 *  - `revisi_sertifikat` — ada sertifikat aktif; `berlaku_sampai` TERCETAK di
 *    PDF-nya, jadi mengubahnya = revisi `-R<n>` (RevisiSertifikat). Jadwal alat
 *    ikut berubah lewat `SinkronJadwalAlat` setelah revisinya terbit, dan
 *    pengingat serta layar pelanggan membaca kolom yang sama.
 */
class JatuhTempoAlatController extends Controller
{
    public function ubah(
        Request $request,
        Equipment $equipment,
        SinkronJadwalAlat $sinkron,
        RevisiSertifikat $revisi,
    ): JsonResponse {
        PenjagaOrganisasi::pastikanSatu($request, $equipment);

        $data = $request->validate([
            'tanggal_jatuh_tempo' => ['required', 'date_format:Y-m-d'],
            'alasan' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $baru = $data['tanggal_jatuh_tempo'];
        $aktif = $sinkron->sertifikatAktif($equipment);

        if ($aktif !== null && $aktif->berlaku_sampai !== null) {
            if ($aktif->berlaku_sampai->toDateString() === $baru) {
                // Tercetak sudah benar; jadwal alat yang melenceng cukup disamakan.
                $sinkron->untuk($equipment);

                return response()->json([
                    'message' => 'Tanggal sudah sama dengan yang tercetak di sertifikat; jadwal alat disamakan.',
                    'jalur' => 'jadwal_alat',
                    'data' => ['tanggal_jatuh_tempo' => $equipment->fresh()->tanggal_jatuh_tempo?->toDateString()],
                ]);
            }

            $terbit = $revisi->terbitkan(
                $aktif,
                ['berlaku_sampai' => $baru],
                $data['alasan'],
                null,
                $request->user(),
            );

            return response()->json([
                'message' => "Tanggal tercetak di sertifikat berubah, jadi dibuat revisi {$terbit->nomor}. "
                    .'Jadwal alat ikut berubah setelah PDF revisinya terbit.',
                'jalur' => 'revisi_sertifikat',
                'sertifikat' => ['id' => $terbit->id, 'nomor' => $terbit->nomor],
                'data' => ['tanggal_jatuh_tempo' => $equipment->tanggal_jatuh_tempo?->toDateString()],
            ], 202);
        }

        $lama = $equipment->tanggal_jatuh_tempo?->toDateString();

        if ($lama === $baru) {
            return response()->json([
                'message' => 'Tanggal jatuh tempo sudah sama.',
                'errors' => ['tanggal_jatuh_tempo' => ['Tanggal jatuh tempo sudah sama.']],
            ], 422);
        }

        DB::transaction(function () use ($equipment, $lama, $baru, $data): void {
            // Lewat query builder + catatan eksplisit supaya satu baris audit
            // memuat ALASAN, bukan dua baris (event model + catatan).
            Equipment::whereKey($equipment->getKey())->update(['tanggal_jatuh_tempo' => $baru, 'updated_at' => now()]);
            $equipment->catatAudit(
                AuditLog::ACTION_DIUBAH,
                ['tanggal_jatuh_tempo' => $lama],
                ['tanggal_jatuh_tempo' => $baru],
                'Ubah jatuh tempo (jadwal alat): '.$data['alasan'],
            );
        });

        return response()->json([
            'message' => 'Jadwal jatuh tempo alat diubah.',
            'jalur' => 'jadwal_alat',
            'data' => ['tanggal_jatuh_tempo' => $baru],
        ]);
    }
}
