<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\Certificate;
use App\Services\Pelanggan\AlurKoreksi;
use App\Services\RevisiSertifikat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu sertifikat, dilihat pemilik alatnya.
 *
 * Identitas alatnya dibaca dari SNAPSHOT, bukan dari baris `equipments` yang
 * hidup: snapshot itu yang tercetak di PDF. Kalau admin memperbaiki serial
 * alat sesudah sertifikat terbit, layar app harus tetap sama dengan kertas di
 * tangan pelanggan — sertifikat terbit adalah dokumen terkendali.
 *
 * Yang TIDAK dibuka: isi `snapshot` mentah (data titik ukur, kode teknisi,
 * jejak internal), `validasi`, path berkas, dan alasan revisi/pembatalan
 * (internal, D4 §38). PDF-nya sendiri diunduh lewat `/sertifikat/{id}/unduh`
 * yang memeriksa kepemilikan lagi. SENGAJA tidak mewarisi resource internal
 * (aturan Modul Pelanggan butir 2).
 *
 * Status dokumen (§38.2) diturunkan dari relasi `revisiTerakhir` yang dimuat
 * controller — satu query untuk seluruh halaman, bukan satu per baris:
 *
 * - `berlaku`    — sah dan belum digantikan.
 * - `digantikan` — ada revisi yang terbit (atau terbit lalu dibatalkan; K38-2:
 *                  pembatalan revisi tidak menghidupkan pendahulunya).
 * - `dibatalkan` — final; unduhnya ditolak 410.
 *
 * @property Certificate $resource
 */
class SertifikatPelangganResource extends JsonResource
{
    /** Diisi controller di layar detail (kalau relasi tidak dimuat). */
    public ?Certificate $pengganti = null;

    public bool $rinci = false;

    public function toArray(Request $request): array
    {
        /** @var Certificate $c */
        $c = $this->resource;
        $header = (array) ($c->snapshot['header'] ?? []);

        $revisi = $c->relationLoaded('revisiTerakhir') ? $c->revisiTerakhir : $this->pengganti;
        $pengganti = $revisi !== null && in_array($revisi->status, Certificate::STATUS_PENGGANTI, true) ? $revisi : null;

        $status = match (Certificate::labelDokumen((string) $c->status, $pengganti?->status)) {
            Certificate::DOKUMEN_DIBATALKAN => Certificate::DOKUMEN_DIBATALKAN,
            Certificate::DOKUMEN_DIGANTIKAN => Certificate::DOKUMEN_DIGANTIKAN,
            default => Certificate::DOKUMEN_BERLAKU,
        };

        $koreksi = $c->relationLoaded('koreksiMenunggu') ? $c->koreksiMenunggu : null;

        $dasar = [
            'id' => $c->id,
            'nomor' => $c->nomor,
            'status' => $status,
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
            'digantikan_oleh' => $pengganti === null ? null : [
                'id' => $pengganti->id,
                'nomor' => $pengganti->nomor,
                'diterbitkan_pada' => $pengganti->diterbitkan_pada?->toDateString(),
            ],
            'dibatalkan_pada' => $c->dibatalkan_pada?->toDateString(),
            // Teks yang DITULIS untuk pelanggan. Yang digantikan membawa catatan
            // revisinya (itu yang menjelaskan apa yang diperbaiki); sisanya
            // catatan barisnya sendiri. Alasan internal tidak pernah ikut.
            'catatan_pelanggan' => $status === Certificate::DOKUMEN_DIGANTIKAN
                ? $pengganti?->catatan_pelanggan
                : $c->catatan_pelanggan,
            // PDF batal tidak boleh beredar lagi sebagai dokumen sah (§38.2).
            'bisa_diunduh' => filled($c->pdf_path) && $status !== Certificate::DOKUMEN_DIBATALKAN,
            'bisa_minta_koreksi' => $status === Certificate::DOKUMEN_BERLAKU && $koreksi === null && filled($c->snapshot),
            'koreksi_menunggu' => $koreksi === null ? null : ['id' => $koreksi->id],
            // Halaman verifikasi PUBLIK yang sama dengan isi QR di PDF. Aman
            // dibuka: halaman itu memang dibuat untuk dibagikan ke auditor.
            'tautan_verifikasi' => is_string($c->qr_payload) && str_starts_with($c->qr_payload, 'http')
                ? $c->qr_payload
                : null,
        ];

        if (! $this->rinci) {
            return $dasar;
        }

        $cetak = RevisiSertifikat::dataCetak($c);

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
            // Isian awal formulir minta koreksi — cuma kunci yang boleh diminta
            // pelanggan (tanpa masa berlaku).
            'data_cetak' => $cetak === null ? null : array_intersect_key($cetak, array_flip(AlurKoreksi::KUNCI_SERTIFIKAT)),
        ];
    }

    public function rinci(?Certificate $pengganti = null): self
    {
        $this->rinci = true;
        $this->pengganti = $pengganti;

        return $this;
    }
}
