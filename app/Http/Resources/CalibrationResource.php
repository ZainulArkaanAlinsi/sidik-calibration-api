<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\UncertaintyCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuknya dikunci sama docs/kontrak-api.md bagian 4 (repo mobile).
 *
 * @mixin CalibrationSession
 */
class CalibrationResource extends JsonResource
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
            'equipment' => [
                'id' => $this->equipment?->id,
                'nama_alat' => $this->equipment?->nama_alat,
                'serial_number' => $this->equipment?->serial_number,
            ],
            'teknisi' => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
            ],
            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toIso8601ZuluString(),
            'status' => $this->status,
            'input_method' => $this->input_method,

            // Sesi bisa punya banyak titik ukur, tapi kontrak minta SATU objek
            // `hasil`. Yang dikirim adalah titik penentu — yang paling mepet ke
            // batas toleransi. Rincian tiap titik ada di `titik` di bawah.
            'hasil' => $penentu ? [
                'rata_rata' => $penentu->rata_rata,
                'error' => $penentu->error,
                'ketidakpastian_gabungan' => $penentu->ketidakpastian_gabungan,
                'faktor_cakupan_k' => $penentu->faktor_cakupan_k,
                'ketidakpastian_diperluas' => $penentu->ketidakpastian_diperluas,
                'keputusan' => $this->keputusan,
            ] : null,

            'catatan_revisi' => $this->catatan_revisi,
            'certificate_id' => $this->certificate?->id,

            // Tambahan di luar kontrak (superset, aman diabaikan mobile) —
            // dibutuhin buat nampilin worksheet & rincian ketidakpastian.
            'suhu_ruang' => $this->suhu_ruang,
            'kelembaban' => $this->kelembaban,
            'lokasi' => $this->lokasi,
            'standar_acuan' => $this->standard ? [
                'id' => $this->standard->id,
                'nama' => $this->standard->nama,
                'no_sertifikat' => $this->standard->no_sertifikat,
            ] : null,
            'titik' => $this->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->values()
                ->map(fn (UncertaintyCalculation $titik): array => [
                    'titik_ke' => $titik->titik_ke,
                    'titik_ukur' => $titik->titik_ukur,
                    'rata_rata' => $titik->rata_rata,
                    'error' => $titik->error,
                    'koreksi' => $titik->koreksi,
                    'standar_deviasi' => $titik->standar_deviasi,
                    'jumlah_pengulangan' => $titik->jumlah_pengulangan,
                    'type_a' => $titik->type_a,
                    'type_b' => $titik->type_b,
                    'type_b_components' => $titik->type_b_components,
                    'ketidakpastian_gabungan' => $titik->ketidakpastian_gabungan,
                    'faktor_cakupan_k' => $titik->faktor_cakupan_k,
                    'ketidakpastian_diperluas' => $titik->ketidakpastian_diperluas,
                    'toleransi' => $titik->toleransi,
                    'keputusan' => $titik->keputusan,
                ]),
        ];
    }
}
