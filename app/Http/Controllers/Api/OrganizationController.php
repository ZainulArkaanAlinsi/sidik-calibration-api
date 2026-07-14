<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master data PT (nama, alamat, no akreditasi) — yang dicetak di kop sertifikat.
 * Admin doang.
 *
 * Sengaja NGGAK ada store/destroy: satu instalasi = satu PT, dan organisasi itu
 * akar dari semua data (user, alat, sertifikat). Bikin/hapus organisasi lewat API
 * bakal gampang bikin data yatim.
 */
class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new OrganizationResource($request->user()->organization),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['sometimes', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'no_akreditasi' => ['nullable', 'string', 'max:100'],
            'standar_akreditasi' => ['nullable', 'string', 'max:255'],
            'akreditasi_mulai' => ['nullable', 'date'],
            'akreditasi_berakhir' => ['nullable', 'date', 'after:akreditasi_mulai'],
            'settings' => ['nullable', 'array'],
        ], [
            'nama.required' => 'Nama PT wajib diisi.',
        ]);

        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $organization->update($validated);

        return response()->json(['data' => new OrganizationResource($organization->fresh())]);
    }
}
