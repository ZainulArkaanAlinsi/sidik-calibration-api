<?php

namespace App\Services\GarisWaktu;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rentang tanggal kalender, kedua ujungnya TERMASUK.
 *
 * Tanggal polos, bukan titik waktu: kolom `diterbitkan_pada` bertipe `date`
 * dan sudah memegang tanggal kalender Asia/Jakarta (`APP_TIMEZONE`), jadi tidak
 * ada konversi zona di SQL. Zona hanya dipakai di sini untuk membangun objek
 * tanggalnya, agar `->day` dan `->isoWeek` tidak bergeser.
 */
final class RentangTanggal
{
    public const ZONA = 'Asia/Jakarta';

    public function __construct(
        public readonly CarbonImmutable $mulai,
        public readonly CarbonImmutable $selesai,
    ) {}

    public static function tanggal(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd, self::ZONA);
    }

    public static function dari(int $tahun, int $bulan = 1, int $hari = 1): CarbonImmutable
    {
        return CarbonImmutable::create($tahun, $bulan, $hari, 0, 0, 0, self::ZONA);
    }

    public function mengandung(CarbonImmutable $tanggal): bool
    {
        return $tanggal->betweenIncluded($this->mulai, $this->selesai);
    }

    /**
     * Batasi kueri ke rentang ini. Setengah terbuka (`>= mulai`, `< selesai + 1
     * hari`) dan BUKAN `BETWEEN`: kolom `date` di MySQL tidak punya jam, tetapi
     * SQLite menyimpan nilai bertanda jam (`2026-11-16 00:00:00`) yang lebih
     * besar dari `2026-11-16` dan lolos dari `BETWEEN` ... `2026-11-16`.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $kueri
     * @return Builder<T>
     */
    public function batasi(Builder $kueri, string $kolom): Builder
    {
        return $kueri
            ->where($kolom, '>=', $this->mulai->toDateString())
            ->where($kolom, '<', $this->selesai->addDay()->toDateString());
    }
}
