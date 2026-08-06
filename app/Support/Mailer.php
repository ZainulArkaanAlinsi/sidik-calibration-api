<?php

namespace App\Support;

/**
 * Satu-satunya tempat yang mutusin "mailer ini beneran ngirim apa nggak".
 *
 * Dipakai dua pihak yang harus SEPAKAT: endpoint kirim sertifikat (yang bilang
 * ke admin "belum beneran terkirim") sama `mail:cek` (yang nolak ngaku sukses).
 * Kalau aturannya ditulis dua kali, cepat atau lambat yang satu diubah dan yang
 * satu ketinggalan — dan bedanya baru ketahuan pas sertifikat nggak nyampe.
 */
class Mailer
{
    /**
     * Mailer yang "berhasil" tanpa ngirim ke mana-mana.
     *
     * `log` nulis email ke `storage/logs/laravel.log`, `array` cuma numpuk di
     * memori. Dua-duanya sah buat ngoprek — yang nggak sah itu ngakunya kekirim.
     */
    private const TAK_MENGIRIM = ['log', 'array'];

    /**
     * Nama mailer yang nggak beneran ngirim, atau `null` kalau beneran ngirim.
     *
     * Dicek dari setelan (`mail.default`), BUKAN dari hasil kirimnya, karena
     * `Mail::send()` nggak ngelempar exception buat mailer-mailer ini. Nggak ada
     * hasil yang bisa dibedain — setelan ini satu-satunya cara tahunya.
     */
    public static function takMengirim(): ?string
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, self::TAK_MENGIRIM, true) ? $mailer : null;
    }
}
