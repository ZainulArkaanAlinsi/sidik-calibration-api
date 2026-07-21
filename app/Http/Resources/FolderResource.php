<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu folder di daftar isi folder induknya.
 *
 * `tipe` sengaja ikut dikirim walau semua isinya folder — frontend nampilin
 * subfolder dan berkas di satu daftar campur, jadi tiap baris harus bisa
 * ngenalin dirinya sendiri tanpa lihat dia datang dari array yang mana.
 *
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
            'tipe' => 'folder',
            'id' => $this->id,
            'nama' => $this->nama,
            'parent_id' => $this->parent_id,
            // Folder akar dibikin sistem: frontend pakai ini buat nyembunyiin
            // tombol Rename/Hapus/Pindah, bukan nunggu ditolak 422 dulu.
            'is_root' => $this->is_root,
            'jumlah_subfolder' => (int) ($this->children_count ?? 0),
            'jumlah_berkas' => (int) ($this->calibration_sessions_count ?? 0),
            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
