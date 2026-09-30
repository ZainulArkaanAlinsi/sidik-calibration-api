<?php

namespace App\Support\Pelanggan;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use Illuminate\Database\Eloquent\Builder;

/**
 * SATU-SATUNYA tempat query data pelanggan disaring ke perusahaannya.
 *
 * Tiap controller data pelanggan (beranda, alat, sertifikat, paket) memulai
 * query-nya dari sini, tidak pernah dari `Equipment::query()` langsung. Jadi
 * pertanyaan "apakah PT A bisa membaca milik PT B?" dijawab dengan membaca
 * berkas ini saja — bukan dengan menyisir tiap `where` di empat controller.
 *
 * Dua saringan, bukan satu:
 *  - `customer_id` — pemiliknya perusahaan ini;
 *  - `organization_id` — dan barisnya milik lab yang sama dengan perusahaan
 *    itu. Hari ini lab-nya satu, jadi saringan kedua kelihatan mubazir. Dia
 *    ada supaya lab kedua yang kebetulan memakai `customer_id` yang sama
 *    (impor, salah ketik admin) tidak jadi jalan bocor.
 *
 * Sertifikat cuma yang `terbit`. Yang masih `menunggu_generate` atau `gagal`
 * bukan dokumen — menampilkannya ke pelanggan berarti menampilkan nomor yang
 * belum tentu jadi.
 */
final class LingkupData
{
    private ?Customer $perusahaan = null;

    public function __construct(private readonly Konteks $konteks) {}

    public function perusahaan(): Customer
    {
        return $this->perusahaan ??= Customer::query()->findOrFail($this->konteks->customerId);
    }

    /** @return Builder<Equipment> */
    public function alat(): Builder
    {
        return Equipment::query()
            ->where('customer_id', $this->konteks->customerId)
            ->where('organization_id', $this->perusahaan()->organization_id);
    }

    /**
     * Sertifikat terbit milik perusahaan ini.
     *
     * Kepemilikan dibaca dari ALAT sesinya, termasuk alat yang sudah dihapus
     * lunak: sertifikat yang pernah terbit tetap milik pelanggannya walau
     * alatnya sudah tidak aktif di daftar lab — itu justru dokumen yang dicari
     * waktu audit.
     *
     * @return Builder<Certificate>
     */
    public function sertifikat(): Builder
    {
        $customerId = $this->konteks->customerId;

        return Certificate::query()
            ->where('status', Certificate::STATUS_TERBIT)
            ->where('organization_id', $this->perusahaan()->organization_id)
            ->whereHas('session', fn (Builder $sesi) => $sesi->whereHas(
                'equipment',
                fn (Builder $alat) => $alat->withTrashed()->where('customer_id', $customerId),
            ));
    }

    /** @return Builder<Order> */
    public function paket(): Builder
    {
        return Order::query()
            ->where('customer_id', $this->konteks->customerId)
            ->where('organization_id', $this->perusahaan()->organization_id)
            ->where('status', '!=', Order::STATUS_DIBATALKAN);
    }

    /**
     * Permintaan kalibrasi milik perusahaan ini — semua status.
     *
     * Berbeda dari `paket()`, yang dibatalkan TIDAK disembunyikan: pelanggan
     * perlu melihat ajuannya yang ditolak (beserta alasannya) dan yang ia
     * batalkan sendiri. Dua saringan yang sama dengan lainnya (customer_id +
     * organization_id).
     *
     * @return Builder<PermintaanKalibrasi>
     */
    public function permintaan(): Builder
    {
        return PermintaanKalibrasi::query()
            ->where('customer_id', $this->konteks->customerId)
            ->where('organization_id', $this->perusahaan()->organization_id);
    }
}
