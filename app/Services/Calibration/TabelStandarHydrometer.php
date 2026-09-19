<?php

namespace App\Services\Calibration;

/**
 * Data acuan **Hydrometer** — metode `SIDIK-IK-CAL-0525_Rev.3` (lampiran
 * akreditasi LK-285-IDN, baris no. 25, Lab. Volumetrik).
 *
 * Dua workbook master jadi sumbernya, dua-duanya ber-password:
 *
 *  - `Master Olah Data Hydrometer 0.600-0.650.xlsm` (terbit 8 Sep 2025) —
 *    rentang RINGAN, pakai beban tambahan (sinker).
 *  - `Master Olah Data Hydrometer 1.800-2.000.xlsm` (terbit 7 Nov 2025) —
 *    rentang BERAT, tanpa sinker.
 *
 * Semua angka di sini DISALIN dari sel masternya, bukan diturunkan ulang.
 * Rantai hitungnya sendiri ada di [HydrometerCalculator]; kelas ini cuma
 * memegang yang sifatnya DATA supaya bisa dikoreksi tanpa menyentuh rumus.
 */
class TabelStandarHydrometer
{
    /**
     * Tegangan permukaan air suling vs suhu, `Tabel Surface Tension` A2:B8.
     * Satuan **dyne/cm**. Nilai di antara dua baris diinterpolasi LINEAR —
     * master memakai `FORECAST` atas dua titik yang mengapit, jadi di luar
     * 0,01-30 °C hasilnya ekstrapolasi garis dari pasangan terluar, bukan
     * error. Ditiru apa adanya.
     *
     * @var list<array{float, float}>
     */
    public const SURFACE_TENSION = [
        [0.01, 75.64],
        [5.0, 74.95],
        [10.0, 74.23],
        [15.0, 73.50],
        [20.0, 72.75],
        [25.0, 71.99],
        [30.0, 71.20],
    ];

    /**
     * Faktor ke dyne/cm untuk satuan tegangan permukaan, tabel `Satuan_teg_muka`
     * (`DATABASE!V29:W31`, dipakai `PERHITUNGAN!G26`). dyne/cm dan mN/m memang
     * identik satu banding satu; N/m seribu kali lipatnya.
     */
    public const FAKTOR_TEGANGAN_KE_DYNE = ['dyne/cm' => 1.0, 'mN/m' => 1.0, 'N/m' => 1000.0];

    /**
     * Faktor satuan densitas ke g/ml, tabel `Tabel_Satuan` (`DATABASE!V20:W21`).
     * Titik skala dan resolusi alat dikonversi lewat sini sebelum masuk rantai
     * hitung, dan dibalik lagi waktu sertifikat dicetak — persis `SERTIFIKAT!E17`
     * yang membagi balik dengan faktor yang sama.
     */
    public const FAKTOR_DENSITAS_KE_G_PER_ML = ['g/ml' => 1.0, 'kg/m3' => 0.001];

    /**
     * Suhu acuan hydrometer (`tr`) yang sah. Master menulisnya sebagai
     * catatan di `PERHITUNGAN!AE39`: "tref : 20 atau 27.5, atau 15 sesuai
     * informasi suhu acuan yg tertera pada alat".
     *
     * @var list<float>
     */
    public const SUHU_ACUAN_SAH = [15.0, 20.0, 27.5];

    /** Tepat tiga ulangan massa & tiga ulangan suhu per titik (`INPUT DATA` baris 41-43 & 47-49). */
    public const PENGULANGAN = 3;

    /** Tepat tiga kali ukur diameter stem (`INPUT DATA` L33:N33). */
    public const UKUR_DIAMETER_STEM = 3;

    /**
     * Ketetapan yang dipakai rantai hitung, disalin dari sel masternya.
     * Tidak satu pun boleh diganti "versi yang lebih benar" — lihat
     * [HydrometerCalculator] dan `docs/pertanyaan-lab-hydrometer.md`.
     */
    public const PHI = 3.14;                 // PERHITUNGAN!J89 — lab memakai 3,14, bukan π

    public const GRAVITASI_CM_S2 = 980.665;  // PERHITUNGAN!J90 = 9.80665 * 100

    public const ALPHA = 1.0e-5;             // PERHITUNGAN!J83 — muai volumetrik bahan hydrometer

    public const KAPPA = 2.5e-11;            // PERHITUNGAN!J86 — isothermal compressibility (1/Pa)

    public const DENSITAS_BEBAN_STANDAR = 8.0;   // PERHITUNGAN!AF85 — ρ beban standar timbangan

    public const DENSITAS_SINKER = 8.0;          // PERHITUNGAN!J91 — ρ beban tambahan

    public const ERR_TIMBANG = 0.0002;           // PERHITUNGAN!AF84 — kesalahan indikasi alat timbang

    public const ERR_DENSITAS = 0.0002;          // PERHITUNGAN!J87 — ketidakstabilan densitas acuan

    public const TEKANAN_ACUAN_HPA = 1013.25;    // PERHITUNGAN!AD29 = 101.325 kPa * 10

    /**
     * Suhu acuan faktor koreksi suhu — `PERHITUNGAN!AD39`/`AD75` menunjuk
     * `E10` = `'INPUT DATA'!E17`, kotak **Temperature** di blok identitas alat,
     * BUKAN `tr`. Di kedua master isinya 20 walau `tr` file ringan 15.
     * Pertanyaan §5.
     */
    public const SUHU_ACUAN_FAKTOR_BAWAAN = 20.0;

    /**
     * Koefisien polinomial densitas air suling orde-5 (g/ml), dari
     * `PERHITUNGAN!G56`. SEMUA digitnya bermakna — dibulatkan, densitas air
     * bergeser di digit yang dicetak sertifikat.
     *
     * @var list<float>
     */
    public const POLINOM_DENSITAS_AIR = [
        999.8395639,
        0.0679829989,
        -0.009106025564,
        0.0001005272999,
        -0.000001126713526,
        0.000000006591795606,
    ];

    /**
     * Ketidakpastian baku standar lab, blok "Uncertainty of Calibrator" sheet
     * `NILAI U95%` baris 27-45. IDENTIK di kedua master.
     *
     * Ini data kalibrasi ALAT STANDAR — begitu sertifikat timbangan /
     * termometer / sensor diperbarui, angkanya berubah. Ditaruh di sini,
     * bukan disebar di rumus, supaya satu tempat yang harus disunting.
     */
    public const U95_TIMBANGAN_LOP = 0.00074;    // E29, g

    public const K_TIMBANGAN_LOP = 2.0;          // E30

    public const STDEV_TIMBANG = 0.0001;         // E34, g

    public const N_TIMBANG = 10;                 // E35

    public const U95_THERMOMETER = 0.72;         // N29, °C

    public const K_THERMOMETER = 2.0;            // N30

    public const U95_SENSOR = 0.06;              // N34, °C

    public const K_SENSOR = 2.0;                 // N35

    /** U95% densitas cairan acuan, `NILAI U95%` F67 — diketik literal di master. */
    public const U_DENSITAS_ACUAN = 5.0e-5;

    /** U gravitasi lokal, `NILAI U95%` F72 — diketik literal di master. */
    public const U_GRAVITASI = 0.0005;

    /**
     * U95% sertifikat thermohygro standar yang dipakai `PERHITUNGAN!P15`/`P16`
     * (`DATABASE!F31`/`J31`). Suhu 1,2 °C, kelembaban 3 %RH.
     */
    public const U95_TH_SUHU = 1.2;

    public const U95_TH_RH = 3.0;

    /**
     * Lantai CMC hydrometer TIDAK tinggal di kelas ini.
     *
     * Versi pertama menaruhnya di sini sebagai satu pita 0 → tak berbatas
     * bernilai `0,0007`, disalin dari sel literal `NILAI U95%!L79` kedua
     * master. Itu SALAH, dan jawabannya sudah ada di repo sejak awal:
     * `database/data/kemampuan-kalibrasi.json` — lampiran akreditasi
     * LK-285-IDN — memuat Hydrometer di kelompok **Densitas** no. 32 dengan
     * **DUA** pita:
     *
     *     1,10 – 1,70 g/mL  →  0,00070 g/mL
     *     0,60 – 1,00 g/mL  →  0,00051 g/mL
     *
     * Hydrometer contoh rentang ringan (0,600-0,650 g/mL) jatuh di pita KEDUA,
     * jadi CMC-nya **0,00051** — sementara masternya mencetak 0,0007, angka
     * pita PERTAMA. Lihat `docs/pertanyaan-lab-hydrometer.md` §7.
     *
     * Jadi lantainya dibaca dari `calibration_capabilities` seperti tiga puluh
     * dua alat lain ([\App\Services\Calibration\Profiles\HydrometerProfile::cmcTitik]),
     * bukan dari konstanta di sini: itu data ber-rentang & ber-versi yang bisa
     * dikoreksi lab tanpa deploy, dan itu yang memang diminta.
     */

    /**
     * Tegangan permukaan air suling pada suhu `$t` (dyne/cm).
     *
     * Ditiru dari `'Tabel Surface Tension'!B9`:
     * `FORECAST(t, OFFSET(B2:B8, MATCH(t,A2:A8,1)-1, 0, 2), OFFSET(A2:A8, ...))`
     * — MATCH tipe 1 mengambil baris TERAKHIR yang ≤ t, lalu FORECAST atas dua
     * titik itu = garis lurus. Di bawah 0,01 °C `MATCH` di Excel memulangkan
     * `#N/A`; di sini dipakai pasangan terbawah supaya sesi tidak mati di
     * tengah jalan — suhu air kalibrasi hydrometer tidak pernah ke sana, dan
     * kalau sampai ke sana validasi suhu yang menahannya, bukan tabel ini.
     */
    public static function teganganPermukaan(float $t): float
    {
        $idx = 0;

        foreach (self::SURFACE_TENSION as $i => [$suhu, $_]) {
            if ($suhu <= $t) {
                $idx = $i;
            }
        }

        $idx = min($idx, count(self::SURFACE_TENSION) - 2);

        [$x1, $y1] = self::SURFACE_TENSION[$idx];
        [$x2, $y2] = self::SURFACE_TENSION[$idx + 1];

        return $y1 + ($y2 - $y1) * ($t - $x1) / ($x2 - $x1);
    }

    /**
     * Densitas air suling (g/ml) pada suhu `$t` — polinomial orde-5
     * [POLINOM_DENSITAS_AIR] dibagi 1000, persis `PERHITUNGAN!G56`.
     *
     * Ditulis suku demi suku dengan pangkat eksplisit, BUKAN Horner: master
     * memang menjumlah `a0 + a1·t + a2·t² + …` dan urutan penjumlahan float
     * ikut menentukan digit terakhir yang diadu test rekonsiliasi.
     */
    public static function densitasAirSuling(float $t): float
    {
        $a = self::POLINOM_DENSITAS_AIR;

        return ($a[0]
            + ($a[1] * $t)
            + ($a[2] * $t ** 2)
            + ($a[3] * $t ** 3)
            + ($a[4] * $t ** 4)
            + ($a[5] * $t ** 5)) / 1000;
    }

    /** Ketidakpastian baku massa aquadest, `NILAI U95%!E39` = √(Utimb² + Urepeat²). */
    public static function uMassaAquadest(): float
    {
        $uTimb = self::U95_TIMBANGAN_LOP / self::K_TIMBANGAN_LOP;
        $uRepeat = self::STDEV_TIMBANG / sqrt(self::N_TIMBANG);

        return sqrt($uTimb ** 2 + $uRepeat ** 2);
    }
}
