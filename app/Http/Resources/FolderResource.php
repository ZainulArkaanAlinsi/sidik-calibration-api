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

            /*
             * Alat milik PT ini — cuma keisi di folder AKAR PT (lihat
             * `FolderController::show`). Bikin folder PT jadi satu pintu masuk:
             * buka PT-nya, kelihatan alat apa aja yang dia punya, bukan cuma
             * berkasnya.
             *
             * `tanggal_jatuh_tempo` ikut karena itu yang bikin daftar ini
             * berguna, bukan cuma lengkap: yang dicari admin waktu buka folder
             * PT biasanya "alat mana yang udah waktunya dikalibrasi lagi".
             */
            'alat' => $this->when(
                $this->relationLoaded('customer')
                    && $this->customer?->relationLoaded('equipments'),
                fn () => $this->customer->equipments->map(fn ($alat) => [
                    'id' => $alat->id,
                    'nama_alat' => $alat->nama_alat,
                    'merk' => $alat->merk,
                    'model' => $alat->model,
                    'serial_number' => $alat->serial_number,
                    'status' => $alat->status,
                    'tanggal_kalibrasi_terakhir' => $alat->tanggal_kalibrasi_terakhir?->toDateString(),
                    'tanggal_jatuh_tempo' => $alat->tanggal_jatuh_tempo?->toDateString(),
                    'jumlah_kalibrasi' => $alat->calibration_sessions_count,
                ])->values(),
            ),
        ];
    }
}
