<?php

namespace App\Support\ImporPelanggan;

use RuntimeException;

/**
 * Baca berkas CSV daftar pelanggan lab jadi baris yang siap diadu.
 *
 * ## Kenapa CSV saja, bukan xlsx
 *
 * Membaca xlsx butuh dependensi baru (PhpSpreadsheet), dan yang dibeli cuma
 * satu langkah manual: "Save As CSV" sekali per berkas. Impor ini jalan
 * beberapa kali seumur hidup lab, bukan tiap hari — jadi harganya tidak
 * sebanding. Kalau suatu saat lab minta xlsx langsung, yang ditambah pembaca
 * baru di sini; sisanya (pemilah, laporan, perintah) tidak perlu berubah.
 *
 * ## Tiga hal yang bikin CSV dari Excel gagal TANPA ERROR
 *
 * Ketiganya kejadian nyata di Excel berlokal Indonesia, dan ketiganya lolos
 * pembaca CSV yang naif:
 *
 *   1. **Pemisahnya `;`, bukan `,`.** Excel memakai daftar pemisah dari setelan
 *      wilayah Windows, dan di lokal Indonesia itu titik-koma. Dibaca dengan
 *      `,`, seluruh berkas jadi SATU kolom bernama `nama;alamat;telepon`,
 *      dan tiap baris masuk dengan nama berisi seluruh barisnya. Tidak ada
 *      yang error — yang lahir 300 pelanggan bernama sampah.
 *   2. **BOM di depan header.** "CSV UTF-8" menulis `EF BB BF` sebelum huruf
 *      pertama, jadi header pertamanya bukan `nama` melainkan `\xEF\xBB\xBFnama`
 *      dan kolom namanya dianggap tidak ada.
 *   3. **Bukan UTF-8 sama sekali.** "CSV (Comma delimited)" menulis ANSI
 *      (Windows-1252). Huruf beraksen dan `°` jadi byte rusak yang lolos ke
 *      database dan baru kelihatan di sertifikat.
 *
 * Ketiganya ditangani di sini, sekali, supaya tidak ada pemanggil yang harus
 * ingat.
 */
final class BacaBerkas
{
    /** Batas kolom `customers` — semuanya `string` = varchar(255). */
    private const BATAS = 255;

    /**
     * Escape KOSONG, yaitu RFC 4180 — bukan `\` bawaan lama PHP.
     *
     * Excel tidak pernah memakai backslash sebagai escape; dia menggandakan
     * tanda kutip (`""`). Dengan escape `\`, alamat yang kebetulan BERAKHIR
     * backslash — `"Jl. Raya Blok C\"` — menelan tanda kutip penutupnya, lalu
     * kolom alamat dan kolom sesudahnya MELEBUR jadi satu nilai dan seluruh
     * kolom setelahnya bergeser. Tidak ada error; yang tersimpan cuma alamat
     * yang kelihatan aneh dan satu kolom yang hilang.
     */
    private const ESCAPE = '';

    /**
     * Nama kolom yang diterima untuk tiap kolom tujuan.
     *
     * Dicocokkan SETELAH header dinormalkan (huruf kecil, tanda baca jadi
     * spasi). Jadi `Nama PT.`, `NAMA_PT`, dan `nama pt` sama-sama kena.
     *
     * @var array<string, list<string>>
     */
    private const SINONIM = [
        'nama' => ['nama', 'nama pt', 'nama perusahaan', 'perusahaan', 'pelanggan', 'customer', 'nama pelanggan', 'nama customer'],
        'alamat' => ['alamat', 'address', 'alamat perusahaan', 'alamat pt', 'alamat lengkap'],
        'contact_person' => ['contact person', 'contactperson', 'cp', 'pic', 'kontak', 'narahubung', 'nama kontak'],
        'telepon' => ['telepon', 'telp', 'no telp', 'nomor telepon', 'no telepon', 'hp', 'no hp', 'nomor hp', 'phone', 'telephone'],
        'email' => ['email', 'e mail', 'surel', 'alamat email'],
    ];

    /**
     * @return array{
     *     baris: list<BarisMasukan>,
     *     ditolak: list<array{baris: int, alasan: string, isi: string}>,
     *     pemisah: string,
     *     kolom: array<string, int>,
     * }
     */
    public static function baca(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Berkas tidak ketemu atau tidak bisa dibaca: {$path}");
        }

        $isi = (string) file_get_contents($path);

        if (trim($isi) === '') {
            throw new RuntimeException("Berkas kosong: {$path}");
        }

        $isi = self::keUtf8(self::buangBom($isi));
        $pemisah = self::tebakPemisah($isi);

        $aliran = fopen('php://memory', 'r+');

        if ($aliran === false) {
            throw new RuntimeException('Tidak bisa membuka aliran memori untuk membaca CSV.');
        }

        fwrite($aliran, $isi);
        rewind($aliran);

        $kolom = self::petakanHeader(fgetcsv($aliran, 0, $pemisah, '"', self::ESCAPE) ?: [], $path);

        $baris = [];
        $ditolak = [];
        $nomor = 1;

        while (($sel = fgetcsv($aliran, 0, $pemisah, '"', self::ESCAPE)) !== false) {
            $nomor++;

            // `fgetcsv` mengembalikan `[null]` untuk baris kosong. Berkas Excel
            // hampir selalu punya beberapa di ujung, dan menolaknya satu per
            // satu bikin laporan penuh sampah yang menenggelamkan yang penting.
            if ($sel === [null] || implode('', array_map(strval(...), $sel)) === '') {
                continue;
            }

            $hasil = self::susunBaris($sel, $kolom, $nomor);

            if ($hasil instanceof BarisMasukan) {
                $baris[] = $hasil;
            } else {
                $ditolak[] = $hasil;
            }
        }

        fclose($aliran);

        return ['baris' => $baris, 'ditolak' => $ditolak, 'pemisah' => $pemisah, 'kolom' => $kolom];
    }

    /**
     * @param  list<string|null>  $sel
     * @param  array<string, int>  $kolom
     * @return BarisMasukan|array{baris: int, alasan: string, isi: string}
     */
    private static function susunBaris(array $sel, array $kolom, int $nomor): BarisMasukan|array
    {
        $ambil = static fn (string $tujuan): ?string => isset($kolom[$tujuan])
            ? self::bersihkan($sel[$kolom[$tujuan]] ?? null)
            : null;

        $mentahBaris = implode(' | ', array_map(static fn ($s) => (string) $s, $sel));
        $nama = $ambil('nama');

        if ($nama === null) {
            return ['baris' => $nomor, 'alasan' => 'kolom nama kosong', 'isi' => $mentahBaris];
        }

        if (mb_strlen($nama) > self::BATAS) {
            // Dipotong berarti nama badan hukum yang salah, dan §4 bikin
            // salahnya permanen begitu ikut tercetak di sertifikat.
            return ['baris' => $nomor, 'alasan' => 'nama lebih dari '.self::BATAS.' huruf', 'isi' => $mentahBaris];
        }

        $peringatan = [];

        if (count($sel) !== count($kolom) && count($sel) < max($kolom) + 1) {
            $peringatan[] = 'kolomnya kurang dari header ('.count($sel).' vs '.(max($kolom) + 1).')';
        }

        $alamat = self::batasiPanjang($ambil('alamat'), 'alamat', $peringatan);
        $contactPerson = self::batasiPanjang($ambil('contact_person'), 'contact_person', $peringatan);
        $telepon = self::sahkanTelepon(self::batasiPanjang($ambil('telepon'), 'telepon', $peringatan), $peringatan);
        $email = self::sahkanEmail(self::batasiPanjang($ambil('email'), 'email', $peringatan), $peringatan);

        return new BarisMasukan(
            nomorBaris: $nomor,
            nama: $nama,
            alamat: $alamat,
            contactPerson: $contactPerson,
            telepon: $telepon,
            email: $email,
            peringatan: $peringatan,
        );
    }

    /**
     * Nilai kepanjangan dikosongkan, BUKAN dipotong.
     *
     * Alamat yang dipotong di huruf ke-255 tetap kelihatan seperti alamat
     * lengkap — dan itu justru bentuk kesalahan yang paling sulit ketahuan.
     * Kosong yang jujur bisa dilihat admin dan dilengkapi; potongan tidak.
     *
     * @param  list<string>  $peringatan
     */
    private static function batasiPanjang(?string $nilai, string $kolom, array &$peringatan): ?string
    {
        if ($nilai === null || mb_strlen($nilai) <= self::BATAS) {
            return $nilai;
        }

        $peringatan[] = "{$kolom} lebih dari ".self::BATAS.' huruf, dikosongkan (isi aslinya tidak dipotong)';

        return null;
    }

    /**
     * Telepon yang terbaca sebagai notasi ilmiah dikosongkan.
     *
     * Excel memperlakukan `081234567890` sebagai ANGKA, dan angka sepanjang itu
     * disimpan `8.1234567890E+11`. Nilainya lolos ke database sebagai teks yang
     * kelihatan wajar di kolom sempit, tapi tidak ada nomor yang bisa dihubungi
     * di baliknya. Kosong + peringatan bisa ditindaklanjuti; `8.12E+11` tidak.
     *
     * @param  list<string>  $peringatan
     */
    private static function sahkanTelepon(?string $nilai, array &$peringatan): ?string
    {
        if ($nilai !== null && preg_match('/^\d(?:[.,]\d+)?[eE][+-]?\d+$/', $nilai) === 1) {
            $peringatan[] = "telepon terbaca sebagai angka Excel (`{$nilai}`), dikosongkan — "
                .'format ulang kolomnya jadi Teks di sumbernya lalu impor lagi';

            return null;
        }

        return $nilai;
    }

    /**
     * @param  list<string>  $peringatan
     */
    private static function sahkanEmail(?string $nilai, array &$peringatan): ?string
    {
        if ($nilai !== null && filter_var($nilai, FILTER_VALIDATE_EMAIL) === false) {
            $peringatan[] = "email `{$nilai}` bukan alamat email yang sah, dikosongkan";

            return null;
        }

        return $nilai;
    }

    private static function bersihkan(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        // `\xC2\xA0` = spasi tanpa putus. Excel menaburkannya waktu menyalin
        // dari web, dan dia LOLOS `trim()` biasa — jadi nama yang kelihatan
        // sama persis punya `nama_normal` berbeda, dan penjaga kembarnya diam.
        $nilai = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $nilai);

        return trim($nilai) === '' ? null : trim($nilai);
    }

    private static function buangBom(string $isi): string
    {
        return str_starts_with($isi, "\xEF\xBB\xBF") ? substr($isi, 3) : $isi;
    }

    private static function keUtf8(string $isi): string
    {
        // Windows-1252 dipilih, bukan ISO-8859-1: Excel di Windows memakai yang
        // pertama, dan bedanya justru di rentang yang sering muncul di alamat
        // Indonesia hasil salin-tempel (tanda kutip miring, en dash).
        return mb_check_encoding($isi, 'UTF-8') ? $isi : mb_convert_encoding($isi, 'UTF-8', 'Windows-1252');
    }

    /**
     * Pemisah ditebak dari BARIS HEADER saja, bukan seluruh berkas.
     *
     * Alamat penuh koma (`Jl. Raya No. 5, Kawasan Industri, Bekasi`) bikin `,`
     * menang telak di seluruh berkas walaupun pemisah sebenarnya `;`. Header
     * tidak punya alamat, jadi hitungannya jujur di sana.
     */
    private static function tebakPemisah(string $isi): string
    {
        $header = strtok($isi, "\r\n");
        $header = $header === false ? '' : $header;

        $jumlah = [];

        foreach ([',', ';', "\t", '|'] as $calon) {
            $jumlah[$calon] = substr_count($header, $calon);
        }

        arsort($jumlah);
        $menang = array_key_first($jumlah);

        // Satu kolom saja (tidak ada pemisah sama sekali) tetap sah: berkas
        // berisi nama PT doang itu bentuk yang wajar untuk impor pertama.
        return $jumlah[$menang] > 0 ? (string) $menang : ',';
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private static function petakanHeader(array $header, string $path): array
    {
        $kolom = [];

        foreach ($header as $indeks => $judul) {
            $normal = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $judul)));

            foreach (self::SINONIM as $tujuan => $sinonim) {
                // Kolom pertama yang cocok yang menang. Berkas dengan dua kolom
                // alamat (mis. `alamat` dan `alamat lengkap`) tidak bikin yang
                // belakangan diam-diam menimpa yang depan.
                if (! isset($kolom[$tujuan]) && in_array($normal, $sinonim, true)) {
                    $kolom[$tujuan] = $indeks;
                }
            }
        }

        if (! isset($kolom['nama'])) {
            throw new RuntimeException(
                "Berkas {$path} tidak punya kolom nama. Header yang dikenali: "
                .implode(', ', self::SINONIM['nama']).'. '
                .'Yang terbaca: '.(implode(' | ', array_map(static fn ($h) => (string) $h, $header)) ?: '(kosong)'),
            );
        }

        return $kolom;
    }
}
