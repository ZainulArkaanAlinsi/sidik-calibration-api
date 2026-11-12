<?php

namespace App\Services\GarisWaktu;

use Carbon\CarbonInterface;

/**
 * Label tampilan garis waktu dalam Bahasa Indonesia. Murni teks: tidak ada
 * logika kalender di sini.
 *
 * Tabel nama ditulis sendiri (bukan locale Carbon) supaya hasilnya sama di
 * semua mesin, tidak bergantung ekstensi `intl` atau berkas locale.
 */
final class LabelWaktu
{
    private const BULAN_PENUH = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const BULAN_PENDEK = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /** Kunci = hari ISO (1 = Senin ... 7 = Minggu). */
    private const HARI = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    /** `2026` */
    public static function tahun(CarbonInterface $tanggal): string
    {
        return (string) $tanggal->year;
    }

    /** `November 2026` */
    public static function bulan(CarbonInterface $tanggal): string
    {
        return self::BULAN_PENUH[$tanggal->month].' '.$tanggal->year;
    }

    /**
     * `Pekan 46 (10–16 Nov 2026)`; satu hari saja: `Pekan 44 (1 Nov 2026)`.
     * Rentang yang diberikan selalu di dalam SATU bulan (pekan dipotong per
     * bulan, lihat `LingkupGarisWaktu`).
     */
    public static function pekan(int $nomor, CarbonInterface $mulai, CarbonInterface $selesai): string
    {
        $akhir = $selesai->day.' '.self::BULAN_PENDEK[$selesai->month].' '.$selesai->year;

        if ($mulai->isSameDay($selesai)) {
            return "Pekan {$nomor} ({$akhir})";
        }

        return "Pekan {$nomor} ({$mulai->day}–{$akhir})";
    }

    /** `Kamis, 12 Nov 2026` */
    public static function hari(CarbonInterface $tanggal): string
    {
        return self::HARI[$tanggal->dayOfWeekIso].', '.$tanggal->day.' '
            .self::BULAN_PENDEK[$tanggal->month].' '.$tanggal->year;
    }
}
