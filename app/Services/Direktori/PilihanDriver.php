<?php

namespace App\Services\Direktori;

/**
 * Menerjemahkan setelan `DIREKTORI_PERUSAHAAN_DRIVER` jadi driver yang
 * BENERAN dipakai.
 *
 * ## Kenapa satu tempat, bukan `match` yang disalin dua kali
 *
 * Aturan di sini dibaca DUA pihak: `AppServiceProvider` yang membangun
 * penyedianya, dan `GET /api/health` yang melaporkannya. Kalau keduanya punya
 * salinan sendiri-sendiri, cepat atau lambat salah satunya ketinggalan — dan
 * yang terbit bukan sekadar laporan yang salah, tapi laporan yang DIPERCAYA.
 * Health yang bilang "osm" sementara container membangun jalur berbayar lebih
 * buruk daripada health yang nggak melaporkan apa-apa.
 *
 * ## Kenapa yang dilaporkan yang EFEKTIF, bukan isi `.env` apa adanya
 *
 * Keduanya beda persis di keadaan yang paling perlu ketahuan: nilai yang salah
 * ketik. `DIREKTORI_PERUSAHAAN_DRIVER=osmm` bukan kerusakan — dia jatuh ke
 * [BAWAAN] supaya pendaftaran pelanggan di lapangan nggak mati. Tapi kalau
 * health memantulkan `osmm` apa adanya, yang membacanya nggak tahu apakah
 * lab-nya sedang ditagih atau nggak. Yang dia tanyakan bukan "apa yang saya
 * ketik", tapi "yang jalan yang mana".
 */
final class PilihanDriver
{
    /**
     * Dipakai kalau setelannya kosong ATAU nggak dikenali.
     *
     * OSM karena dia satu-satunya yang memenuhi dua syaratnya sekaligus: hidup
     * tanpa API key, dan nol tagihan. Bawaan yang menyentuh Google berarti
     * pemasangan yang lupa menyetel apa-apa ditagih diam-diam.
     */
    public const BAWAAN = 'osm';

    /** Semua yang dikenali. Selain ini jatuh ke [BAWAAN]. */
    public const DIKENALI = ['osm', 'google', 'auto'];

    /**
     * Driver yang benar-benar akan dibangun untuk setelan ini.
     *
     * @param  string|null  $disetel  isi `services.direktori_perusahaan.driver`
     */
    public static function efektif(?string $disetel): string
    {
        return in_array($disetel, self::DIKENALI, strict: true)
            ? $disetel
            : self::BAWAAN;
    }

    /** Driver yang benar-benar dipakai proses ini sekarang. */
    public static function sekarang(): string
    {
        $disetel = config('services.direktori_perusahaan.driver');

        return self::efektif(is_string($disetel) ? $disetel : null);
    }

    /** Jalur ini menyentuh penyedia yang MENAGIH per request? */
    public static function bisaDitagih(string $efektif): bool
    {
        return $efektif !== 'osm';
    }
}
