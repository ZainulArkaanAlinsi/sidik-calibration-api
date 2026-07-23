<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            // `sistem` = kebentuk otomatis dari data. Mobile pakai ini buat
            // nyembunyiin tombol rename/hapus, biar user nggak nyoba lalu
            // ditolak server.
            'tipe' => $this->tipe,
            'parent_id' => $this->parent_id,
            'keterangan' => $this->keterangan,
            'pelanggan' => $this->customer ? [
                'id' => $this->customer->id,
                'nama' => $this->customer->nama,
            ] : null,
            // Dihitung lewat withCount di controller; kalau nggak dimuat,
            // biarin null daripada nge-query per baris.
            'jumlah_folder' => $this->whenCounted('children'),
            'jumlah_file' => $this->whenCounted('files'),
            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),

            'sub_folder' => FolderResource::collection($this->whenLoaded('children')),
            'file' => FolderFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
