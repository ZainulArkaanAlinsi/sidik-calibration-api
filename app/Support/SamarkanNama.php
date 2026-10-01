<?php

namespace App\Support;

/**
 * Samarkan sebagian nama pemilik alat di halaman verifikasi QR publik.
 *
 * `PT Contoh Jaya Abadi` → `PT Con•• Jaya Abadi` (bentuk artboard Web_Verifikasi).
 *
 * Yang disamarkan KATA KHAS PERTAMA — kata yang membedakan perusahaan ini dari
 * perusahaan lain. Bentuk badan usaha (PT, CV, Tbk, …) dan kata sependek dua
 * huruf dilewati, karena menyamarkannya tidak menyembunyikan apa pun. Separuh
 * awal kata itu tetap tampil supaya orang yang MEMEGANG kertasnya masih bisa
 * mencocokkan; yang tidak memegangnya tidak bisa membaca nama utuh dari
 * tangkapan layar.
 *
 * Ini penghalang tangkapan layar, BUKAN kerahasiaan: lembar lengkap (dan
 * PDF-nya) di halaman yang sama tetap memuat nama utuh untuk siapa pun yang
 * memegang token QR-nya (spesifikasi poin 13).
 */
final class SamarkanNama
{
    private const BADAN_USAHA = [
        'pt', 'pt.', 'cv', 'cv.', 'ud', 'ud.', 'pd', 'pd.', 'tbk', 'tbk.', '(persero)', 'persero',
        'perum', 'koperasi', 'yayasan', 'firma', 'fa', 'fa.',
    ];

    private const TITIK = '••';

    public static function untuk(?string $nama): ?string
    {
        if ($nama === null || trim($nama) === '') {
            return $nama;
        }

        $kata = preg_split('/(\s+)/u', trim($nama), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($kata as $i => $potong) {
            if (trim($potong) === '' || in_array(mb_strtolower(trim($potong, ',')), self::BADAN_USAHA, true)) {
                continue;
            }

            $panjang = mb_strlen($potong);

            if ($panjang < 3) {
                continue;
            }

            $kata[$i] = mb_substr($potong, 0, (int) ceil($panjang / 2)).self::TITIK;

            return implode('', $kata);
        }

        // Semua katanya pendek / bentuk badan usaha: samarkan kata terakhir.
        $akhir = array_key_last($kata);
        $kata[$akhir] = mb_substr($kata[$akhir], 0, 1).self::TITIK;

        return implode('', $kata);
    }
}
