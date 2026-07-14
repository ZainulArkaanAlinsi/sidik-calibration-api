<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\EquipmentCategory;
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
    public function show(Request $request, string $kode): JsonResponse
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
                ]),
            ],
        ]);
    }
}
