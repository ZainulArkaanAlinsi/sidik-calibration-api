<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;

/**
 * Satu **profil kalibrasi** = satu jenis alat (pH Meter, Turbidimeter, ...),
 * ditulis sebagai SATU file yang berdiri sendiri.
 *
 * Kenapa ada: lab bakal nambah sampai 48 jenis alat, dan tiap jenis punya
 * bentuk lembar kerja, titik standar, satuan, dan budget ketidakpastian yang
 * beda. Sebelum ini semuanya di-hardcode khusus pH di `LembarKerjaTemplate` &
 * `GumCalculator` — nambah alat berarti nyisipin `if besaran==...` di kelas
 * bersama, dan dalam lima alat aja itu udah nggak kebaca.
 *
 * Dengan profil: nambah alat ke-3..48 = bikin SATU subclass di folder ini +
 * satu seeder CMC-nya. Kelas bersama (`GumCalculator::agregasiBudget`, jalur
 * CMC, `PerhitunganBuilder`) nggak disentuh lagi.
 *
 * Yang TETAP di kelas bersama, bukan di profil: agregasi budget (Uc, Welch–
 * Satterthwaite, k dari t-student), lantai CMC, dan keputusan PASS/FAIL. Itu
 * aturan GUM yang sama buat semua alat — cuma DAFTAR KOMPONEN-nya yang beda,
 * dan itulah yang diserahin ke [komponenBudget].
 */
abstract class CalibrationProfile
{
    /** Kode stabil profil, mis. `ph_meter` / `turbidimeter`. Dipakai routing & log. */
    abstract public function kode(): string;

    /**
     * Nama jenis alat seperti yang tercatat di `equipments.nama_alat_kemampuan`
     * dan `calibration_capabilities.nama_alat` (mis. `pH Meter`, `Turbidimeter`).
     * Ini kunci yang dipakai [CalibrationProfileRegistry] buat nyocokin alat ke
     * profilnya.
     */
    abstract public function namaAlatKemampuan(): string;

    /** Kode Formula GUM buat besaran ini (`Formula::KODE_GUM_*`). */
    abstract public function kodeFormula(): string;

    /** Nama besaran buat metadata Formula (`ph`, `turbidity`, ...). */
    abstract public function besaran(): string;

    /**
     * Resolusi alat buat satu titik ukur, kalau alatnya beda resolusi per titik
     * (mis. Turbidimeter 0,01/0,1/1). `null` = resolusi seragam — pemakai jatuh
     * ke `equipments.resolusi` yang tunggal. Default null; profil yang perlu
     * override.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return null;
    }

    /**
     * Jumlah desimal buat nampilin nilai di titik ini (turunan resolusi). Dikirim
     * ke lembar perhitungan & sertifikat biar angkanya dipad ke resolusi tanpa
     * buang nol belakang (4,60 tetap 4,60). Default null.
     */
    public function desimalTitik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Apa larutan/standar acuan alat ini PUNYA kurva suhu (nilai acuannya
     * bergeser ikut suhu larutan, kayak buffer pH)?
     *
     * Dipakai [\App\Services\CalibrationValidator] buat mbedain dua hal yang di
     * tabel `standards` kelihatan sama persis — `koefisien_suhu` NULL:
     *
     *  - **data kurvanya belum diisi** → suhu yang susah-susah dicatat teknisi
     *    kebuang percuma, pantas diperingatin; dan
     *  - **standarnya emang nggak berkurva** (turbidity, chlorine) → NULL itu
     *    jawaban yang benar, bukan data yang bolong.
     *
     * Default `true` karena profil pertama (pH) emang berkurva. Profil yang
     * standarnya dibaca nominal apa adanya override jadi `false` — kalau nggak,
     * tiap sertifikatnya ke-flag `valid: false` gara-gara temuan yang sebenernya
     * perilaku yang diharapkan.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return true;
    }

    /**
     * Bentuk lembar kerja (struktur bagian/field/tabel) — yang dulu di
     * `LembarKerjaTemplate::phMeter()`. Dibaca langsung layar input mobile.
     *
     * @return array<string, mixed>
     */
    abstract public function bentukLembarKerja(bool $untukAdmin = false): array;

    /** Sedikit-dikitnya kolom pengulangan yang masuk akal buat lembar kerja. */
    public const MIN_KOLOM_PENGULANGAN = 2;

    /**
     * Sebanyak-banyaknya. Lembar kerja itu dicetak & diisi tangan — lebih dari
     * ini kolomnya kekecilan buat dibaca, dan nggak ada prosedur lab yang minta
     * sebanyak itu.
     */
    public const MAKS_KOLOM_PENGULANGAN = 10;

    /**
     * Ganti jumlah KOLOM pengulangan di bentuk lembar kerja.
     *
     * Tiap profil punya jumlah bawaannya sendiri (5, ngikut form kertas), tapi
     * teknisi kadang cuma perlu 3 — mis. sampelnya terbatas atau alatnya lama
     * banget stabil. Angka ini murni soal berapa kotak yang DIGAMBAR: rumusnya
     * sendiri selalu ngikut berapa kotak yang beneran diisi (lihat
     * `GumCalculator::hitungTitik()`), jadi ngecilin kolom nggak ngubah cara
     * ngitungnya sama sekali.
     *
     * Ditulis ulang di sini — sekali, buat semua profil — bukan dioper sebagai
     * parameter ke tiap `bentukLembarKerja()`. Bentuknya nested dan tiap profil
     * nyusunnya beda; kalau tiap profil harus ngoper sendiri, cepat atau lambat
     * ada satu yang lupa dan kolomnya balik ke 5 diam-diam.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    public static function setelKolomPengulangan(array $bentuk, int $jumlah): array
    {
        $jumlah = max(self::MIN_KOLOM_PENGULANGAN, min(self::MAKS_KOLOM_PENGULANGAN, $jumlah));

        $tulisUlang = static function (array $simpul) use (&$tulisUlang, $jumlah): array {
            foreach ($simpul as $kunci => $nilai) {
                if ($kunci === 'jumlah_pengulangan' && is_int($nilai)) {
                    $simpul[$kunci] = $jumlah;

                    continue;
                }

                // `pengulangan` di tabel hasil isinya nomor kolom (`[1,2,3,4,5]`).
                // Dicek isinya, bukan cuma namanya, biar kunci bernama sama yang
                // isinya lain nggak ikut kegilas.
                if ($kunci === 'pengulangan' && is_array($nilai) && $nilai === range(1, count($nilai))) {
                    $simpul[$kunci] = range(1, $jumlah);

                    continue;
                }

                if (is_array($nilai)) {
                    $simpul[$kunci] = $tulisUlang($nilai);
                }
            }

            return $simpul;
        };

        return $tulisUlang($bentuk);
    }

    /**
     * Daftar komponen budget ketidakpastian buat SATU titik ukur, siap disuap
     * ke `GumCalculator::agregasiBudget()`. Balikin `null` kalau profil ini
     * nggak (atau belum) bisa nyusun budget penuh buat titik ini — pemanggil
     * bakal jatuh ke jalur CMC / generik yang lama, tanpa berubah perilaku.
     *
     * Tiap komponen: `['sumber','keterangan','distribusi','u','ci','vi']`.
     * Komponen pengulangan (Type A) WAJIB `'distribusi' => 't-student'` — itu
     * yang dipakai pemanggil buat misahin Type A dari Type B waktu ngitung
     * `type_b` (RSS Type B doang).
     *
     * @param  float  $typeA  ketidakpastian baku Type A (STDEV/√n) titik ini
     * @param  int  $n  jumlah pengulangan
     * @param  float|null  $suhuRuang  rata-rata suhu ruang MENTAH (awal+akhir)/2,
     *                                 sebelum koreksi sertifikat thermohygro.
     *                                 Refractometer butuh ini buat komponen
     *                                 "Pengaruh Perbedaan Temperature"; profil
     *                                 lain mengabaikannya. Lihat
     *                                 `GumCalculator::hitungTitik()`.
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>|null
     */
    abstract public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
    ): ?array;

    /**
     * Normalisasi RATA-RATA pembacaan alat ke suhu acuannya. Default: nggak
     * ngapa-ngapain — pembacaan dipakai apa adanya.
     *
     * ## Beda dari `Standard::nilaiPadaSuhu()` — dua arah yang berlawanan
     *
     * Dua-duanya ngurus suhu, tapi yang digeser BEDA SISI:
     *
     *  - `Standard::nilaiPadaSuhu()` geser **nilai acuan**. Buffer pH "10.01"
     *    yang diukur pada 25,3 °C nilai benernya 9,9451681 — botolnya yang
     *    berubah, pembacaan alatnya sah apa adanya.
     *  - Method ini geser **pembacaan alat**. Refractometer dibaca pada 27 °C
     *    nunjukin 1,3362; kalau larutan yang sama dibaca pada 20 °C (suhu acuan
     *    n20D) alatnya bakal nunjukin 1,33935. Larutan standarnya tetap 1,33659.
     *
     * Kalau kebalik, kolom "Correction" di sertifikat kegeser sebesar dua kali
     * koreksi suhu dan bisa ketuker tanda.
     *
     * ## Kenapa RATA-RATA, bukan tiap pembacaan
     *
     * Master Excel lab ngerata-rata dulu pembacaan DAN suhunya, baru dikoreksi
     * (sheet `PERHITUNGAN` baris Average: `1,3362 | 27`). STDEV-nya dihitung
     * dari pembacaan MENTAH. Kalau tiap pembacaan dikoreksi sendiri-sendiri,
     * satu repeat yang suhunya nyeleneh (35 °C di sesi contoh) bikin STDEV
     * bukan nol dan seluruh budget-nya meleset.
     *
     * @param  float|null  $suhuLarutan  rata-rata suhu larutan titik ini (°C)
     */
    public function rataRataPadaSuhuAcuan(
        float $rataRata,
        ?float $suhuLarutan,
        Equipment $equipment,
    ): float {
        return $rataRata;
    }
}
