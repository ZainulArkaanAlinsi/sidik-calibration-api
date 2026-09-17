<?php

namespace App\Support;

/**
 * Normalisasi nomor HP Indonesia ke bentuk `+62…` (02-SRS §11).
 *
 * Satu nomor yang sama ditulis orang dengan lima cara: `0812-3456-7890`,
 * `0812 3456 7890`, `+62 812 3456 7890`, `62812345678 90`, `812…`. Menyimpannya
 * apa adanya bikin dua hal patah diam-diam: pencarian "pelanggan dengan nomor
 * ini" meleset, dan penyedia WhatsApp/SMS menolak nomor yang tidak E.164.
 *
 * Yang SENGAJA tidak dilakukan: menebak nomor luar negeri. Aplikasi ini dipakai
 * PIC pabrik di Indonesia; nomor yang tidak muat pola Indonesia ditolak dengan
 * pesan, bukan dipaksa jadi `+62` yang salah.
 */
class NomorTelepon
{
    /**
     * Bentuk `+62…`, atau `null` kalau tidak bisa dibaca sebagai nomor Indonesia.
     */
    public static function normal(?string $nomor): ?string
    {
        if ($nomor === null) {
            return null;
        }

        $angka = preg_replace('/\D+/', '', $nomor) ?? '';

        if ($angka === '') {
            return null;
        }

        // `0…` (tulisan lokal) dan `8…` (orang yang membuang nol depannya)
        // sama-sama dipetakan ke kode negara. `62…` sudah benar.
        $angka = match (true) {
            str_starts_with($angka, '62') => $angka,
            str_starts_with($angka, '0') => '62'.substr($angka, 1),
            str_starts_with($angka, '8') => '62'.$angka,
            default => null,
        };

        if ($angka === null) {
            return null;
        }

        $tanpaKode = substr($angka, 2);

        // Nomor seluler Indonesia: diawali 8, total 9–13 digit sesudah kode
        // negara. Batas atasnya longgar dengan sengaja — operator baru sempat
        // memakai blok yang lebih panjang, dan menolak nomor sah itu lebih
        // mahal daripada menerima satu-dua nomor ngawur yang tetap gagal
        // waktu dikirimi pesan.
        if (! preg_match('/^8\d{8,12}$/', $tanpaKode)) {
            return null;
        }

        return '+'.$angka;
    }

    public static function sah(?string $nomor): bool
    {
        return self::normal($nomor) !== null;
    }
}
