<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris DAFTAR sesi (`GET /calibrations?bentuk=ringan`): hanya yang dibaca
 * layar Riwayat/antrean/draf, tanpa hasil hitung, standar, metode, ruangan,
 * reviewer, atau pembacaan mentah.
 *
 * Kunci yang sama dengan `CalibrationResource` memakai NAMA dan ARTI yang sama,
 * jadi pembaca daftar yang sudah ada (`CalibrationHistoryItem.fromJson`) bisa
 * berpindah tanpa mengubah parsernya. Bedanya, relasi berat tidak dimuat:
 * `hasil` hanya memuat `keputusan` (kolom sesi), tidak ada `titik`.
 *
 * Detail satu sesi tetap `GET /calibrations/{id}` (bentuk penuh).
 *
 * @mixin CalibrationSession
 */
class CalibrationRingkasResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $alat = $this->equipment;
        $sertifikat = $this->certificate;

        return [
            'id' => $this->id,
            'nomor_sesi' => $this->nomor_sesi,
            'nomor_order' => $this->nomor_order,
            'status' => $this->status,
            'keputusan' => $this->keputusan,
            // Cermin `CalibrationResource.hasil.keputusan` untuk pembaca lama.
            'hasil' => $this->keputusan !== null ? ['keputusan' => $this->keputusan] : null,
            // Tanggal POLOS (kolom `date`); jangan dijadikan ISO -- di zona
            // Jakarta mundur sehari. Lihat `CalibrationResource`.
            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toDateString(),
            'equipment' => [
                'id' => $alat?->id,
                'nama_alat' => $alat?->nama_alat,
                'nama_alat_kemampuan' => $alat?->nama_alat_kemampuan,
                'profil' => $alat !== null ? app(CalibrationProfileRegistry::class)->untukAlat($alat)->kode() : null,
                'serial_number' => $alat?->serial_number,
            ],
            'alat_serial_number' => $this->alat_serial_number,
            // `pemilik_nama` isian teknisi menang, sama dengan `CalibrationResource`.
            'pelanggan' => [
                'id' => $alat?->customer?->id,
                'nama' => $this->pemilik_nama ?: $alat?->customer?->nama,
            ],
            'teknisi' => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
            ],
            'catatan_revisi' => $this->catatan_revisi,
            'certificate_id' => $sertifikat?->id,
            'sertifikat' => $sertifikat === null ? null : [
                'id' => $sertifikat->id,
                'nomor' => $sertifikat->nomor,
                'status' => $sertifikat->status,
                'diterbitkan_pada' => $sertifikat->diterbitkan_pada?->toDateString(),
            ],
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Preferensi tampilan akun yang login (dari `withExists` di controller).
            'tersembunyi' => (bool) $this->resource->getAttribute('tersembunyi'),
        ];
    }
}
