<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use RuntimeException;

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
    public const PAKET = 'hydrometer';

    private const BERKAS = 'tabel-standar-hydrometer.json';

    // Tegangan permukaan air suling vs suhu (`Tabel Surface Tension` A2:B8,
    // dyne/cm) — dulu konstanta `SURFACE_TENSION` — kini data acuan lapis 1
    // ([surfaceTension], paket `hydrometer`).

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

    // Ketetapan rantai hitung yang ada di SEL master `PERHITUNGAN` (phi J89
    // 3,14; gravitasi J90 980,665; alpha J83; kappa J86; rho beban standar
    // AF85; rho sinker J91; err timbang AF84; err densitas J87; tekanan acuan
    // AD29 1013,25 hPa — alamat master ringan, master berat bergeser) kini data
    // acuan lapis 1: [konstanta]. Tidak satu pun boleh diganti "versi yang
    // lebih benar" — lihat [HydrometerCalculator] dan
    // `docs/pertanyaan-lab-hydrometer.md`.

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

    // Ketidakpastian standar lab, blok "Uncertainty of Calibrator" `NILAI U95%`
    // (timbangan E29/E30/E34/E35, termometer N29/N30, sensor N34/N35, densitas
    // cairan acuan F67, gravitasi F72) dan U95 thermohygro `DATABASE!F31`/`J31`
    // — IDENTIK di kedua master — kini data acuan lapis 1: [standar]. Sertifikat
    // timbangan/termometer/sensor diperbarui = versi data acuan baru di
    // Studio, bukan sunting kode.

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

        $tabel = self::surfaceTension();

        foreach ($tabel as $i => [$suhu, $_]) {
            if ($suhu <= $t) {
                $idx = $i;
            }
        }

        $idx = min($idx, count($tabel) - 2);

        [$x1, $y1] = $tabel[$idx];
        [$x2, $y2] = $tabel[$idx + 1];

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
        $uTimb = self::standar('u95_timbangan_lop') / self::standar('k_timbangan_lop');
        $uRepeat = self::standar('stdev_timbang') / sqrt(self::standar('n_timbang'));

        return sqrt($uTimb ** 2 + $uRepeat ** 2);
    }

    /**
     * Tegangan permukaan air suling vs suhu, `Tabel Surface Tension` A2:B8
     * (dyne/cm), pasangan `[suhu °C, dyne/cm]` urut baris. Data acuan lapis 1.
     *
     * @return list<array{float, float}>
     */
    public static function surfaceTension(): array
    {
        $baris = self::isi()['surface_tension'] ?? null;

        if (! is_array($baris) || count($baris) < 2 || ! array_is_list($baris)) {
            throw new RuntimeException('Tabel hydrometer tanpa surface_tension (minimal dua baris).');
        }

        return array_map(static function (mixed $b): array {
            if (! is_array($b) || ! is_numeric($b['suhu_c'] ?? null) || ! is_numeric($b['dyne_cm'] ?? null)) {
                throw new RuntimeException('Tabel hydrometer: baris surface_tension rusak.');
            }

            return [(float) $b['suhu_c'], (float) $b['dyne_cm']];
        }, $baris);
    }

    /** Ketetapan rantai hitung `konstanta.<kunci>` (sel `PERHITUNGAN` master). */
    public static function konstanta(string $kunci): float
    {
        $x = self::isi()['konstanta'][$kunci] ?? null;

        return is_int($x) || is_float($x) ? (float) $x
            : throw new RuntimeException("Tabel hydrometer tanpa angka konstanta.{$kunci}.");
    }

    /**
     * Ketidakpastian standar lab `standar.<kunci>`. `n_timbang` bilangan bulat
     * (jumlah penimbangan, pembagi √n); selebihnya float.
     */
    public static function standar(string $kunci): float|int
    {
        $x = self::isi()['standar'][$kunci] ?? null;

        if ($kunci === 'n_timbang') {
            return is_int($x) && $x > 0 ? $x : throw new RuntimeException('Tabel hydrometer: standar.n_timbang harus bilangan bulat > 0.');
        }

        return is_int($x) || is_float($x) ? (float) $x
            : throw new RuntimeException("Tabel hydrometer tanpa angka standar.{$kunci}.");
    }

    /** @return array<string, mixed> */
    private static function isi(): array
    {
        $berkas = database_path('data/'.self::BERKAS);

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel hydrometer nggak ketemu: {$berkas}");
        }

        return app(SumberAcuan::class)->isi(self::PAKET, $berkas);
    }

    /**
     * Skema Studio Data Acuan paket `hydrometer`.
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(): array
    {
        $kolom = static fn (string $kode, string $label, string $tipe, ?string $satuan, ?string $asal): array => [
            'kode' => $kode, 'label' => $label, 'tipe' => $tipe, 'satuan' => $satuan, 'asal' => $asal, 'perlu_konfirmasi' => false,
        ];

        return [
            'skema_versi' => 1,
            'sumber_json' => self::BERKAS,
            'lembar' => [
                [
                    'kode' => 'surface_tension', 'label' => 'Tegangan permukaan air suling', 'jalur' => 'surface_tension',
                    'jenis' => 'daftar', 'kunci' => 'suhu_c',
                    'kolom' => [
                        $kolom('suhu_c', 'Suhu', 'desimal', '°C', 'Tabel Surface Tension!A2:A8'),
                        $kolom('dyne_cm', 'Tegangan permukaan', 'desimal', 'dyne/cm', 'Tabel Surface Tension!B2:B8'),
                    ],
                    'boleh_tambah_baris' => false,
                ],
                [
                    'kode' => 'konstanta', 'label' => 'Ketetapan rantai hitung (PERHITUNGAN, alamat master 0.600-0.650)', 'jalur' => 'konstanta',
                    'jenis' => 'objek', 'kunci' => null,
                    'kolom' => [
                        $kolom('alpha', 'Koefisien ekspansi volumetrik bahan', 'desimal', '1/°C', 'PERHITUNGAN!J83'),
                        $kolom('kappa', 'Isothermal compressibility cairan', 'desimal', '1/Pa', 'PERHITUNGAN!J86'),
                        $kolom('err_densitas', 'Kesalahan stabilitas densitas acuan', 'desimal', 'g/cm3', 'PERHITUNGAN!J87'),
                        $kolom('phi', 'phi (lab memakai 3,14)', 'desimal', null, 'PERHITUNGAN!J89'),
                        $kolom('gravitasi_cm_s2', 'Percepatan gravitasi', 'desimal', 'cm/s2', 'PERHITUNGAN!J90'),
                        $kolom('err_timbang', 'Kesalahan indikasi alat timbang', 'desimal', 'g', 'PERHITUNGAN!AF84'),
                        $kolom('densitas_beban_standar', 'Densitas beban standar timbangan', 'desimal', 'g/ml', 'PERHITUNGAN!AF85'),
                        $kolom('tekanan_acuan_hpa', 'Tekanan acuan', 'desimal', 'hPa', 'PERHITUNGAN!AD29'),
                        $kolom('densitas_sinker', 'Densitas beban tambahan (sinker)', 'desimal', 'g/cm3', 'PERHITUNGAN!J91'),
                    ],
                    'boleh_tambah_baris' => false,
                ],
                [
                    'kode' => 'standar', 'label' => 'Uncertainty of Calibrator (NILAI U95%) & thermohygro', 'jalur' => 'standar',
                    'jenis' => 'objek', 'kunci' => null,
                    'kolom' => [
                        $kolom('u95_timbangan_lop', 'U95 timbangan (LOP/read.)', 'desimal', 'g', 'NILAI U95%!E29'),
                        $kolom('k_timbangan_lop', 'k timbangan', 'desimal', null, 'NILAI U95%!E30'),
                        $kolom('stdev_timbang', 'Stdev penimbangan', 'desimal', 'g', 'NILAI U95%!E34'),
                        $kolom('n_timbang', 'n penimbangan', 'bulat', null, 'NILAI U95%!E35'),
                        $kolom('u95_thermometer', 'U95 termometer', 'desimal', '°C', 'NILAI U95%!N29'),
                        $kolom('k_thermometer', 'k termometer', 'desimal', null, 'NILAI U95%!N30'),
                        $kolom('u95_sensor', 'U95 sensor', 'desimal', '°C', 'NILAI U95%!N34'),
                        $kolom('k_sensor', 'k sensor', 'desimal', null, 'NILAI U95%!N35'),
                        $kolom('u_densitas_acuan', 'U densitas cairan acuan', 'desimal', 'g/ml', 'NILAI U95%!F67'),
                        $kolom('u_gravitasi', 'U gravitasi lokal', 'desimal', 'm/s2', 'NILAI U95%!F72'),
                        $kolom('u95_th_suhu', 'U95 thermohygro suhu', 'desimal', '°C', 'DATABASE!F31'),
                        $kolom('u95_th_rh', 'U95 thermohygro kelembaban', 'desimal', '%RH', 'DATABASE!J31'),
                    ],
                    'boleh_tambah_baris' => false,
                ],
            ],
        ];
    }
}
