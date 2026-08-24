<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\EquipmentCategory;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /** Buat dropdown kategori di mobile. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = EquipmentCategory::query()
            ->with('capabilities')
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('nama')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Detail 1 kategori + SEMUA rentang kemampuannya (CMC). Ini yang dipakai buat
     * validasi rentang alat & nyiapin worksheet — bukan ringkasan di /categories.
     */
    public function show(Request $request, string $kode, CalibrationProfileRegistry $registry): JsonResponse
    {
        $category = EquipmentCategory::query()
            ->with('capabilities')
            ->where('organization_id', $request->user()->organization_id)
            ->where('kode', $kode)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'kode' => $category->kode,
                'nama' => $category->nama,
                'kemampuan' => $category->capabilities->map(fn ($c) => [
                    'nama_alat' => $c->nama_alat,
                    'parameter' => $c->parameter,
                    'range_min' => $c->range_min,
                    'range_max' => $c->range_max,
                    // Teks asli kalau batas bawahnya non-numerik, contoh "ambient".
                    'range_note' => $c->range_note,
                    'satuan' => $c->satuan,
                    'ketidakpastian_terbaik' => $c->ketidakpastian_terbaik,
                    'satuan_ketidakpastian' => $c->satuan_ketidakpastian,
                    'faktor_cakupan' => $c->faktor_cakupan,
                    'metode' => $c->metode,
                    // Asal baris ini: `akreditasi` (salinan lampiran
                    // LK-285-IDN), `admin`, atau `teknisi`.
                    'sumber' => $c->sumber,
                    // PENANDA yang nggak boleh dibuang dari kontrak ini.
                    //
                    // Sejak teknisi boleh nambah nama alat sendiri dari
                    // lapangan, daftar ini bisa berisi baris yang cuma punya
                    // NAMA — tanpa rentang, tanpa angka ketidakpastian terbaik.
                    // Dari luar dia kelihatan persis sama kayak baris lampiran
                    // akreditasi, dan itu masalahnya: alat yang dikalibrasi
                    // pakai nama itu dihitung lewat jalur generik TANPA lantai
                    // CMC, jadi U95 yang terbit bisa lebih kecil daripada yang
                    // diakreditasi lab — tanpa satu pun error di mana pun.
                    //
                    // Jadi barisnya nandain dirinya sendiri di sini, biar layar
                    // teknisi bisa ngasih tau sebelum alatnya dipilih, bukan
                    // sesudah sertifikatnya kekirim ke pelanggan.
                    'tanpa_cmc' => $c->tanpaCmc(),
                    'alasan_tanpa_cmc' => $c->alasanTanpaCmc(),
                    // Kode lembar kerja khusus buat jenis alat ini, atau null
                    // kalau dia pakai form generik.
                    //
                    // Dulu pemetaan nama->lembar ini HARDCODED di APK (map
                    // `_profilKhusus`, 26 ejaan). Artinya tiap kali admin
                    // nambah nama alat baru, alat itu MUSTAHIL dapat lembar
                    // yang bener sampai ada rilis mobile — tabel
                    // penerjemahnya nangkring di HP orang, bukan di server
                    // yang punya datanya. Sekarang server yang jawab, dan
                    // ejaannya hidup di `aliasNama()` tiap profil.
                    //
                    // null di sini BUKAN kegagalan: itu jawaban "pakai form
                    // generik". Registry-nya sengaja nggak jatuh ke pH —
                    // lihat `CalibrationProfileRegistry::kodeProfilDariNama()`.
                    'profil' => $registry->kodeProfilDariNama((string) $c->nama_alat),
                ]),
            ],
        ]);
    }
}
