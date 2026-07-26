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
     * Unggah logo lab yang dicetak di kop sertifikat (fase-2 §3a).
     *
     * Multipart, field `logo`. Admin doang (dijaga `role:admin` di routes).
     *
     * Disimpen di disk **public**, bukan private — beda dari PDF sertifikat.
     * Alasannya: `GenerateCertificate::logoDataUri()` udah baca dari disk publik,
     * dan logo perusahaan itu identitas yang memang dipajang, bukan data
     * pelanggan. Kalau ditaruh di disk privat, kop sertifikat kehilangan logonya
     * dan mobile butuh route ber-auth cuma buat nampilin gambar statis.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            // PNG & JPG DOANG — sengaja nggak nerima WEBP/GIF.
            //
            // Logonya berakhir di PDF lewat dompdf, dan dompdf nggak bisa render
            // WEBP. Lebih dari itu, `GenerateCertificate::logoDataUri()` nebak mime
            // dari ekstensi (`.png` → image/png, selain itu → image/jpeg), jadi
            // WEBP bakal dilabeli JPEG dan kop sertifikatnya rusak TANPA error —
            // ketahuannya baru waktu ada yang buka PDF-nya.
            //
            // `dimensions` nggak dipakai: logo lab bentuknya macem-macem, dan
            // nolak file cuma gara-gara rasio bikin admin mentok tanpa jalan
            // keluar. Ukuran fisiknya diurus waktu render, bukan waktu unggah.
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ], [
            'logo.required' => 'File logonya wajib ada.',
            'logo.image' => 'File-nya harus berupa gambar.',
            'logo.mimes' => 'Format logo harus PNG atau JPG — WEBP nggak bisa dicetak di PDF sertifikat.',
            'logo.max' => 'Logo maksimal 2 MB.',
        ]);

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        $lama = $organization->logo_path;

        // Nama file diacak, BUKAN pakai nama asli dari admin. Nama asli bisa
        // bikin path traversal & nabrak file org lain kalau dua PT ngunggah
        // "logo.png" — dan URL-nya publik, jadi nama yang bisa ditebak bikin logo
        // satu lab kesenggol yang lain.
        $path = $request->file('logo')->store("logo-organisasi/{$organization->id}", 'public');

        $organization->update(['logo_path' => $path]);

        // Yang lama dihapus SESUDAH yang baru kesimpen & tercatat. Kalau dihapus
        // duluan lalu unggahnya gagal, org-nya kehilangan logo tanpa gantinya.
        if ($lama && $lama !== $path) {
            Storage::disk('public')->delete($lama);
        }

        return response()->json(['data' => new OrganizationResource($organization->fresh())]);
    }

    /** Hapus logo — kop sertifikat balik ke logo bawaan. */
    public function deleteLogo(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->organization;

        if ($organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
            $organization->update(['logo_path' => null]);
        }

        return response()->json(['data' => new OrganizationResource($organization->fresh())]);
    }
}
