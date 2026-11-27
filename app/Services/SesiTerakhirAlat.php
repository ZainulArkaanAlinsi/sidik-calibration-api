<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;

/**
 * Sesi kalibrasi TERAKHIR tiap alat di satu daftar — sumber `sesi_terakhir` di
 * `GET /api/equipments` (status per alat: dikerjakan, diinput, atau sudah dikirim).
 *
 * ## Tautan alat ke sesi
 *
 * Lewat `calibration_sessions.equipment_id` (kolom yang sudah ada, tanpa migrasi).
 * "Terakhir" = id sesi TERBESAR, aturan yang sama dengan `GET /calibrations`
 * (`latest('id')`) dan `OrderItem::sesiTerakhir()`. Sesi yang diulang setelah
 * `perlu_revisi` itu baris baru, jadi sesi lama tidak mengalahkan yang baru.
 *
 * ## Kenapa bukan `hasOne()->latestOfMany()` di model
 *
 * Teknisi cuma boleh melihat sesi miliknya sendiri (`CalibrationController::index`
 * dan `pastikanBolehLihat`). Penyaring `teknisi_id` harus masuk ke subquery
 * "id terbesar", bukan ke kueri luarnya: kalau dipasang di luar, alat yang sesi
 * terbarunya milik teknisi lain tampil TANPA sesi (null) padahal sesi lama
 * milik si penanya masih ada. Eager load biasa tidak bisa memasang kondisi di
 * subquery itu, jadi satu kueri ditulis di sini.
 *
 * ## Biaya
 *
 * Satu kueri sesi (subquery max(id) per alat) + dua eager load (`certificate`,
 * `orderItem`) per halaman, berapa pun jumlah alatnya. Sesi yang diarsipkan
 * (soft delete) tidak ikut: scope `SoftDeletes` berlaku di kueri luar dan di
 * subquery.
 */
class SesiTerakhirAlat
{
    /**
     * Pasang relasi `sesiTerakhir` (atau `null` bila belum ada sesi) ke tiap alat.
     *
     * @param  iterable<Equipment>  $daftarAlat
     */
    public function muat(iterable $daftarAlat, User $pengguna): void
    {
        $alat = collect($daftarAlat)->values();

        if ($alat->isEmpty()) {
            return;
        }

        $batas = function ($query) use ($alat, $pengguna): void {
            $query
                ->where('organization_id', $pengguna->organization_id)
                ->whereIn('equipment_id', $alat->pluck('id')->all())
                ->when(
                    $pengguna->role === User::ROLE_TEKNISI,
                    fn ($q) => $q->where('teknisi_id', $pengguna->id),
                );
        };

        $terbaru = CalibrationSession::query()
            ->tap($batas)
            ->whereIn(
                'id',
                CalibrationSession::query()
                    ->tap($batas)
                    ->selectRaw('max(id)')
                    ->groupBy('equipment_id'),
            )
            ->with(['certificate', 'orderItem'])
            ->get()
            ->keyBy('equipment_id');

        foreach ($alat as $satu) {
            $satu->setRelation('sesiTerakhir', $terbaru->get($satu->id));
        }
    }
}
