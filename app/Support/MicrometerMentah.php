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

    /** Satuan nominal balok ukur — SELALU mm, apa pun skala mikrometernya. */
    public const SATUAN_BALOK = 'mm';

    /**
     * Faktor pengali tiap satuan alat ke mm.
     *
     * Disalin dari `MicrometerProfile::SATUAN_PILIHAN` — ditaruh di sini juga
     * supaya jalur hitung ulang tidak perlu memuat kelas profil cuma untuk
     * mengalikan satu angka.
     */
    public const FAKTOR_KE_MM = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    /**
     * Ubah satu penunjukan ke mm.
     *
     * ## Kenapa konversinya di TEMPAT PAKAI, bukan di ujung masuk
     *
     * Versi pertama mengalikan di `CalibrationController` dan menyimpan mm.
     * Itu TIDAK IDEMPOTEN, dan jalur draft membuktikannya: teknisi menyimpan
     * draft (1 inch → tersimpan 25,4 mm), membuka lagi lembarnya, lalu
     * menyimpan lagi. HP tidak punya konversi balik sama sekali — dia
     * mengirimkan kembali angka yang dia terima — jadi 25,4 dikali 25,4 lagi
     * jadi **645,16 mm**, dan berlipat tiap kali disimpan.
     *
     * Nol error di seluruh jalur: payloadnya sah, kolomnya lengkap, dan
     * sertifikatnya terbit dengan koreksi yang salah ratusan kali lipat.
     *
     * Yang tersimpan sekarang ANGKA MENTAH yang diketik teknisi, berikut
     * satuannya di `raw_measurements.satuan`. Menyimpan payload yang sama dua
     * kali menghasilkan baris yang sama persis, dan tiap baris menyebutkan
     * sendiri satuannya — jadi hitung ulang tahun depan tidak perlu menebak.
     *
     * Satuan yang tidak dikenali dibaca `mm` (faktor 1), sama seperti kolom
     * kosong: menebak 25,4 untuk satuan yang tidak jelas jauh lebih berbahaya
     * daripada membiarkan angkanya apa adanya.
     */
    public static function keMm(mixed $nilai, ?string $satuan): float
    {
        return (float) $nilai * (self::FAKTOR_KE_MM[(string) $satuan] ?? 1.0);
    }

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
     * Yang TIDAK ada di sini, dan sengaja: suhu balok ukur & suhu UUT
     * (diturunkan dari suhu ruangan, lihat [MicrometerCalculator]) serta balok
     * ukur pra-evaluasi (ditentukan varian kertas, bukan diketik teknisi).
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
     * @return array{kapasitas_mm: float, resolusi_mm: float, pra_evaluasi: list<float>}|null
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

        // Pra-evaluasi tersimpan MENTAH dalam satuan alat; diubah ke mm di
        // sini, satu-satunya tempat blok ini dibaca oleh kedua jalur hitung.
        $satuan = (string) ($blok['satuan'] ?? 'mm');

        return [
            'kapasitas_mm' => $angka($blok['kapasitas_mm'] ?? null),
            'resolusi_mm' => $angka($blok['resolusi_mm'] ?? null),
            'pra_evaluasi' => array_map(
                static fn (float $v): float => self::keMm($v, $satuan),
                $deret($blok['pra_evaluasi'] ?? null),
            ),
        ];
    }

    /**
     * Rata-rata suhu ruangan MENTAH — sumber suhu balok ukur dan suhu UUT.
     *
     * Ditaruh di sini, bukan disalin di tiga jalur yang memanggilnya (simpan,
     * validator, hitung ulang), karena yang diangkut BUKAN sekadar rata-rata:
     * dia pernyataan bahwa untuk lembar ini suhu balok ukur = suhu UUT =
     * rata-rata suhu ruangan. Terbukti di keempat workbook master; lihat
     * [\App\Services\Calibration\MicrometerCalculator::budget].
     *
     * Ujung yang kosong dilewati, bukan dibaca nol: satu ujung yang belum
     * diisi bikin rata-ratanya separuh, dan suhu 10 °C di ruang berpendingin
     * 20 °C menggeser komponen suhu tanpa satu pun error.
     */
    public static function rataSuhuRuang(mixed $awal, mixed $akhir): float
    {
        $terisi = array_values(array_filter(
            [$awal, $akhir],
            static fn ($s): bool => is_numeric($s),
        ));

        return $terisi === []
            ? 0.0
            : array_sum(array_map('floatval', $terisi)) / count($terisi);
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
            // Tiap baris menyebutkan satuannya sendiri: penunjukan alat bisa
            // inch/µm, nominal balok ukur selalu mm. Dibaca dari barisnya, bukan
            // dari satu satuan tingkat-sesi, supaya sesi lama yang tersimpan
            // dalam mm tetap terbaca benar sesudah perubahan ini.
            ->map(static fn ($b): float => self::keMm($b->pembacaan, $b->satuan))
            ->values()
            ->all();
    }
}
