<?php

namespace App\Services\Pelanggan;

use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Models\Equipment;
use App\Models\OrderItem;
use App\Services\TahapPaket;
use App\Support\Pelanggan\LingkupData;

/**
 * "Alat ini sedang ada di paket mana, sudah sampai tahap apa" -- untuk kartu
 * alat di aplikasi pelanggan (`sedang_dikerjakan` di `AlatPelangganResource`).
 *
 * ## Aturan
 *
 * - Hanya paket MILIK PERUSAHAAN pemanggil yang masih berjalan
 *   (`LingkupData::paketAktif()`: bukan `selesai`, bukan `dibatalkan`, dan
 *   disaring `customer_id` + `organization_id`). Alat perusahaan A yang
 *   tercatat di order perusahaan B tidak pernah terlihat dari sisi A.
 * - Alat yang sudah `diserahkan` bukan "sedang dikerjakan" lagi; baris itu
 *   dilewati, dan paket aktif lain (kalau ada) yang dipakai.
 * - Satu alat bisa ada di beberapa paket aktif (kalibrasi ulang): yang dipilih
 *   paket TERBARU (`tanggal_masuk`, lalu `order_id`, lalu id item).
 * - Tahap = tahap yang DILIHAT pelanggan (pengesahan dilipat ke pemeriksaan),
 *   sama persis dengan `/paket`.
 * - `garis_waktu` = `TahapPaket::garisWaktu($tahap, untukPelanggan: true)` untuk
 *   tahap ALAT itu (bukan tahap paket): satu fungsi dengan `/paket/{id}`.
 *   Kode tahap yang tak dikenal `garisWaktu()` menandai `diterima` sebagai
 *   `sekarang` (terkunci `TahapPaketTerkunciTest`). Kolom `tahap_fisik` berupa
 *   enum, jadi kode asing hanya mungkin lahir dari perubahan `TahapPaket`.
 *
 * ## Biaya
 *
 * Empat kueri untuk satu halaman berapa pun jumlah alatnya (item, order,
 * sesi terakhir, sertifikat) -- bukan satu per alat. Dijaga
 * `PelacakanAlatPelangganTest::test_jumlah_kueri_daftar_alat_tidak_bertambah_dengan_jumlah_alat`.
 */
class PaketAktifAlat
{
    public function __construct(private readonly TahapPaket $tahap) {}

    /**
     * @param  iterable<Equipment>  $alat
     * @return array<int, array{paket_id: int, order_item_id: int, nomor_order: string, tahap: string, tahap_label: string, garis_waktu: list<array{kode: string, label: string, lewat: bool, sekarang: bool}>}|null>
     *                                                                                                                                                                                                                id alat -> objek, atau null; setiap id alat yang diminta ada kuncinya
     */
    public function untuk(LingkupData $lingkup, iterable $alat): array
    {
        $idAlat = collect($alat)->map(fn (Equipment $a): int => (int) $a->getKey())->values();
        $hasil = $idAlat->mapWithKeys(fn (int $id): array => [$id => null])->all();

        if ($idAlat->isEmpty()) {
            return $hasil;
        }

        $baris = OrderItem::query()
            ->select(['id', 'order_id', 'equipment_id', 'tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada'])
            ->whereIn('equipment_id', $idAlat->all())
            ->whereIn('order_id', $lingkup->paketAktif()->select('orders.id'))
            ->with([
                'order:id,nomor,tanggal_masuk',
                'sesiTerakhir',
                'sesiTerakhir.certificate',
            ])
            ->get()
            ->map(fn (OrderItem $item): array => [
                'item' => $item,
                'kode' => PaketPelangganResource::untukPelanggan($this->tahap->untukItem($item)),
            ])
            ->reject(fn (array $b): bool => $b['kode'] === TahapPaket::DISERAHKAN)
            // Terbaru dulu: tanggal masuk, lalu order, lalu item.
            ->sortByDesc(fn (array $b): string => sprintf(
                '%s-%010d-%010d',
                $b['item']->order->tanggal_masuk?->toDateString() ?? '0000-00-00',
                $b['item']->order_id,
                $b['item']->getKey(),
            ))
            ->groupBy(fn (array $b): int => (int) $b['item']->equipment_id);

        foreach ($baris as $equipmentId => $kandidat) {
            /** @var array{item: OrderItem, kode: string} $terpilih */
            $terpilih = $kandidat->first();
            $item = $terpilih['item'];

            $hasil[(int) $equipmentId] = [
                'paket_id' => (int) $item->order_id,
                'order_item_id' => (int) $item->getKey(),
                'nomor_order' => (string) $item->order->nomor,
                'tahap' => $terpilih['kode'],
                'tahap_label' => TahapPaket::LABEL_PELANGGAN[$terpilih['kode']] ?? $terpilih['kode'],
                // Additive (14 Nov 2026): jejak tahap ALAT ini, bentuk dan sumber yang
                // sama dengan `garis_waktu` di `GET /paket/{id}`. Aplikasi tidak
                // menyalin urutan tahap. Tanpa kueri tambahan (murni dari `kode`).
                'garis_waktu' => $this->tahap->garisWaktu($terpilih['kode'], untukPelanggan: true),
            ];
        }

        return $hasil;
    }
}
