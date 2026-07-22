<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Unggah logo lab buat kop sertifikat. Multipart, field `logo`.
     *
     * Disimpen di disk `public` (bukan `local`) supaya `logo_url` bisa dibuka
     * langsung tanpa token — logo lab bukan barang rahasia, dan PDF-nya juga
     * baca dari situ (lihat `GenerateCertificate::logoDataUri`).
     *
     * Logo lama dihapus setelah yang baru berhasil disimpen — kalau nggak,
     * tiap ganti logo ninggalin sampah di disk selamanya.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            // PNG/JPG doang: dompdf nggak bisa nge-render SVG, jadi nerima SVG
            // cuma bikin logonya diam-diam ilang dari sertifikat.
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ], [
            'logo.required' => 'File logonya wajib ada.',
            'logo.image' => 'Logo harus berupa gambar.',
            'logo.mimes' => 'Logo harus PNG atau JPG — SVG nggak kebaca waktu bikin PDF sertifikat.',
            'logo.max' => 'Logo maksimal 2 MB.',
        ]);

        /** @var Organization $organization */
        $organization = $request->user()->organization;
        $lama = $organization->logo_path;

        $path = $request->file('logo')->store('logo', 'public');
        $organization->update(['logo_path' => $path]);

        if ($lama && $lama !== $path) {
            Storage::disk('public')->delete($lama);
        }

        return response()->json(['data' => new OrganizationResource($organization->fresh())]);
    }
}
