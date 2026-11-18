<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pelanggan\AlatPelangganResource;
use App\Models\Customer;
use App\Models\Equipment;
use App\Services\PenjagaOrganisasi;
use App\Services\RiwayatSertifikatAlat;
use App\Support\KursorId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/customers/{customer}/alat` — alat milik SATU pelanggan, ringkas,
 * paginasi kursor (kontrak: `docs/perintah-frontend-super-admin.md` §3.4).
 *
 * Untuk tab "Alat" di detail Pelanggan di aplikasi lab. `GET /equipments?customer_id=`
 * sudah ada tapi berpaginasi `page`, memuat bentuk penuh, dan tidak tahu jumlah
 * maupun sertifikat terbaru per alat; endpoint ini tidak menggantikannya.
 *
 * ## Konsisten dengan yang dilihat pelanggan
 *
 * `status_alat` dihitung oleh fungsi yang SAMA dengan aplikasi pelanggan
 * (`AlatPelangganResource::statusAlat()` + `konteks()`), bukan salinan: dua
 * salinan aturan REQ-ALT-05 suatu hari berbeda, dan yang tampak adalah lab
 * bilang "lewat jadwal" untuk alat yang di HP pelanggan masih "terkalibrasi".
 * Yang dipakai ulang hanya fungsi statis penurun status, bukan resource-nya —
 * bentuk JSON lab sendiri, tidak mewarisi resource dunia pelanggan.
 */
class PelangganAlatController extends Controller
{
    public function __construct(private readonly RiwayatSertifikatAlat $riwayat) {}

    public function index(Request $request, Customer $customer): JsonResponse
    {
        // Penjaga kepemilikan DULU, validasi query sesudahnya: `limit=999` ke
        // perusahaan lab lain harus tetap 404, bukan 422 (lihat `KursorId::baca`).
        PenjagaOrganisasi::pastikanSatu($request, $customer);

        [$setelahId, $limit] = KursorId::baca($request);

        $organisasi = (int) $request->user()->organization_id;

        [$alat, $berikutnya] = KursorId::potong(
            Equipment::query()
                ->where('organization_id', $organisasi)
                ->where('customer_id', $customer->getKey()),
            $setelahId,
            $limit,
        );

        $ringkasan = $this->riwayat->ringkasanPerAlat($alat->pluck('id')->all(), $organisasi);
        $konteks = AlatPelangganResource::konteks($alat);

        return response()->json([
            'data' => $alat->map(function (Equipment $satu) use ($ringkasan, $konteks): array {
                $hari = AlatPelangganResource::hariKeJatuhTempo($satu);

                return [
                    'id' => $satu->id,
                    'nama_alat' => $satu->nama_alat,
                    'merk' => $satu->merk,
                    'model' => $satu->model,
                    'serial_number' => $satu->serial_number,
                    'no_identifikasi' => $satu->no_identifikasi,
                    // Kosakata lab (sama dengan `GET /equipments`).
                    'status' => $satu->statusUntukApi(),
                    // Kode yang sama persis dengan yang dilihat pelanggan.
                    'status_alat' => AlatPelangganResource::statusAlat($satu, $hari, $konteks[$satu->id]['pernah_terbit']),
                    'tanggal_kalibrasi_terakhir' => $satu->tanggal_kalibrasi_terakhir?->toDateString(),
                    'tanggal_jatuh_tempo' => $satu->tanggal_jatuh_tempo?->toDateString(),
                    'hari_ke_jatuh_tempo' => $hari,
                    'jumlah_sertifikat' => $ringkasan[$satu->id]['jumlah'],
                    'sertifikat_terbaru' => $ringkasan[$satu->id]['terbaru'],
                ];
            })->values()->all(),
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $berikutnya,
                'ada_lagi' => $berikutnya !== null,
            ],
        ]);
    }
}
