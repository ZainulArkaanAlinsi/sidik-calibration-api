<?php

namespace App\Http\Controllers\Pelanggan;

use App\Http\Controllers\Controller;
use App\Models\CustomerMember;
use App\Services\Pelanggan\PreferensiNotifikasi;
use App\Support\Pelanggan\Konteks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/preferensi-notifikasi` — saklar notifikasi satu anggota di SATU perusahaan.
 *
 * Anggotanya diambil dari `Konteks` (perusahaan yang sedang aktif di header
 * `X-Perusahaan-Id`), bukan dari body: konsultan yang mengikuti tiga pabrik
 * punya tiga set saklar, dan layarnya menulis "setelan ini khusus untuk PT X".
 * Mengubah saklar pabrik A tidak boleh bisa terjadi lewat header pabrik B.
 */
class PreferensiNotifikasiController extends Controller
{
    public function __construct(private readonly PreferensiNotifikasi $preferensi) {}

    public function tampil(Konteks $konteks): JsonResponse
    {
        return response()->json(['data' => $this->preferensi->untuk($this->anggota($konteks))]);
    }

    public function simpan(Request $request, Konteks $konteks): JsonResponse
    {
        // Sebagian boleh: yang tidak dikirim tidak berubah. Sakelar disimpan
        // langsung per ketukan di layar, jadi kirim satu kunci itu kasus normal.
        $data = $request->validate(
            array_fill_keys(PreferensiNotifikasi::kunci(), ['sometimes', 'boolean']),
        );

        $nilai = array_map(fn ($v): bool => (bool) $v, $data);

        return response()->json([
            'data' => $this->preferensi->simpan($this->anggota($konteks), $nilai),
        ]);
    }

    private function anggota(Konteks $konteks): CustomerMember
    {
        // `findOrFail` pada id dari KONTEKS — bukan dari request. Anggota yang
        // dinonaktifkan di tengah sesi sudah ditolak `perusahaan` di grup rute.
        return CustomerMember::query()->findOrFail($konteks->memberId);
    }
}
