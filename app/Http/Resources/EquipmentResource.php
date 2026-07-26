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
                // Bagian OWNER di lembar kerja minta Name DAN Address, dua-duanya
                // keisi otomatis begitu teknisi milih alat. Tanpa alamat di sini
                // mobile kepaksa nembak /api/customers cuma buat satu baris —
                // dan itu endpoint admin, teknisi bakal kena 403.
                'alamat' => $this->customer?->alamat,
            ],
            'tanggal_kalibrasi_terakhir' => $this->tanggal_kalibrasi_terakhir?->toDateString(),
            'tanggal_jatuh_tempo' => $this->tanggal_jatuh_tempo?->toDateString(),
            // `overdue` dihitung dari tanggal jatuh tempo, bukan disimpen di DB.
            'status' => $this->statusUntukApi(),

            // Tambahan di luar kontrak (superset, aman buat mobile) — dibutuhin
            // waktu masuk fitur kalibrasi & perhitungan ketidakpastian.
            'model' => $this->model,
            'no_identifikasi' => $this->no_identifikasi,
            'range_min' => $this->range_min,
            'range_max' => $this->range_max,
            'satuan' => $this->satuan,
            'resolusi' => $this->resolusi,
            'toleransi' => $this->toleransi,
            'lokasi' => $this->lokasi,
            'nama_alat_kemampuan' => $this->nama_alat_kemampuan,
        ];
    }
}
