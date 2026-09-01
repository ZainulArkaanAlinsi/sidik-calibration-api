<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Timer/Stopwatch** dari baris
 * `raw_measurements`.
 *
 * ## Kenapa ini ada — kejadian kedelapan dengan pola yang sama
 *
 * Saudaranya [GridSensorMentah], [PasanganStandarUutMentah], dan
 * [TimbanganMentah] sudah menjelaskan pola besarnya: alat yang satu titiknya
 * BUKAN satu deret pembacaan datar harus disusun ULANG tiap kali sesi
 * tersimpan dihitung lagi. Lupa melakukannya tidak menghasilkan error — yang
 * muncul `hitung_ulang_gagal` di setiap titik, tiap kali, sampai admin belajar
 * menekan "setujui tetap" tanpa membaca.
 *
 * Pola itu sudah menggigit **tujuh kali**. Timer/Stopwatch bentuk kedelapan,
 * dan kelas ini ditulis BERSAMAAN dengan profilnya supaya tidak jadi kejadian
 * kedelapan yang ditemukan belakangan.
 *
 * ## Bentuk yang disusun ulang
 *
 * Satu titik = tiga ulangan × DUA sisi yang dibaca berbarengan:
 *
 *   peran_sensor  sensor_ke  arti
 *   standar       1..n       penunjukan stopwatch STANDAR, milidetik
 *   uut           1..n       penunjukan alat pelanggan, milidetik
 *
 * Meratakannya jadi satu deret bikin standar dan UUT campur aduk, dan koreksi
 * yang lahir dari situ — selisih rata-rata keduanya — tidak berarti apa-apa.
 *
 * ## Urutan ulangan MENGIKAT
 *
 * `sensor_ke` menyimpan nomor ulangan, dan diurut ulang di sini alih-alih
 * mengandalkan urutan baris dari database: [WaktuCalculator] memasangkan
 * standar ke-i dengan UUT ke-i, dan urutan baris `raw_measurements` tidak
 * dijamin tanpa `ORDER BY`. Pasangan yang tertukar menggeser koreksi tanpa
 * menerbitkan satu pun error.
 *
 * Balik `[]` — bukan blok kosong — kalau tidak ada baris ber-`peran_sensor`
 * milik lembar ini. Alasannya sama seperti ketiga saudaranya: profil alat lain
 * tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih berbahaya
 * daripada kunci yang tidak ada.
 */
class WaktuMentah
{
    public const PERAN_STANDAR = 'waktu_standar';

    public const PERAN_UUT = 'waktu_uut';

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{waktu_standar: list<float>, waktu_uut: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            [self::PERAN_STANDAR, self::PERAN_UUT],
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        return [
            'waktu_standar' => self::deret($milikKita, self::PERAN_STANDAR),
            'waktu_uut' => self::deret($milikKita, self::PERAN_UUT),
        ];
    }

    /**
     * Ubah penunjukan stopwatch (jam/menit/detik/milidetik) jadi satu angka
     * milidetik — bentuk yang disimpan `raw_measurements`.
     *
     * Master memecah tiap pembacaan ke empat kolom karena begitulah stopwatch
     * menampilkannya, bukan karena keempatnya besaran yang berbeda.
     */
    public static function keMilidetik(int $jam, int $menit, float $detik, float $milidetik): float
    {
        return ($jam * 3_600_000) + ($menit * 60_000) + ($detik * 1000) + $milidetik;
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris
     * @return list<float>
     */
    private static function deret(Collection $baris, string $peran): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
            // Diurut EKSPLISIT — lihat docblock kelas.
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
            ->map(static fn ($b): float => (float) $b->pembacaan)
            ->values()
            ->all();
    }
}
