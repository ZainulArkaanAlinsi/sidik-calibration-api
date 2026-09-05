<?php

namespace App\Services\Direktori;

use Illuminate\Support\Facades\Log;

/**
 * Beberapa direktori dipakai berurutan, bukan dipilih satu.
 *
 * ## Kenapa berlapis, bukan salah satu
 *
 * Dua sumbernya punya kelemahan yang **berlawanan**, dan itu yang bikin
 * pasangannya masuk akal:
 *
 * | | Cakupan pabrik Indonesia | Bisa mati karena |
 * |---|---|---|
 * | Google Places | tebal — hampir semua pabrik ada di Maps | key belum disetel, kuota habis, key ditolak |
 * | OpenStreetMap | tipis — cuma yang pernah dipetakan sukarelawan | (nyaris tidak pernah — tanpa key, tanpa kuota) |
 *
 * Dipilih salah satu, teknisi kena kelemahan yang itu sepenuhnya: dengan Google
 * saja, satu setelan yang salah mematikan pencarian di lapangan; dengan OSM
 * saja, pabrik yang belum dipetakan tidak akan pernah ketemu.
 *
 * Berurutan, yang tersisa cuma irisan kelemahannya — dan itu jauh lebih kecil.
 *
 * ## Aturan mainnya
 *
 *  1. Lapis dicoba **berurutan**. Yang pertama memulangkan hasil, itu yang
 *     dipakai.
 *  2. Lapis yang memulangkan **nol hasil** BUKAN jawaban akhir — lapis
 *     berikutnya tetap dicoba. Ini seluruh gunanya: PT yang tidak ada di satu
 *     sumber sering ada di sumber lain.
 *  3. Lapis yang **gagal** dicatat lalu dilewat, bukan menjatuhkan seluruh
 *     pencarian. Kuota Google habis di tengah kerjaan tidak boleh
 *     menghentikan teknisi yang sedang berdiri di gerbang pabrik.
 *  4. [DirektoriGagal] dilempar HANYA kalau setiap lapis yang sempat dicoba
 *     gagal. Semua lapis menjawab "nol hasil" itu keadaan yang berbeda — itu
 *     jawaban sah, dan pulang sebagai daftar kosong.
 *
 * Butir 4 yang paling gampang diratakan, dan akibatnya persis kebalikan dari
 * gunanya fitur ini: teknisi membaca "PT-nya tidak ada di direktori" padahal
 * yang terjadi seluruh direktorinya sedang tidak bisa dihubungi — lalu dia
 * mendaftarkan ulang perusahaan yang sebenarnya sudah ada di sana.
 *
 * ## Atribusi ikut lapis yang menjawab
 *
 * Ini bukan detail administratif. Atribusi itu syarat lisensi yang melekat ke
 * SUMBER DATANYA — memajang "© OpenStreetMap" di atas hasil Google (atau
 * sebaliknya) sama saja salah menyebut sumber, dan itu pelanggaran yang tidak
 * meninggalkan satu pun error.
 *
 * Karena itu [atribusi] mengikuti lapis yang benar-benar menjawab panggilan
 * [cari] terakhir. Objek ini **di-`bind`, bukan `singleton`** (lihat
 * `AppServiceProvider`), jadi tiap permintaan HTTP punya instansnya sendiri dan
 * ingatan itu tidak pernah bocor antar teknisi.
 */
class DirektoriBerlapis implements DirektoriPerusahaan
{
    /** Lapis yang menjawab [cari] terakhir. Lihat catatan atribusi di kelas. */
    private ?DirektoriPerusahaan $penjawab = null;

    /** @param  array<int, DirektoriPerusahaan>  $lapis  Urut: yang paling tebal duluan. */
    public function __construct(private readonly array $lapis) {}

    /**
     * Siap kalau ADA SATU SAJA lapis yang siap.
     *
     * Bukan "semua lapis siap": Google yang belum disetel bukan alasan
     * menyembunyikan tombol cari dari teknisi, selama OSM di belakangnya masih
     * jalan.
     */
    public function tersedia(): bool
    {
        foreach ($this->lapis as $satu) {
            if ($satu->tersedia()) {
                return true;
            }
        }

        return false;
    }

    /** {@inheritDoc} */
    public function atribusi(): ?string
    {
        return $this->penjawab?->atribusi();
    }

    /** {@inheritDoc} */
    public function cari(string $kata): array
    {
        $this->penjawab = null;

        $dicoba = 0;
        $gagal = 0;

        foreach ($this->lapis as $satu) {
            if (! $satu->tersedia()) {
                continue;
            }

            $dicoba++;

            try {
                $hasil = $satu->cari($kata);
            } catch (DirektoriGagal $e) {
                $gagal++;

                // Dicatat, lalu dilewat. Satu lapis yang mati bukan alasan
                // menghentikan teknisi — keadaan inilah yang bikin lapis
                // berikutnya ada.
                Log::warning('Lapis direktori gagal, lanjut ke lapis berikutnya.', [
                    'lapis' => $satu::class,
                    'sebab' => $e->getMessage(),
                ]);

                continue;
            }

            if ($hasil !== []) {
                $this->penjawab = $satu;

                return $hasil;
            }
        }

        // Tidak ada lapis yang siap sama sekali, atau semua yang dicoba gagal.
        // Dua-duanya berarti "tidak bisa dihubungi", bukan "tidak ketemu".
        if ($dicoba === 0 || $gagal === $dicoba) {
            throw new DirektoriGagal('Semua direktori perusahaan tidak bisa dihubungi.');
        }

        // Sampai sini: ada lapis yang menjawab dengan sukses, dan jawabannya
        // memang kosong. Itu jawaban sah.
        return [];
    }
}
