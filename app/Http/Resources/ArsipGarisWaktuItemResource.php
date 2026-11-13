<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu dokumen di garis waktu arsip LAB (tingkat hari). Hanya yang dibutuhkan
 * untuk mengenali dan membuka dokumennya; detail tetap lewat
 * `GET /certificates/{id}`.
 *
 * Identitas alat dibaca dari lembar kerja lebih dulu (nomor seri yang diketik
 * teknisi), lalu data alat -- urutan yang sama dengan Riwayat.
 *
 * @mixin Certificate
 */
class ArsipGarisWaktuItemResource extends JsonResource
{
    /**
     * Relasi yang harus dimuat pemanggil supaya satu halaman tidak N+1. Alat dan
     * PT yang dihapus lunak tetap dimuat: sertifikatnya tetap dokumen arsip.
     *
     * @return array<int|string, mixed>
     */
    public static function relasi(): array
    {
        return [
            'session:id,equipment_id,order_item_id,nomor_order,alat_serial_number,pemilik_nama',
            'session.equipment' => fn ($alat) => $alat->withTrashed()
                ->select('id', 'customer_id', 'nama_alat', 'serial_number'),
            'session.equipment.customer' => fn ($pt) => $pt->withTrashed()->select('id', 'nama'),
            'session.orderItem:id,order_id',
            'session.orderItem.order:id,nomor',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sesi = $this->session;
        $alat = $sesi?->equipment;

        return [
            'id' => $this->id,
            'nomor' => $this->nomor,
            'status' => $this->status,
            'revisi_ke' => $this->revisi_ke ?: null,
            'tanggal' => $this->diterbitkan_pada?->toDateString(),
            'nama_alat' => $alat?->nama_alat,
            'serial_number' => filled($sesi?->alat_serial_number) ? $sesi->alat_serial_number : $alat?->serial_number,
            'perusahaan' => $sesi?->pemilik_nama ?: $alat?->customer?->nama,
            'customer_id' => $alat?->customer?->id,
            'nomor_order' => filled($sesi?->nomor_order) ? $sesi->nomor_order : $sesi?->orderItem?->order?->nomor,
            'calibration_session_id' => $this->calibration_session_id,
            'pdf_url' => in_array($this->status, [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN, Certificate::STATUS_DITARIK], true)
                ? route('certificates.download', $this->resource)
                : null,
        ];
    }
}
