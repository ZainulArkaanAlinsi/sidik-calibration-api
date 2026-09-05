<?php

namespace App\Services\Direktori;

use App\Models\Customer;
use App\Models\DirektoriLokal;
use Throwable;

/**
 * Direktori yang dibaca dari tabel lab sendiri, bukan ditembak ke luar.
 *
 * ## Kenapa dia memenuhi kontrak yang sama
 *
 * Karena dengan begitu **nol berkas di sisi HP berubah**. `GET
 * /customers/direktori` sudah ada, `cariDirektori()` di Flutter sudah
 * memanggilnya, dan layar `pelanggan_baru_screen` sudah menggambar hasilnya.
 * Menambah sumber data lewat kontrak berarti fitur ini mendarat tanpa rilis
 * APK baru, tanpa satu byte pun menambah ukuran aplikasi.
 *
 * ## Kelemahannya berlawanan dengan direktori luar, dan itu gunanya
 *
 * | | Cakupan | Mati karena |
 * |---|---|---|
 * | Lokal (ini) | cuma yang pernah diimpor | (tidak pernah — sekueri database) |
 * | OSM / Google | apa pun yang ada di peta | jaringan, kuota, key |
 *
 * Ditaruh sebagai lapis PERTAMA di [DirektoriBerlapis], dia menjawab tanpa
 * menyentuh jaringan sama sekali — yang dibeli bukan cuma kecepatan, tapi
 * berkurangnya request berbayar: pencarian yang ketemu di sini tidak pernah
 * sampai ke Google.
 *
 * ## `tersedia()` bergantung ISI, bukan setelan
 *
 * Beda dari driver luar yang memeriksa API key, yang menentukan di sini ada
 * tidaknya baris. Tabel kosong berarti jalur ini memang belum bisa menjawab,
 * dan harus jujur bilang begitu — kalau dia mengaku tersedia lalu memulangkan
 * daftar kosong, [DirektoriBerlapis] tetap melanjutkan ke lapis berikutnya
 * (butir 2 aturannya), jadi tidak ada yang rusak; tapi `GET /api/health` jadi
 * melaporkan jalur yang sebenarnya mati sebagai hidup.
 */
class DirektoriLokalDb implements DirektoriPerusahaan
{
    /** Hasil per pencarian. Sengaja kecil: ini daftar pilih, bukan laporan. */
    private const BATAS = 8;

    public function tersedia(): bool
    {
        return self::jumlah() > 0;
    }

    /**
     * Berapa baris yang siap dicari, atau 0 kalau tabelnya belum ada.
     *
     * **Kegagalan database DITELAN di sini, dan itu disengaja.** Jalur ini
     * dipanggil `AppServiceProvider` waktu membangun `DirektoriPerusahaan`, dan
     * `DirektoriPerusahaan` diselesaikan oleh `GET /api/health`. Tanpa
     * penjagaan ini, pemasangan yang migrasinya belum jalan bikin health
     * membalas **500** — endpoint yang justru dipakai buat mendiagnosis kenapa
     * pemasangannya belum benar. Persis kebalikan dari gunanya.
     *
     * Yang ditelan cuma kegagalan MEMBACA. Tabel yang tidak ada berarti lapis
     * ini memang belum bisa menjawab, dan `false` itu jawaban yang jujur —
     * bukan menyembunyikan kerusakan. Pencariannya sendiri tetap melempar,
     * lihat [cari].
     */
    public static function jumlah(): int
    {
        try {
            return DirektoriLokal::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<PerusahaanDitemukan>
     */
    public function cari(string $kata): array
    {
        $normal = Customer::normalkanNama($kata);

        // Kata kunci yang isinya tanda baca doang turun jadi string KOSONG, dan
        // `LIKE '%%'` mencocoki SEMUA baris — daftar pilih berubah jadi delapan
        // perusahaan acak yang tidak ada hubungannya dengan yang diketik.
        // Jebakan yang sama sudah pernah menggigit `CustomerController::lookup`.
        if ($normal === '') {
            return [];
        }

        try {
            return $this->ambil($normal);
        } catch (Throwable $e) {
            // Dibungkus jadi tipe yang DIKENALI kontraknya. `DirektoriBerlapis`
            // cuma menangkap `DirektoriGagal` — `PDOException` mentah lolos
            // dari sana dan menjatuhkan seluruh pencarian, padahal lapis
            // berikutnya masih bisa menjawab.
            throw new DirektoriGagal('Direktori lokal tidak bisa dibaca: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return list<PerusahaanDitemukan>
     */
    private function ambil(string $normal): array
    {
        return DirektoriLokal::query()
            ->where('nama_normal', 'like', '%'.$normal.'%')
            // Yang diawali kata kuncinya naik duluan. Tanpa ini "maju" memulangkan
            // "PT Sinar Maju Abadi" di atas "PT Maju Jaya", dan teknisi yang
            // mengetik nama depan PT-nya harus memindai daftar buat menemukan
            // yang paling jelas.
            ->orderByRaw('CASE WHEN nama_normal LIKE ? THEN 0 ELSE 1 END', [$normal.'%'])
            ->orderBy('nama')
            ->limit(self::BATAS)
            ->get()
            ->map(fn (DirektoriLokal $baris) => new PerusahaanDitemukan(
                ref: $baris->refDirektori(),
                nama: (string) $baris->nama,
                alamat: $baris->alamat,
            ))
            ->all();
    }

    /**
     * Atribusi menyebut sumbernya, dan itu bukan basa-basi.
     *
     * Teknisi yang melihat alamat di layar perlu tahu dia sedang membaca
     * salinan direktori pihak ketiga yang sebagian bertanggal 2020 — bukan data
     * pelanggan lab yang sudah diverifikasi. Tanpa kalimat ini, hasil impor
     * kelihatan sama persis dengan pelanggan yang beneran pernah dilayani, dan
     * bedanya baru ketahuan waktu alamat lama tercetak di sertifikat.
     */
    public function atribusi(): ?string
    {
        return 'Sumber: direktori internal lab (salinan Jababeka 2020 & Indonetwork). '
            .'Belum diverifikasi — cocokkan dengan surat pesanan sebelum dipakai di sertifikat.';
    }
}
