<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\FotoPelanggan;
use App\Models\PermintaanKalibrasi;
use App\Models\PermintaanKalibrasiItem;
use App\Services\Pelanggan\FotoPelangganLayanan;
use App\Support\TahapPermintaan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Permintaan kalibrasi dilihat pelanggannya.
 *
 * SENGAJA tidak mewarisi resource internal (AGENTS.md §Modul Pelanggan butir
 * 2). Yang TIDAK ada di sini dan tidak boleh ditambahkan tanpa sengaja: siapa
 * admin yang memutuskan, id organisasi, dan kategori alat di lab. Yang ada
 * hanya yang pelanggan sendiri ketik, keputusan lab, dan alasannya.
 *
 * `alasan_penolakan` hanya dikirim kalau statusnya `ditolak` — selain itu
 * kolomnya memang kosong, tapi "tidak dikirim sama sekali" lebih sulit
 * bocor daripada "dikirim kosong".
 *
 * @property PermintaanKalibrasi $resource
 */
class PermintaanPelangganResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PermintaanKalibrasi $p */
        $p = $this->resource;

        $hasil = [
            'id' => $p->id,
            'nomor' => $p->nomor,
            'status' => $p->status,
            'metode_pengantaran' => $p->metode_pengantaran,
            'tanggal_diinginkan_dari' => $p->tanggal_diinginkan_dari?->toDateString(),
            'tanggal_diinginkan_sampai' => $p->tanggal_diinginkan_sampai?->toDateString(),
            'catatan' => $p->catatan,
            'diajukan_pada' => $p->created_at?->toIso8601ZuluString(),
            'diputuskan_pada' => $p->diputuskan_pada?->toIso8601ZuluString(),
            'dibatalkan_pada' => $p->dibatalkan_pada?->toIso8601ZuluString(),
            'dapat_dibatalkan' => $p->masihBaru(),
            'percakapan_terbuka' => $p->percakapanTerbuka(),
            'jumlah_pesan' => (int) ($p->pesan_count ?? 0),
            'jumlah_alat' => $p->items->count(),
            'alat' => $p->items->map(fn (PermintaanKalibrasiItem $i): array => self::alat($i))->values()->all(),
            // Sesudah diterima: penunjuk ke paket (`/paket/{id}`) tempat
            // pelacakan tahapnya hidup. Paket yang sudah dibatalkan lab tidak
            // ditautkan — `/paket` memang menyembunyikannya.
            'paket' => $p->order !== null && $p->order->status !== 'dibatalkan'
                ? ['id' => $p->order->id, 'nomor' => $p->order->nomor]
                : null,
            // §42 B5 — tahap, resi, jadwal teknisi. Dari `TahapPermintaan`, BUKAN
            // dari resource internal (aturan Modul Pelanggan butir 2).
            ...TahapPermintaan::bentuk($p),
        ];

        if ($p->status === PermintaanKalibrasi::STATUS_DITOLAK) {
            $hasil['alasan_penolakan'] = $p->alasan_penolakan;
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private static function alat(PermintaanKalibrasiItem $item): array
    {
        // Sesudah diterima, alat baru sudah jadi baris `equipments` — datanya
        // dibaca dari situ (admin mungkin merapikannya). Sebelum itu, dari
        // ketikan pelanggan sendiri.
        if ($item->equipment !== null) {
            return [
                'id' => $item->id,
                'alat_id' => $item->equipment->id,
                'baru' => $item->alatBaru(),
                'nama' => $item->equipment->nama_alat,
                'merk' => $item->equipment->merk,
                'model' => $item->equipment->model,
                'serial' => $item->equipment->serial_number,
                'foto' => self::foto($item),
            ];
        }

        $a = $item->alat_baru ?? [];

        return [
            'id' => $item->id,
            'alat_id' => null,
            'baru' => true,
            'nama' => $a['nama_alat'] ?? null,
            'merk' => $a['merk'] ?? null,
            'model' => $a['model'] ?? null,
            'serial' => $a['serial_number'] ?? null,
            'foto' => self::foto($item),
        ];
    }

    /** @return list<array{id: int, url: string}> */
    private static function foto(PermintaanKalibrasiItem $item): array
    {
        if (! $item->relationLoaded('foto')) {
            return [];
        }

        return $item->foto->map(fn (FotoPelanggan $f) => FotoPelangganLayanan::bentukPelanggan($f))->values()->all();
    }
}
