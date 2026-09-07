<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Height Gauge** dari baris `raw_measurements`,
 * plus blok tingkat-sesinya dari `spesifikasi_alat`.
 *
 * ## Kenapa ini ada — kejadian KESEPULUH dengan pola yang sama
 *
 * Saudaranya [GridSensorMentah], [PasanganStandarUutMentah], [TimbanganMentah],
 * [WaktuMentah], dan [MicrometerMentah] sudah menjelaskan pola besarnya: alat
 * yang satu titiknya BUKAN satu deret pembacaan datar harus disusun ULANG tiap
 * kali sesi tersimpan dihitung lagi. Lupa melakukannya tidak menghasilkan
 * error — yang muncul `hitung_ulang_gagal` di setiap titik, tiap kali, sampai
 * admin belajar menekan "setujui tetap" tanpa membaca.
 *
 * Pola itu sudah menggigit **sembilan kali**. Height Gauge bentuk kesepuluh,
 * dan kelas ini ditulis BERSAMAAN dengan profilnya supaya tidak jadi kejadian
 * kesepuluh yang ditemukan belakangan.
 *
 * ## Bentuk yang disusun ulang
 *
 *   peran_sensor    sensor_ke  arti
 *   hg_nominal      1..3       slot nominal Caliper Checker, SELALU mm
 *   hg_pembacaan    1..3       penunjukan Height Gauge, ikut satuan alat
 *
 * Meratakannya jadi satu deret datar membuat nominal standar dan penunjukan
 * alat campur aduk, dan koreksi yang lahir dari situ — selisih keduanya — tidak
 * berarti apa-apa.
 *
 * Slot nominalnya sampai tiga walau kertasnya cuma menyediakan satu: master
 * menyapu tiga baris per titik (`H = SUM(F:G)` × tiga baris) dan sesi contoh
 * cuma mengisi baris pertama. Jalurnya disediakan supaya tidak perlu dibongkar
 * kalau lab mulai menumpuk.
 *
 * ## NOL kolom baru
 *
 * Sumbu `peran_sensor`/`sensor_ke` yang sudah ada cukup, dan blok
 * tingkat-SESI — paralelisme, pra-evaluasi, kapasitas, resolusi, kerataan muka
 * ukur — hidup di `calibration_sessions.spesifikasi_alat`. Memberinya `titik_ke`
 * melahirkan titik hantu yang selalu gagal hitung ulang; lihat [blokSesi].
 *
 * Balik `[]` — bukan blok kosong — kalau tidak ada baris ber-`peran_sensor`
 * milik lembar ini. Alasannya sama seperti kelima saudaranya: profil alat lain
 * tidak pernah menengok kunci ini, dan kunci yang muncul kosong lebih berbahaya
 * daripada kunci yang tidak ada.
 */
class HeightGaugeMentah
{
    public const PERAN_NOMINAL = 'hg_nominal';

    public const PERAN_PEMBACAAN = 'hg_pembacaan';

    /**
     * Satuan slot nominal — SELALU mm, apa pun skala Height Gauge-nya.
     *
     * Nominalnya nilai terkoreksi sertifikat Caliper Checker, dan sertifikat
     * itu terbit dalam mm. Dia tidak ikut satuan alat pelanggan.
     */
    public const SATUAN_NOMINAL = 'mm';

    /** Kunci blok tingkat-sesi di `calibration_sessions.spesifikasi_alat`. */
    public const KUNCI_SESI = 'height_gauge';

    /**
     * Faktor pengali tiap satuan alat ke mm.
     *
     * Disalin dari `HeightGaugeProfile::SATUAN_PILIHAN` — ditaruh di sini juga
     * supaya jalur hitung ulang tidak perlu memuat kelas profil cuma untuk
     * mengalikan satu angka.
     */
    public const FAKTOR_KE_MM = ['mm' => 1.0, 'inch' => 25.4, 'µm' => 0.001];

    /**
     * Ubah satu penunjukan ke mm.
     *
     * ## Kenapa konversinya di TEMPAT PAKAI, bukan di ujung masuk
     *
     * Ini bukan kehati-hatian teoretis — dia bug NYATA yang sudah terjadi di
     * Micrometer. Versi pertama di sana mengalikan di `CalibrationController`
     * dan menyimpan mm, dan jalur draft membuktikannya tidak idempoten: teknisi
     * menyimpan draft (1 inch → tersimpan 25,4 mm), membuka lagi lembarnya,
     * lalu menyimpan lagi. HP tidak punya konversi balik sama sekali — dia
     * mengirimkan kembali angka yang dia terima — jadi 25,4 dikali 25,4 lagi
     * jadi **645,16 mm**, dan berlipat tiap kali disimpan.
     *
     * Nol error di seluruh jalur: payloadnya sah, kolomnya lengkap, dan
     * sertifikatnya terbit dengan koreksi yang salah ratusan kali lipat.
     *
     * Yang tersimpan sekarang ANGKA MENTAH yang diketik teknisi, berikut
     * satuannya di `raw_measurements.satuan`. Menyimpan payload yang sama dua
     * kali menghasilkan baris yang sama persis.
     *
     * Satuan yang tidak dikenali dibaca `mm` (faktor 1), sama seperti kolom
     * kosong: menebak 25,4 untuk satuan yang tidak jelas jauh lebih berbahaya
     * daripada membiarkan angkanya apa adanya.
     */
    public static function keMm(mixed $nilai, ?string $satuan): float
    {
        return (float) $nilai * (self::FAKTOR_KE_MM[(string) $satuan] ?? 1.0);
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{hg_nominal: list<float>, hg_pembacaan: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        $milikKita = $baris->filter(static fn ($b): bool => in_array(
            (string) $b->peran_sensor,
            [self::PERAN_NOMINAL, self::PERAN_PEMBACAAN],
            true,
        ));

        if ($milikKita->isEmpty()) {
            return [];
        }

        return [
            self::PERAN_NOMINAL => self::deret($milikKita, self::PERAN_NOMINAL),
            self::PERAN_PEMBACAAN => self::deret($milikKita, self::PERAN_PEMBACAAN),
        ];
    }

    /**
     * Blok tingkat-SESI dari `spesifikasi_alat`, sudah dinormalkan ke bentuk
     * yang diterima `HeightGaugeCalculator::hitungSesi()`.
     *
     * Kenapa di `spesifikasi_alat` dan bukan sebagai `titik_ke = 0`: jalur
     * hitung ulang mengelompokkan baris mentah per `titik_ke`, dan blok tanpa
     * titik yang dipaksa masuk ke situ lahir sebagai titik hantu yang selalu
     * gagal — persis yang sudah terjadi pada blok keterulangan Timbangan.
     *
     * ## Paralelisme TIDAK ikut satuan alat, pra-evaluasi ikut
     *
     * Keduanya angka di lembar yang sama, dan bedanya menentukan. Pra-evaluasi
     * dibaca pada Height Gauge itu sendiri, jadi dia ikut satuan alat.
     * Paralelisme dibaca pada **Dial Indicator** standar resolusi 0,001 mm
     * (catatan kertas `INPUT DATA`), dan batas kelulusannya pun ditulis dalam
     * mm (`≤ 0,01 mm`) — bukan dalam satuan alat pelanggan.
     *
     * Menyeretnya ikut satuan alat berarti sesi Height Gauge berskala inch
     * mengalikan pembacaan dial indicator 0,002 jadi 0,0508 mm; hasilnya 0,0359
     * mm, lewat dari batas 0,01, dan kaki sertifikat mencetak **"Not Good"
     * palsu** untuk alat yang paralelismenya baik. Nol error di sepanjang
     * jalurnya.
     *
     * Balik `null` kalau bloknya belum ada: sesi Height Gauge tanpa
     * pra-evaluasi tidak bisa dihitung, dan menebak nilainya berarti
     * menerbitkan ketidakpastian yang tidak bersumber.
     *
     * Yang dioper `spesifikasi_alat`-nya, BUKAN model sesinya. Alasannya sama
     * dengan [MicrometerMentah]: jalur simpan (`CalibrationController`) dan
     * jalur hitung ulang (`HitungUlangSesi`) sama-sama menaruh blok itu di
     * `konteks`, dan profil yang menengok relasi sesi cuma jalan di salah
     * satunya — diam-diam, tanpa error, di jalur yang tidak pernah dites.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat  isi `calibration_sessions.spesifikasi_alat`
     * @return array{satuan: string, kapasitas_mm: float, resolusi_mm: float, paralelisme: list<float>, pra_evaluasi: list<float>, kerataan_muka_ukur: string|null}|null
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

        $satuan = (string) ($blok['satuan'] ?? 'mm');

        return [
            'satuan' => $satuan,
            'kapasitas_mm' => $angka($blok['kapasitas_mm'] ?? null),
            'resolusi_mm' => $angka($blok['resolusi_mm'] ?? null),
            // Dibaca pada Dial Indicator standar, SELALU mm — lihat docblock.
            'paralelisme' => $deret($blok['paralelisme'] ?? null),
            // Dibaca pada Height Gauge-nya sendiri, jadi ikut satuan alat.
            'pra_evaluasi' => array_map(
                static fn (float $v): float => self::keMm($v, $satuan),
                $deret($blok['pra_evaluasi'] ?? null),
            ),
            'kerataan_muka_ukur' => self::kerataan($blok['kerataan_muka_ukur'] ?? null),
        ];
    }

    /**
     * Kerataan muka ukur — SATU pilihan, bukan dua centang.
     *
     * Master memakai dua checkbox terpisah, dan di sesi contoh **dua-duanya
     * `TRUE`** (`INPUT DATA!Y22` dan `Y23`). `Z22 = IF(Y22=TRUE;"Baik";"Buruk")`
     * cuma membaca yang pertama, jadi hasilnya "Baik" tanpa satu pun protes —
     * padahal yang tercatat sebenarnya dua pernyataan yang saling meniadakan.
     *
     * Di lembar kita dia satu field pilihan. Dua boolean yang saling
     * meniadakan itu bentuk yang tidak bisa divalidasi: apa pun yang dikirim
     * HP, selalu ada tafsir yang membuatnya "sah".
     *
     * Nilai di luar `baik`/`buruk` balik `null` — bukan ditebak ke salah
     * satunya. Ini cuma catatan sertifikat, bukan angka; menebaknya berarti
     * mencetak vonis kondisi alat yang tidak pernah dibuat teknisi.
     */
    private static function kerataan(mixed $nilai): ?string
    {
        $bersih = is_string($nilai) ? strtolower(trim($nilai)) : null;

        return in_array($bersih, ['baik', 'buruk'], true) ? $bersih : null;
    }

    /**
     * Rata-rata suhu ruangan MENTAH — sumber suhu Caliper Checker dan suhu UUT.
     *
     * Ditaruh di sini, bukan disalin di tiga jalur yang memanggilnya (simpan,
     * validator, hitung ulang), karena yang diangkut BUKAN sekadar rata-rata:
     * dia pernyataan bahwa untuk lembar ini suhu standar = suhu UUT =
     * rata-rata suhu ruangan. Terbukti di `PERHITUNGAN!M35`/`N35` master, yang
     * dua-duanya 20,25 = (20,2 + 20,3) / 2.
     *
     * Ujung yang kosong dilewati, bukan dibaca nol: satu ujung yang belum diisi
     * bikin rata-ratanya separuh, dan suhu 10 °C di ruang berpendingin 20 °C
     * menggeser ϴ dari 0,25 jadi −10 — komponen budget ke-4 melonjak empat
     * puluh kali, tanpa satu pun error.
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
            // Diurut EKSPLISIT, bukan mengandalkan urutan baris dari database:
            // total nominal memang penjumlahan (urutannya tidak menggeser
            // hasil), tapi sertifikat mencetak slotnya apa adanya dan jejak
            // auditnya menyebut urutannya.
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
            // Tiap baris menyebutkan satuannya sendiri: penunjukan alat bisa
            // inch/µm, slot nominal selalu mm. Dibaca dari barisnya, bukan dari
            // satu satuan tingkat-sesi, supaya sesi lama tetap terbaca benar
            // sesudah satuan sesinya diubah.
            ->map(static fn ($b): float => self::keMm($b->pembacaan, $b->satuan))
            ->values()
            ->all();
    }
}
