<?php

namespace App\Support\ImporPelanggan;

use App\Models\Customer;

/**
 * Pilah baris impor jadi tiga keranjang SEBELUM satu baris pun ditulis.
 *
 * ## Kenapa memilah dulu, bukan insert-lalu-tangkap-error
 *
 * `customers` punya unique index `customers_organization_id_nama_unique` di
 * kolom `nama` MENTAH (migrasi 2026_07_30_090000). Jadi nama yang persis sama
 * memang akan ditolak database — tapi cuma yang PERSIS. `PT. Maju Jaya` di
 * berkas dan `PT Maju Jaya` di database lolos berdampingan, dan lab bangun dua
 * folder arsip untuk satu pelanggan. Yang menjaganya `nama_normal`, dan itu
 * penjagaan aplikasi, bukan penjagaan database.
 *
 * ## Bentuk badan usaha tidak pernah digabung
 *
 * `PT Maju` dan `CV Maju` turun ke `pt maju` dan `cv maju` — beda, jadi aturan
 * "nama_normal sama" aman sendirinya. Yang TIDAK aman aturan jarak: jaraknya
 * cuma 2, jadi tanpa penjaga di sini keduanya masuk keranjang tinjau
 * berpasangan, dan tinjauan yang menampilkan dua nama nyaris sama itu justru
 * yang paling gampang di-"gabung saja". Keduanya badan hukum berbeda dengan
 * NPWP berbeda; sertifikatnya tidak boleh tertukar.
 */
final class Pemilah
{
    /** Jarak maksimum yang masih dianggap "mirip, tolong ditinjau orang". */
    public const JARAK_TINJAU = 2;

    /**
     * Bentuk badan usaha yang muncul sebagai kata pertama nama PT Indonesia.
     *
     * Dipakai HANYA untuk membatalkan kemiripan, tidak pernah untuk membuat
     * kemiripan. Daftar yang kurang lengkap berarti ada pasangan yang lolos ke
     * tinjauan manusia — aman. Daftar yang kelewat rajin berarti ada pasangan
     * kembar asli yang lolos jadi baris baru — tidak aman.
     *
     * @var list<string>
     */
    private const BADAN_USAHA = [
        'pt', 'cv', 'ud', 'pd', 'fa', 'firma', 'nv', 'perum',
        'koperasi', 'kop', 'yayasan', 'bumd', 'bumn',
    ];

    /**
     * @param  list<BarisMasukan>  $baris
     * @param  list<array{id: int, nama: string, nama_normal: string, terhapus?: bool}>  $sudahAda
     * @return array{
     *     baru: list<BarisMasukan>,
     *     kembar_pasti: list<array{baris: BarisMasukan, lawan: string, sebab: string}>,
     *     perlu_tinjau: list<array{baris: BarisMasukan, lawan: string, sebab: string}>,
     * }
     */
    public static function pilah(array $baris, array $sudahAda): array
    {
        $baru = [];
        $kembarPasti = [];
        $perluTinjau = [];

        // Baris yang sudah diterima dari berkas ini ikut jadi lawan. Tanpa ini
        // satu berkas yang memuat PT yang sama dua kali menabrak unique index di
        // tengah jalan — setengah baris masuk, setengah tidak, dan yang mana
        // tergantung urutan berkas.
        $diterima = [];

        foreach ($baris as $satu) {
            $normal = Customer::normalkanNama($satu->nama);

            $lawan = self::cariLawan($satu->nama, $normal, $sudahAda, $diterima);

            if ($lawan === null) {
                $baru[] = $satu;
                $diterima[] = ['id' => 0, 'nama' => $satu->nama, 'nama_normal' => $normal];

                continue;
            }

            if ($lawan['kelas'] === 'pasti') {
                $kembarPasti[] = ['baris' => $satu, 'lawan' => $lawan['nama'], 'sebab' => $lawan['sebab']];

                continue;
            }

            $perluTinjau[] = ['baris' => $satu, 'lawan' => $lawan['nama'], 'sebab' => $lawan['sebab']];
        }

        return ['baru' => $baru, 'kembar_pasti' => $kembarPasti, 'perlu_tinjau' => $perluTinjau];
    }

    /**
     * @param  list<array{id: int, nama: string, nama_normal: string, terhapus?: bool}>  $sudahAda
     * @param  list<array{id: int, nama: string, nama_normal: string}>  $diterima
     * @return array{kelas: 'pasti'|'tinjau', nama: string, sebab: string}|null
     */
    private static function cariLawan(string $nama, string $normal, array $sudahAda, array $diterima): ?array
    {
        foreach ([['db', $sudahAda], ['berkas', $diterima]] as [$asal, $daftar]) {
            foreach ($daftar as $ada) {
                if ($ada['nama'] === $nama) {
                    return [
                        'kelas' => 'pasti',
                        'nama' => $ada['nama'],
                        'sebab' => 'nama persis sama dengan '.self::sebutan($asal, $ada),
                    ];
                }

                if ($ada['nama_normal'] === $normal) {
                    return [
                        'kelas' => 'pasti',
                        'nama' => $ada['nama'],
                        'sebab' => 'beda tanda baca/huruf besar saja dari '.self::sebutan($asal, $ada),
                    ];
                }
            }
        }

        foreach ([['db', $sudahAda], ['berkas', $diterima]] as [$asal, $daftar]) {
            foreach ($daftar as $ada) {
                if (! self::mirip($normal, $ada['nama_normal'])) {
                    continue;
                }

                return [
                    'kelas' => 'tinjau',
                    'nama' => $ada['nama'],
                    'sebab' => sprintf(
                        'mirip (jarak %d) dengan %s',
                        levenshtein($normal, $ada['nama_normal']),
                        self::sebutan($asal, $ada),
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * Sebutan lawan yang menjelaskan DI MANA admin bisa melihatnya.
     *
     * Pelanggan yang sudah di-soft-delete dipisahkan sendiri: dia tetap
     * memegang unique index, jadi barisnya memang tidak bisa masuk — tapi admin
     * yang membuka panel TIDAK akan menemukannya, dan laporan yang cuma bilang
     * "sudah ada" bikin dia mengira laporannya yang salah.
     *
     * @param  array{id: int, nama: string, nama_normal: string, terhapus?: bool}  $ada
     */
    private static function sebutan(string $asal, array $ada): string
    {
        if ($asal !== 'db') {
            return 'baris lain di berkas ini';
        }

        return ($ada['terhapus'] ?? false)
            ? 'pelanggan yang sudah DIHAPUS (pulihkan dulu kalau memang dia)'
            : 'pelanggan yang sudah ada';
    }

    private static function mirip(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Badan usaha berbeda = badan hukum berbeda. Berhenti di sini SEBELUM
        // jarak dihitung: `pt maju` vs `cv maju` jaraknya 2 dan akan lolos.
        if (self::badanUsaha($a) !== self::badanUsaha($b)) {
            return false;
        }

        // `levenshtein()` PHP bekerja per BYTE dan menyerah di atas 255 byte.
        // Tanpa penjaga ini nama panjang balik -1, dan -1 <= 2 itu BENAR —
        // tiap nama panjang jadi mirip dengan tiap nama panjang lain.
        if (strlen($a) > 255 || strlen($b) > 255) {
            return false;
        }

        // Saringan murah dulu: beda panjang di atas ambang tidak mungkin punya
        // jarak di bawah ambang.
        if (abs(strlen($a) - strlen($b)) > self::JARAK_TINJAU) {
            return false;
        }

        return levenshtein($a, $b) <= self::JARAK_TINJAU;
    }

    /**
     * Kata pertama kalau dia bentuk badan usaha, kosong kalau bukan.
     *
     * Nama tanpa bentuk badan usaha (`Maju Jaya`) sama-sama mengembalikan
     * kosong, jadi dua nama telanjang tetap bisa dibandingkan satu sama lain —
     * yang dibatalkan cuma pasangan yang bentuknya BERBEDA.
     */
    private static function badanUsaha(string $normal): string
    {
        $kata = explode(' ', $normal)[0] ?? '';

        return in_array($kata, self::BADAN_USAHA, true) ? $kata : '';
    }
}
