<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Organization;

/**
 * Nomor order: ORD/2026/07/0001 — urut per organisasi per bulan.
 *
 * Dipindah dari `OrderController` (perilaku TIDAK berubah) supaya dua pintu
 * yang melahirkan order — meja penerimaan (`OrderController::store`) dan
 * penerimaan permintaan pelanggan (`AlurPermintaan::terima`) — memakai SATU
 * penomoran. Dua salinan berarti dua urutan yang lambat laun bertabrakan di
 * nomor yang sama, dan nomor order itu yang dicetak di work order.
 *
 * Wajib dipanggil di dalam transaksi: `lockForUpdate()` hanya menahan kalau
 * transaksinya masih terbuka sampai order-nya tersimpan.
 */
class PenomoranOrder
{
    public function berikutnya(int $organizationId): string
    {
        // Tanpa ini dua order yang disimpan bersamaan bisa deadlock (500) —
        // alasannya di `Organization::kunciUntukPenomoran()`.
        Organization::kunciUntukPenomoran($organizationId);

        $prefix = sprintf('ORD/%s/', now()->format('Y/m'));

        $urutanTerakhir = Order::where('organization_id', $organizationId)
            ->where('nomor', 'like', $prefix.'%')
            // Dikunci biar dua petugas yang nyimpen barengan nggak dapet nomor
            // yang sama — sama kayak penomoran sesi & sertifikat.
            ->lockForUpdate()
            ->orderByDesc('nomor')
            ->value('nomor');

        $urutan = $urutanTerakhir ? ((int) substr($urutanTerakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }
}
