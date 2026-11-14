<?php

namespace App\Services\Pelanggan;

use App\Http\Resources\Pelanggan\AlatPelangganResource;
use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Order;
use App\Services\TahapPaket;
use App\Support\Pelanggan\LingkupData;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Empat hitungan `data.ringkasan` di `GET /beranda` yang TIDAK bisa diturunkan
 * aplikasi dari daftar yang dikirim (additive, 14 Nov 2026):
 *
 * | Kunci | Isi |
 * |---|---|
 * | `alat_berlaku` | alat aktif ber-`status_alat` = `terkalibrasi` ATAU `mendekati_jadwal` (sertifikatnya masih berlaku; hanya `lewat_jadwal`, `belum_pernah`, `nonaktif` yang tidak) |
 * | `alat_belum_pernah` | alat aktif ber-`status_alat` = `belum_pernah` |
 * | `paket_siap_diambil` | paket berjalan perusahaan yang `tahap`-nya (versi pelanggan) `siap_diambil` |
 * | `sertifikat_baru_7_hari` | sertifikat terbit, belum digantikan, `diterbitkan_pada` 7 hari terakhir |
 *
 * Semuanya dihitung dari {@see LingkupData} (`customer_id` + `organization_id`),
 * tidak dibatasi `BerandaController::BATAS` (5) maupun tiga paket terbaru -- itu
 * pagar tampilan daftar, bukan pagar hitungan.
 *
 * ## Satu sumber kebenaran, dan dua tempat yang HARUS sepadan
 *
 * - `alat_berlaku` / `alat_belum_pernah` mengulang aturan
 *   {@see AlatPelangganResource::statusAlat()} dalam SQL (satu kueri `count()`
 *   per angka, bukan memuat semua alat). Aturan di PHP itu tetap yang memutuskan
 *   `status_alat` per alat; versi SQL ini dijaga `RingkasanBerandaPelangganTest`
 *   yang mengadu hitungannya ke `status_alat` di `/alat` untuk satu himpunan
 *   alat yang beragam. Mengubah satu sisi tanpa sisi lain membuat tes itu merah.
 * - `paket_siap_diambil` TIDAK diulang dalam SQL: tahap paket diturunkan oleh
 *   {@see TahapPaket} dari status sesi & sertifikat (sengaja tidak disimpan),
 *   jadi hitungannya memakai fungsi yang sama dengan `/paket`, di atas item yang
 *   dimuat `LingkupData::relasiPaket()` (item beralat pelanggan lain
 *   tersembunyi, sama seperti `/paket`). Harganya jumlah kueri TETAP (lima,
 *   berapa pun jumlah paketnya). Yang dimuat hanya paket berjalan yang punya
 *   sekurangnya satu item `tahap_fisik` = `siap_diambil` (prasaring, lihat
 *   [paketSiapDiambil()]); batas skala di atas itu belum diukur.
 */
class RingkasanBerandaPelanggan
{
    /** Jendela "baru": hari kalender, termasuk hari ini dan 7 hari ke belakang. */
    public const HARI_SERTIFIKAT_BARU = 7;

    public function __construct(private readonly TahapPaket $tahap) {}

    /**
     * @return array{alat_berlaku: int, alat_belum_pernah: int, paket_siap_diambil: int, sertifikat_baru_7_hari: int}
     */
    public function hitung(LingkupData $lingkup): array
    {
        return [
            'alat_berlaku' => $this->alatBerlaku($lingkup),
            'alat_belum_pernah' => $this->alatBelumPernah($lingkup),
            'paket_siap_diambil' => $this->paketSiapDiambil($lingkup),
            'sertifikat_baru_7_hari' => $this->sertifikatBaru($lingkup),
        ];
    }

    /**
     * `status_alat` = `terkalibrasi` ATAU `mendekati_jadwal` (keputusan pemilik
     * 14 Nov 2026): sudah pernah dikalibrasi (sertifikat terbit di sistem ATAU
     * tanggal kalibrasi terakhir terisi) dan belum lewat jadwal (jatuh tempo hari
     * ini atau sesudahnya, atau tidak berjadwal). Alat yang jatuh temponya masuk
     * jendela "mendekati" (30 hari) sertifikatnya masih berlaku, jadi ikut dihitung;
     * jendela itu urusan `segera_jatuh_tempo`, bukan urusan "berlaku".
     */
    private function alatBerlaku(LingkupData $lingkup): int
    {
        return $this->alatAktif($lingkup)
            ->where(fn (Builder $pernah) => $pernah
                ->whereNotNull('tanggal_kalibrasi_terakhir')
                ->orWhereHas('calibrationSessions', $this->sesiBersertifikatTerbit()))
            ->where(fn (Builder $jadwal) => $jadwal
                ->whereNull('tanggal_jatuh_tempo')
                ->orWhereDate('tanggal_jatuh_tempo', '>=', now()))
            ->count();
    }

    /** `status_alat` = `belum_pernah`: tak ada sertifikat terbit DAN tanggal kalibrasi terakhir kosong. */
    private function alatBelumPernah(LingkupData $lingkup): int
    {
        return $this->alatAktif($lingkup)
            ->whereNull('tanggal_kalibrasi_terakhir')
            ->whereDoesntHave('calibrationSessions', $this->sesiBersertifikatTerbit())
            ->count();
    }

    /**
     * Prasaring `whereHas('items', tahap_fisik = siap_diambil)`: `siap_diambil`
     * hanya lahir dari kolom `tahap_fisik` (`TahapPaket::untukItem` menurunkan
     * paling jauh `sertifikat_terbit` dari sesi & sertifikat), jadi paket tanpa
     * satu pun item berkolom itu pasti tidak berpaket `siap_diambil` (tahap paket
     * = alat paling tertinggal). Syarat perlu, BUKAN cukup: keputusan akhirnya tetap
     * `TahapPaket::untukPaket` di atas item yang terlihat. Asumsinya dikunci
     * `TahapPaketTerkunciTest::siap_diambil_dan_diserahkan_hanya_lahir_dari_tahap_fisik`.
     * Prasaring ini bagian dari kueri `orders` yang sama: nol kueri tambahan.
     */
    private function paketSiapDiambil(LingkupData $lingkup): int
    {
        return $lingkup->paketAktif()
            ->whereHas('items', fn (Builder $item) => $item->where('tahap_fisik', TahapPaket::SIAP_DIAMBIL))
            ->select('orders.id')
            ->with($lingkup->relasiPaket())
            ->get()
            ->filter(fn (Order $paket): bool => PaketPelangganResource::untukPelanggan(
                $this->tahap->untukPaket($paket->items),
            ) === TahapPaket::SIAP_DIAMBIL)
            ->count();
    }

    /**
     * Himpunan yang sama dengan `sertifikat_terbaru` (terbit, belum digantikan,
     * milik perusahaan), tanpa `limit`. Sertifikat revisi yang terbit dihitung
     * satu kali; pendahulunya yang digantikan tidak.
     *
     * Jendela: `diterbitkan_pada` dari 7 hari lalu sampai hari ini (kolomnya
     * `date`). Tanggal di depan hari ini tidak dihitung "baru". Sama dengan
     * hitungan cadangan di aplikasi (`hitungSertifikatBaru`).
     */
    private function sertifikatBaru(LingkupData $lingkup): int
    {
        return $lingkup->sertifikat()
            ->where('status', Certificate::STATUS_TERBIT)
            ->belumDigantikan()
            ->whereDate('diterbitkan_pada', '>=', now()->subDays(self::HARI_SERTIFIKAT_BARU))
            ->whereDate('diterbitkan_pada', '<=', now())
            ->count();
    }

    /** @return Builder<Equipment> */
    private function alatAktif(LingkupData $lingkup): Builder
    {
        return $lingkup->alat()->where('status', Equipment::STATUS_AKTIF);
    }

    /**
     * Sama dengan `AlatPelangganResource::konteks()` (`pernah_terbit`): sesi
     * alat yang sertifikat aslinya berstatus `terbit`.
     *
     * @return Closure(Builder<CalibrationSession>): void
     */
    private function sesiBersertifikatTerbit(): Closure
    {
        return fn (Builder $sesi) => $sesi->whereHas(
            'certificate',
            fn (Builder $c) => $c->where('status', Certificate::STATUS_TERBIT),
        );
    }
}
