<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\User;
use App\Services\PenjagaOrganisasi;
use App\Services\RiwayatSertifikatAlat;
use App\Support\KursorId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/equipments/{equipment}/sertifikat` — riwayat sertifikat SATU alat,
 * terbaru dibuat dulu, paginasi kursor (kontrak:
 * `docs/perintah-frontend-super-admin.md` §3.5).
 *
 * Nomor seri yang sama dikalibrasi tahun demi tahun, dan sertifikat lama tidak
 * pernah tertimpa: revisi dan terbit ulang melahirkan baris BARU, baris lama
 * tetap tampil `digantikan` dengan penunjuk ke penggantinya. Bentuk barisnya
 * dari `RiwayatSertifikatAlat` — satu bentuk dengan `sertifikat_terbaru` di daftar
 * alat pelanggan.
 *
 * Peran = aturan `GET /equipments/{e}` (semua peran internal) ditambah aturan
 * `GET /certificates`: teknisi hanya sertifikat dari sesi miliknya sendiri.
 * Aturan itu ditegakkan di query (`RiwayatSertifikatAlat::halaman()`), bukan di UI.
 */
class RiwayatSertifikatAlatController extends Controller
{
    public function __construct(private readonly RiwayatSertifikatAlat $riwayat) {}

    public function __invoke(Request $request, Equipment $equipment): JsonResponse
    {
        // Penjaga kepemilikan DULU, validasi query sesudahnya (lihat `KursorId::baca`).
        PenjagaOrganisasi::pastikanSatu($request, $equipment);

        [$setelahId, $limit] = KursorId::baca($request);

        /** @var User $pemanggil */
        $pemanggil = $request->user();

        [$baris, $berikutnya] = $this->riwayat->halaman($equipment, $pemanggil, $setelahId, $limit);

        return response()->json([
            'data' => $baris,
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $berikutnya,
                'ada_lagi' => $berikutnya !== null,
            ],
        ]);
    }
}
