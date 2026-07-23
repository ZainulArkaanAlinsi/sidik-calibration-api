<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use App\Models\FolderFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FolderFile
 */
class FolderFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folder_id' => $this->folder_id,
            'nama' => $this->nama,
            'sumber' => $this->sumber,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'keterangan' => $this->keterangan,

            // File sertifikat nggak disalin — dia nunjuk ke sertifikat aslinya.
            'sertifikat' => $this->certificate ? [
                'id' => $this->certificate->id,
                'nomor' => $this->certificate->nomor,
                'status' => $this->certificate->status,
                'siap_diunduh' => $this->certificate->status === Certificate::STATUS_TERBIT,
            ] : null,

            'download_url' => route('folder-files.download', $this->resource),
            'diunggah_oleh' => $this->uploader?->name,
            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
