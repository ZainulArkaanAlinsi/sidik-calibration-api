<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris laporan kalibrasi (fase-2 §5).
 *
 * Sengaja BUKAN `CalibrationResource`. Laporan itu tabel: yang dibutuhin cuma
 * kolom yang muat di satu baris, dan `CalibrationResource` bawa `titik[]`,
 * `type_b_components`, `pembacaan_mentah`, `status_standar` — puluhan kali lebih
 * besar per baris, buat data yang nggak dipajang di tabel. Di lab yang datanya
 * setahun, itu bedanya laporan kebuka atau HP-nya keabisan memori.
 *
 * Isinya nyocokin `LaporanKalibrasi::baris()` (yang dipakai PDF & Excel), jadi
 * layar dan file nunjukin kolom yang sama.
 *
 * @mixin CalibrationSession
 */
class LaporanKalibrasiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $penentu = $this->titikPenentu();

        return [
            'id' => $this->id,
            'nomor_sesi' => $this->nomor_sesi,
            // Tanggal polos `2024-05-26`, BUKAN ISO ber-zona waktu. Kolomnya cuma
            // tanggal (nggak ada jamnya), dan `toIso8601ZuluString()` di zona
            // Jakarta bikin dua kerusakan sekaligus: (1) layar nulis
            // `2024-05-25T17:00:00Z` sementara Excel/PDF nulis `2024-05-26` buat
            // sesi yang sama — dua tanggal beda buat satu pengukuran; (2) nilai
            // yang dikembalikan nggak bisa dipakai balik jadi penyaring `dari`/
            // `sampai`, jadi date-picker "1–31 Juli" diam-diam ngebuang sesi
            // tanggal 1. Ini nyocokin `LaporanKalibrasi::baris()`.
            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toDateString(),
            'pelanggan' => $this->equipment?->customer ? [
                'id' => $this->equipment->customer->id,
                'nama' => $this->equipment->customer->nama,
            ] : null,
            'alat' => [
                'id' => $this->equipment?->id,
                'nama_alat' => $this->equipment?->nama_alat,
                'serial_number' => $this->equipment?->serial_number,
            ],
            'kategori' => $this->equipment?->category ? [
                'kode' => $this->equipment->category->kode,
                'nama' => $this->equipment->category->nama,
            ] : null,
            'teknisi' => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
                'kode_teknisi' => $this->teknisi?->kodeTeknisi(),
            ],
            'status' => $this->status,
            'keputusan' => $this->keputusan,
            // Dari titik PENENTU (paling mepet batas toleransi) — angka yang sama
            // yang dipajang di `hasil` detail sesi, bukan rata-rata semua titik.
            'ketidakpastian_diperluas' => $penentu?->ketidakpastian_diperluas,
            'sertifikat' => $this->certificate ? [
                'id' => $this->certificate->id,
                'nomor' => $this->certificate->nomor,
                'status' => $this->certificate->status,
            ] : null,
        ];
    }
}
