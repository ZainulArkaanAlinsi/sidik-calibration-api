<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesFolderAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderFileResource;
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
        $path = $unggahan->store('folder-files', 'local');

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

            abort_unless(Storage::disk('local')->exists($sertifikat->pdf_path), 404);

            return Storage::disk('local')->download($sertifikat->pdf_path, $sertifikat->namaFile('pdf'));
        }

        abort_unless($folderFile->path && Storage::disk('local')->exists($folderFile->path), 404);

        return Storage::disk('local')->download($folderFile->path, $folderFile->nama);
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
