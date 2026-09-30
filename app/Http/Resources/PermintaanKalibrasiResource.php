<?php

namespace App\Http\Resources;

use App\Models\PermintaanKalibrasi;
use App\Models\PermintaanKalibrasiItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Permintaan kalibrasi dilihat ADMIN lab (antrean & detail).
 *
 * Beda dari versi pelanggan (`Pelanggan\PermintaanPelangganResource`, yang
 * TIDAK mewarisi yang ini): di sini ada identitas pemohon & pemutus, dan data
 * alat baru apa adanya supaya admin tahu apa yang harus dilengkapi sebelum
 * menerima (`perlu_kategori`, `perlu_nomor_seri`).
 *
 * @property PermintaanKalibrasi $resource
 */
class PermintaanKalibrasiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PermintaanKalibrasi $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'nomor' => $p->nomor,
            'status' => $p->status,
            'customer' => $p->customer ? ['id' => $p->customer->id, 'nama' => $p->customer->nama] : null,
            'pemohon' => $p->pemohon ? [
                'id' => $p->pemohon->id,
                'nama' => $p->pemohon->name,
                'email' => $p->pemohon->email,
                'telepon' => $p->pemohon->telepon,
            ] : null,
            'metode_pengantaran' => $p->metode_pengantaran,
            'tanggal_diinginkan_dari' => $p->tanggal_diinginkan_dari?->toDateString(),
            'tanggal_diinginkan_sampai' => $p->tanggal_diinginkan_sampai?->toDateString(),
            'catatan' => $p->catatan,
            'alasan_penolakan' => $p->alasan_penolakan,
            'diputuskan_oleh' => $p->pemutus ? ['id' => $p->pemutus->id, 'nama' => $p->pemutus->name] : null,
            'diputuskan_pada' => $p->diputuskan_pada?->toIso8601ZuluString(),
            'dibatalkan_pada' => $p->dibatalkan_pada?->toIso8601ZuluString(),
            'order' => $p->order ? [
                'id' => $p->order->id,
                'nomor' => $p->order->nomor,
                'status' => $p->order->status,
            ] : null,
            'jumlah_alat' => $p->items->count(),
            'jumlah_pesan' => (int) ($p->pesan_count ?? 0),
            'alat' => $p->items->map(fn (PermintaanKalibrasiItem $i): array => self::alat($i))->values()->all(),
            'dibuat_pada' => $p->created_at?->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    private static function alat(PermintaanKalibrasiItem $item): array
    {
        $a = $item->alat_baru ?? [];
        $belumJadi = $item->alatBaru() && $item->equipment_id === null;

        return [
            'id' => $item->id,
            'equipment_id' => $item->equipment_id,
            'baru' => $item->alatBaru(),
            'nama_alat' => $item->equipment?->nama_alat ?? ($a['nama_alat'] ?? null),
            'merk' => $item->equipment?->merk ?? ($a['merk'] ?? null),
            'model' => $item->equipment?->model ?? ($a['model'] ?? null),
            'serial_number' => $item->equipment?->serial_number ?? ($a['serial_number'] ?? null),
            'no_identifikasi' => $item->equipment?->no_identifikasi ?? ($a['no_identifikasi'] ?? null),
            'rentang_min' => $a['rentang_min'] ?? null,
            'rentang_maks' => $a['rentang_maks'] ?? null,
            'satuan' => $a['satuan'] ?? null,
            'resolusi' => $a['resolusi'] ?? null,
            'lokasi' => $a['lokasi'] ?? null,
            'catatan' => $a['catatan'] ?? null,
            // Petunjuk buat layar Terima: apa yang masih harus dilengkapi admin.
            'perlu_kategori' => $belumJadi,
            'perlu_nomor_seri' => $belumJadi && blank($a['serial_number'] ?? null),
        ];
    }
}
