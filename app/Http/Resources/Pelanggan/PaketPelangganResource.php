<?php

namespace App\Http\Resources\Pelanggan;

use App\Models\Certificate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\TahapPaket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paket (order) dilihat pelanggannya — versi SEMPIT dari `PaketLacakResource`.
 *
 * Yang dibuang dibanding versi lab: nama & kode teknisi, kondisi terima,
 * catatan internal, dan tahap `menunggu_pengesahan`. Dua lapis persetujuan lab
 * (admin memeriksa, pengesah mengesahkan) di mata pelanggan SATU langkah:
 * "Hasil sedang diperiksa". Menampilkan dua-duanya cuma membuat paket yang
 * menunggu tanda tangan terlihat seperti mundur selangkah.
 *
 * @property Order $resource
 */
class PaketPelangganResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Order $paket */
        $paket = $this->resource;
        $tahap = app(TahapPaket::class);

        $alat = $paket->items->map(function (OrderItem $item) use ($tahap): array {
            $kode = self::untukPelanggan($tahap->untukItem($item));
            $sertifikat = $item->sesiTerakhir?->certificate;

            return [
                'id' => $item->id,
                'nama' => $item->equipment?->nama_alat,
                'merk' => $item->equipment?->merk,
                'serial' => $item->equipment?->serial_number,
                'tahap' => $kode,
                'tahap_label' => TahapPaket::LABEL_PELANGGAN[$kode] ?? $kode,
                'diserahkan_kepada' => $kode === TahapPaket::DISERAHKAN ? $item->diserahkan_kepada : null,
                'sertifikat_id' => $sertifikat?->status === Certificate::STATUS_TERBIT ? $sertifikat->id : null,
            ];
        });

        $kodePaket = self::untukPelanggan($tahap->untukPaket($paket->items));
        $posisiTerbit = array_search(TahapPaket::SERTIFIKAT_TERBIT, TahapPaket::URUTAN, true);
        $selesai = $alat->filter(
            fn (array $a): bool => array_search($a['tahap'], TahapPaket::URUTAN, true) >= $posisiTerbit
        )->count();

        $janji = $paket->tanggal_janji_selesai;

        return [
            'id' => $paket->id,
            'nomor' => $paket->nomor,
            'tanggal_masuk' => $paket->tanggal_masuk?->toDateString(),
            'tanggal_janji_selesai' => $janji?->toDateString(),
            'terlambat' => $janji !== null
                && $janji->copy()->startOfDay()->isPast()
                && $selesai < $alat->count(),
            'tahap' => $kodePaket,
            'tahap_label' => TahapPaket::LABEL_PELANGGAN[$kodePaket] ?? $kodePaket,
            'jumlah_alat' => $alat->count(),
            'jumlah_selesai' => $selesai,
            'alat' => $alat->values()->all(),
        ];
    }

    /** Pengesahan dilipat ke pemeriksaan — lihat docblock kelas. */
    public static function untukPelanggan(string $kode): string
    {
        return $kode === TahapPaket::MENUNGGU_PENGESAHAN ? TahapPaket::MENUNGGU_PEMERIKSAAN : $kode;
    }
}
