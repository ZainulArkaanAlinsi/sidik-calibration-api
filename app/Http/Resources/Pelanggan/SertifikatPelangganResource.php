<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu sertifikat terbit, dilihat pemilik alatnya.
 *
 * Identitas alatnya dibaca dari SNAPSHOT, bukan dari baris `equipments` yang
 * hidup: snapshot itu yang tercetak di PDF. Kalau admin memperbaiki serial
 * alat sesudah sertifikat terbit, layar app harus tetap sama dengan kertas di
 * tangan pelanggan — sertifikat terbit adalah dokumen terkendali.
 *
 * Yang TIDAK dibuka: isi `snapshot` mentah (data titik ukur, kode teknisi,
 * jejak internal), `validasi`, dan path berkas. PDF-nya sendiri diunduh lewat
 * `/sertifikat/{id}/unduh` yang memeriksa kepemilikan lagi.
 *
 * @property Certificate $resource
 */
class SertifikatPelangganResource extends JsonResource
{
    /** Diisi controller di layar detail: sertifikat yang menggantikan ini. */
    public ?Certificate $pengganti = null;

    public bool $rinci = false;

    public function toArray(Request $request): array
    {
        /** @var Certificate $c */
        $c = $this->resource;
        $header = (array) ($c->snapshot['header'] ?? []);

        $dasar = [
            'id' => $c->id,
            'nomor' => $c->nomor,
            'diterbitkan_pada' => $c->diterbitkan_pada?->toDateString(),
            'berlaku_sampai' => $c->berlaku_sampai?->toDateString(),
            'keputusan' => $c->snapshot['meta']['keputusan'] ?? null,
            'alat' => [
                'id' => $c->session?->equipment_id,
                'nama' => $header['equipment_name'] ?? null,
                'merk' => $header['manufacturer'] ?? null,
                'model' => $header['model_type'] ?? null,
                'serial' => $header['serial_number'] ?? null,
            ],
            'revisi_dari' => $c->revision_of === null ? null : [
                'id' => $c->revision_of,
                'nomor' => $c->revisionOf?->nomor,
            ],
            'bisa_diunduh' => filled($c->pdf_path),
            // Halaman verifikasi PUBLIK yang sama dengan isi QR di PDF. Aman
            // dibuka: halaman itu memang dibuat untuk dibagikan ke auditor.
            'tautan_verifikasi' => is_string($c->qr_payload) && str_starts_with($c->qr_payload, 'http')
                ? $c->qr_payload
                : null,
        ];

        if (! $this->rinci) {
            return $dasar;
        }

        return $dasar + [
            'rincian' => [
                'nomor_order' => $header['order_number'] ?? null,
                'tanggal_terima' => $header['received_date'] ?? null,
                'tanggal_kalibrasi' => $header['calibration_date'] ?? null,
                'lokasi_kalibrasi' => $header['calibration_location'] ?? null,
                'metode' => $header['calibration_method'] ?? null,
                'kondisi_lingkungan' => $header['env_condition'] ?? null,
            ],
            'penanda_tangan' => $c->penandaTangan(),
            'digantikan_oleh' => $this->pengganti === null ? null : [
                'id' => $this->pengganti->id,
                'nomor' => $this->pengganti->nomor,
            ],
        ];
    }

    public function rinci(?Certificate $pengganti): self
    {
        $this->rinci = true;
        $this->pengganti = $pengganti;

        return $this;
    }
}
