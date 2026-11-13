<?php

namespace App\Services\Pelanggan;

use App\Http\Resources\Pelanggan\PaketPelangganResource;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\Pelanggan\TahapAlatBerubah;
use App\Services\PenjagaNotifikasiUlang;
use App\Services\TahapPaket;
use Illuminate\Support\Facades\Log;

/**
 * Mengabari anggota PELANGGAN begitu tahap SATU ALAT di paketnya berubah
 * (13 Nov 2026). Kembaran `KabarSertifikatTerbit` untuk perjalanan alat.
 *
 * Dipanggil dari dua tempat, dan hanya itu:
 *  - `PelacakanController::tandaiTahapFisik` -- tahap FISIK ("Siap diambil",
 *    "Sudah diserahkan"), lewat [tahapTampilAman] + [kabariTahapFisik];
 *  - `SesiKabarTahapAlat` (observer `CalibrationSession`) -- tahap TURUNAN dari
 *    status sesi, lewat [sesiBerubah]. Hanya hidup untuk sesi yang punya
 *    `order_item_id`.
 *
 * ## Aturan yang tidak boleh longgar
 *
 * **1. Hanya perubahan yang TERLIHAT pelanggan.** Yang dibandingkan tahap versi
 * pelanggan (pengesahan dilipat ke pemeriksaan), sebelum vs sesudah. Sama = tidak
 * ada kabar: `disetujui` -> `menunggu_pengesahan` tidak terlihat, dan sesi yang
 * berubah sementara tahap fisik sudah dicatat (tahap fisik menang) juga tidak.
 * Item yang alatnya milik pelanggan LAIN disembunyikan dari `/paket`
 * (`LingkupData::relasiPaket`), jadi perubahannya pun tidak dikabarkan.
 *
 * **2. Tidak dobel dengan kabar sertifikat.** Tahap `sertifikat_terbit` sudah
 * punya `KabarSertifikatTerbit` ("Sertifikat ... sudah terbit"); kabar kedua
 * untuk kejadian yang sama hanya mengganda. Kemunduran dari `sertifikat_terbit`
 * ke atas (sertifikat ditarik/direvisi) juga bukan urusan di sini: itu
 * `SertifikatBerubah`.
 *
 * **3. Cuma perusahaan pemilik paket**, dipilih lewat `PreferensiNotifikasi::penerima`
 * dengan saklar `tahap_alat` (bawaan menyala; dimatikan = tidak dikirim).
 * Perusahaan yang `organization_id`-nya beda dengan order dilewati; order
 * `dibatalkan` dilewati (paketnya tidak tampil di aplikasi).
 *
 * **4. Sekali per peristiwa per orang.** `tanda_tangan` di `notifications.data`
 * (tanpa tabel/kolom baru). Batasnya jujur, sama dengan `KabarSertifikatTerbit`:
 * notifikasi yang masih mengantre belum terlihat oleh pemeriksaan ini.
 *
 * **5. Tidak pernah menggagalkan pemanggil.** Serah terima alat atau
 * penyimpanan sesi tidak boleh gagal gara-gara kabar ke pelanggan. Galat
 * ditelan dan dicatat TANPA pesan galat (bisa memuat email/nama).
 */
class KabarTahapAlat
{
    public function __construct(
        private readonly PreferensiNotifikasi $preferensi,
        private readonly TahapPaket $tahap,
    ) {}

    /** Tahap yang DILIHAT pelanggan untuk satu item paket (kode, bukan label). */
    public function tahapTampil(OrderItem $item): string
    {
        $item->loadMissing(['sesiTerakhir', 'sesiTerakhir.certificate']);

        return PaketPelangganResource::untukPelanggan($this->tahap->untukItem($item));
    }

    /**
     * Seperti [tahapTampil], tapi TIDAK PERNAH melempar: galat dicatat dan hasilnya
     * `null` (= tahap sebelumnya tidak diketahui, jadi tidak ada kabar). Dipakai
     * `PelacakanController` SEBELUM menyimpan tahap fisik -- membaca tahap untuk
     * kabar tidak boleh bisa menggagalkan atau mendahului penyimpanannya.
     */
    public function tahapTampilAman(OrderItem $item): ?string
    {
        try {
            return $this->tahapTampil($item);
        } catch (\Throwable $e) {
            $this->catatGagal($item->getKey(), $e);

            return null;
        }
    }

    /**
     * Kabar untuk perpindahan tahap FISIK yang BARU disimpan (`$sebelum` dari
     * [tahapTampilAman]). Seluruh jalurnya -- membaca tahap sesudah, memilih
     * penerima, mengantre -- ada di dalam satu try/catch: serah terima yang sudah
     * tersimpan tidak boleh berubah jadi 500 gara-gara kabarnya.
     *
     * @return int jumlah akun yang dikirimi (0 bila tidak ada kabar / gagal)
     */
    public function kabariTahapFisik(OrderItem $item, ?string $sebelum): int
    {
        if ($sebelum === null) {
            return 0;
        }

        try {
            return $this->kirim(
                $item,
                $sebelum,
                $this->tahapTampil($item),
                'fisik:'.$item->tahap_fisik_pada?->getTimestamp(),
            );
        } catch (\Throwable $e) {
            $this->catatGagal($item->getKey(), $e);

            return 0;
        }
    }

    /**
     * @param  string  $peristiwa  pembeda peristiwa, mis. `fisik:<epoch>`
     * @return int jumlah akun yang dikirimi (0 bila tidak ada kabar / gagal)
     */
    public function kabari(OrderItem $item, string $sebelum, string $sesudah, string $peristiwa): int
    {
        try {
            return $this->kirim($item, $sebelum, $sesudah, $peristiwa);
        } catch (\Throwable $e) {
            $this->catatGagal($item->getKey(), $e);

            return 0;
        }
    }

    /**
     * Jalur tahap TURUNAN: sebuah sesi baru lahir (`$statusLama` null) atau
     * `status`-nya berubah. Tahap sebelum/sesudah dihitung oleh `TahapPaket`
     * yang sama dengan layar pelanggan -- bukan salinan aturannya di sini.
     *
     * @return int jumlah akun yang dikirimi
     */
    public function sesiBerubah(CalibrationSession $sesi, ?string $statusLama): int
    {
        try {
            if ($sesi->order_item_id === null) {
                return 0;
            }

            $item = OrderItem::query()->find($sesi->order_item_id);

            if ($item === null) {
                return 0;
            }

            $item->load(['sesiTerakhir', 'sesiTerakhir.certificate']);
            $terakhir = $item->sesiTerakhir;

            // Yang dihitung `TahapPaket` adalah sesi TERAKHIR paket. Sesi lama yang
            // diubah tidak menggeser apa pun yang dilihat pelanggan.
            if ($terakhir === null || (int) $terakhir->getKey() !== (int) $sesi->getKey()) {
                return 0;
            }

            $sesudah = $this->tahapTampil($item);

            if ($statusLama === null) {
                // Sesi baru: "sebelumnya" = sesi terakhir yang LAIN (paket yang
                // pernah dikerjakan ulang), atau tidak ada sama sekali.
                $dulu = $item->calibrationSessions()
                    ->whereKeyNot($sesi->getKey())
                    ->with('certificate')
                    ->orderByDesc('id')
                    ->first();
            } else {
                $dulu = $sesi->replicate();
                $dulu->status = $statusLama;
                $dulu->setRelation('certificate', $terakhir->certificate);
            }

            $item->setRelation('sesiTerakhir', $dulu);
            $sebelum = $this->tahapTampil($item);
            $item->setRelation('sesiTerakhir', $terakhir);

            return $this->kirim(
                $item,
                $sebelum,
                $sesudah,
                // `updated_at` ikut: sesi yang sama bisa masuk status yang sama dua kali
                // (diulang, lalu diajukan lagi) dan itu dua peristiwa yang berbeda.
                sprintf('sesi:%d:%s:%d', $sesi->getKey(), $sesi->status, $sesi->updated_at?->getTimestamp() ?? 0),
            );
        } catch (\Throwable $e) {
            $this->catatGagal($sesi->order_item_id, $e);

            return 0;
        }
    }

    private function kirim(OrderItem $item, string $sebelum, string $sesudah, string $peristiwa): int
    {
        if ($sebelum === $sesudah || $sesudah === TahapPaket::SERTIFIKAT_TERBIT) {
            return 0;
        }

        $batas = array_search(TahapPaket::SERTIFIKAT_TERBIT, TahapPaket::URUTAN, true);
        $posisiSebelum = array_search($sebelum, TahapPaket::URUTAN, true);
        $posisiSesudah = array_search($sesudah, TahapPaket::URUTAN, true);

        // Mundur dari sertifikat terbit ke atas = sertifikat ditarik/direvisi.
        if ($posisiSebelum >= $batas && $posisiSesudah < $posisiSebelum) {
            return 0;
        }

        $order = Order::query()->find($item->order_id);

        if ($order === null || $order->status === Order::STATUS_DIBATALKAN) {
            return 0;
        }

        $alat = Equipment::query()
            ->withTrashed()
            ->find($item->equipment_id, ['id', 'organization_id', 'customer_id', 'nama_alat', 'merk', 'serial_number']);

        // Alat milik pelanggan lain tidak tampil di `/paket` pelanggan ini, jadi
        // tahapnya pun bukan kabar buat mereka.
        if ($alat === null
            || (int) $alat->organization_id !== (int) $order->organization_id
            || ($alat->customer_id !== null && (int) $alat->customer_id !== (int) $order->customer_id)) {
            return 0;
        }

        $pelanggan = $order->customer;

        if (! $pelanggan instanceof Customer
            || (int) $pelanggan->organization_id !== (int) $order->organization_id) {
            return 0;
        }

        $penerima = $this->preferensi->penerima($pelanggan, PreferensiNotifikasi::TAHAP_ALAT);

        if ($penerima->isEmpty()) {
            return 0;
        }

        $notifikasi = TahapAlatBerubah::dari(
            (int) $order->getKey(),
            (int) $item->getKey(),
            (string) $order->nomor,
            (string) $alat->nama_alat,
            $alat->merk,
            $alat->serial_number,
            $sesudah,
            $peristiwa,
        );
        $tandaTangan = TahapAlatBerubah::tandaTanganUntuk((int) $item->getKey(), $sesudah, $peristiwa);

        return $this->kirimKe($penerima, $notifikasi, $tandaTangan, $item);
    }

    /**
     * Satu per satu, masing-masing dibungkus sendiri: satu akun yang gagal
     * tidak boleh membuat rekannya tidak dikabari.
     *
     * @param  iterable<int, User>  $penerima
     */
    private function kirimKe(iterable $penerima, TahapAlatBerubah $notifikasi, string $tandaTangan, OrderItem $item): int
    {
        $terkirim = 0;

        /** @var User $user */
        foreach ($penerima as $user) {
            try {
                if ($this->sudahDikabari($user, $tandaTangan)) {
                    continue;
                }

                $user->notify(clone $notifikasi);
                $terkirim++;
            } catch (\Throwable $e) {
                $this->catatGagal($item->getKey(), $e);
            }
        }

        return $terkirim;
    }

    private function sudahDikabari(User $user, string $tandaTangan): bool
    {
        // `tanda_tangan` hanya berisi huruf, angka, titik, titik dua, dan garis
        // bawah -- tidak ada karakter yang di-escape JSON, jadi kecocokan literal
        // (dengan tanda kutip penutup) pasti persis.
        return $user->notifications()
            ->where('type', TahapAlatBerubah::class)
            ->where('data', 'like', '%"'.PenjagaNotifikasiUlang::KEY_TANDA_TANGAN.'":"'.$tandaTangan.'"%')
            ->exists();
    }

    private function catatGagal(mixed $itemId, \Throwable $e): void
    {
        // Sengaja BUKAN `$e->getMessage()`: pesan galat bisa memuat email atau nama.
        Log::warning('Tahap alat berubah, tapi mengabari pelanggan gagal.', [
            'order_item_id' => $itemId,
            'galat' => $e::class,
        ]);
    }
}
