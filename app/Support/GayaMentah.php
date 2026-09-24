<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Bentuk ulang baris `raw_measurements` keluarga GAYA jadi masukan kalkulator.
 *
 * Dipakai DUA jalur yang menghitung hal yang sama: `CalibrationValidator`
 * (sebelum sertifikat terbit) dan `HitungUlangSesi` (perintah artisan). Alat
 * baru yang cuma disambung ke salah satunya lolos tanpa error — dan itu sudah
 * menggigit tujuh kali di repo ini.
 *
 * ## Kenapa tidak bikin tabel baru
 *
 * Panduan master mengusulkan dua tabel baru (`gaya_titik_beban`,
 * `gaya_pembacaan`). Repo ini sengaja menempuh jalan lain, dan itu aturan
 * tertulis: kolom/tabel baru pilihan TERAKHIR (AGENTS.md §Alur Kerja poin 4).
 * Empat alat terakhir mendarat dengan **nol** kolom baru.
 *
 * Sumbu yang sudah ada cukup memuat bentuk gaya:
 *
 *   - `peran_sensor` menampung POSISI (`gaya_pos_0` … `gaya_pos_270`), atau
 *     arah UP/DOWN untuk Proving Ring;
 *   - `sensor_ke` menampung replikat 1..3;
 *   - `titik_ukur` menampung nominal bebannya.
 *
 * Yang benar-benar tidak punya titik — preload dan misalignment — masuk
 * `spesifikasi_alat`, bukan dipaksa jadi `titik_ke = 0`. Blok tanpa titik yang
 * diberi titik hantu selalu gagal di jalur hitung ulang, dan gagalnya jauh dari
 * sebabnya.
 */
class GayaMentah
{
    /** Blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'gaya';

    /** Empat posisi UTM & Load Cell — 4 posisi x 3 replikat = 12 bacaan/titik. */
    public const PERAN_POSISI = [
        'gaya_pos_0',
        'gaya_pos_90',
        'gaya_pos_180',
        'gaya_pos_270',
    ];

    /** Proving Ring tidak diputar; dia diuji naik-turun karena histeresis. */
    public const PERAN_UP = 'gaya_up';

    public const PERAN_DOWN = 'gaya_down';

    public const PERAN_SEMUA = [
        'gaya_pos_0',
        'gaya_pos_90',
        'gaya_pos_180',
        'gaya_pos_270',
        self::PERAN_UP,
        self::PERAN_DOWN,
    ];

    /**
     * Peran di sini BUKAN besaran alat.
     *
     * `GridSensorMentah::dari()` memulangkan `[]` cuma kalau tidak ada
     * `peran_sensor` sama sekali — dan kosakata gaya tetap mengisinya. Tanpa
     * daftar ini, sesi gaya nyasar ke jalur grid sensor dan hasilnya bukan
     * error, melainkan angka yang dihitung dengan aturan alat lain.
     */
    public const PERAN_BUKAN_BESARAN_ALAT = self::PERAN_SEMUA;

    public const ARAH_PUSH = 'Push';

    public const ARAH_PULL = 'Pull';

    /** Replikat per posisi, dikunci master. Divisor akar-12 di budget mengandalkannya. */
    public const REPLIKAT = 3;

    /**
     * Susun ulang baris mentah jadi `[titik_ke => konteks]`.
     *
     * Bacaan dikumpulkan per titik TANPA memandang posisinya, karena yang
     * dipakai hitungan memang keduabelasnya sekaligus (rata-rata, STDEV,
     * MAX-MIN). Posisinya tetap disimpan terpisah supaya Master Data bisa
     * melihat sebaran antar posisi — itu yang memberi tahu ada misalignment
     * mekanis, dan itu satu-satunya alasan pengujian empat posisi ada.
     *
     * Yang dioper pemanggil baris SATU titik, bukan seluruh sesi — begitu
     * `CalibrationValidator` dan `HitungUlangSesi` memakainya, dua-duanya di
     * dalam `groupBy('titik_ke')`. Karena itu keluarannya datar (`bacaan`,
     * `per_posisi`), bukan dikelompokkan lagi per titik: yang mengelompokkan
     * dua kali menghasilkan konteks tanpa kunci `bacaan`, dan tiap titik
     * dilaporkan "tidak bisa dihitung ulang" padahal datanya lengkap.
     *
     * @param  Collection<int, RawMeasurement>  $baris
     * @return array<string, mixed>
     */
    public static function dari(Collection $baris): array
    {
        $gaya = $baris->filter(
            static fn ($b): bool => in_array((string) $b->peran_sensor, self::PERAN_SEMUA, true)
        );

        if ($gaya->isEmpty()) {
            return [];
        }

        $perPosisi = [];
        $semua = [];

        foreach ($gaya as $b) {
            $peran = (string) $b->peran_sensor;
            $nilai = (float) $b->pembacaan;

            $perPosisi[$peran][(int) $b->sensor_ke] = $nilai;
            $semua[] = $nilai;
        }

        // Diurutkan supaya keluarannya stabil: urutan baris dari database tidak
        // dijamin, dan STDEV yang dihitung dari urutan berbeda bisa meleset di
        // bit terakhir. Kecil, tapi hasil yang berubah tiap dijalankan bikin
        // perbandingan ke master tidak mungkin.
        foreach ($perPosisi as $peran => $nilai) {
            ksort($nilai);
            $perPosisi[$peran] = array_values($nilai);
        }

        return [
            'bacaan' => $semua,
            'per_posisi' => $perPosisi,
            'jumlah_bacaan' => count($semua),
        ];
    }

    /**
     * Blok tingkat-sesi: identitas standar, preload, dan misalignment.
     *
     * Memulangkan `null` kalau bloknya belum ada — dan pemanggil WAJIB berhenti
     * di situ, bukan melanjutkan dengan nilai bawaan. Satuan yang tidak
     * ditentukan, standar yang tidak dipilih, dan misalignment yang kosong
     * masing-masing menghasilkan angka yang kelihatan wajar tapi tidak pernah
     * bisa ditelusuri.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array<string, mixed>|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = $spesifikasiAlat[self::KUNCI_SESI] ?? null;

        if (! is_array($blok) || $blok === []) {
            return null;
        }

        $angkaDeret = static fn (mixed $isi): array => array_values(array_map(
            'floatval',
            array_filter(
                is_array($isi) ? $isi : [],
                static fn (mixed $x): bool => $x !== null && $x !== '',
            ),
        ));

        return [
            'satuan' => isset($blok['satuan']) ? (string) $blok['satuan'] : null,
            'tipe_beban' => isset($blok['tipe_beban']) ? (string) $blok['tipe_beban'] : null,
            'standar' => isset($blok['standar']) ? (string) $blok['standar'] : null,
            'suhu_sertifikat_standar' => isset($blok['suhu_sertifikat_standar'])
                ? (float) $blok['suhu_sertifikat_standar'] : null,
            'resolusi_uut' => isset($blok['resolusi_uut']) ? (float) $blok['resolusi_uut'] : null,
            'kapasitas' => isset($blok['kapasitas']) ? (float) $blok['kapasitas'] : null,
            'resolusi_standar' => isset($blok['resolusi_standar'])
                ? (float) $blok['resolusi_standar'] : null,
            'kapasitas_standar' => isset($blok['kapasitas_standar'])
                ? (float) $blok['kapasitas_standar'] : null,
            'preload_zero' => $angkaDeret($blok['preload_zero'] ?? null),
            'preload_max' => $angkaDeret($blok['preload_max'] ?? null),
            'misalignment' => $angkaDeret($blok['misalignment'] ?? null),
        ];
    }

    /**
     * Zero error terbesar dari preload — masuk budget sebagai komponen sendiri.
     *
     * Master memakai nilai MUTLAK terbesar, bukan rata-rata: yang ditanyakan
     * "seberapa jauh alat ini bisa meleset dari nol", dan rata-rata menutupi
     * pergeseran yang berganti tanda.
     *
     * @param  array<int, float>  $preloadZero
     */
    public static function zeroErrorMaks(array $preloadZero): float
    {
        if ($preloadZero === []) {
            return 0.0;
        }

        return max(array_map('abs', $preloadZero));
    }
}
