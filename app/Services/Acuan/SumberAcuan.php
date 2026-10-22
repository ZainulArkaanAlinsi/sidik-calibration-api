<?php

namespace App\Services\Acuan;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Support\JsonKanonik;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Satu pintu baca data acuan lapis 1 (Studio Data Acuan, 03-SDD §4, ADR-04).
 *
 * Pemakai: kelas `Tabel*` milik profil yang punya `skemaAcuan()` (daftarnya
 * dipatok `SkemaAcuanProfilTest::BERSKEMA`). Kelas `Tabel*` lain masih membaca
 * `database/data/*.json` sendiri — angkanya konstanta berkas/kode sampai
 * profilnya dimigrasikan (skemaAcuan + peta sel), bukan "dari Studio".
 * Pemakai kelas ini bertanya dengan kode profil dan path berkasnya.
 * Jawabannya:
 *
 *  - **isi versi DB** bila `config('acuan.sumber') === 'db'` DAN ada versi
 *    yang sedang dalam lingkup untuk profil itu ([denganVersi]);
 *  - **berkas JSON** dalam keadaan lain — persis perilaku sebelum Studio.
 *
 * ## Kenapa versi DB hanya dibaca DI DALAM lingkup
 *
 * Keputusan P1 butir 2. Tabel Micrometer dipakai juga oleh Dial Indicator
 * (balok ukur yang sama), `micrometer:audit-cmc`, lembar kerja, dan seeder.
 * Kalau versi aktif dibaca di mana saja, versi 2 Micrometer diam-diam
 * mengubah angka Dial Indicator — tanpa stempel versi di hasilnya. Dengan
 * lingkup, invariannya sederhana: angka dari versi DB ⇒ jalur itu sendiri
 * yang memilih versinya (dan, mulai B3, menstempelnya).
 *
 * ## Cache dikunci (versi, sha256), bukan per kelas
 *
 * Sebelum ini tiap `Tabel*` menyimpan isinya di `private static ?array $data`.
 * Worker `queue:work` hidup sampai satu jam; cache per kelas berarti sesudah
 * versi baru sah, worker tetap menghitung dengan isi lama — tanpa error
 * (03-SDD §4, dijaga `CacheAcuanPerVersiTest`). Kunci di sini ikut isi:
 * versi lain atau isi lain = kunci lain, jadi tidak ada yang perlu
 * di-invalidate.
 *
 * Cache-nya statis (hidup lintas instance & lintas test dalam satu proses)
 * dan dibatasi [BATAS_CACHE] entri, buang yang paling lama tidak dipakai.
 * Karena kuncinya memuat sha256 isi, entri yang tertinggal dari test lain
 * dengan id versi yang sama hanya cocok kalau isinya memang identik.
 *
 * Lingkupnya sendiri BUKAN statis: dia milik instance singleton di container,
 * jadi aplikasi baru (tiap test) mulai tanpa lingkup.
 */
class SumberAcuan
{
    /** Batas entri cache statis; worker panjang tidak boleh menumpuk isi tanpa batas. */
    public const BATAS_CACHE = 32;

    /** @var array<string, array<array-key, mixed>> kunci cache => isi terurai */
    private static array $cache = [];

    /** @var array<string, list<PaketAcuanVersi>> kode profil => tumpukan versi dalam lingkup */
    private array $lingkup = [];

    /**
     * Jalankan `$aksi` dengan `$versi` sebagai data acuan profil paketnya.
     *
     * Boleh bersarang (yang terdalam menang) dan lingkup selalu dilepas di
     * `finally` — exception di tengah hitungan tidak boleh meninggalkan versi
     * yang tetap "aktif" untuk pemanggil berikutnya di proses yang sama.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    public function denganVersi(PaketAcuanVersi $versi, callable $aksi): mixed
    {
        // Versi `bentuk` (lapis 3) tidak berisi tabel acuan; membacanya sebagai
        // data berarti calculator menerima struktur yang salah.
        if ($versi->jenis !== PaketAcuanVersi::JENIS_DATA) {
            throw new InvalidArgumentException(
                "Versi #{$versi->getKey()} berjenis '{$versi->jenis}', bukan '".PaketAcuanVersi::JENIS_DATA
                ."' — hanya versi data yang boleh jadi lingkup data acuan.",
            );
        }

        $kode = $this->kodeProfil($versi);
        $this->lingkup[$kode][] = $versi;

        try {
            return $aksi();
        } finally {
            array_pop($this->lingkup[$kode]);

            if ($this->lingkup[$kode] === []) {
                unset($this->lingkup[$kode]);
            }
        }
    }

    /** Versi terdalam yang sedang dalam lingkup untuk profil ini, atau `null`. */
    public function versiDalamLingkup(string $kodeProfil): ?PaketAcuanVersi
    {
        $tumpukan = $this->lingkup[$kodeProfil] ?? [];

        return $tumpukan === [] ? null : $tumpukan[array_key_last($tumpukan)];
    }

    /**
     * Isi data acuan profil ini, sudah terurai (`json_decode(..., true)`).
     *
     * Isi versi DB adalah JSON berkas yang dikanonikkan (`JsonKanonik`), jadi
     * nilai & tipe tiap daun sama dengan berkasnya — yang beda hanya URUTAN
     * kunci objek (diurutkan byte). Kelas pembaca wajib mencari lewat kunci,
     * bukan lewat posisi kunci objek; urutan list tetap dipertahankan.
     *
     * Untuk jalur berkas: berkas yang ada tapi bukan objek/list JSON
     * memulangkan `[]` (tidak di-cache) supaya penjaga kunci wajib milik
     * kelas pemanggil yang menolaknya dengan pesannya sendiri — sama seperti
     * sebelum kelas ini ada.
     *
     * @return array<array-key, mixed>
     */
    public function isi(string $kodeProfil, string $berkasJson): array
    {
        $versi = config('acuan.sumber') === 'db'
            ? $this->versiDalamLingkup($kodeProfil)
            : null;

        return $versi !== null
            ? $this->isiVersi($versi)
            : $this->isiBerkas($berkasJson);
    }

    /** Kosongkan cache statis. Untuk test. */
    public static function lupakanCache(): void
    {
        self::$cache = [];
    }

    /** @return array<array-key, mixed> */
    private function isiVersi(PaketAcuanVersi $versi): array
    {
        $isi = (string) $versi->isi;
        $sha = $versi->isi_sha256 ?? JsonKanonik::sha256($isi);
        $kunci = 'versi:'.$versi->getKey().':'.$sha;

        $tersimpan = $this->ambil($kunci);

        if ($tersimpan !== null) {
            return $tersimpan;
        }

        // `isi_sha256` dikunci saat diajukan (03-SDD §2.2). Kalau byte isinya
        // berhenti cocok dengan hash itu, angka yang akan dihitung bukan angka
        // yang disahkan — gagal keras, jangan menghitung.
        if ($versi->isi_sha256 !== null && JsonKanonik::sha256($isi) !== $versi->isi_sha256) {
            throw new UnexpectedValueException(
                "Isi paket_acuan_versi #{$versi->getKey()} tidak cocok dengan isi_sha256-nya "
                ."({$versi->isi_sha256}); isi diubah sesudah dikunci.",
            );
        }

        return $this->simpan($kunci, $versi->isiTerurai());
    }

    /** @return array<array-key, mixed> */
    private function isiBerkas(string $berkas): array
    {
        $kunci = 'berkas:'.$berkas;
        $tersimpan = $this->ambil($kunci);

        if ($tersimpan !== null) {
            return $tersimpan;
        }

        if (! is_file($berkas)) {
            throw new RuntimeException("Berkas data acuan nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        return is_array($isi) ? $this->simpan($kunci, $isi) : [];
    }

    /** @return array<array-key, mixed>|null */
    private function ambil(string $kunci): ?array
    {
        if (! array_key_exists($kunci, self::$cache)) {
            return null;
        }

        // Pindah ke ekor: yang dibuang saat penuh adalah yang paling lama
        // tidak dipakai, bukan berkas JSON yang dibaca terus-menerus.
        $isi = self::$cache[$kunci];
        unset(self::$cache[$kunci]);

        return self::$cache[$kunci] = $isi;
    }

    /**
     * @param  array<array-key, mixed>  $isi
     * @return array<array-key, mixed>
     */
    private function simpan(string $kunci, array $isi): array
    {
        while (count(self::$cache) >= self::BATAS_CACHE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }

        return self::$cache[$kunci] = $isi;
    }

    /**
     * `kode_profil` paket pemilik versi. Paket boleh sudah soft delete: versi
     * yang pernah menstempel hasil tetap harus bisa dihitung ulang.
     */
    private function kodeProfil(PaketAcuanVersi $versi): string
    {
        $paket = $versi->relationLoaded('paketAcuan') ? $versi->paketAcuan : null;

        $paket ??= PaketAcuan::withTrashed()->find($versi->paket_acuan_id);

        if ($paket === null) {
            throw new UnexpectedValueException(
                "Paket acuan untuk versi #{$versi->getKey()} tidak ditemukan.",
            );
        }

        return (string) $paket->kode_profil;
    }
}
