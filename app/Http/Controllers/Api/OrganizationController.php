<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Unggah gambar tanda tangan penanda tangan sertifikat (fase-2 §3c).
     *
     * Multipart, field `tanda_tangan`. **Admin doang** — dan itu memang yang diminta:
     * teknisi & viewer nggak boleh nyentuh ini sama sekali.
     *
     * ## Disimpen di disk PRIVAT, beda dari logo
     *
     * Logo ada di disk `public` karena itu identitas yang memang dipajang. Tanda
     * tangan **nggak boleh**: gambar tanda tangan yang URL-nya bisa diakses siapa pun
     * berarti siapa pun bisa nempelin ke dokumen palsu. Nggak ada alasan sah buat
     * bikin file ini kebuka ke internet — yang butuh cuma dompdf, dan dompdf jalan di
     * server.
     *
     * Konsekuensinya: responsnya **nggak** bawa URL, cuma penanda
     * `punya_tanda_tangan`. Buat pratinjau di layar admin ada endpoint terpisah yang
     * nge-stream file-nya dengan pengecekan hak akses.
     *
     * ## PNG doang, bukan PNG/JPG kayak logo
     *
     * JPG nggak punya alpha channel. Tanda tangan JPG bakal kecetak sebagai **kotak
     * putih** yang nutupin garis tanda tangan & nama di bawahnya — rusak, tapi
     * rusaknya SUNYI: nggak ada error, ketahuannya baru waktu ada yang buka PDF-nya.
     * Jadi ditolak di pintu masuk, dengan pesan yang nyebut alasannya.
     */
    public function uploadTandaTangan(Request $request): JsonResponse
    {
        $request->validate([
            'tanda_tangan' => ['required', 'image', 'mimes:png', 'max:2048'],
        ], [
            'tanda_tangan.required' => 'File tanda tangannya wajib ada.',
            'tanda_tangan.image' => 'File-nya harus berupa gambar.',
            'tanda_tangan.mimes' => 'Tanda tangan harus PNG dengan latar transparan. '
                .'JPG nggak punya latar transparan, jadi bakal kecetak sebagai kotak putih '
                .'yang nutupin garis tanda tangan & nama di bawahnya.',
            'tanda_tangan.max' => 'Tanda tangan maksimal 2 MB.',
        ]);

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        $lama = $organization->tanda_tangan_path;

        // Nama file diacak, sama alasannya kayak logo — plus di sini lebih penting:
        // nama yang bisa ditebak di gabungan dengan salah konfigurasi disk bikin
        // gambar tanda tangan bisa dicari-cari.
        $path = $request->file('tanda_tangan')->store("tanda-tangan/{$organization->id}", 'local');

        $organization->update(['tanda_tangan_path' => $path]);

        // Yang lama dihapus SESUDAH yang baru kesimpen & tercatat — kalau dihapus
        // duluan lalu unggahnya gagal, sertifikat berikutnya terbit tanpa tanda
        // tangan tanpa ada yang sadar.
        if ($lama && $lama !== $path) {
            Storage::disk('local')->delete($lama);
        }

        return response()->json(['data' => new OrganizationResource($organization->fresh())]);
    }

    /** Hapus gambar tanda tangan — sertifikat balik nyetak garis kosong. */
    public function deleteTandaTangan(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->organization;

        if ($organization->tanda_tangan_path) {
            Storage::disk('local')->delete($organization->tanda_tangan_path);
            $organization->update(['tanda_tangan_path' => null]);
        }

        return response()->json(['data' => new OrganizationResource($organization->fresh())]);
    }

    /**
     * Pratinjau gambar tanda tangan — buat layar pengaturan & nanti buat drag-drop.
     *
     * Ada karena file-nya di disk privat, jadi nggak bisa dipanggil lewat URL
     * storage. Admin doang, dan di-scope ke organisasinya sendiri: tanda tangan lab
     * lain nggak boleh kebuka dari sini.
     */
    public function previewTandaTangan(Request $request): StreamedResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->organization;

        abort_unless(
            filled($organization->tanda_tangan_path)
                && Storage::disk('local')->exists($organization->tanda_tangan_path),
            404,
            'Belum ada gambar tanda tangan yang diunggah.',
        );

        return Storage::disk('local')->response(
            $organization->tanda_tangan_path,
            'tanda-tangan.png',
            [
                'Content-Type' => 'image/png',
                // `private`: ini aset yang nggak boleh nyangkut di cache perantara.
                'Cache-Control' => 'private, max-age=60',
            ],
        );
    }

    /**
     * Setel posisi & ukuran cetak tanda tangan (fase-2 §3c).
     *
     * Ini yang bakal dipakai UI drag-and-drop: geser di layar → kirim geseran ke
     * sini. Disimpen SEKALI di tingkat template, **bukan per sertifikat** — dan itu
     * keputusan yang disengaja:
     *
     * Sertifikat yang udah terbit itu dokumen terkendali. Kalau posisi tanda tangan
     * disimpen per sertifikat, berarti sertifikat yang udah dikirim ke pelanggan bisa
     * diubah — dan dua orang bisa punya PDF beda dengan nomor sertifikat yang sama.
     * Dengan disimpen di template, admin geser SEKALI dan semua sertifikat rapi &
     * konsisten.
     *
     * Geserannya RELATIF ke blok tanda tangan, bukan koordinat absolut halaman:
     * tinggi isi sertifikat berubah-ubah (jumlah titik ukur & standar beda tiap
     * sesi), jadi koordinat absolut yang pas di satu sertifikat bakal nimpa tabel di
     * sertifikat lain.
     */
    public function updatePosisiTandaTangan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'geser_x_mm' => [
                'sometimes', 'nullable', 'numeric',
                'between:-'.Organization::MAKS_TTD_GESER_MM.','.Organization::MAKS_TTD_GESER_MM,
            ],
            'geser_y_mm' => [
                'sometimes', 'nullable', 'numeric',
                'between:-'.Organization::MAKS_TTD_GESER_MM.','.Organization::MAKS_TTD_GESER_MM,
            ],
            'lebar_mm' => [
                'sometimes', 'nullable', 'numeric',
                'between:'.Organization::MIN_TTD_LEBAR_MM.','.Organization::MAKS_TTD_LEBAR_MM,
            ],
        ], [
            'geser_x_mm.between' => 'Geseran horizontal maksimal ±'.Organization::MAKS_TTD_GESER_MM.' mm — '
                .'lebih dari itu tanda tangannya keluar dari bloknya.',
            'geser_y_mm.between' => 'Geseran vertikal maksimal ±'.Organization::MAKS_TTD_GESER_MM.' mm.',
            'lebar_mm.between' => 'Lebar cetak harus '.Organization::MIN_TTD_LEBAR_MM.'–'
                .Organization::MAKS_TTD_LEBAR_MM.' mm. Di bawah itu nggak kebaca, di atasnya nutupin teks.',
        ]);

        /** @var Organization $organization */
        $organization = $request->user()->organization;

        // Digabung ke `settings` yang udah ada, BUKAN ditimpa — `settings` juga
        // nyimpen penandatangan, kode dokumen, ambang reminder, masa berlaku.
        // Nimpa seluruh array bikin semuanya ilang sekali klik.
        $settings = $organization->settings ?? [];

        $peta = [
            'geser_x_mm' => Organization::KEY_TTD_GESER_X,
            'geser_y_mm' => Organization::KEY_TTD_GESER_Y,
            'lebar_mm' => Organization::KEY_TTD_LEBAR,
        ];

        foreach ($peta as $masukan => $kunci) {
            if (! array_key_exists($masukan, $data)) {
                continue;
            }

            // `null` artinya balik ke bawaan — kuncinya dibuang, bukan disimpen null.
            if ($data[$masukan] === null) {
                unset($settings[$kunci]);

                continue;
            }

            $settings[$kunci] = (float) $data[$masukan];
        }

        $organization->update(['settings' => $settings]);

        return response()->json([
            'data' => new OrganizationResource($organization->fresh()),
        ]);
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
