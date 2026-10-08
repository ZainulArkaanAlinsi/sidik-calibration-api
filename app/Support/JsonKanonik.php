<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Bentuk kanonik dokumen JSON: satu isi, satu deretan byte, satu sha256.
 *
 * Dipakai `paket_acuan_versi.isi` (Studio Data Acuan, 03-SDD §2.2 & §2.6).
 * Versi yang sudah diajukan dikunci lewat `isi_sha256`; kalau dua isi yang
 * sama bisa menghasilkan byte berbeda (urutan kunci, spasi, `1e-05` lawan
 * `0.00001`), hash itu berhenti membuktikan apa pun — isi yang tidak berubah
 * kelihatan berubah, dan simulasi/penahan terbit kehilangan pegangannya.
 *
 * ## Aturan
 *
 *  - Kunci objek diurutkan rekursif secara BYTE (`strcmp`), bukan locale.
 *  - Urutan list dipertahankan — urutan baris tabel itu data.
 *  - Tanpa spasi.
 *  - string/bool/null lewat `json_encode` dengan
 *    `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`.
 *  - int → literal bulat (`200`).
 *  - float → desimal round-trip TERPENDEK, notasi biasa tanpa eksponen,
 *    minimal satu digit pecahan, tanpa nol buntut lain (`200.0`, `0.00001`,
 *    `2.50014`). `-0.0` → `0.0`. INF/NAN ditolak.
 *
 * `200` dan `200.0` SENGAJA dibedakan: `json_decode` memulangkan int untuk
 * yang pertama dan float untuk yang kedua, dan tipe itu ikut sampai ke
 * calculator.
 *
 * ## Kenapa angka float dikerjakan sebagai STRING
 *
 * Representasi terpendek diambil dari `var_export` dengan
 * `serialize_precision = -1` (algoritme round-trip terpendek PHP), lalu
 * eksponennya digeser secara string. Menggeser dengan aritmetika float
 * (`$m * 10 ** $e`) membuka pembulatan baru di tengah jalan, dan angka yang
 * ditulis bisa berhenti membaca balik ke bit yang sama — persis kelas selisih
 * yang tidak kelihatan sampai sertifikatnya diadu ke master.
 *
 * Invarian (dijaga `Tests\Unit\JsonKanonikTest`): untuk setiap float `f`
 * selain `-0.0`, `json_decode(serialisasi(f))` identik bit dengan `f`; int
 * tetap int.
 *
 * ## Satu batas yang perlu diketahui pemanggil
 *
 * Array PHP tidak bisa membedakan objek kosong `{}` dari list kosong `[]`,
 * juga objek berkunci `"0","1",…` dari list. `serialisasi()` atas array
 * asosiatif mengikuti `array_is_list()`. Kalau sumbernya teks JSON, pakai
 * `dariTeks()` — dia mengurai ke `stdClass` sehingga objek tetap objek.
 */
final class JsonKanonik
{
    private const FLAG_STRING = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private function __construct() {}

    /**
     * @throws InvalidArgumentException nilai tidak bisa dikanonikkan (INF/NAN, objek selain stdClass, resource)
     * @throws JsonException string bukan UTF-8 sah
     */
    public static function serialisasi(mixed $data): string
    {
        return match (true) {
            $data === null => 'null',
            $data === true => 'true',
            $data === false => 'false',
            is_int($data) => (string) $data,
            is_float($data) => self::float($data),
            is_string($data) => json_encode($data, self::FLAG_STRING),
            is_array($data) => array_is_list($data) ? self::daftar($data) : self::objek($data),
            $data instanceof stdClass => self::objek(get_object_vars($data)),
            default => throw new InvalidArgumentException(
                'JsonKanonik hanya menerima null, bool, int, float, string, array, dan stdClass; '
                .'diberi '.get_debug_type($data).'.',
            ),
        };
    }

    /**
     * Urai teks JSON lalu kanonikkan.
     *
     * Diurai sebagai objek (`$assoc = false`) supaya `{}` tetap objek dan
     * kunci numerik tetap kunci objek, bukan berubah jadi list.
     *
     * @throws JsonException teks bukan JSON sah
     */
    public static function dariTeks(string $json): string
    {
        return self::serialisasi(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /** sha256 heksadesimal (64 karakter kecil) atas byte kanonik apa adanya. */
    public static function sha256(string $kanonik): string
    {
        return hash('sha256', $kanonik);
    }

    /** @param  list<mixed>  $daftar */
    private static function daftar(array $daftar): string
    {
        return '['.implode(',', array_map(self::serialisasi(...), $daftar)).']';
    }

    /** @param  array<array-key, mixed>  $objek */
    private static function objek(array $objek): string
    {
        // Kunci numerik ("200") sudah jadi int di array PHP — juga lewat
        // get_object_vars(). Dikembalikan ke string sebelum diurutkan dan
        // ditulis; PHP hanya mengubah bentuk desimal bulat kanonik, jadi
        // (string) memulihkan kunci aslinya byte per byte.
        $kunci = array_map(static fn (int|string $k): string => (string) $k, array_keys($objek));
        usort($kunci, strcmp(...));

        $bagian = [];

        foreach ($kunci as $k) {
            $bagian[] = json_encode($k, self::FLAG_STRING).':'.self::serialisasi($objek[$k]);
        }

        return '{'.implode(',', $bagian).'}';
    }

    private static function float(float $angka): string
    {
        if (is_nan($angka) || is_infinite($angka)) {
            throw new InvalidArgumentException(
                'JsonKanonik menolak INF/NAN: JSON tidak punya bentuknya, dan angka itu tidak boleh '
                .'sampai ke data acuan.',
            );
        }

        // Menangkap 0.0 dan -0.0 sekaligus; tanda nol dibuang dengan sengaja.
        if ($angka == 0.0) {
            return '0.0';
        }

        $teks = self::terpendek($angka);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $teks, $m) !== 1) {
            throw new InvalidArgumentException("Bentuk float tak dikenal dari var_export: {$teks}");
        }

        $negatif = $m[1] === '-';
        $bulat = $m[2];
        $pecahan = $m[3] ?? '';
        $eksponen = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;

        // Semua digit signifikan berderet, dengan posisi titik desimal dihitung
        // dari digit pertama. Menggeser eksponen = menggeser posisi ini saja.
        $digit = $bulat.$pecahan;
        $posisi = strlen($bulat) + $eksponen;

        $nolDepan = strspn($digit, '0');
        $digit = substr($digit, $nolDepan);
        $posisi -= $nolDepan;
        $digit = rtrim($digit, '0');

        if ($digit === '') {
            return '0.0'; // tidak tercapai (nol sudah ditangkap di atas), jaga-jaga
        }

        $panjang = strlen($digit);

        if ($posisi <= 0) {
            $hasil = '0.'.str_repeat('0', -$posisi).$digit;
        } elseif ($posisi >= $panjang) {
            $hasil = $digit.str_repeat('0', $posisi - $panjang).'.0';
        } else {
            $hasil = substr($digit, 0, $posisi).'.'.substr($digit, $posisi);
        }

        return ($negatif ? '-' : '').$hasil;
    }

    /**
     * Representasi round-trip terpendek, tidak bergantung setelan php.ini.
     *
     * `var_export` membaca `serialize_precision`; kalau server menyetelnya ke
     * 17, keluarannya berhenti terpendek (`0.10000000000000001`) dan hash isi
     * yang sama berubah antar mesin. Jadi dipatok -1 di sini dan dipulihkan.
     */
    private static function terpendek(float $angka): string
    {
        $sebelumnya = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return var_export($angka, true);
        } finally {
            if ($sebelumnya !== false) {
                ini_set('serialize_precision', $sebelumnya);
            }
        }
    }
}
