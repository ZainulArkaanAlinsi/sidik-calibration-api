<?php

namespace App\Http\Resources;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuknya dikunci sama docs/kontrak-api.md (repo mobile).
 *
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama_alat' => $this->nama_alat,
            'serial_number' => $this->serial_number,
            'kategori' => $this->category?->kode,
            'merk' => $this->merk,
            'pelanggan' => [
                'id' => $this->customer?->id,
                'nama' => $this->customer?->nama,
            ],
            'tanggal_kalibrasi_terakhir' => $this->tanggal_kalibrasi_terakhir?->toIso8601ZuluString(),
            'tanggal_jatuh_tempo' => $this->tanggal_jatuh_tempo?->toIso8601ZuluString(),
            // `overdue` dihitung dari tanggal jatuh tempo, bukan disimpen di DB.
            'status' => $this->statusUntukApi(),

            // Tambahan di luar kontrak (superset, aman buat mobile) — dibutuhin
            // waktu masuk fitur kalibrasi & perhitungan ketidakpastian.
            'model' => $this->model,
            'no_identifikasi' => $this->no_identifikasi,
            'range_min' => $this->range_min,
            'range_max' => $this->range_max,
            'satuan' => $this->satuan,
            // Versi siap-tempel dari range_min/max/satuan, buat form Order yang
            // butuh rentangnya sebagai satu baris ("0–14 pH"). Dihitung di sini,
            // BUKAN kolom baru — datanya udah ada, yang belum cuma bentuknya.
            // Logikanya pindah ke model — dipakai juga di detail sesi kalibrasi.
            'rentang_ukur' => $this->resource->rentangUkur(),
            'resolusi' => $this->resolusi,
            'toleransi' => $this->toleransi,
            'lokasi' => $this->lokasi,
            'nama_alat_kemampuan' => $this->nama_alat_kemampuan,
        ];
    }
}
