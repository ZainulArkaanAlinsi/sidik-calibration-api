<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu kartu di antrean pengesahan (artboard `SA_Gerbang_Sertifikat`).
 *
 * Sengaja BUKAN `CalibrationResource`. Yang dibutuhkan layar ini cuma cukup
 * untuk memutuskan "buka yang mana dulu" — sementara `CalibrationResource`
 * membawa serta pembacaan mentah, perhitungan ketidakpastian, dan relasi
 * standar. Di antrean 40 baris itu puluhan kali lebih banyak data daripada yang
 * dipakai, dan super admin biasanya membukanya dari HP.
 *
 * Detail lengkapnya diambil layar berikutnya (`SA_Sahkan_Detail`) lewat
 * `GET /api/calibrations/{id}` yang sudah ada — jadi tidak ada endpoint baru
 * untuk itu.
 *
 * @mixin CalibrationSession
 */
class AntreanPengesahanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomor_sesi' => $this->nomor_sesi,
            'status' => $this->status,
            'keputusan' => $this->keputusan,

            'alat' => [
                'nama' => $this->equipment?->nama_alat,
                'merk' => $this->equipment?->merk,
                'model' => $this->equipment?->model,
                'serial_number' => $this->equipment?->serial_number,
                'pelanggan' => $this->equipment?->customer?->nama,
            ],

            // Inisial, bukan nama lengkap — yang tercetak di sertifikat juga
            // `kode_teknisi` (mis. `RZP`). Kartu antrean menampilkan hal yang
            // sama supaya apa yang dilihat pengesah di layar dan apa yang keluar
            // di kertas tidak pernah berbeda.
            'teknisi' => [
                'nama' => $this->teknisi?->name,
                'kode' => $this->teknisi?->kode_teknisi,
            ],
            'diperiksa_oleh' => $this->reviewer?->name,
            'diajukan_oleh' => $this->pengaju?->name,

            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toDateString(),
            'diajukan_pada' => $this->diajukan_pada?->toIso8601String(),

            // Umur pengajuan dihitung DI SERVER, bukan di mobile. Kalau jam HP
            // teknisi meleset — dan di lapangan itu biasa — angka "menunggu 3
            // hari" ikut meleset, dan angka itulah yang dipakai memutuskan
            // urutan kerja. Null berarti barisnya dipindahkan tanpa jejak
            // pengajuan (lihat migrasi), dan layar menampilkannya sebagai
            // "paling lama" bukan "baru".
            'menunggu_hari' => $this->diajukan_pada?->diffInDays(now()),

            'catatan_pengajuan' => $this->catatan_pengajuan,
            'berlaku_sampai_diminta' => $this->berlaku_sampai_diminta?->toDateString(),
            'penandatangan' => $this->whenLoaded(
                'penandatangan',
                fn () => ['id' => $this->penandatangan?->id, 'nama' => $this->penandatangan?->name],
            ),
        ];
    }
}
