<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu "berkas" di dalam folder alat: satu sesi kalibrasi beserta sertifikatnya
 * (kalau udah terbit). Sesi yang masih draft/menunggu approval juga ikut muncul
 * — biar folder nampilin pekerjaan yang lagi jalan, bukan cuma yang udah kelar.
 *
 * @mixin CalibrationSession
 */
class ArsipBerkasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tipe' => 'berkas',
            'id' => $this->id,
            'nomor_sesi' => $this->nomor_sesi,
            'nomor_order' => $this->nomor_order,
            'status' => $this->status,
            'keputusan' => $this->keputusan,
            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toIso8601ZuluString(),
            'teknisi' => $this->whenLoaded('teknisi', fn () => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
            ]),
            // Null kalau sesi ini belum menghasilkan sertifikat (masih draft/
            // menunggu approval/ditolak). Frontend munculin badge "belum terbit".
            'sertifikat' => $this->when(
                $this->relationLoaded('certificate') && $this->certificate !== null,
                fn (): array => [
                    'id' => $this->certificate->id,
                    'nomor' => $this->certificate->nomor,
                    'status' => $this->certificate->status,
                    'diterbitkan_pada' => $this->certificate->diterbitkan_pada?->toIso8601ZuluString(),
                    'berlaku_sampai' => $this->certificate->berlaku_sampai?->toIso8601ZuluString(),
                    'kadaluarsa' => (bool) $this->certificate->berlaku_sampai?->isPast(),
                    // Cuma ada kalau PDF-nya udah jadi — kalau gagal/menunggu,
                    // null (frontend munculin tombol retry, bukan unduh).
                    'pdf_url' => $this->certificate->status === Certificate::STATUS_TERBIT
                        ? route('certificates.download', $this->certificate)
                        : null,
                ],
            ),
        ];
    }
}
