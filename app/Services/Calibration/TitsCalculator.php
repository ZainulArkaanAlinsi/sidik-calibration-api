<?php

namespace App\Services\Calibration;

use App\Services\Calibration\Profiles\TitsProfile;
use App\Services\GumCalculator;
use App\Services\StudentTDistribution;

/**
 * Mesin hitung TITS (Temperature Indikator Tanpa Sensor) — MURNI: masuk array,
 * keluar array, tidak menyentuh DB, request, atau Eloquent.
 *
 * Master: `Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` (sesi
 * 01-CAL-625) dan `… fungsi Source utk UUT.xlsm` (sesi 0159-CAL-626), sheet
 * `PERHITUNGAN FC` + `PERHITUNGAN U95%`.
 *
 * ## Kenapa berdiri sendiri, di luar `GumCalculator::hitungTitik()`
 *
 * Dua hal yang tidak muat di jalur per-titik:
 *
 * **1. SATU U95 untuk seluruh sesi.** Sheet `PERHITUNGAN U95%` cuma punya satu
 * tabel budget, dan komponen keterulangannya diberi makan `M23 = MAX(K23:L40)` —
 * STDEV TERBESAR dari SEMUA titik. Sembilan titik keluar dengan U95 yang sama,
 * dan angka itu tidak bisa diturunkan dari satu titik saja. Sama persis alasan
 * Spectrophotometer punya kalkulatornya sendiri, bedanya di sini "kelompok"-nya
 * seluruh sesi.
 *
 * **2. Arah perhitungan berbalik antara dua mode.** Lihat blok di bawah.
 *
 * Yang TIDAK dipisah: agregasi GUM-nya. `uc`, Welch–Satterthwaite, dan lantai
 * CMC tetap lewat `GumCalculator::agregasiBudget()` — cuma ada satu
 * implementasi aturan GUM di repo ini.
 *
 * ## Dua mode, dua arah
 *
 * Kolom sertifikatnya sama di kedua mode — `Standard Reading`, `Unit Under
 * Test`, `Correction = Standard − UUT` — tapi yang mengisi keduanya bertukar:
 *
 *   mode      Standard Reading          Unit Under Test        yang diulang 6×
 *   measure   setpoint kalibrator + FC  rata-rata bacaan UUT   bacaan UUT
 *   source    rata-rata bacaan std + FC setpoint di UUT        bacaan standar
 *
 * `FC` = koreksi kalibrator dari sertifikatnya sendiri ([TabelKalibratorSuhu]),
 * dan di kedua mode dia menempel ke sisi STANDARD. Yang berpindah cuma sisi mana
 * yang berisi rata-rata pengulangan.
 *
 * Kalau arahnya ketuker, tanda seluruh kolom `Correction` terbalik — dan alat
 * yang membaca 4,98 °C terlalu tinggi akan tercetak sebagai membaca 4,98 °C
 * terlalu rendah. Tidak ada satu pun angka yang kelihatan janggal.
 *
 * ## UP & DOWN itu enam pembacaan, bukan dua seri
 *
 * Tiap titik dibaca naik (UP ×3) lalu turun (DOWN ×3). Master menghitung
 * `AVERAGE(D23:G24)` dan `STDEV(D23:G24)` — dua-duanya atas keenam angka
 * sekaligus, bukan per arah lalu digabung. Histeresis tidak dilaporkan terpisah
 * di sertifikat TITS, jadi UP/DOWN di sini murni urutan pengambilan:
 * `pembacaan_ke` 1–3 UP, 4–6 DOWN.
 *
 * ## Empat penyimpangan master yang SENGAJA ditiru
 *
 * Tiap-tiapnya mencetak baris `catatan_audit` yang menyebutkan berapa hasilnya
 * kalau dibetulkan — yang memutuskan mana yang benar manajer teknis lab, bukan
 * diam-diam kode ini.
 *
 *  1. **Pembagi AC Pick Up `√(√3)`, bukan `√3`.** Sel `Q22 = SQRT(3)` lalu
 *     `U22 = N22/SQRT(Q22)` — akar dari akar tiga, jadi pembaginya 1,3161.
 *     Empat baris di sekitarnya (`U20 = N20/Q20`, `U21 = N21/Q21`) memakai pola
 *     yang benar, jadi ini bukan salah baca kolom. Muncul identik di KEDUA
 *     workbook. Ditiru; lihat [PEMBAGI_AC_PICKUP].
 *  2. **`v_eff` tidak dipotong ke bawah.** Master memakai aproksimasi
 *     polinomial t-student pada `v_eff` apa adanya (`AC27 = 1.95996 +
 *     2.37356/v + …`). Sepuluh alat lain di repo ini memotong dulu (GUM G.4.1),
 *     dan itu sudah dicocokkan ke lembar manual pH. Di sini yang dipakai cara
 *     master supaya U95-nya sama dengan sertifikat yang sudah terbit — lihat
 *     [FLOOR_V_EFF] dan catatan audit `v_eff_tidak_dipotong`.
 *  3. **Drift kalibrator dibagi 2 di mode `measure`, tidak di mode `source`.**
 *     `N20 = VLOOKUP(…)/2` versus `N20 = VLOOKUP(…)`. Dua workbook, dua
 *     perlakuan, komponen yang sama.
 *  4. **`u` kalibrator diambil beda cara di dua mode.** `measure` memakai
 *     `MAX` seluruh kolom U95 tipe sensor itu; `source` memakai U95 di titik
 *     index TERTINGGI sesi (`VLOOKUP(R17, U95_source, …)` dengan
 *     `R17 = MAX(P23:P40)`). Yang pertama konservatif, yang kedua tidak — tapi
 *     dua-duanya yang tercetak.
 *
 * ## Satu penyimpangan yang TIDAK ditiru
 *
 * Budget mode `source` punya komponen bernama `Drift` (baris 22) DI LUAR
 * `Ketidakpastian Baku Drift Temp. Kalibrator` (baris 20) — dua komponen drift
 * untuk satu kalibrator. Nilainya `'STANDAR KALIBRATOR'!Y8` — referensi sel
 * MUTLAK ke drift **Constant Type N** (0,38), padahal sesi itu memakai Yokogawa
 * Type S. Sel itu tidak ikut berubah kalau tipe sensornya diganti; dia bukan
 * lookup, dia alamat mati.
 *
 * Ditiru default, karena membuangnya mengubah `uc` sesi contoh dari 0,5761 ke
 * 0,3733 — tapi TIDAK mengubah U95 yang dilaporkan (1,2, lantai CMC menang di
 * kedua versi). Lihat [SERTAKAN_DRIFT_MATI] dan catatan audit
 * `drift_referensi_mati`.
 *
 * @see TitsProfile
 * @see docs/pertanyaan-lab-tits.md
 */
class TitsCalculator
{
    /**
     * Pengaruh AC Pick Up, `PERHITUNGAN U95%!N22 = 0.2` °C di kedua workbook.
     *
     * Konstanta karakteristik lab (induksi jala-jala 50 Hz ke kabel termokopel),
     * bukan angka yang lahir dari sesi — karena itu hard-coded seperti di master,
     * bukan dibaca dari data sesi.
     */
    public const AC_PICKUP = 0.2;

    /**
     * Pembagi komponen AC Pick Up: **√(√3) ≈ 1,3160740**, bukan √3.
     *
     * Master menandai distribusinya `rect.` di kolom K — dan distribusi persegi
     * pembaginya √3. Tapi selnya menulis lain: `Q22 = SQRT(3)` (isi kolom
     * *Divisor*, jadi sampai sini benar) lalu `U22 = N22/SQRT(Q22)` — akar
     * DIAMBIL LAGI dari divisor yang sudah berupa akar.
     *
     * Baris tepat di atas dan di bawahnya menulis `U = N/Q` yang benar, dengan
     * `Q` yang sama-sama `SQRT(3)`. Jadi ini bukan pola yang disengaja untuk
     * seluruh tabel — tapi dia identik di KEDUA workbook, dan dua-duanya sudah
     * menerbitkan sertifikat.
     *
     * Dibetulkan ke √3, `u` komponen ini turun dari 0,1520 ke 0,1155 dan U95
     * mode measure sesi contoh turun dari 0,8533 ke 0,8351 — masih di atas CMC
     * 0,83, jadi angka sertifikatnya ikut berubah. Bukan kosmetik.
     */
    public const PEMBAGI_AC_PICKUP = 1.3160740129524924;

    /**
     * `v_eff` **tidak** dipotong ke bawah sebelum dicari `k`-nya.
     *
     * `GumCalculator::agregasiBudget()` memotong (GUM G.4.1) dan itu perilaku
     * sepuluh alat lain. Master TITS tidak: `AC27` menghitung t-student lewat
     * aproksimasi polinomial atas `v_eff` pecahan apa adanya.
     *
     * Selisihnya nyata waktu `v_eff` kecil — sesi measure contoh `v_eff` 6,5068:
     *
     *   dipotong ke 6  →  k = 2,446912  →  U = 0,8694  (tercetak "0,87")
     *   apa adanya     →  k = 2,401446  →  U = 0,8533  (tercetak "0,85")
     *
     * Yang tercetak di sertifikat 01-CAL-625 yang sudah diserahkan ke pelanggan
     * `0,85` dengan `k = 2,40`. Modul ini mereproduksi lembar TITS, jadi yang
     * dipakai cara master — dan selisihnya dilaporkan lewat catatan audit tiap
     * sesi supaya lab bisa memutuskan mana yang mau dipakai ke depan.
     *
     * Nilai `k` sendiri tetap dicari lewat `StudentTDistribution` repo ini,
     * bukan dengan menyalin polinomial Excel: pada `v_eff` yang sama keduanya
     * cuma berbeda 1,3·10⁻⁴ (2,401446 vs 2,401577), jauh di bawah presisi cetak,
     * dan yang satu punya test-nya sendiri.
     */
    public const FLOOR_V_EFF = false;

    /**
     * Komponen `Drift` bernilai mati di budget mode `source` IKUT dihitung.
     *
     * Lihat blok "Satu penyimpangan yang TIDAK ditiru" di docblock kelas —
     * namanya begitu karena yang tidak ditiru bukan komponennya, melainkan
     * kepercayaan bahwa angkanya benar: dia tetap dijumlahkan (supaya `uc` sama
     * dengan master) tapi selalu melahirkan catatan audit yang menyebut nilai
     * tanpa-dia.
     */
    public const SERTAKAN_DRIFT_MATI = true;

    /** Nilai komponen `Drift` mati itu — `'STANDAR KALIBRATOR'!Y8` (Constant Type N). */
    public const DRIFT_MATI = 0.38;

    /** `ci` komponen `Drift` mati — `X22 = 2` di master. */
    public const CI_DRIFT_MATI = 2.0;

    /** `vi` komponen `Drift` mati — `S22 = 1000001`. */
    public const VI_DRIFT_MATI = 1000001;

    /** `vi` sertifikat kalibrator & AC Pick Up — `S19`/`S22` mode measure. */
    public const VI_STANDAR = 200;

    /** `vi` drift kalibrator — `S20`. */
    public const VI_DRIFT = 50;

    /** `vi` daya baca — `S21`. */
    public const VI_RESOLUSI = 1000000;

    /** Faktor cakupan sertifikat kalibrator — `Q19 = 2`. */
    public const K_STANDAR = 2.0;

    private ?GumCalculator $gum = null;

    private ?StudentTDistribution $t = null;

    public function __construct(private readonly TabelKalibratorSuhu $tabel = new TabelKalibratorSuhu) {}

    /**
     * Hitung satu sesi TITS utuh: statistik tiap titik + satu budget bersama.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>}>  $titik
     * @param  array{mode: string, merk_kalibrator: string, tipe_sensor: string, resolusi: float, cmc: float}  $spek
     * @return array{
     *     mode: string, tipe_sensor: string, merk_kalibrator: string,
     *     titik: list<array<string, mixed>>, standar_deviasi_maks: float, jumlah_pengulangan: int,
     *     budget: list<array<string, mixed>>, ketidakpastian_gabungan: float,
     *     derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float,
     *     ketidakpastian_diperluas: float, cmc: float, u95_sertifikat: float,
     *     sumber_u95: string, catatan_audit: list<array<string, mixed>>
     * }
     *
     * @throws \InvalidArgumentException kalau sesinya kosong, mode/tipe sensornya
     *                                   tidak dikenal, atau tabel kalibratornya
     *                                   tidak memuat titik yang dipakai.
     */
    public function hitungSesi(array $titik, array $spek): array
    {
        if ($titik === []) {
            throw new \InvalidArgumentException(
                'Sesi TITS nggak punya satu pun titik terisi — budget ketidakpastiannya nggak bisa disusun.',
            );
        }

        $mode = $spek['mode'];
        $merk = $spek['merk_kalibrator'];
        $tipe = $spek['tipe_sensor'];

        if (! in_array($mode, [TabelKalibratorSuhu::MODE_MEASURE, TabelKalibratorSuhu::MODE_SOURCE], true)) {
            throw new \InvalidArgumentException("Mode TITS `{$mode}` nggak dikenal — cuma `measure` & `source`.");
        }

        if (! in_array($tipe, TabelKalibratorSuhu::TIPE_SENSOR, true)) {
            throw new \InvalidArgumentException("Tipe sensor `{$tipe}` nggak dikenal.");
        }

        if (! in_array($merk, TabelKalibratorSuhu::MERK, true)) {
            throw new \InvalidArgumentException("Merk kalibrator `{$merk}` nggak punya tabel koreksi.");
        }

        $resolusi = (float) $spek['resolusi'];

        if (! is_finite($resolusi) || $resolusi <= 0.0) {
            throw new \InvalidArgumentException(
                'Resolusi alat wajib angka > 0 — komponen daya baca budget-nya lahir dari situ.',
            );
        }

        $hasilTitik = array_map(
            fn (array $t): array => $this->hitungTitik(
                (int) $t['titik_ke'],
                (float) $t['titik_ukur'],
                $t['pembacaan'],
                $mode,
                $merk,
                $tipe,
            ),
            array_values($titik),
        );

        // `M23 = MAX(K23:L40)` — STDEV terbesar seluruh sesi, satu angka yang
        // jadi Type A budget bersama.
        $stdevMaks = max(array_column($hasilTitik, 'standar_deviasi'));

        // `Q23 = 3` di master: pembagi & derajat kebebasan Type A ditulis 3,
        // padahal yang dirata-rata SATU titik ada enam angka (UP 3 + DOWN 3).
        //
        // Bukan salah ketik yang bisa diabaikan — `U23 = N23/SQRT(3)` dan
        // `S23 = 3-1 = 2` saling mengonfirmasi, dan dua-duanya yang melahirkan
        // `v_eff` 6,5068 di sesi contoh. Dipakai 6, `v_eff` naik ke 13,9 dan
        // `k` turun ke 2,15.
        //
        // Yang dipakai jumlah pengulangan PER ARAH (UP saja atau DOWN saja),
        // karena itu yang cocok dengan angka master DAN punya arti fisik: tiga
        // pembacaan berturut-turut pada arah yang sama.
        $n = self::pengulanganPerArah($hasilTitik);

        $budget = $this->budget($stdevMaks, $n, $mode, $merk, $tipe, $resolusi, $hasilTitik);
        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));

        $agg = $this->agregasi($dipakai);

        $cmc = (float) $spek['cmc'];
        $uHitung = $agg['ketidakpastian_diperluas'];

        return [
            'mode' => $mode,
            'tipe_sensor' => $tipe,
            'merk_kalibrator' => $merk,
            'titik' => $hasilTitik,
            'standar_deviasi_maks' => $stdevMaks,
            'jumlah_pengulangan' => $n,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $uHitung,
            'cmc' => $cmc,
            // Lantai CMC (ILAC-P14, `AC30 = MAX(AC28:AI29)`): lab tidak boleh
            // mengklaim ketidakpastian lebih baik dari kemampuan terakreditasinya.
            'u95_sertifikat' => max($uHitung, $cmc),
            'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
            'catatan_audit' => $this->catatanAudit($budget, $dipakai, $uHitung, $cmc, $mode),
        ];
    }

    /**
     * Statistik satu titik + koreksinya, dengan arah yang benar untuk mode ini.
     *
     * `titik_ukur` yang dikembalikan adalah nilai kolom **Standard Reading**
     * sertifikat, bukan angka yang diketik teknisi — sama seperti pH yang
     * menyimpan nilai buffer pada suhu pengukuran, bukan nominal botolnya.
     * Setpoint aslinya tetap utuh di `raw_measurements.titik_ukur` dan
     * dikembalikan di sini sebagai `setpoint`.
     *
     * @param  list<float>  $pembacaan
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public function hitungTitik(
        int $titikKe,
        float $setpoint,
        array $pembacaan,
        string $mode,
        string $merk,
        string $tipeSensor,
    ): array {
        $pembacaan = array_values(array_map('floatval', $pembacaan));
        $n = count($pembacaan);

        if ($n < GumCalculator::MIN_PENGULANGAN) {
            throw new \InvalidArgumentException(sprintf(
                'Titik ke-%d cuma punya %d pembacaan, minimal %d — STDEV sampel nggak bisa dihitung dari satu angka.',
                $titikKe,
                $n,
                GumCalculator::MIN_PENGULANGAN,
            ));
        }

        $koreksiStandar = $this->tabel->koreksi($mode, $merk, $tipeSensor, $setpoint);

        if ($koreksiStandar === null) {
            throw new \InvalidArgumentException(sprintf(
                'Titik ke-%d (%s °C): tabel koreksi kalibrator %s mode %s untuk %s nggak punya satu pun titik — '
                .'koreksinya nggak boleh dianggap nol.',
                $titikKe,
                $setpoint,
                TabelKalibratorSuhu::MERK_TERCETAK[$merk] ?? $merk,
                $mode,
                $tipeSensor,
            ));
        }

        $rataRataPembacaan = array_sum($pembacaan) / $n;
        $stdev = $this->standarDeviasiSampel($pembacaan, $rataRataPembacaan);
        $fc = $koreksiStandar['nilai'];

        // Inilah satu-satunya tempat arah dua mode berpisah. Di bawah sini
        // semuanya sudah jadi pasangan (standard, uut) yang artinya sama.
        if ($mode === TabelKalibratorSuhu::MODE_MEASURE) {
            $standard = $setpoint + $fc;
            $uut = $rataRataPembacaan;
        } else {
            $standard = $rataRataPembacaan + $fc;
            $uut = $setpoint;
        }

        return [
            'titik_ke' => $titikKe,
            'setpoint' => $setpoint,
            'titik_ukur' => $standard,
            'rata_rata' => $uut,
            'rata_rata_pembacaan' => $rataRataPembacaan,
            'koreksi_standar' => $fc,
            'titik_tabel_standar' => $koreksiStandar['titik'],
            // Error = UUT − Standard; koreksi lawannya, dan koreksi yang dicetak
            // di sertifikat (`SERTIFIKAT!L20 = E20-J20`).
            'error' => $uut - $standard,
            'koreksi' => $standard - $uut,
            'standar_deviasi' => $stdev,
            'jumlah_pengulangan' => $n,
            'type_a' => $stdev / sqrt($n),
        ];
    }

    /**
     * Daftar komponen budget — lima baris di mode `measure`, enam di `source`.
     *
     * @param  list<array<string, mixed>>  $hasilTitik
     * @return list<array<string, mixed>>
     */
    private function budget(
        float $stdevMaks,
        int $n,
        string $mode,
        string $merk,
        string $tipeSensor,
        float $resolusi,
        array $hasilTitik,
    ): array {
        $sqrt3 = sqrt(3.0);
        $merkTercetak = TabelKalibratorSuhu::MERK_TERCETAK[$merk] ?? $merk;

        $uStandar = $this->uStandar($mode, $merk, $tipeSensor, $hasilTitik);
        $drift = $this->tabel->drift($mode, $merk, $tipeSensor);

        // Mode measure membagi drift dua, mode source tidak. Lihat penyimpangan
        // nomor 3 di docblock kelas.
        $driftDipakai = $drift === null
            ? null
            : ($mode === TabelKalibratorSuhu::MODE_MEASURE ? $drift / 2.0 : $drift);

        $komponen = [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => $uStandar === null
                    ? sprintf('Sertifikat kalibrator %s %s — tabel U95-nya kosong', $merkTercetak, $tipeSensor)
                    : sprintf(
                        'Sertifikat kalibrator %s %s (%s, U=%s °C, k=%s)',
                        $merkTercetak,
                        $tipeSensor,
                        $uStandar['asal'],
                        $this->angka($uStandar['nilai']),
                        $this->angka(self::K_STANDAR),
                    ),
                'distribusi' => 'normal',
                'u' => ($uStandar['nilai'] ?? 0.0) / self::K_STANDAR,
                'ci' => 1.0,
                'vi' => self::VI_STANDAR,
                'disertakan' => $uStandar !== null,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => $driftDipakai === null
                    ? sprintf('Drift kalibrator %s %s — nggak ada di tabel drift', $merkTercetak, $tipeSensor)
                    : sprintf(
                        'Drift kalibrator %s %s (%s °C%s ÷√3)',
                        $merkTercetak,
                        $tipeSensor,
                        $this->angka($drift ?? 0.0),
                        $mode === TabelKalibratorSuhu::MODE_MEASURE ? ' ÷2,' : ',',
                    ),
                'distribusi' => 'persegi',
                'u' => ($driftDipakai ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $driftDipakai !== null,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s °C (÷2, ÷√3)', $this->angka($resolusi)),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_RESOLUSI,
                'disertakan' => true,
            ],
        ];

        if ($mode === TabelKalibratorSuhu::MODE_SOURCE && self::SERTAKAN_DRIFT_MATI) {
            $komponen[] = [
                'sumber' => 'drift_referensi_mati',
                'keterangan' => sprintf(
                    'Drift tambahan %s °C (÷√3, ci %s) — sel mati master ke drift Constant Type N',
                    $this->angka(self::DRIFT_MATI),
                    $this->angka(self::CI_DRIFT_MATI),
                ),
                'distribusi' => 'persegi',
                'u' => self::DRIFT_MATI / $sqrt3,
                'ci' => self::CI_DRIFT_MATI,
                'vi' => self::VI_DRIFT_MATI,
                'disertakan' => true,
            ];
        }

        $komponen[] = [
            'sumber' => 'ac_pick_up',
            'keterangan' => sprintf(
                'Pengaruh AC Pick Up %s °C (÷√(√3) mengikuti master)',
                $this->angka(self::AC_PICKUP),
            ),
            'distribusi' => 'persegi',
            'u' => self::AC_PICKUP / self::PEMBAGI_AC_PICKUP,
            'ci' => 1.0,
            'vi' => self::VI_STANDAR,
            'disertakan' => true,
        ];

        $komponen[] = [
            'sumber' => 'pengulangan_pembacaan',
            'keterangan' => sprintf(
                'Pengulangan pembacaan (Type A) — STDEV terbesar %s °C dari %d titik, ÷√%d',
                $this->angka($stdevMaks),
                count($hasilTitik),
                $n,
            ),
            'distribusi' => 't-student',
            'u' => $stdevMaks / sqrt($n),
            'ci' => 1.0,
            'vi' => max($n - 1, 1),
            'disertakan' => true,
        ];

        return $komponen;
    }

    /**
     * `u` sertifikat kalibrator — diambil beda cara di dua mode.
     *
     *  - `measure`: `MAX` seluruh kolom U95 tipe sensor ini (`O19 = MAX(P32:P49)`).
     *  - `source`: U95 di titik index TERTINGGI sesi (`O19 = VLOOKUP(R17, …)`
     *    dengan `R17 = 'PERHITUNGAN FC'!P41 = MAX(P23:P40)`).
     *
     * @param  list<array<string, mixed>>  $hasilTitik
     * @return array{nilai: float, asal: string}|null
     */
    private function uStandar(string $mode, string $merk, string $tipeSensor, array $hasilTitik): ?array
    {
        if ($mode === TabelKalibratorSuhu::MODE_MEASURE) {
            $maks = $this->tabel->u95Maksimum($mode, $merk, $tipeSensor);

            return $maks === null ? null : ['nilai' => $maks, 'asal' => 'maks seluruh rentang'];
        }

        // Titik TABEL tertinggi yang dipakai sesi ini — bukan setpoint tertinggi.
        // Master mencocokkan setpoint ke index-nya dulu (`P23:P40`), baru
        // mengambil maksimumnya.
        $indexTertinggi = max(array_map(
            static fn (array $t): float => (float) $t['titik_tabel_standar'],
            $hasilTitik,
        ));

        $u95 = $this->tabel->u95($mode, $merk, $tipeSensor, $indexTertinggi);

        return $u95 === null
            ? null
            : ['nilai' => $u95['nilai'], 'asal' => sprintf('titik index %s °C', $this->angka($indexTertinggi))];
    }

    /**
     * Agregasi GUM, dengan `v_eff` TIDAK dipotong — lihat [FLOOR_V_EFF].
     *
     * Dijalankan dua kali: sekali untuk mendapat `v_eff` (dan `uc`), sekali lagi
     * dengan `k` yang sudah ditetapkan dari `v_eff` pecahan itu. Lebih murah
     * daripada menyalin rumus Welch–Satterthwaite ke sini, dan aturan GUM-nya
     * tetap cuma hidup di satu tempat.
     *
     * @param  list<array<string, mixed>>  $dipakai
     * @return array{ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float}
     */
    private function agregasi(array $dipakai): array
    {
        $gum = $this->gum ??= new GumCalculator;
        $komponen = array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $dipakai,
        );

        $agg = $gum->agregasiBudget($komponen);

        if (self::FLOOR_V_EFF || $agg['derajat_kebebasan_efektif'] === null) {
            return $agg;
        }

        $k = ($this->t ??= new StudentTDistribution)
            ->quantile(0.975, max(1.0, $agg['derajat_kebebasan_efektif']));

        return $gum->agregasiBudget($komponen, $k);
    }

    /**
     * Jumlah pengulangan PER ARAH — `Q23 = 3` di master, bukan enam.
     *
     * Diturunkan dari data, bukan dipatok: kalau lab suatu saat membaca empat
     * kali per arah, `8 / 2 = 4` dan budget-nya ikut. Dibulatkan ke bawah dan
     * dijaga minimal 2 supaya `vi = n − 1` tidak pernah nol.
     *
     * @param  list<array<string, mixed>>  $hasilTitik
     */
    private static function pengulanganPerArah(array $hasilTitik): int
    {
        $total = 0;

        foreach ($hasilTitik as $t) {
            $total = max($total, (int) $t['jumlah_pengulangan']);
        }

        return max(2, intdiv($total, TitsProfile::ARAH_PER_TITIK));
    }

    /**
     * Baris jejak audit: tiap penyimpangan master yang ditiru menyebutkan
     * berapa hasilnya kalau dibetulkan.
     *
     * @param  list<array<string, mixed>>  $budget
     * @param  list<array<string, mixed>>  $dipakai
     * @return list<array<string, mixed>>
     */
    private function catatanAudit(array $budget, array $dipakai, float $uHitung, float $cmc, string $mode): array
    {
        $gum = $this->gum ??= new GumCalculator;
        $catatan = [];

        $tanpaKomponen = function (string $sumber) use ($dipakai, $gum): ?float {
            $sisa = array_values(array_filter(
                $dipakai,
                static fn (array $k): bool => $k['sumber'] !== $sumber,
            ));

            if ($sisa === $dipakai || $sisa === []) {
                return null;
            }

            return $gum->agregasiBudget(array_map(
                static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
                $sisa,
            ))['ketidakpastian_diperluas'];
        };

        // 1. AC Pick Up: berapa U-nya kalau pembaginya √3 seperti label `rect.`.
        $dibetulkan = array_map(
            static fn (array $k): array => $k['sumber'] === 'ac_pick_up'
                ? [...$k, 'u' => self::AC_PICKUP / sqrt(3.0)]
                : $k,
            $dipakai,
        );

        $catatan[] = [
            'kode' => 'pembagi_ac_pick_up',
            'pesan' => sprintf(
                'Komponen AC Pick Up dibagi √(√3)=%s mengikuti master (sel U22 = N22/SQRT(Q22)); '
                .'dengan pembagi √3 yang sesuai label "rect." U95 hitung jadi %s °C, bukan %s °C.',
                $this->angka(self::PEMBAGI_AC_PICKUP),
                $this->angka($gum->agregasiBudget(array_map(
                    static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
                    $dibetulkan,
                ))['ketidakpastian_diperluas']),
                $this->angka($uHitung),
            ),
        ];

        // 2. v_eff tidak dipotong.
        $dipotong = $gum->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $dipakai,
        ));

        $catatan[] = [
            'kode' => 'v_eff_tidak_dipotong',
            'pesan' => sprintf(
                'v_eff dipakai apa adanya mengikuti master (k=%s, U=%s °C); dipotong ke bawah sesuai GUM G.4.1 '
                .'seperti sepuluh alat lain, k=%s dan U=%s °C.',
                $this->angka($this->faktorK($dipakai)),
                $this->angka($uHitung),
                $this->angka($dipotong['faktor_cakupan_k']),
                $this->angka($dipotong['ketidakpastian_diperluas']),
            ),
        ];

        // 3. Drift kalibrator dibagi 2 di mode measure, tidak di mode source —
        // dua workbook, dua perlakuan buat komponen yang sama. Penyimpangan
        // nomor 3 di docblock kelas.
        if ($mode === TabelKalibratorSuhu::MODE_MEASURE) {
            $driftPenuh = array_map(
                static fn (array $k): array => $k['sumber'] === 'drift_standar'
                    ? [...$k, 'u' => $k['u'] * 2.0]
                    : $k,
                $dipakai,
            );

            $catatan[] = [
                'kode' => 'drift_kalibrator_dibagi_dua',
                'pesan' => sprintf(
                    'Drift kalibrator dibagi 2 mengikuti master mode measure (sel N20 = VLOOKUP(…)/2); '
                    .'master mode source memakai nilai penuh buat komponen yang sama. Tanpa pembagi 2 '
                    .'U95 hitung jadi %s °C, bukan %s °C.',
                    $this->angka($gum->agregasiBudget(array_map(
                        static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
                        $driftPenuh,
                    ))['ketidakpastian_diperluas']),
                    $this->angka($uHitung),
                ),
            ];
        }

        // 4. Cara `u` kalibrator diambil berbeda di dua mode. Penyimpangan
        // nomor 4 di docblock kelas: `measure` MAX seluruh rentang (konservatif),
        // `source` U95 di index tertinggi sesi (tidak). Yang dicatat cuma cara
        // pengambilannya — angka tandingannya butuh tabel, dan itu ada di
        // keterangan komponen `ketidakpastian_standar` yang sudah tercetak.
        $catatan[] = [
            'kode' => 'u_kalibrator_beda_cara',
            'pesan' => $mode === TabelKalibratorSuhu::MODE_MEASURE
                ? 'u kalibrator diambil MAX seluruh kolom U95 tipe sensor ini mengikuti master mode measure '
                    .'(sel O19 = MAX(P32:P49)) — konservatif. Master mode source memakai U95 di titik index '
                    .'TERTINGGI sesi, yang bisa lebih kecil. Dua workbook, dua cara buat komponen yang sama.'
                : 'u kalibrator diambil dari U95 di titik index TERTINGGI sesi mengikuti master mode source '
                    .'(sel O19 = VLOOKUP(R17, …), R17 = MAX(P23:P40)) — bukan MAX seluruh rentang seperti '
                    .'master mode measure, jadi nilainya bisa lebih kecil dari U95 kalibrator di titik lain.',
        ];

        // 5. Komponen drift bersel mati, cuma ada di mode source.
        if ($mode === TabelKalibratorSuhu::MODE_SOURCE && self::SERTAKAN_DRIFT_MATI) {
            $tanpa = $tanpaKomponen('drift_referensi_mati');

            $catatan[] = [
                'kode' => 'drift_referensi_mati',
                'pesan' => sprintf(
                    'Budget mode source memuat komponen Drift %s °C dari sel MUTLAK master '
                    .'(drift Constant Type N), yang nggak ikut berubah waktu tipe sensor diganti. '
                    .'Tanpa komponen itu U95 hitung jadi %s °C, bukan %s °C.',
                    $this->angka(self::DRIFT_MATI),
                    $tanpa === null ? '—' : $this->angka($tanpa),
                    $this->angka($uHitung),
                ),
            ];
        }

        // 6. Komponen yang datanya nggak ada — supaya tidak diam-diam hilang.
        foreach ($budget as $k) {
            if (! $k['disertakan']) {
                $catatan[] = [
                    'kode' => 'komponen_tanpa_data',
                    'pesan' => sprintf(
                        'Komponen `%s` nggak ikut dihitung: %s. U95 sesi ini disusun tanpa dia.',
                        $k['sumber'],
                        $k['keterangan'],
                    ),
                ];
            }
        }

        if ($cmc > $uHitung) {
            $catatan[] = [
                'kode' => 'lantai_cmc',
                'pesan' => sprintf(
                    'U95 hitung %s °C di bawah CMC lab %s °C — yang dilaporkan CMC (ILAC-P14).',
                    $this->angka($uHitung),
                    $this->angka($cmc),
                ),
            ];
        }

        return $catatan;
    }

    /**
     * `k` yang benar-benar dipakai, untuk teks catatan audit.
     *
     * @param  list<array<string, mixed>>  $dipakai
     */
    private function faktorK(array $dipakai): float
    {
        return $this->agregasi($dipakai)['faktor_cakupan_k'];
    }

    /**
     * STDEV sampel (Excel `STDEV`) — penyebut n−1.
     *
     * @param  list<float>  $nilai
     */
    private function standarDeviasiSampel(array $nilai, float $rataRata): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $jumlahKuadrat = array_sum(array_map(
            static fn (float $x): float => ($x - $rataRata) ** 2,
            $nilai,
        ));

        return sqrt($jumlahKuadrat / ($n - 1));
    }

    /** Angka buat teks keterangan: presisi cukup, tanpa nol belakang yang bikin ramai. */
    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 8, '.', ''), '0'), '.') ?: '0';
    }
}
