<?php

namespace App\Support;

/**
 * "Dua nama perusahaan ini orang yang sama atau bukan?" — satu aturan, satu tempat.
 *
 * ## Kenapa diekstrak, bukan disalin
 *
 * Aturannya lahir di `ImporPelanggan\Pemilah` dan sekarang dibutuhkan jalur
 * kedua: saran "pelanggan mirip" waktu admin menyetujui pengajuan akun
 * (REQ-AUTH-04). Menyalinnya berarti menyalin TIGA penjagaan yang masing-masing
 * lahir dari kesalahan nyata, dan salinan yang ketinggalan satu di antaranya
 * tidak memunculkan error — dia cuma memberi saran yang salah:
 *
 * 1. **Badan usaha tidak pernah digabung.** `pt maju` vs `cv maju` jaraknya 2,
 *    jadi tanpa penjaga ini keduanya muncul berpasangan di layar tinjauan —
 *    dan pasangan yang nyaris sama persis itu yang paling gampang
 *    di-"gabung saja". Keduanya badan hukum berbeda dengan NPWP berbeda;
 *    sertifikatnya tidak boleh tertukar.
 * 2. **Batas panjang** — lihat catatan di bawah, alasannya sudah BUKAN yang
 *    tertulis waktu aturan ini lahir.
 * 3. **Saringan panjang murah di depan**, supaya `levenshtein()` tidak
 *    dijalankan pada pasangan yang jelas-jelas tidak mungkin lolos.
 *
 * ## Soal `BATAS_LEVENSHTEIN`: alasan lamanya sudah tidak berlaku
 *
 * Komentar aslinya menulis `levenshtein()` "menyerah di atas 255 byte dan
 * memulangkan -1", lalu `-1 <= 2` bikin tiap nama panjang mirip dengan tiap
 * nama panjang lain. Itu BENAR sampai PHP 7.4. **Sejak PHP 8.0 batas itu
 * dicabut**, dan repo ini jalan di 8.4 — diperiksa langsung:
 * `levenshtein(str_repeat('a',300), str_repeat('b',300))` memulangkan `300`,
 * bukan `-1`.
 *
 * Penjaganya sengaja DIPERTAHANKAN apa adanya, dan itu keputusan sadar:
 * memindahkan aturan ini ke kelas baru adalah refactor, dan refactor tidak
 * boleh mengubah perilaku (CLAUDE.md §Alur Kerja poin 10). Mencabutnya di sini
 * berarti menyelundupkan perubahan perilaku ke dalam pemindahan berkas.
 *
 * Yang hilang karena dipertahankan: dua nama di atas 255 BYTE yang sebenarnya
 * mirip dijawab "tidak mirip". Praktis tidak terjangkau — `customers.nama` itu
 * `varchar(255)`, jadi butuh nama 255 karakter yang sebagian besar multibyte,
 * sementara nama perusahaan Indonesia praktis ASCII. Kalau suatu hari mau
 * dicabut, itu commit sendiri dengan alasannya sendiri.
 *
 * Perilakunya SAMA PERSIS dengan sebelum diekstrak; `Pemilah` sekarang
 * mendelegasikan ke sini. Yang membuktikan itu `ImporPelangganTest`, dijalankan
 * sebelum dan sesudah pemindahan.
 */
final class KemiripanNama
{
    /** Jarak maksimum yang masih dianggap "mirip, tolong ditinjau orang". */
    public const JARAK_TINJAU = 2;

    /**
     * Batas panjang yang dipertahankan dari aturan lama — BUKAN batas
     * `levenshtein()` lagi (dicabut di PHP 8.0). Lihat docblock kelas.
     */
    private const BATAS_LEVENSHTEIN = 255;

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
     * Dua nama yang SUDAH dinormalkan (`Customer::normalkanNama()`).
     *
     * Menerima bentuk mentah di sini itu jebakan halus: pemanggil yang lupa
     * menormalkan dapat jawaban "tidak mirip" buat dua nama yang sebenarnya
     * sama, dan tidak ada yang error.
     */
    public static function mirip(string $aNormal, string $bNormal): bool
    {
        if ($aNormal === $bNormal) {
            return true;
        }

        if (self::badanUsaha($aNormal) !== self::badanUsaha($bNormal)) {
            return false;
        }

        if (strlen($aNormal) > self::BATAS_LEVENSHTEIN || strlen($bNormal) > self::BATAS_LEVENSHTEIN) {
            return false;
        }

        if (abs(strlen($aNormal) - strlen($bNormal)) > self::JARAK_TINJAU) {
            return false;
        }

        return levenshtein($aNormal, $bNormal) <= self::JARAK_TINJAU;
    }

    /**
     * Kata pertama kalau dia bentuk badan usaha, kosong kalau bukan.
     *
     * Nama tanpa bentuk badan usaha (`maju jaya`) sama-sama mengembalikan
     * kosong, jadi dua nama telanjang tetap bisa dibandingkan satu sama lain —
     * yang dibatalkan cuma pasangan yang bentuknya BERBEDA.
     */
    private static function badanUsaha(string $normal): string
    {
        $kata = explode(' ', $normal)[0] ?? '';

        return in_array($kata, self::BADAN_USAHA, true) ? $kata : '';
    }
}
