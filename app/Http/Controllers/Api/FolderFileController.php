<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesFolderAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderFileResource;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\FolderFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File di dalam Folder Manager (spesifikasi poin 3).
 *
 * Dua macam isinya, dan bedanya penting:
 *
 * - `sertifikat` — TAUTAN ke sertifikat resmi. Nggak nyimpen salinan file, dan
 *   dihapus dari sini nggak ngehapus sertifikatnya. Kalau disalin, dua berkas
 *   bisa beda isi dan nggak ada yang tau mana yang sah.
 * - `unggahan`   — dokumen pendukung yang beneran diunggah admin (foto alat,
 *   surat jalan, lampiran). File-nya disimpan di disk privat `local`.
 */
class FolderFileController extends Controller
{
    use ScopesFolderAccess;

    /** Semua file yang boleh dilihat, bisa disaring per folder. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $file = $this->queryFileYangBolehDilihat($request)
            ->with(['certificate', 'uploader'])
            ->when(
                $request->filled('folder_id'),
                fn (Builder $query) => $query->where('folder_id', $request->integer('folder_id')),
            )
            ->when(
                $request->filled('q'),
                fn (Builder $query) => $query->where('nama', 'like', '%'.$request->string('q').'%'),
            )
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return FolderFileResource::collection($file);
    }

    /** Unggah dokumen pendukung ke sebuah folder. Admin doang. */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'folder_id' => [
                'required',
                Rule::exists('folders', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'file' => ['required', 'file', 'max:20480'],
            'nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.max' => 'File maksimal 20 MB.',
        ]);

        $unggahan = $request->file('file');
        // Disk `local` (privat): isi folder ini dokumen kerja lab, bukan
        // konsumsi publik. Satu-satunya jalan ngambilnya lewat endpoint
        // download di bawah, yang ngecek hak akses.
        $path = $unggahan->store('folder-files', 'arsip');

        // Tanpa ini, tulis yang gagal bikin baris FolderFile dengan `path`
        // berisi `false` — barisnya muncul di daftar folder, keliatan seperti
        // dokumen yang ada, tapi tiap kali diunduh balik 404.
        if ($path === false) {
            return response()->json(['message' => 'File gagal disimpan. Coba unggah lagi.'], 500);
        }

        $file = FolderFile::create([
            'organization_id' => $organizationId,
            'folder_id' => $data['folder_id'],
            'uploaded_by' => $request->user()->id,
            'nama' => $data['nama'] ?? $unggahan->getClientOriginalName(),
            'sumber' => FolderFile::SUMBER_UNGGAHAN,
            'path' => $path,
            'mime' => $unggahan->getClientMimeType(),
            'ukuran' => $unggahan->getSize(),
            'keterangan' => $data['keterangan'] ?? null,
        ]);

        return response()->json([
            'data' => new FolderFileResource($file->load(['certificate', 'uploader'])),
        ], 201);
    }

    /** Ganti nama / keterangan / pindah folder. Admin doang. */
    public function update(Request $request, FolderFile $folderFile): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folderFile);

        $data = $request->validate([
            'nama' => ['sometimes', 'string', 'max:255'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'folder_id' => [
                'sometimes',
                Rule::exists('folders', 'id')
                    ->where('organization_id', $request->user()->organization_id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        // Satu sertifikat cuma boleh nongol sekali per folder (ada unique index
        // di DB). Ditahan di sini biar pesannya kebaca manusia, bukan 500.
        if (isset($data['folder_id']) && $folderFile->certificate_id !== null) {
            $bentrok = FolderFile::where('folder_id', $data['folder_id'])
                ->where('certificate_id', $folderFile->certificate_id)
                ->whereKeyNot($folderFile->id)
                ->exists();

            if ($bentrok) {
                return response()->json([
                    'message' => 'Sertifikat ini udah ada di folder tujuan.',
                ], 422);
            }
        }

        $folderFile->update($data);

        return response()->json([
            'data' => new FolderFileResource($folderFile->fresh()->load(['certificate', 'uploader'])),
        ]);
    }

    /**
     * Pindahin berkas sertifikat, dikunci pakai **id SESI KALIBRASI**
     * (`docs/permintaan-backend-2026-07-24.md` §2b).
     *
     * Kenapa perlu alias ini padahal `update()` udah bisa pindah lewat `folder_id`:
     * yang dipegang mobile di layar arsip itu **id sesi**, bukan id `folder_files`.
     * Tanpa alias ini, mobile harus nebak dulu berkas mana yang punya sesi itu —
     * dan satu-satunya cara nebaknya bener itu nyapu `/folder-files` sambil
     * ngebandingin `sertifikat.id`, yang artinya request tambahan buat tiap kali
     * drag.
     *
     * Admin doang.
     */
    public function pindahBerkasSesi(Request $request, CalibrationSession $calibration): JsonResponse
    {
        // 404, bukan 403 — id sesi lab lain nggak boleh bisa dipakai buat mastiin
        // sesi itu ada.
        abort_if(
            $calibration->organization_id !== $request->user()->organization_id,
            404,
        );

        $data = $request->validate([
            'folder_id' => [
                'required',
                Rule::exists('folders', 'id')
                    ->where('organization_id', $request->user()->organization_id)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'folder_id.required' => 'Kirim `folder_id` folder tujuannya.',
            'folder_id.exists' => 'Folder tujuannya nggak ketemu.',
        ]);

        $sertifikat = $calibration->certificate;

        abort_if(
            $sertifikat === null,
            404,
            'Sesi ini belum punya sertifikat, jadi belum ada berkasnya di arsip.',
        );

        $berkas = FolderFile::where('certificate_id', $sertifikat->id)->get();

        abort_if(
            $berkas->isEmpty(),
            404,
            'Sertifikat sesi ini belum tertaut ke folder mana pun.',
        );

        // Satu sertifikat BISA nyangkut di lebih dari satu folder — unique
        // index-nya `(folder_id, certificate_id)`, bukan `certificate_id` sendiri.
        // Kalau kejadian, nebak yang mana yang dimaksud itu lebih jahat daripada
        // nanya: yang salah kepindah nggak keliatan sebagai error, cuma sebagai
        // berkas yang ilang dari folder yang orangnya lihat.
        if ($berkas->count() > 1) {
            return response()->json([
                'message' => 'Sertifikat sesi ini tertaut di '.$berkas->count().' folder, jadi nggak '
                    .'jelas yang mana yang mau dipindah. Pakai `PUT /api/folder-files/{id}` '
                    .'dengan id berkasnya langsung.',
                'data' => FolderFileResource::collection($berkas->load(['certificate', 'uploader'])),
            ], 422);
        }

        $satu = $berkas->first();

        if ($satu->folder_id === (int) $data['folder_id']) {
            // Udah di situ — tetap 200 biar drag ke tempat yang sama nggak error.
            return response()->json([
                'data' => new FolderFileResource($satu->load(['certificate', 'uploader'])),
            ]);
        }

        // Aturan yang sama kayak `update()`: satu sertifikat cuma boleh nongol
        // sekali per folder. Ditahan di sini biar pesannya kebaca manusia, bukan
        // 500 dari unique index.
        $bentrok = FolderFile::where('folder_id', $data['folder_id'])
            ->where('certificate_id', $sertifikat->id)
            ->whereKeyNot($satu->id)
            ->exists();

        if ($bentrok) {
            return response()->json([
                'message' => 'Sertifikat ini udah ada di folder tujuan.',
            ], 422);
        }

        $satu->update(['folder_id' => (int) $data['folder_id']]);

        return response()->json([
            'data' => new FolderFileResource($satu->fresh()->load(['certificate', 'uploader'])),
        ]);
    }

    /** Admin doang. */
    public function destroy(Request $request, FolderFile $folderFile): JsonResponse
    {
        $this->pastikanSatuOrganisasi($request, $folderFile);

        // Sengaja soft delete, dan file fisiknya NGGAK dihapus dari disk.
        // Sertifikat & lampirannya itu rekaman mutu — salah klik di aplikasi
        // nggak boleh langsung ngilangin bukti audit. Pembersihan file yatim
        // urusan perawatan terjadwal, bukan tombol hapus.
        $folderFile->delete();

        return response()->json(null, 204);
    }

    /**
     * Unduh isinya. Buat file sertifikat, yang dikirim PDF sertifikat aslinya —
     * jadi selalu versi terbaru yang sah, bukan salinan basi.
     */
    public function download(Request $request, FolderFile $folderFile): StreamedResponse
    {
        $this->pastikanBolehLihat($request, $folderFile);

        if ($folderFile->sumber === FolderFile::SUMBER_SERTIFIKAT) {
            $sertifikat = $folderFile->certificate;

            abort_unless(
                $sertifikat?->status === Certificate::STATUS_TERBIT && $sertifikat->pdf_path,
                404,
                'Sertifikatnya belum punya PDF yang bisa diunduh.',
            );

            abort_unless(Storage::disk('arsip')->exists($sertifikat->pdf_path), 404);

            return Storage::disk('arsip')->download($sertifikat->pdf_path, $sertifikat->namaFile('pdf'));
        }

        // Lembar kerja itu TAUTAN ke record, bukan berkas — `path`-nya memang
        // null. Kalau dibiarin jatuh ke 404 di bawah, pesannya jadi
        // "nggak ketemu", padahal barisnya ada dan sah; yang nggak ada cuma
        // berkas buat diunduh. Bedanya penting: "nggak ketemu" bikin orang
        // ngira datanya ilang.
        abort_if(
            $folderFile->sumber === FolderFile::SUMBER_LEMBAR_KERJA,
            422,
            'Ini tautan ke lembar kerja, bukan berkas unduhan. '
            .'Buka sesi kalibrasinya buat lihat isinya.',
        );

        abort_unless($folderFile->path && Storage::disk('arsip')->exists($folderFile->path), 404);

        return Storage::disk('arsip')->download($folderFile->path, $folderFile->nama);
    }

    private function pastikanBolehLihat(Request $request, FolderFile $file): void
    {
        $this->pastikanSatuOrganisasi($request, $file);

        // Daftar udah disaring, tapi unduhan per-ID tetap harus dicek sendiri —
        // tanpa ini tinggal tebak ID buat narik file orang lain.
        abort_unless(
            $this->queryFileYangBolehDilihat($request)->whereKey($file->id)->exists(),
            404,
        );
    }

    /** Jaring pengaman multi-tenant. */
    private function pastikanSatuOrganisasi(Request $request, FolderFile $file): void
    {
        abort_if($file->organization_id !== $request->user()->organization_id, 404);
    }
}
