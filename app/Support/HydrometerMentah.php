<?php

namespace App\Support;

use App\Models\RawMeasurement;
use App\Services\Calibration\TabelStandarHydrometer;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Hydrometer** dari baris `raw_measurements`,
 * plus blok Pre Condition tingkat-sesinya dari `spesifikasi_alat`.
 *
 * Saudaranya [MicrometerMentah], [JangkaSorongMentah], [HeightGaugeMentah], dan
 * [AnakTimbanganMentah] sudah menjelaskan pola besarnya: alat yang satu
 * titiknya bukan satu deret datar harus disusun ULANG tiap kali sesi tersimpan
 * dihitung lagi, dan lupa melakukannya tidak menghasilkan error — cuma
 * `hitung_ulang_gagal` di tiap titik, yang mengajari admin menekan "setujui
 * tetap" secara refleks. Kelas ini ditulis BERSAMAAN dengan profilnya.
 *
 * ## Dua deret per titik, kosakatanya beda
 *
 *   peran_sensor    sensor_ke  arti
 *   hydro_massa     1..3       hasil timbang hydrometer di cairan, gram
 *   hydro_suhu      1..3       suhu air media kalibrasi, °C
 *
 * Keduanya WAJIB ada dan WAJIB tiga-tiga. Deret datar tanpa `peran_sensor`
 * tidak bisa dipakai: massa dan suhu ber-orde berbeda (21 g lawan 20,6 °C) dan
 * tidak ada satu pun yang bisa membedakannya begitu perannya hilang — densitas
 * yang lahir dari situ tetap terbit, cuma salah.
 *
 * ## NOL kolom baru
 *
 * Blok Pre Condition — `Sl`, `Ma`, `yx` + satuannya, `tr`, dan ketiga ukuran
 * diameter stem — hidup di `spesifikasi_alat.hydrometer`. Tekanan udaranya
 * TIDAK: `calibration_sessions.tekanan_awal`/`tekanan_akhir` sudah ada sejak
 * Gas Detector (migrasi 2026_08_20_100000) dan satuannya memang **hPa**, persis
 * yang dibaca rumus densitas udara di sini.
 */
class HydrometerMentah
{
    public const KUNCI_SESI = 'hydrometer';

    /**
     * Peran yang besarannya BUKAN besaran alatnya.
     *
     * Dibaca `CalibrationValidator`, yang mengadu tiap pembacaan ke
     * `equipments.range_min..range_max` dan `equipments.resolusi`. Buat tiga
     * puluh dua alat lain itu benar — yang diketik teknisi memang besaran yang
     * sama dengan rentang alatnya. Di sini rentangnya g/ml sementara yang
     * diketik gram dan °C, jadi tiap pembacaan sesi yang sempurna dilaporkan
     * "jauh di luar rentang ukur alat".
     *
     * Daftarnya hidup di sini, bukan sebagai literal di validator: kedua nama
     * peran ini juga yang dipakai `simpan_ke` lembar kerja dan jalur hitung
     * ulang, dan nama yang ditulis dua kali di dua berkas itu nama yang bisa
     * menyimpang diam-diam.
     *
     * @var list<string>
     */
    public const PERAN_BUKAN_BESARAN_ALAT = [self::PERAN_MASSA, self::PERAN_SUHU];

    public const PERAN_MASSA = 'hydro_massa';

    public const PERAN_SUHU = 'hydro_suhu';

    /** Kunci konteks yang dibaca `HydrometerProfile::hitungPerGrup()`. */
    public const KONTEKS_MASSA = 'hydro_massa';

    public const KONTEKS_SUHU = 'hydro_suhu';

    /** Satuan massa hasil timbang — SELALU gram, itu satuan neraca analitiknya. */
    public const SATUAN_MASSA = 'g';

    /**
     * Dua deret satu titik, dari baris `raw_measurements` milik `titik_ke` itu.
     *
     * Balik `[]` — bukan dua deret kosong — kalau tidak ada baris ber-peran
     * milik lembar ini. Alasannya sama seperti keempat saudaranya: profil alat
     * lain tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih
     * berbahaya daripada kunci yang tidak ada.
     *
     * @param  Collection<int, RawMeasurement>  $baris
     * @return array{hydro_massa: list<float>, hydro_suhu: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milik = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            [self::PERAN_MASSA, self::PERAN_SUHU],
            true,
        ));

        if ($milik->isEmpty()) {
            return [];
        }

        return [
            self::KONTEKS_MASSA => self::deret($milik, self::PERAN_MASSA),
            self::KONTEKS_SUHU => self::deret($milik, self::PERAN_SUHU),
        ];
    }

    /**
     * Blok Pre Condition tingkat-SESI, dinormalkan ke bentuk yang diterima
     * `HydrometerCalculator::hitungSesi()`. Balik `null` kalau bloknya belum ada.
     *
     * `pakai_beban_tambahan` dibaca sebagai boolean EKSPLISIT, bukan disimpulkan
     * dari `beban_tambahan !== null`. Itu bedanya "tidak perlu sinker" dengan
     * "lupa mengisi sinker", dan menyimpulkannya dari kekosongan membuat yang
     * kedua diam-diam terbit dengan rumus varian yang salah.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array<string, mixed>|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $angka = static fn (mixed $x): ?float => is_numeric($x) ? (float) $x : null;

        return [
            'pakai_beban_tambahan' => self::pakaiBebanTambahan($blok['pakai_beban_tambahan'] ?? null),
            'beban_tambahan' => $angka($blok['beban_tambahan'] ?? null),
            'massa_udara' => $angka($blok['massa_udara'] ?? null) ?? 0.0,
            'tegangan_permukaan' => $angka($blok['tegangan_permukaan'] ?? null) ?? 0.0,
            'satuan_tegangan' => (string) ($blok['satuan_tegangan'] ?? 'dyne/cm'),
            'suhu_acuan_alat' => $angka($blok['suhu_acuan_alat'] ?? null),
            // Kotak `Temperature` blok identitas alat — yang dipakai faktor
            // koreksi suhu, BUKAN `tr`. Lihat temuan 6 di HydrometerCalculator.
            'suhu_acuan_faktor' => $angka($blok['suhu_acuan_faktor'] ?? null)
                ?? TabelStandarHydrometer::SUHU_ACUAN_FAKTOR_BAWAAN,
            'diameter_stem' => self::ratakan($blok['diameter_stem'] ?? null),
            'resolusi' => $angka($blok['resolusi'] ?? null) ?? 0.0,
            'satuan_densitas' => (string) ($blok['satuan_densitas'] ?? 'g/ml'),
        ];
    }

    /**
     * Varian rumus yang dipilih teknisi, dibaca dari DUA bentuk yang sah.
     *
     * Lembar kerja HP mengirim dropdown `ya`/`tidak` — tipe `pilihan`, karena
     * kontrak lembar kerja tidak punya saklar boolean dan tipe yang tidak
     * dikenal jatuh ke kotak teks bebas tanpa satu pun error. Seeder, test, dan
     * klien lain boleh mengirim boolean asli.
     *
     * Apa pun selain itu dibaca **`false`** (varian non-sinker), dan itu arah
     * yang benar: varian sinker MENGURANGI berat semu beban tambahan, jadi
     * menebak "ya" atas nilai yang tidak dikenali berarti mengurangkan `Sl`
     * yang mungkin kosong — dan `Sl` kosong terbaca nol membuat `Q = 0`,
     * densitasnya bergeser diam-diam. Varian non-sinker yang salah pilih
     * ditahan gerbangnya sendiri (`HydrometerCalculator::hitungSesi()` menolak
     * toggle menyala tanpa angka), jadi kesalahan ke arah ini selalu kelihatan.
     */
    public static function pakaiBebanTambahan(mixed $nilai): bool
    {
        if (is_string($nilai)) {
            $bersih = strtolower(trim($nilai));

            if ($bersih === 'ya') {
                return true;
            }

            if ($bersih === 'tidak') {
                return false;
            }
        }

        // Sisanya lewat `FILTER_VALIDATE_BOOLEAN`, yang mengenal `true`/`1`/`on`
        // dan pasangannya. Yang tidak dikenali sama sekali (`FILTER_NULL_ON_FAILURE`
        // memulangkan null) jatuh ke `false`.
        return filter_var($nilai ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Ubah satu angka bersatuan densitas ke g/ml — satuan rantai hitung.
     *
     * Konversinya DI TEMPAT PAKAI, bukan di ujung masuk, sama alasannya dengan
     * [MicrometerMentah::keMm]: jalur draft tidak idempoten, dan mengalikan di
     * ujung masuk membuat titik skala berlipat tiap kali teknisi menyimpan
     * ulang lembar yang sama. Satuan tak dikenal dibaca g/ml.
     */
    public static function keGramPerMl(mixed $nilai, ?string $satuan): float
    {
        return (float) $nilai
            * (TabelStandarHydrometer::FAKTOR_DENSITAS_KE_G_PER_ML[(string) $satuan] ?? 1.0);
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris
     * @return list<float>
     */
    private static function deret(Collection $baris, string $peran): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
            // Kolomnya `pembacaan`, BUKAN `nilai`. `raw_measurements` tidak
            // punya kolom `nilai` sama sekali, jadi `$b->nilai` pulang null
            // tanpa satu pun error dan seluruh deret jadi nol — densitas yang
            // lahir dari situ tetap terbit, sama untuk setiap titik skala.
            ->map(static fn ($b): float => (float) $b->pembacaan)
            ->values()
            ->all();
    }

    /**
     * Deret angka dari deret datar ATAU cerminan tabel HP
     * (`{baris: [{pembacaan: [...]}]}`). Yang bukan angka dilewati.
     *
     * PUBLIC, dan itu perlu: `CalibrationRequest::bakukanBlokHydrometer()`
     * memanggilnya di `prepareForValidation()` supaya aturan `size:3` mengadu
     * bentuk yang SUDAH rata. Kalau perataannya ditulis ulang di sana, dua
     * salinan bentuk tabel hidup berdampingan — dan yang satu bisa berubah
     * tanpa yang lain ikut.
     *
     * Idempoten: deret yang sudah rata dipulangkan apa adanya, jadi jalur draft
     * yang menyimpan berulang tidak merusaknya.
     *
     * @return list<float>
     */
    public static function ratakan(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        $mentah = isset($nilai['baris'])
            ? array_merge([], ...array_map(
                static fn ($b): array => array_values((array) (is_array($b) ? ($b['pembacaan'] ?? []) : [])),
                array_values((array) $nilai['baris']),
            ))
            : array_values($nilai);

        return array_values(array_map('floatval', array_filter($mentah, static fn ($v): bool => is_numeric($v))));
    }
}
