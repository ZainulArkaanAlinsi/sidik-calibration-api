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
            // `dibatalkan` ikut sejak 1 Okt 2026 (§38.2): pelanggan harus
            // MELIHAT bahwa dokumennya dibatalkan — menghilangkannya diam-diam
            // membuat dia terus mengirim nomor itu ke auditornya. Unduhnya
            // ditolak 410 di `SertifikatController::unduh`. Pemanggil yang butuh
            // "yang berlaku saja" menyaring `status = terbit` sendiri.
            ->whereIn('status', [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN])
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
     * Paket yang masih berjalan: `paket()` tanpa yang `selesai`.
     *
     * @return Builder<Order>
     */
    public function paketAktif(): Builder
    {
        return $this->paket()->where('status', '!=', Order::STATUS_SELESAI);
    }

    /**
     * Relasi eager-load untuk `PaketPelangganResource` — SATU-SATUNYA tempat
     * item paket dimuat untuk pelanggan (`/paket`, `/paket/{id}`, `/beranda`).
     *
     * Item yang alatnya milik PELANGGAN LAIN disembunyikan (item-nya, bukan
     * paketnya): order PT A yang dulu sempat dibuat memuat alat PT B tidak
     * boleh membocorkan nama + nomor seri alat itu ke PT A, dan nilai `tahap`/
     * `jumlah_alat`/`garis_waktu` paket dihitung dari item yang TERLIHAT saja.
     * Alat tanpa pemilik (`customer_id` null) tetap tampil — sama dengan aturan
     * tulis di `OrderRequest`. Alat yang dihapus lunak tetap dihitung: paketnya
     * memang pernah memuat alat itu.
     *
     * Sengaja TANPA `items.teknisi`: nama teknisi bukan urusan pelanggan.
     *
     * @return array<int|string, mixed>
     */
    public function relasiPaket(): array
    {
        $customerId = $this->konteks->customerId;
        $organizationId = $this->perusahaan()->organization_id;

        return [
            'items' => fn ($q) => $q
                ->select(['id', 'order_id', 'equipment_id', 'tahap_fisik', 'tahap_fisik_pada', 'diserahkan_kepada'])
                ->whereHas('equipment', fn (Builder $alat) => $alat
                    ->withTrashed()
                    ->where('organization_id', $organizationId)
                    ->where(fn (Builder $pemilik) => $pemilik
                        ->whereNull('customer_id')
                        ->orWhere('customer_id', $customerId))),
            'items.equipment' => fn ($q) => $q->withTrashed()->select(['id', 'nama_alat', 'merk', 'serial_number']),
            'items.sesiTerakhir',
            'items.sesiTerakhir.certificate',
        ];
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
