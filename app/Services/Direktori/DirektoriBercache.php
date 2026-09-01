<?php

namespace App\Services\Direktori;

use Illuminate\Support\Facades\Cache;

/**
 * Pembungkus [DirektoriPerusahaan] yang mengingat hasil pencarian.
 *
 * ## Kenapa ada
 *
 * Places API ditagih **per request**, dan sebelum berkas ini tidak ada satu pun
 * cache maupun throttle di jalur itu: tiap kali teknisi mencari nama perusahaan
 * yang sama, lab membayar lagi untuk jawaban yang sama persis. Pencarian di
 * lapangan justru berulang-ulang — satu pabrik dicari beberapa teknisi, dan satu
 * teknisi mengulang kata kunci yang sama waktu jaringannya putus di tengah.
 *
 * Nama & alamat tempat usaha praktis tidak berubah dari jam ke jam, jadi ini
 * salah satu jawaban yang paling aman untuk diingat.
 *
 * ## Kenapa membungkus TIAP LAPIS, bukan [DirektoriBerlapis] di luarnya
 *
 * Ini bukan selera, dan salah pasang di sini gagalnya diam:
 * `DirektoriBerlapis::atribusi()` memulangkan `$this->penjawab?->atribusi()`,
 * dan `$penjawab` itu di-set DI DALAM `cari()`. Kalau cache dipasang di
 * luarnya, cache-hit membuat `cari()` tidak pernah jalan, `$penjawab` tetap
 * `null`, dan atribusinya pulang **null** — layar berhenti memajang "Powered by
 * Google" / "© OpenStreetMap" tanpa satu pun error. Itu pelanggaran lisensi
 * yang tidak akan pernah ketahuan dari log.
 *
 * Dibungkus per lapis, `DirektoriBerlapis` tetap memanggil `cari()` sungguhan
 * pada pembungkus ini, `$penjawab` tetap terisi, dan [atribusi] di bawah
 * meneruskan ke penyedia aslinya. Kedua penyedia yang ada sekarang memulangkan
 * konstanta di `atribusi()`, jadi penerusan itu tidak bisa basi.
 *
 * ## Yang TIDAK diingat
 *
 * **Kegagalan.** [DirektoriGagal] dibiarkan melintas tanpa disimpan. Menyimpan
 * kegagalan berarti satu gangguan sesaat di sisi penyedia mengunci pencarian
 * selama masa berlaku cache — dan yang terkunci adalah pendaftaran pelanggan di
 * lapangan, bukan sekadar tampilan.
 *
 * Hasil KOSONG tetap diingat, tapi sebentar saja ([DETIK_KOSONG]): "belum
 * ketemu" itu jawaban yang paling mungkin berubah — pabrik baru didaftarkan ke
 * peta — sementara kata kunci yang tidak ketemu justru yang paling sering
 * diulang teknisi, dan tiap pengulangan itu ditagih.
 *
 * ## Kalau hasilnya terasa basi
 *
 * `php artisan cache:clear`. Sengaja tidak dibikin bisa disetel lewat `.env`:
 * satu tombol yang tidak pernah dipakai lebih mahal daripada satu perintah yang
 * sudah ada, dan angkanya bisa diubah di dua konstanta di bawah.
 */
final class DirektoriBercache implements DirektoriPerusahaan
{
    /** Hasil yang KETEMU diingat sehari. */
    private const DETIK_KETEMU = 86400;

    /** Hasil KOSONG diingat sejam saja — lihat docblock kelas. */
    private const DETIK_KOSONG = 3600;

    private const AWALAN_KUNCI = 'direktori-perusahaan';

    private readonly string $ruang;

    /**
     * @param  string|null  $ruang  Nama ruang cache. Bawaannya nama kelas
     *                              penyedianya — cukup selama tiap penyedia
     *                              punya kelas sendiri, seperti sekarang.
     *                              WAJIB diisi kalau dua penyedia BERKELAS SAMA
     *                              hidup berdampingan (misalnya dua Places
     *                              dengan API key berbeda): tanpa itu keduanya
     *                              berebut satu entri, dan yang terbit bukan
     *                              sekadar hasil yang salah tapi hasil satu
     *                              penyedia yang dipajang dengan atribusi
     *                              penyedia lain.
     */
    public function __construct(
        private readonly DirektoriPerusahaan $dalam,
        ?string $ruang = null,
    ) {
        $this->ruang = $ruang ?? $dalam::class;
    }

    /** {@inheritDoc} */
    public function tersedia(): bool
    {
        return $this->dalam->tersedia();
    }

    /** {@inheritDoc} */
    public function atribusi(): ?string
    {
        return $this->dalam->atribusi();
    }

    /**
     * {@inheritDoc}
     *
     * Kegagalan penyedia dibiarkan melintas — lihat docblock kelas.
     */
    public function cari(string $kata): array
    {
        $kunci = $this->kunci($kata);
        $tersimpan = Cache::get($kunci);

        if (is_array($tersimpan)) {
            /** @var list<PerusahaanDitemukan> $tersimpan */
            return $tersimpan;
        }

        $hasil = $this->dalam->cari($kata);

        Cache::put($kunci, $hasil, $hasil === [] ? self::DETIK_KOSONG : self::DETIK_KETEMU);

        return $hasil;
    }

    /**
     * Kunci cache satu pencarian.
     *
     * Kata kuncinya DINORMALKAN dulu — huruf kecil, spasi ganda dirapatkan,
     * ujungnya dipangkas. Di situlah penghematannya: `PT Sidik`, `pt sidik`,
     * dan `PT  Sidik ` itu satu pertanyaan yang sama buat teknisi, dan tanpa
     * normalisasi jadi tiga tagihan.
     *
     * Ruang penyedianya ikut masuk kunci — lihat `$ruang` di konstruktor.
     * Tanpa itu jawaban Google dan jawaban OpenStreetMap berebut satu entri.
     */
    private function kunci(string $kata): string
    {
        $rapat = preg_replace('/\s+/u', ' ', $kata) ?? $kata;
        $normal = mb_strtolower(trim($rapat));

        return self::AWALAN_KUNCI.':'.md5($this->ruang.'|'.$normal);
    }
}
