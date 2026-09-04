<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Micrometer** dari baris `raw_measurements`,
 * plus blok tingkat-sesinya dari `spesifikasi_alat`.
 *
 * ## Kenapa ini ada — kejadian kesembilan dengan pola yang sama
 *
 * Saudaranya [GridSensorMentah], [PasanganStandarUutMentah], [TimbanganMentah],
 * dan [WaktuMentah] sudah menjelaskan pola besarnya: alat yang satu titiknya
 * BUKAN satu deret pembacaan datar harus disusun ULANG tiap kali sesi tersimpan
 * dihitung lagi. Lupa melakukannya tidak menghasilkan error — yang muncul
 * `hitung_ulang_gagal` di setiap titik, tiap kali, sampai admin belajar menekan
 * "setujui tetap" tanpa membaca.
 *
 * Pola itu sudah menggigit **delapan kali**. Micrometer bentuk kesembilan, dan
 * kelas ini ditulis BERSAMAAN dengan profilnya supaya tidak jadi kejadian
 * kesembilan yang ditemukan belakangan.
 *
 * ## Bentuk yang disusun ulang
 *
 * Satu titik = satu TUMPUKAN balok ukur (sampai tiga keping di-*wringing*)
 * yang dibaca alat sampai lima kali:
 *
 *   peran_sensor       sensor_ke  arti
 *   mikro_balok        1..3       nominal tiap keping balok ukur, mm
 *   mikro_pembacaan    1..5       penunjukan mikrometer, mm
 *
 * Meratakannya jadi satu deret datar membuat nominal balok ukur dan penunjukan
 * alat campur aduk, dan koreksi yang lahir dari situ — selisih keduanya — tidak
 * berarti apa-apa. Lebih buruk lagi, nominal balok yang terbaca sebagai
 * pembacaan menggeser rata-rata tanpa satu pun error.
 *
 * ## Urutan keping MENGIKAT
 *
 * `sensor_ke` menyimpan urutan keping dalam tumpukan, dan diurut ulang di sini
 * alih-alih mengandalkan urutan baris dari database. Total nominal memang
 * penjumlahan — jadi urutannya tidak menggeser hasil — tapi
 * `ketidakpastianTumpukan()` memetakan tiap keping ke tangga
 * ketidakpastiannya sendiri, dan sertifikat mencetak tumpukannya apa adanya.
 *
 * ## NOL kolom baru
 *
 * Sumbu `peran_sensor`/`sensor_ke` yang sudah ada cukup, dan blok
 * tingkat-SESI — pra-evaluasi, suhu, kapasitas, resolusi — hidup di
 * `calibration_sessions.spesifikasi_alat`. Memberinya `titik_ke` melahirkan
 * titik hantu yang selalu gagal hitung ulang; lihat [blokSesi].
 *
 * Balik `[]` — bukan blok kosong — kalau tidak ada baris ber-`peran_sensor`
 * milik lembar ini. Alasannya sama seperti keempat saudaranya: profil alat lain
 * tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih berbahaya
 * daripada kunci yang tidak ada.
 */
class MicrometerMentah
{
    public const PERAN_BALOK = 'mikro_balok';

    public const PERAN_PEMBACAAN = 'mikro_pembacaan';

    /**
     * Satuan yang tersimpan di `raw_measurements.satuan` untuk lembar ini.
     *
     * Milimeter, SELALU — walau alatnya berskala inch. Master menyimpan
     * penunjukan dalam satuan alat lalu mengalikannya 25,4 di dalam rumus, dan
     * itulah yang melahirkan sesi 0-25 mm yang koreksinya terbit −61 mm:
     * satuannya tersetel `inch` sementara angkanya diketik dalam mm, dan tidak
     * ada satu pun sel yang memprotes. Di sini konversi terjadi SEKALI, di
     * ujung masuk, dan yang tersimpan sudah dalam mm.
     */
    public const SATUAN = 'mm';

    /** Kunci blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'micrometer';

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{mikro_balok: list<float>, mikro_pembacaan: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            [self::PERAN_BALOK, self::PERAN_PEMBACAAN],
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        return [
            self::PERAN_BALOK => self::deret($milikKita, self::PERAN_BALOK),
            self::PERAN_PEMBACAAN => self::deret($milikKita, self::PERAN_PEMBACAAN),
        ];
    }

    /**
     * Blok tingkat-SESI dari `spesifikasi_alat`, sudah dinormalkan ke bentuk
     * yang diterima `MicrometerCalculator::hitungSesi()`.
     *
     * Kenapa di `spesifikasi_alat` dan bukan sebagai `titik_ke = 0`: jalur
     * hitung ulang mengelompokkan baris mentah per `titik_ke`, dan blok tanpa
     * titik yang dipaksa masuk ke situ lahir sebagai titik hantu yang selalu
     * gagal — persis yang sudah terjadi pada blok keterulangan Timbangan.
     *
     * Balik `null` kalau bloknya belum ada: sesi Micrometer tanpa pra-evaluasi
     * tidak bisa dihitung, dan menebak nilainya berarti menerbitkan
     * ketidakpastian yang tidak bersumber.
     *
     * Yang dioper `spesifikasi_alat`-nya, BUKAN model sesinya. Alasannya sama
     * dengan `TimbanganMentah`: jalur simpan (`CalibrationController`) dan
     * jalur hitung ulang (`HitungUlangSesi`) sama-sama menaruh blok itu di
     * `konteks`, dan profil yang menengok relasi sesi cuma jalan di salah
     * satunya — diam-diam, tanpa error, di jalur yang tidak pernah dites.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat  isi `calibration_sessions.spesifikasi_alat`
     * @return array{kapasitas_mm: float, resolusi_mm: float, suhu_balok_c: float, suhu_uut_c: float, pra_evaluasi: list<float>, balok_pra_evaluasi: list<float>}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $angka = static fn (mixed $x): float => is_numeric($x) ? (float) $x : 0.0;
        $deret = static fn (mixed $x): array => is_array($x)
            ? array_values(array_map(
                static fn ($v): float => (float) $v,
                array_filter($x, static fn ($v): bool => is_numeric($v)),
            ))
            : [];

        return [
            'kapasitas_mm' => $angka($blok['kapasitas_mm'] ?? null),
            'resolusi_mm' => $angka($blok['resolusi_mm'] ?? null),
            'suhu_balok_c' => $angka($blok['suhu_balok_c'] ?? null),
            'suhu_uut_c' => $angka($blok['suhu_uut_c'] ?? null),
            'pra_evaluasi' => $deret($blok['pra_evaluasi'] ?? null),
            'balok_pra_evaluasi' => $deret($blok['balok_pra_evaluasi'] ?? null),
        ];
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
