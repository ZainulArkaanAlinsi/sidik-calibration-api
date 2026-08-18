<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Viscometer rotasi Brookfield (alat ke-7). Formulir
 * `SIDIK-FM-CAL-0524_Rev.3`, metode `SIDIK-IK-CAL-0517_Rev.3` (DATABASE row 17),
 * master `Master Olah Data_Viscometer`.
 *
 * ## Dua hal yang bikin alat ini beda dari enam sebelumnya
 *
 * **1. Nilai acuan standarnya bergerak, dan bergeraknya tajam.** Larutan 60000 cP
 * bernilai 95192 cP pada 20 °C dan 19259 cP pada 37,78 °C — turun 80 % dalam 18
 * derajat. Jadi kolom "Standard Value" di sertifikat BUKAN angka nominal botol,
 * melainkan hasil interpolasi linier tabel sertifikat larutan pada suhu terukur
 * titik itu. Yang ngerjain [Standard::nilaiPadaSuhu] bentuk tabel, dipanggil
 * `GumCalculator::hitungTitik()` — jalur yang sama yang dipakai kurva kuadratik
 * buffer pH, cuma bentuk datanya beda.
 *
 * Yang TIDAK dipakai: persamaan kubik yang ikut tercetak di bawah tabel master
 * (`y = -0,0007x³ + 0,1522x² - 11,693x + 313,28`). Itu trendline grafik. Pada
 * 26,52 °C dia ngasih 97,16 cP sementara sel masternya 93,88 cP — beda 3,5 %,
 * cukup buat mbalik vonis MPE.
 *
 * **2. Batas keberterimaannya dihitung, bukan diisi.** Enam alat lain mbandingin
 * hasil ke satu kolom `equipments.toleransi`. Viscometer nggak punya angka itu;
 * yang ada MPE yang lahir dari alat & cara pakainya:
 *
 *   Fullscale = TK × SMC × 10000 / RPM
 *   MPE       = 1 % × Fullscale + 1 % × rata-rata pembacaan
 *
 * `TK` sifat model alatnya (satu per sesi, [TABEL_TK]); `SMC` dari spindle yang
 * dipasang dan `RPM` dari kecepatan yang dipilih — dua-duanya **per titik**.
 * Sesi contoh master pakai tiga spindle berbeda (SMC 1 / 4 / 400) dengan dua
 * kecepatan (63 / 62 rpm) dalam satu lembar, jadi ini beneran per titik, bukan
 * penyederhanaan yang kebetulan seragam.
 *
 * ## Yang SENGAJA sama kayak alat lain
 *
 * Budgetnya lahir per TITIK (bukan per kelompok kayak Spectrophotometer), jadi
 * [komponenBudget] yang dipakai dan `hitungPerGrup()` sengaja nggak di-override.
 * Koefisien sensitivitas suhunya juga rumus rumah yang sama:
 * `ci = (UTemperature / 400) × nilai nominal titik` — persis
 * [TurbidimeterProfile::komponenBudget] & [ChlorineProfile::komponenBudget].
 * Yang beda cuma derajat kebebasannya (50 di sini, 200 di dua alat itu) dan
 * "nilai nominal titik" yang buat alat ini WAJIB nilai @25 °C, bukan nilai
 * hasil interpolasi — lihat [SUHU_ACUAN].
 *
 * ## Cetakan Rev.3 vs master: tiga selisih
 *
 *  1. **Larutan standar.** Kertas nulis "Larutan Std Visco 100 cP" DUA KALI,
 *     lalu 30000 cP & 60000 cP. Master, `DATABASE`, dan lampiran KAN semuanya
 *     100 / 1000 / 60000 cP, dan `FORM VALIDASI` rev.18 (18 Mei 2026) nyebut
 *     eksplisit "Update Standard Viscometer 1000 cP". Yang dipakai master;
 *     kertasnya ketinggalan satu revisi standar.
 *  2. **Blok 30000 cP nggak diimplementasi.** Budgetnya di `PERHITUNGAN U95%`
 *     `#DIV/0!` di seluruh baris — sumber angkanya sudah hilang dari workbook
 *     itu sendiri. Sama perlakuannya kayak blok SRE di Spectrophotometer:
 *     muncul sebagai bagian `sumber_belum_ada` yang nggak nerima input.
 *  3. **Kotak Spindle & RPM per titik ditambahkan.** Kertas cuma punya daftar
 *     spindle global buat dilingkari — nggak cukup buat MPE. Lihat
 *     [SPINDLE_TIDAK_DIPINDAI] soal kenapa dua kotak ini nggak jadi sel pindai.
 *
 * @see docs/PRD-viscometer.md
 * @see docs/pertanyaan-lab-viscometer.md
 */
class ViscometerProfile extends CalibrationProfile
{
    /** Nomor FORMULIR lembar kerjanya (footer kanan bawah cetakan + "Rev: 3"). */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0524_Rev.3';

    /**
     * Metode kalibrasinya, TERCETAK di lembar kerja ("Methode :
     * SIDIK-IK-CAL-0517"). Nomor lengkapnya dari `DATABASE` baris 17. Sengaja
     * dipisah dari [KODE_DOKUMEN] biar dua nomor yang beda jenis nggak saling
     * nimpa — kasus yang pernah kejadian di Conductivity & Spectrophotometer.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0517_Rev.3';

    public const JUMLAH_PENGULANGAN = 5;

    public const SATUAN = 'cP';

    /**
     * Suhu acuan tabel sertifikat larutan.
     *
     * Dua angka di budget diambil dari BARIS INI, bukan dari suhu terukur:
     * nilai nominal larutan dan persen ketidakpastiannya. Sudah diuji
     * dua-duanya lawan master — cuma nilai @25 °C yang cocok, dan `INPUT DATA`
     * pun nulisnya eksplisit ("Index nilai 100cP : 25", "Value visco on 100cP :
     * 99,65").
     *
     * Nilai hasil interpolasi tetap dipakai buat kolom "Standard Value" &
     * "Correction" — dua peran yang beda, jangan ketuker.
     */
    public const SUHU_ACUAN = 25.0;

    /**
     * Derajat kebebasan komponen pengaruh temperature.
     *
     * **50, bukan 200.** Turbidimeter & Chlorine pakai 200 buat komponen yang
     * rumusnya sama persis; master Viscometer pakai 50 (`PERHITUNGAN U95%`
     * kolom `vi`). Angka master yang dipakai karena dia yang mereproduksi
     * `veff` di ketiga titik — 5,376144439333908 / 60,620109476367105 /
     * 168,2349490679152. Diseragamin ke 200, ketiganya meleset.
     */
    public const VI_TEMPERATURE = 50;

    /** Derajat kebebasan sertifikat kalibrator — sama kayak lima alat lain. */
    public const VI_KALIBRATOR = 200;

    /**
     * Pembagi koefisien sensitivitas suhu. Rumus rumah lab, bukan angka khusus
     * alat ini: `ci = (UTemperature / 400) × nilai nominal titik`, sama persis
     * kayak Turbidimeter & Chlorine.
     */
    public const PEMBAGI_CI_SUHU = 400.0;

    /**
     * Persentase MPE, dipakai DUA KALI: 1 % dari Fullscale + 1 % dari pembacaan.
     * Diturunkan dari blok "MPE Check for Viscometer" di sheet `SERTIFIKAT`,
     * dan cocok di ketiga titik (4,141803174603175 / 22,079825806451613 /
     * 1921,8410806451611 cP).
     */
    public const PERSEN_MPE = 0.01;

    /**
     * Berapa desimal yang dicetak sertifikat.
     *
     * Di alat lain angka ini dibaca dari FORMAT SEL workbook masternya. Berkas
     * `.xlsm` Viscometer nggak ada di folder — cuma export CSV-nya, dan CSV
     * nggak nyimpen format sel. Jadi buktinya memang belum ada.
     *
     * Yang dipakai sementara: **dua desimal**, ikut aturan umum enam alat lain
     * buat alat beresolusi 0,1 cP. Hasilnya `93,88` / `910,29` / `61898,12` cP.
     * Yang dibulatkan CUMA bentuk cetaknya — seluruh rantai hitung (nilai
     * acuan, koreksi, `uc`, `veff`, `U95`, MPE) tetap jalan di presisi penuh
     * dan yang disimpan di `uncertainty_calculations` juga angka penuh.
     *
     * Kenapa bukan presisi penuh apa adanya: `93,87566510172147 cP` di
     * dokumen terakreditasi ngeklaim ketelitian empat belas desimal buat alat
     * yang resolusinya 0,1 cP. Itu salah baca sendiri, bukan kejujuran.
     *
     * Begitu lab ngirim `.xlsm` atau satu sertifikat viscometer yang sudah
     * tercetak, yang diganti ANGKA INI DOANG — rumusnya nggak tersentuh.
     * Permintaannya dicatat di `docs/pertanyaan-lab-viscometer.md`.
     */
    public const DESIMAL_SERTIFIKAT = 2;

    /**
     * Kenapa Spindle & RPM nggak jadi sel yang dipindai kamera.
     *
     * Kotaknya ADA di lembar cetak kita (kertas Rev.3 nggak punya) supaya
     * teknisi nulisnya sekalian waktu ngukur. Tapi dia nggak masuk grid sel
     * pindai, dan itu keputusan sadar: alfabet pembaca angka di pipeline ini
     * MURNI digit (`config('ocr.angka.whitelist')` = `0123456789,.-`), sementara
     * kode spindle bentuknya `HA7`, `CPE-51 or CPA-51Z`, `LV4 or 4B2`. Dipaksa
     * jadi sel pindai, hasilnya bukan "kadang salah" tapi "SELALU MERAH" —
     * teknisi tetap ngetik ulang, cuma sesudah nunggu proses pindai.
     *
     * Jadi dua-duanya diisi di layar, dari daftar pilihan tertutup ([TABEL_SMC]
     * & angka), supaya SMC yang masuk rumus MPE nggak pernah lahir dari salah
     * ketik. Rentang SMC 0,327 sampai 1280 — satu salah pilih menggeser
     * Fullscale ribuan kali.
     */
    public const SPINDLE_TIDAK_DIPINDAI = true;

    /**
     * Tiga titik kalibrasi tercetak. `nilai` = viskositas larutan pada
     * [SUHU_ACUAN] (`Tabel Pengaruh Temperature` baris 25 °C), `u_persen` =
     * ketidakpastian sertifikat larutan di baris yang sama.
     *
     * `nilai` di sini BUKAN titik yang kecetak di sertifikat — yang kecetak
     * hasil interpolasi pada suhu terukur. Yang ini nilai nominalnya, dan cuma
     * dipakai dua tempat: pengali `ci` suhu dan pengali `u_persen`. Lihat
     * [SUHU_ACUAN].
     *
     * `resolusi` 0,1 cP dari `INPUT DATA` ("Resolusi Alat : 0.1") — seragam di
     * ketiga titik di master, walaupun kertasnya nyediain kotak resolusi
     * per titik. Lihat [peringatanSesi] kalau sesi nyata ternyata beda.
     *
     * @var list<array{nilai: float, label: string, resolusi: float, desimal: int, u_persen: float, standar: list<string>, cmc_parameter: string}>
     */
    public const TITIK = [
        [
            'nilai' => 99.65,
            'label' => '100',
            'resolusi' => 0.1,
            'desimal' => 1,
            'u_persen' => 0.17,
            'standar' => ['Viscosity Standard Solution 100 cP', '1241202088'],
            'cmc_parameter' => 'viskositas (cP)-Std 100 cP',
        ],
        [
            'nilai' => 1018.0,
            'label' => '1000',
            'resolusi' => 0.1,
            'desimal' => 1,
            'u_persen' => 0.23,
            'standar' => ['Viscosity Standard Solution 1000 cP', '1252905118'],
            'cmc_parameter' => 'viskositas (cP)-Std 1000 cP',
        ],
        [
            'nilai' => 59003.0,
            'label' => '60000',
            'resolusi' => 0.1,
            'desimal' => 1,
            'u_persen' => 0.23,
            'standar' => ['Viscosity Standard Solution 60000 cP', '4230901097'],
            'cmc_parameter' => 'viskositas (cP)-Std 60000 cP',
        ],
    ];

    /**
     * Tabel D-2 Brookfield (`MPE Visco.csv`) — model alat → TK (Torque
     * Constant). Dua kolom modelnya dua-duanya tercetak di kertas: yang di
     * BADAN alat dan yang di LAYAR. Teknisi milih satu baris; dua-duanya
     * ditampilkan biar dia bisa cocokin dari mana pun yang kebaca.
     *
     * @var list<array{badan: string, layar: string, tk: float}>
     */
    public const TABEL_TK = [
        ['badan' => 'DV2TLV', 'layar' => 'LV', 'tk' => 0.09373],
        ['badan' => '2.5DV2TLV', 'layar' => 'L3', 'tk' => 0.2343],
        ['badan' => '5DV2TLV', 'layar' => 'L5', 'tk' => 0.4686],
        ['badan' => '1/4DV2TRV', 'layar' => 'RQ', 'tk' => 0.25],
        ['badan' => '1/2DV2TRV', 'layar' => 'RH', 'tk' => 0.5],
        ['badan' => 'DV2TRV', 'layar' => 'RV', 'tk' => 1.0],
        ['badan' => 'DV2THA', 'layar' => 'HA', 'tk' => 2.0],
        ['badan' => '2DV2THA', 'layar' => 'A2', 'tk' => 4.0],
        ['badan' => '2.5DV2THA', 'layar' => 'A3', 'tk' => 5.0],
        ['badan' => 'DV2THB', 'layar' => 'HB', 'tk' => 8.0],
        ['badan' => '2DV2THB', 'layar' => 'B3', 'tk' => 16.0],
        ['badan' => '2.5DV2THB', 'layar' => 'B5', 'tk' => 20.0],
    ];

    /**
     * Tabel D-1 Brookfield (`MPE Visco.csv`) — spindle → SMC (Spindle
     * Multiplier Constant), dikelompokkan seperti di kertas.
     *
     * Ditulis lengkap 63 baris, bukan cuma tiga yang kepakai sesi contoh:
     * daftarnya yang tercetak di lembar kerja, dan teknisi milih dari situ.
     *
     * @var list<array{spindle: string, smc: float, grup: string}>
     */
    public const TABEL_SMC = [
        ['spindle' => 'RV1', 'smc' => 1.0, 'grup' => 'RV'],
        ['spindle' => 'RV2', 'smc' => 4.0, 'grup' => 'RV'],
        ['spindle' => 'RV3', 'smc' => 10.0, 'grup' => 'RV'],
        ['spindle' => 'RV4', 'smc' => 20.0, 'grup' => 'RV'],
        ['spindle' => 'RV5', 'smc' => 40.0, 'grup' => 'RV'],
        ['spindle' => 'RV6', 'smc' => 100.0, 'grup' => 'RV'],
        ['spindle' => 'RV7', 'smc' => 400.0, 'grup' => 'RV'],
        ['spindle' => 'HA1', 'smc' => 1.0, 'grup' => 'HA'],
        ['spindle' => 'HA2', 'smc' => 4.0, 'grup' => 'HA'],
        ['spindle' => 'HA3', 'smc' => 10.0, 'grup' => 'HA'],
        ['spindle' => 'HA4', 'smc' => 20.0, 'grup' => 'HA'],
        ['spindle' => 'HA5', 'smc' => 40.0, 'grup' => 'HA'],
        ['spindle' => 'HA6', 'smc' => 100.0, 'grup' => 'HA'],
        ['spindle' => 'HA7', 'smc' => 400.0, 'grup' => 'HA'],
        ['spindle' => 'HB1', 'smc' => 1.0, 'grup' => 'HB'],
        ['spindle' => 'HB2', 'smc' => 4.0, 'grup' => 'HB'],
        ['spindle' => 'HB3', 'smc' => 10.0, 'grup' => 'HB'],
        ['spindle' => 'HB4', 'smc' => 20.0, 'grup' => 'HB'],
        ['spindle' => 'HB5', 'smc' => 40.0, 'grup' => 'HB'],
        ['spindle' => 'HB6', 'smc' => 100.0, 'grup' => 'HB'],
        ['spindle' => 'HB7', 'smc' => 400.0, 'grup' => 'HB'],
        ['spindle' => 'LV1', 'smc' => 6.4, 'grup' => 'LV'],
        ['spindle' => 'LV2', 'smc' => 32.0, 'grup' => 'LV'],
        ['spindle' => 'LV3', 'smc' => 128.0, 'grup' => 'LV'],
        ['spindle' => 'LV4 or 4B2', 'smc' => 640.0, 'grup' => 'LV'],
        ['spindle' => 'LV5', 'smc' => 1280.0, 'grup' => 'LV'],
        ['spindle' => 'LV-2C', 'smc' => 32.0, 'grup' => 'LV'],
        ['spindle' => 'LV-3C', 'smc' => 128.0, 'grup' => 'LV'],
        ['spindle' => 'SPIRAL', 'smc' => 105.0, 'grup' => 'SPIRAL'],
        ['spindle' => 'T-A', 'smc' => 20.0, 'grup' => 'T'],
        ['spindle' => 'T-B', 'smc' => 40.0, 'grup' => 'T'],
        ['spindle' => 'T-C', 'smc' => 100.0, 'grup' => 'T'],
        ['spindle' => 'T-D', 'smc' => 200.0, 'grup' => 'T'],
        ['spindle' => 'T-E', 'smc' => 500.0, 'grup' => 'T'],
        ['spindle' => 'T-F', 'smc' => 1000.0, 'grup' => 'T'],
        ['spindle' => 'ULA', 'smc' => 0.64, 'grup' => 'ULA'],
        ['spindle' => 'DIN-81', 'smc' => 3.7, 'grup' => 'DIN'],
        ['spindle' => 'DIN-82', 'smc' => 3.75, 'grup' => 'DIN'],
        ['spindle' => 'DIN-83', 'smc' => 12.09, 'grup' => 'DIN'],
        ['spindle' => 'DIN-85', 'smc' => 1.22, 'grup' => 'DIN'],
        ['spindle' => 'DIN-86', 'smc' => 3.65, 'grup' => 'DIN'],
        ['spindle' => 'DIN-87', 'smc' => 12.13, 'grup' => 'DIN'],
        ['spindle' => 'SC4-14', 'smc' => 125.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-15', 'smc' => 50.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-16', 'smc' => 128.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-18', 'smc' => 3.2, 'grup' => 'SC4'],
        ['spindle' => 'SC4-21', 'smc' => 5.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-25', 'smc' => 512.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-27', 'smc' => 25.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-28', 'smc' => 50.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-29', 'smc' => 100.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-31', 'smc' => 32.0, 'grup' => 'SC4'],
        ['spindle' => 'SC4-34', 'smc' => 64.0, 'grup' => 'SC4'],
        ['spindle' => 'CPE-40 or CPA-40Z', 'smc' => 0.327, 'grup' => 'CPE/CPA'],
        ['spindle' => 'CPE-41 or CPA-41Z', 'smc' => 1.228, 'grup' => 'CPE/CPA'],
        ['spindle' => 'CPE-42 or CPA-42Z', 'smc' => 0.64, 'grup' => 'CPE/CPA'],
        ['spindle' => 'CPE-51 or CPA-51Z', 'smc' => 5.178, 'grup' => 'CPE/CPA'],
        ['spindle' => 'CPE-52 or CPA-52Z', 'smc' => 9.922, 'grup' => 'CPE/CPA'],
        ['spindle' => 'V-71', 'smc' => 2.62, 'grup' => 'V'],
        ['spindle' => 'V-72', 'smc' => 11.1, 'grup' => 'V'],
        ['spindle' => 'V-73', 'smc' => 53.5, 'grup' => 'V'],
        ['spindle' => 'V-74', 'smc' => 543.0, 'grup' => 'V'],
        ['spindle' => 'V-75', 'smc' => 213.0, 'grup' => 'V'],
    ];

    /**
     * Baris tabel STANDARD di lembar kerja. Tiga larutan + termometer & sensor
     * standar (`DATABASE` R13:Z17). Kertas Rev.3 nulis larutan 100 cP dua kali
     * lalu 30000 cP — lihat "Cetakan Rev.3 vs master" di dokumentasi kelas.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Viscosity Standard Solution 100 cP', 'cocok' => ['Viscosity Standard Solution 100 cP', '1241202088']],
        ['label' => 'Viscosity Standard Solution 1000 cP', 'cocok' => ['Viscosity Standard Solution 1000 cP', '1252905118']],
        ['label' => 'Viscosity Standard Solution 60000 cP', 'cocok' => ['Viscosity Standard Solution 60000 cP', '4230901097']],
        ['label' => 'Termometer & Sensor Std.', 'cocok' => ['Termometer & Sensor Std.', '23P1005', 'SH1/20']],
    ];

    /**
     * SEMUA unit thermohygro lab, dikelompokkan Insitu vs Inlab. Cetakan Rev.3
     * cuma NYETAK empat kotak — TH-2, TH-6, TH-7 (Insitu) & TH-4 (Inlab) —
     * dan itu ditandai `di_kertas`, BUKAN dipakai buat mempersempit daftarnya.
     *
     * Mempersempit persis itu yang pernah dicoba dan gagal: sertifikat pH
     * master `012-CAL-524` memakai TH-3, TH-3 nggak ada di daftar yang
     * dipersempit, dan Env. Condition tiga alat meleset dari master pada 10 Agt
     * 2026 — bukan karena salah hitung, tapi karena tabel koreksi unit yang
     * salah. Yang tau unit mana yang beneran dibawa itu teknisinya.
     *
     * @var list<array{label: string, grup: string, di_kertas: bool}>
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-1', 'grup' => 'Inlab', 'di_kertas' => false],
        ['label' => 'TH-3', 'grup' => 'Inlab', 'di_kertas' => false],
        ['label' => 'TH-4', 'grup' => 'Inlab', 'di_kertas' => true],
        ['label' => 'TH-5', 'grup' => 'Inlab', 'di_kertas' => false],
        ['label' => 'TH-7', 'grup' => 'Insitu', 'di_kertas' => true],
        ['label' => 'TH-2', 'grup' => 'Insitu', 'di_kertas' => true],
        ['label' => 'TH-6', 'grup' => 'Insitu', 'di_kertas' => true],
    ];

    public function kode(): string
    {
        return 'viscometer';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Viscometer';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_VISCOMETER;
    }

    public function besaran(): string
    {
        return 'viskositas';
    }

    /**
     * Larutan standar viskositas PUNYA kurva suhu — bedanya dari buffer pH cuma
     * bentuk datanya (tabel sertifikat, bukan polinom kuadratik). Dibiarin
     * `true` supaya `CalibrationValidator` beneran mperingatin kalau
     * `koefisien_suhu` standarnya belum keisi: tanpa tabel itu, suhu yang
     * susah-susah dicatat teknisi kebuang dan nilai acuannya jatuh ke nominal
     * botol.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return true;
    }

    /**
     * Alat ini DIVONIS, tapi batasnya nggak datang dari `equipments.toleransi`
     * — dia dihitung per titik lewat [toleransiTitik]. `true` di sini bikin
     * validator tetap nganggep vonis itu wajib ada; yang nyediain angkanya
     * profil ini, bukan kolom master alat.
     */
    public function punyaToleransi(): bool
    {
        return true;
    }

    /**
     * Batasnya MPE per titik, bukan satu angka di `equipments.toleransi` —
     * lihat `toleransiTitik()`. Kolom alatnya sengaja NULL.
     */
    public function toleransiDariKolomAlat(): bool
    {
        return false;
    }

    /** Lihat [DESIMAL_SERTIFIKAT] — dua desimal, menunggu `.xlsm` dari lab. */
    public function desimalSertifikat(): ?int
    {
        return self::DESIMAL_SERTIFIKAT;
    }

    /**
     * Tiap sel di lembar ini isinya SEPASANG angka (pembacaan cP + suhu larutan
     * °C), dan standarnya berdiri sebagai judul baris dengan Repeat 1..5
     * berjajar ke kanan — bentuk yang sama persis kayak lembar pH &
     * Turbidimeter. Bukan bentuk spektro.
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => true, 'standar_di_baris' => false];
    }

    /**
     * Resolusi seragam 0,1 cP di ketiga titik (master `INPUT DATA`), jadi balik
     * `null` dan pemakainya jatuh ke `equipments.resolusi`. Kertas Rev.3
     * nyediain kotak resolusi per titik ("* Resolusi tuliskan pada masing-masing
     * titik kalibrasi"); yang diisi teknisi ke situ dicatat di
     * `spesifikasi_alat` dan dibandingin di [peringatanSesi] — bukan diam-diam
     * dipakai buat ngitung sebelum lab mastiin ada alat yang beneran begitu.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return null;
    }

    /**
     * Jangkauan tabel sertifikat larutan pada suhu kerja 20-37,78 °C, per
     * titik. Ini yang jadi pita sel pindai, bukan nominal ±10 %.
     *
     * Lihat `CalibrationProfile::pitaPembacaan()` soal kenapa aturan umumnya
     * nggak bisa dipakai di sini.
     */
    public const JANGKAUAN_LARUTAN = [
        // Sengaja LIST, bukan map bernomor: kunci array PHP nggak bisa float —
        // `99.65 => …` diam-diam jadi `99 => …` dan barisnya nggak pernah
        // ketemu, sementara dua baris lain (1018, 59003) yang kebetulan bulat
        // tetap jalan. Setengah fitur yang kelihatan hidup.
        ['nominal' => 99.65, 'min' => 51.1, 'maks' => 134.0],
        ['nominal' => 1018.0, 'min' => 419.5, 'maks' => 1504.0],
        ['nominal' => 59003.0, 'min' => 19259.0, 'maks' => 95192.0],
    ];

    /**
     * Kelonggaran di luar jangkauan larutan, buat alat yang MEMANG meleset.
     *
     * Yang dibaca sel itu pembacaan UUT, bukan nilai larutannya: alat yang
     * belum di-adjust boleh jauh dari acuan. 20 % dipilih karena masih 5x
     * lebih ketat dari kesalahan yang mau ditangkap penjaga ini — geseran
     * titik desimal, yang selalu 10x.
     */
    public const KELONGGARAN_PITA = 1.2;

    /**
     * Pita per baris, dari jangkauan larutan × kelonggaran.
     *
     * Penjaga rasio ikut dilonggarkan ke 0,25-2,0×: batas bawah baris 60000 cP
     * (16049 cP) itu 0,27× nominalnya, jadi rasio bawaan 0,5 bakal nolak
     * pembacaan sah di suhu tinggi sebelum pitanya sempat dipakai. 10x tetap
     * ketangkep — 631813 itu 10,7× dan 6318 itu 0,107×.
     */
    public function pitaPembacaan(float $titikUkur): ?array
    {
        foreach (self::JANGKAUAN_LARUTAN as $j) {
            if (abs($titikUkur - $j['nominal']) > 1e-6) {
                continue;
            }

            return [
                'min' => $j['min'] / self::KELONGGARAN_PITA,
                'maks' => $j['maks'] * self::KELONGGARAN_PITA,
                'rasio_min' => 0.25,
                'rasio_maks' => 2.0,
            ];
        }

        return null;
    }

    /**
     * Tiap titik cuma boleh diisi pakai larutannya sendiri — 60000 cP nggak
     * pernah dibaca pakai botol 100 cP.
     *
     * Toleransi pencocokannya relatif ([TOLERANSI_PASANGAN_TITIK], 2 %), dan
     * di sini itu MASIH sempit: jarak antar titik nominal 99,65 → 1018 → 59003
     * itu sepuluh kali lipat, sementara jendelanya cuma ±2 %.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return array_map(
            static fn (array $t): array => ['titik' => $t['nilai'], 'standar' => $t['standar']],
            self::TITIK,
        );
    }

    /**
     * Budget 4 komponen Viscometer buat satu titik.
     *
     * Balik `null` kalau `u_temperature` belum keisi di baris CMC-nya — jatuh ke
     * jalur CMC lama, bukan ngarang komponen suhu dari angka yang nggak ada.
     *
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>|null
     */
    public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
    ): ?array {
        if ($kemampuan->u_temperature === null) {
            return null;
        }

        // Nilai NOMINAL @25 °C, bukan `$titikUkur` yang sudah tergeser suhu.
        //
        // `$titikUkur` yang nyampe ke sini sudah dilewatin
        // `Standard::nilaiPadaSuhu()` sama `GumCalculator::hitungTitik()`, jadi
        // isinya 93,87566510172147 — bukan 99,65. Dipakai di dua komponen di
        // bawah, ci suhu dan U kalibrator meleset ~6 % dan ketiga U95 nggak
        // cocok lagi sama master.
        $nominal = $this->nominalTitik($titikUkur, $standard);

        if ($nominal === null) {
            return null;
        }

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;
        $uTemperature = (float) $kemampuan->u_temperature;
        $resolusi = (float) ($equipment->resolusi ?: self::TITIK[0]['resolusi']);

        // U95 sertifikat kalibrator = persen sertifikat × nilai nominalnya.
        // Diambil dari `standards.ketidakpastian` kalau sudah keisi absolut
        // (jalur normal, diisi seeder), dan dihitung dari tabel sertifikat
        // kalau belum — biar standar yang baru diinput lab lewat panel nggak
        // diam-diam jatuh ke nol.
        $uStandar = $standard->ketidakpastian !== null
            ? (float) $standard->ketidakpastian
            : $this->uStandarDariTabel($standard, $nominal);

        if ($uStandar === null) {
            return null;
        }

        // Rumus rumah lab, sama persis kayak Turbidimeter & Chlorine —
        // bedanya cuma pengalinya nilai NOMINAL, bukan titik tergeser.
        $ciSuhu = ($uTemperature / self::PEMBAGI_CI_SUHU) * $nominal;

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U=%s %s, k=%s) pada %s °C',
                    $standard->nama, $uStandar, self::SATUAN, $kStandar, self::SUHU_ACUAN,
                ),
                'distribusi' => 'normal',
                'u' => $uStandar / $kStandar,
                'ci' => 1.0,
                'vi' => self::VI_KALIBRATOR,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s', $resolusi, self::SATUAN),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
            ],
            [
                'sumber' => 'ketidakpastian_temperature',
                'keterangan' => sprintf(
                    'UTemperature %s °C (÷√3), ci (%s/%s)·%s',
                    $uTemperature, $uTemperature, self::PEMBAGI_CI_SUHU, $nominal,
                ),
                'distribusi' => 'persegi',
                'u' => $uTemperature / $sqrt3,
                'ci' => $ciSuhu,
                'vi' => self::VI_TEMPERATURE,
            ],
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan (Type A)', $n),
                'distribusi' => 't-student',
                'u' => $typeA,
                'ci' => 1.0,
                'vi' => max($n - 1, 1),
            ],
        ];
    }

    /**
     * Batas keberterimaan titik ini = MPE Brookfield. `null` kalau spindle atau
     * RPM-nya belum keisi — tanpa dua itu Fullscale nggak punya arti, dan
     * ngarang angka toleransi berarti ngarang vonis PASS/FAIL.
     *
     * @param  array<string, mixed>  $konteks  `spindle`, `rpm`, `tk` dari baris pembacaan & sesi
     */
    public function toleransiTitik(
        float $titikUkur,
        float $rataRata,
        Equipment $equipment,
        array $konteks = [],
    ): ?float {
        $fullscale = $this->fullscale(
            $konteks['tk'] ?? null,
            $konteks['spindle'] ?? null,
            $konteks['rpm'] ?? null,
        );

        if ($fullscale === null) {
            return null;
        }

        return self::PERSEN_MPE * $fullscale + self::PERSEN_MPE * abs($rataRata);
    }

    /**
     * `Fullscale = TK × SMC × 10000 / RPM`, satuan cP.
     *
     * Publik karena dua pemakai di luar jalur hitung: test yang ngadu ke blok
     * "MPE Check" master, dan layar yang mau nampilin Fullscale-nya ke teknisi
     * biar dia bisa lihat spindle-nya kekecilan atau nggak.
     *
     * @param  float|string|null  $tk  Torque Constant, atau kode model ([TABEL_TK])
     * @param  string|null  $spindle  kode spindle ([TABEL_SMC])
     */
    public function fullscale(float|string|null $tk, ?string $spindle, float|int|null $rpm): ?float
    {
        $nilaiTk = is_string($tk) ? $this->tkModel($tk) : ($tk === null ? null : (float) $tk);
        $smc = $spindle === null ? null : $this->smcSpindle($spindle);
        $rpm = $rpm === null ? null : (float) $rpm;

        if ($nilaiTk === null || $smc === null || $rpm === null || $rpm <= 0.0) {
            return null;
        }

        return $nilaiTk * $smc * 10000.0 / $rpm;
    }

    /** TK dari kode model, dicocokin ke nama di BADAN maupun di LAYAR. */
    public function tkModel(string $model): ?float
    {
        $cari = mb_strtolower(trim($model));

        foreach (self::TABEL_TK as $baris) {
            if (mb_strtolower($baris['badan']) === $cari || mb_strtolower($baris['layar']) === $cari) {
                return $baris['tk'];
            }
        }

        return null;
    }

    /** SMC dari kode spindle. */
    public function smcSpindle(string $spindle): ?float
    {
        $cari = mb_strtolower(trim($spindle));

        foreach (self::TABEL_SMC as $baris) {
            if (mb_strtolower($baris['spindle']) === $cari) {
                return $baris['smc'];
            }
        }

        return null;
    }

    /**
     * Peringatan khas Viscometer buat satu sesi. Semuanya tingkat PERINGATAN:
     * hal yang MUNGKIN benar tapi pantas dilihat mata admin — bukan yang pasti
     * salah.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $temuan = [];

        $spesifikasi = is_array($sesi->spesifikasi_alat) ? $sesi->spesifikasi_alat : [];
        $model = $spesifikasi['model_visco'] ?? null;

        if ($model === null || $this->tkModel((string) $model) === null) {
            $temuan[] = [
                'kode' => 'model_visco_belum_dipilih',
                'pesan' => 'Model viscometer (kolom "Mohon pilih salah satu tipe visco") belum dipilih '
                    .'atau nggak dikenal, jadi TK-nya nggak ketemu dan MPE nggak bisa dihitung. '
                    .'Tanpa MPE, sertifikatnya terbit tanpa vonis PASS/FAIL.',
            ];
        }

        $tanpaSpindle = [];
        $suhuDiLuarTabel = [];
        $resolusiBeda = [];

        foreach ($sesi->rawMeasurements as $baris) {
            $titikKe = (int) $baris->titik_ke;

            if ($baris->spindle === null || $baris->rpm === null) {
                $tanpaSpindle[$titikKe] = $titikKe;
            }
        }

        foreach ($this->suhuRataRataPerTitik($sesi) as $titikKe => $data) {
            if ($data['suhu'] === null || $data['standard'] === null) {
                continue;
            }

            if ($data['standard']->nilaiPadaSuhu($data['suhu']) === null) {
                $suhuDiLuarTabel[$titikKe] = $data['suhu'];
            }
        }

        $resolusiAlat = $sesi->equipment?->resolusi === null ? null : (float) $sesi->equipment->resolusi;

        foreach (self::TITIK as $i => $t) {
            $ditulis = $spesifikasi['resolusi_titik_'.($i + 1)] ?? null;

            if ($ditulis === null || $resolusiAlat === null) {
                continue;
            }

            if (abs((float) $ditulis - $resolusiAlat) > 1e-9) {
                $resolusiBeda[] = sprintf('titik %s cP ditulis %s', $t['label'], $ditulis);
            }
        }

        if ($tanpaSpindle !== []) {
            $temuan[] = [
                'kode' => 'spindle_rpm_kosong',
                'pesan' => sprintf(
                    'Spindle atau RPM belum diisi di titik ke-%s. Fullscale = TK × SMC × 10000 / RPM, '
                    .'jadi tanpa dua angka itu MPE titik tersebut nggak dihitung dan vonisnya kosong.',
                    implode(', ', $tanpaSpindle),
                ),
            ];
        }

        if ($suhuDiLuarTabel !== []) {
            $pesan = [];
            foreach ($suhuDiLuarTabel as $titikKe => $suhu) {
                $pesan[] = sprintf('titik ke-%d pada %s °C', $titikKe, $suhu);
            }

            $temuan[] = [
                'kode' => 'suhu_di_luar_tabel_standar',
                'pesan' => sprintf(
                    'Suhu larutan di luar jangkauan tabel sertifikat standarnya (%s), jadi nilai acuannya '
                    .'NGGAK digeser dan yang dipakai nilai nominal botol. Ekstrapolasi sengaja nggak '
                    .'dilakukan — kurva viskositas melengkung tajam, kemiringan satu ruas nggak berlaku '
                    .'di ruas sebelahnya.',
                    implode(', ', $pesan),
                ),
            ];
        }

        if ($resolusiBeda !== []) {
            $temuan[] = [
                'kode' => 'resolusi_titik_beda_dari_master_alat',
                'pesan' => sprintf(
                    'Resolusi yang ditulis teknisi per titik beda dari resolusi alat di master (%s cP): %s. '
                    .'Yang dipakai ngitung tetap resolusi master alat — kalau yang benar yang di kertas, '
                    .'betulin dulu data alatnya sebelum approve.',
                    $resolusiAlat, implode('; ', $resolusiBeda),
                ),
            ];
        }

        return $temuan;
    }

    /**
     * Nilai NOMINAL @[SUHU_ACUAN] buat satu titik.
     *
     * Dicari dua arah, dan urutannya penting: tabel sertifikat standarnya dulu
     * (itu data lab yang beneran), baru [TITIK] sebagai cadangan. Kalau
     * standarnya diganti lot baru, nilai barunya kebawa tanpa harus ngedit
     * konstanta di file ini.
     */
    private function nominalTitik(float $titikUkur, Standard $standard): ?float
    {
        $baris = $standard->barisTabelSuhu(self::SUHU_ACUAN);

        if ($baris !== null && isset($baris['nilai'])) {
            return $baris['nilai'];
        }

        // Cadangan: titik [TITIK] yang paling dekat SECARA RELATIF. Mutlak
        // nggak bisa dipakai — jarak 93,88 ke 99,65 itu 5,8, sementara jarak
        // 61898 ke 59003 itu 2895; ambang mutlak apa pun bakal salah di salah
        // satu ujung.
        $terdekat = null;
        $selisihTerkecil = null;

        foreach (self::TITIK as $t) {
            $selisih = abs($t['nilai'] - $titikUkur) / max($t['nilai'], 1e-9);

            if ($selisihTerkecil === null || $selisih < $selisihTerkecil) {
                $selisihTerkecil = $selisih;
                $terdekat = $t['nilai'];
            }
        }

        // 30 % — cukup lebar buat nampung geseran suhu terjauh yang masuk akal
        // (larutan 100 cP pada 37,78 °C = 51,1 cP itu −49 %, tapi kalibrasi
        // rutinnya di suhu ruang), dan masih jauh lebih sempit dari jarak
        // antar titik yang sepuluh kali lipat.
        return $selisihTerkecil !== null && $selisihTerkecil <= 0.3 ? $terdekat : null;
    }

    /**
     * U95 kalibrator dari tabel sertifikat (`u_persen` × nilai), buat standar
     * yang `ketidakpastian` absolutnya belum keisi.
     */
    private function uStandarDariTabel(Standard $standard, float $nominal): ?float
    {
        $baris = $standard->barisTabelSuhu(self::SUHU_ACUAN);

        if ($baris === null || ! isset($baris['u_persen'])) {
            return null;
        }

        return $baris['u_persen'] / 100.0 * $nominal;
    }

    /**
     * Rata-rata suhu larutan + standar acuan per titik, buat [peringatanSesi].
     *
     * @return array<int, array{suhu: float|null, standard: Standard|null}>
     */
    private function suhuRataRataPerTitik(CalibrationSession $sesi): array
    {
        $kumpul = [];

        foreach ($sesi->rawMeasurements as $baris) {
            $titikKe = (int) $baris->titik_ke;
            $kumpul[$titikKe]['standard'] ??= $baris->standard;

            if ($baris->suhu !== null) {
                $kumpul[$titikKe]['suhu'][] = (float) $baris->suhu;
            }
        }

        $hasil = [];

        foreach ($kumpul as $titikKe => $data) {
            $suhu = $data['suhu'] ?? [];

            $hasil[$titikKe] = [
                'suhu' => $suhu === [] ? null : array_sum($suhu) / count($suhu),
                'standard' => $data['standard'] ?? null,
            ];
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap();
        $bentuk = $this->tautkanStandar($bentuk);
        $bentuk = $this->tautkanStandarTitik($bentuk);
        $bentuk = $this->isiPilihanThermohygro($bentuk);

        if ($untukAdmin) {
            $bentuk['bagian'][] = $this->bagianAdmin();
            $bentuk['untuk'] = 'admin';

            return $bentuk;
        }

        $bentuk['untuk'] = 'teknisi';
        $bentuk['bagian'] = array_map(
            fn (array $bagian): array => [
                ...$bagian,
                'field' => array_values(array_filter(
                    $bagian['field'] ?? [],
                    fn (array $field): bool => ! $field['hanya_admin'],
                )),
            ],
            $bentuk['bagian'],
        );

        return $bentuk;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'judul' => 'Calibration Worksheet - Viscometer',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_map(static fn (array $t): float => $t['nilai'], self::TITIK),
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — lembar '
                .'kerja tetap bisa dikirim. Khusus alat ini: Spindle & RPM tiap titik ikut nentuin batas '
                .'keberterimaan (MPE), jadi titik yang dua kolom itu kosong tetap dihitung U95%-nya tapi '
                .'nggak dapat vonis PASS/FAIL.',
            'bagian' => [
                [
                    'kode' => 'identitas_alat',
                    'halaman' => 1,
                    'judul' => 'General Information',
                    'field' => [
                        $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                        $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', 'Equipment Name', 'teks', sumber: 'otomatis'),
                        $this->field('alat_merk', 'Manufacturer', 'teks'),
                        $this->field('alat_model', 'Type', 'teks'),
                        $this->field('alat_serial_number', 'SN', 'teks'),
                        $this->field('spesifikasi_alat.rentang_ukur', 'Range', 'teks', satuan: self::SATUAN),
                        // Resolusi ditulis PER TITIK di kertas ("* Resolusi
                        // tuliskan pada masing-masing titik kalibrasi"), jadi
                        // kotak di kepala lembar ini cuma ringkasan. Yang
                        // dipakai ngitung `equipments.resolusi`; kalau ketiganya
                        // beda, `peringatanSesi()` yang ngasih tau.
                        $this->field('spesifikasi_alat.resolusi', 'Resolution', 'teks', satuan: self::SATUAN),
                        $this->field(
                            'thermohygro_standard_id',
                            'Thermohygro used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                    ],
                ],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'OWNER',
                    'field' => [
                        $this->field('pemilik_nama', 'Customer', 'teks'),
                        $this->field('pemilik_alamat', 'Address', 'teks_panjang'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'Standard Used',
                    'baris' => self::STANDARD_TERCETAK,
                    'field' => [
                        $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                        $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                    ],
                ],
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION DATA',
                    'field' => [
                        $this->field('lokasi', 'Location of Calibration', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'In lab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('lokasi_nama', 'Nama Lokasi (kalau Insitu)', 'teks'),
                        $this->field('teknisi.kode', 'Technician ID', 'teks', sumber: 'otomatis'),
                        $this->field('room_id', 'Ruangan', 'pilihan', sumber: 'master_ruangan'),
                        // Tercetak di kertas sebagai "Methode : SIDIK-IK-CAL-0517"
                        // — teknisi melihatnya, tapi yang MEMILIH baris master
                        // metodenya admin.
                        $this->field(
                            'calibration_method_id',
                            'Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'model_visco',
                    'halaman' => 1,
                    'judul' => 'Model Viscometer (menentukan TK)',
                    'catatan' => 'Pilih satu tipe visco. Nilai TK dari tabel ini masuk langsung ke '
                        .'rumus Fullscale = TK × SMC × 10000 / RPM, jadi salah pilih menggeser MPE.',
                    'field' => [
                        $this->field(
                            'spesifikasi_alat.model_visco',
                            // Kertas Rev.3 nulis 'MODEL (on body) / MODEL (on screen
                            // display)'. Di lembar cetak label sepanjang itu kepotong di
                            // tengah — kotak labelnya 43 mm, tulisan 8 pt cuma muat ~30
                            // huruf. Judul bagiannya udah nyebut TK, jadi yang perlu
                            // dibedain cuma badan vs layar.
                            'Model — badan / layar',
                            'pilihan',
                            pilihan: array_map(
                                static fn (array $m): array => [
                                    'nilai' => $m['badan'],
                                    'label' => sprintf('%s / %s (TK %s)', $m['badan'], $m['layar'], $m['tk']),
                                ],
                                self::TABEL_TK,
                            ),
                        ),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 1,
                    'judul' => 'Data Result',
                    'field' => [
                        $this->field('suhu_awal', 'T awal', 'angka', satuan: '°C'),
                        $this->field('suhu_akhir', 'T akhir', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'RH awal', 'angka', satuan: '%RH'),
                        $this->field('kelembaban_akhir', 'RH akhir', 'angka', satuan: '%RH'),
                        // Spindle, RPM, dan resolusi PER TITIK.
                        //
                        // Ditulis sebagai field bernomor (`_titik_1..3`), BUKAN
                        // lewat idiom `measurements.*` kayak `standar_dicek.*`.
                        // Alasannya bukan selera: `TataLetakLembar::isian()`
                        // membuang field yang kodenya mengandung `*` — kepala
                        // lembar cetak itu satu baris per isian, dan wildcard
                        // nggak punya baris. Dipakai `*`, ketiga kotak ini
                        // NGGAK KEGAMBAR di kertas sama sekali, dan itu persis
                        // yang mau dihindari: MPE butuh SMC & RPM per titik,
                        // dan teknisi nggak bisa nulis apa yang nggak ada
                        // kotaknya.
                        //
                        // Yang DISIMPAN tetap per baris pembacaan
                        // (`raw_measurements.spindle`/`rpm`) — lihat migrasi
                        // 2026_08_18_100000. Isian di sini jalur cadangan yang
                        // dibaca `CalibrationController` kalau HP ngirimnya
                        // lewat `spesifikasi_alat`, bukan per baris.
                        //
                        // Sengaja BUKAN sel pindai; lihat [SPINDLE_TIDAK_DIPINDAI].
                        ...$this->fieldPerTitik(),
                    ],
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before Adjustment'),
                        $this->tabelHasil('sesudah_adjustment', 'After Adjustment'),
                    ],
                ],
                [
                    // SENGAJA tanpa field input. Lihat "Cetakan Rev.3 vs master"
                    // di dokumentasi kelas: seluruh budget blok ini `#DIV/0!` di
                    // master, jadi nggak ada angka yang sah buat diisi.
                    'kode' => 'standar_30000',
                    'halaman' => 1,
                    'judul' => 'Larutan Std Visco 30000 cP',
                    'status' => 'sumber_belum_ada',
                    'di_kertas' => true,
                    'catatan' => 'Tercetak di lembar Rev.3 tapi belum diimplementasi. Di master, seluruh '
                        .'budget titik ini `#DIV/0!` (PERHITUNGAN U95% blok keempat) dan larutannya sudah '
                        .'diganti 1000 cP menurut FORM VALIDASI rev.18 (18 Mei 2026). Backend nggak nyetak '
                        .'angka titik ini sampai lab nyediakan lembar sumber yang sah.',
                    'field' => [],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 1,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                        $this->field('reviewer.nama', 'Corrected by', 'teks', sumber: 'otomatis'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bagianAdmin(): array
    {
        return [
            'kode' => 'administratif',
            'halaman' => 1,
            'judul' => 'Data Administratif (Admin)',
            'field' => [
                $this->field('nomor_order', 'Order Number', 'teks', hanyaAdmin: true),
                $this->field('certificate.nomor', 'Certificate Number', 'teks', sumber: 'otomatis', hanyaAdmin: true),
                $this->field('suhu_ketidakpastian', 'U95% Suhu', 'angka', sumber: 'otomatis', satuan: '°C', hanyaAdmin: true),
                $this->field('kelembaban_ketidakpastian', 'U95% Kelembaban', 'angka', sumber: 'otomatis', satuan: '%RH', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * Satu tabel hasil: baris = titik standar, kolom = pembacaan cP + suhu
     * larutan °C, Repeat 1..5.
     *
     * Bentuknya DUA tabel (Before / After) berisi tiga baris — bukan ENAM blok
     * terpisah kayak cetakan Rev.3. Isinya sama persis; yang beda cuma
     * tata letak, dan yang dipakai tata letak rumah karena: (a) enam lembar
     * lain sudah begitu, jadi teknisi nggak perlu belajar bentuk ketujuh;
     * (b) tiga baris dalam satu grid muat jauh lebih longgar di A4 daripada
     * enam blok bertumpuk, dan kotak yang lebih besar itu yang bikin tulisan
     * tangan kebaca kamera; (c) jalur cetak & pindai (`ocr:rangka-geometri`,
     * `ocr:cetak-lembar`) sudah dibentuk buat pola ini, jadi nggak ada kode
     * bersama yang perlu diubah cuma buat niru tata letak.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul): array
    {
        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'satuan' => self::SATUAN,
            'judul_nilai' => 'Standard',
            'judul_pengulangan' => 'UUT Reading',
            'baris' => array_map(
                static fn (array $t): array => [
                    // Nilai NOMINAL @25 °C. Yang kecetak di sertifikat nanti
                    // hasil interpolasi pada suhu terukur — beda peran, dan
                    // yang di lembar kerja memang nominalnya: itu yang tertulis
                    // di botol yang dipegang teknisi.
                    'titik_ukur' => $t['nilai'],
                    'label' => $t['label'],
                    'resolusi' => $t['resolusi'],
                    'desimal' => $t['desimal'],
                    'satuan' => self::SATUAN,
                ],
                self::TITIK,
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => self::SATUAN, 'tipe' => 'angka', 'satuan' => self::SATUAN],
                ['kode' => 'suhu', 'label' => '°C', 'tipe' => 'angka', 'satuan' => '°C'],
            ],
            'pengulangan' => range(1, self::JUMLAH_PENGULANGAN),
        ];
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` lab (nama/serial).
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNull('parameter_kondisi')
            ->get(['id', 'nama', 'serial_number', 'no_sertifikat', 'tertelusur_ke']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $master->first(fn (Standard $s): bool => collect($baris['cocok'])
                        ->contains(fn (string $kunci): bool => $s->nama === $kunci
                            || $s->serial_number === $kunci));

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }

    /**
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNotNull('parameter_kondisi')
            ->pluck('id', 'nama');

        $pilihan = [];

        foreach (self::THERMOHYGRO_TERCETAK as $unit) {
            $id = $master[$unit['label']] ?? null;

            if ($id === null) {
                continue;
            }

            $pilihan[] = [
                'nilai' => (string) $id,
                'label' => $unit['label'],
                'grup' => $unit['grup'],
                // false = unitnya sah dipilih, cuma kotaknya nggak digambar di
                // cetakan Rev.3. Layar boleh nandain, TAPI jangan nyembunyiin —
                // lihat [THERMOHYGRO_TERCETAK].
                'di_kertas' => $unit['di_kertas'],
            ];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if ($field['kode'] === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

        return $bentuk;
    }

    /**
     * Tiga isian per titik: Spindle, RPM, dan Resolusi UUT.
     *
     * Dibangkitkan dari [TITIK], bukan ditulis sembilan kali — kalau lab nambah
     * titik keempat, kotaknya ikut muncul tanpa ada yang perlu ingat.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldPerTitik(): array
    {
        $pilihanSpindle = array_map(
            static fn (array $s): array => [
                'nilai' => $s['spindle'],
                'label' => sprintf('%s (SMC %s)', $s['spindle'], $s['smc']),
                'grup' => $s['grup'],
            ],
            self::TABEL_SMC,
        );

        $field = [];

        foreach (self::TITIK as $i => $t) {
            $ke = $i + 1;

            $field[] = $this->field(
                "spesifikasi_alat.spindle_titik_{$ke}",
                sprintf('Spindle — %s cP', $t['label']),
                'pilihan',
                pilihan: $pilihanSpindle,
            );
            $field[] = $this->field(
                "spesifikasi_alat.rpm_titik_{$ke}",
                sprintf('RPM — %s cP', $t['label']),
                'angka',
                satuan: 'rpm',
            );
            $field[] = $this->field(
                "spesifikasi_alat.resolusi_titik_{$ke}",
                sprintf('Resolusi — %s cP', $t['label']),
                'angka',
                satuan: self::SATUAN,
            );
        }

        return $field;
    }

    /**
     * @param  list<array<string, mixed>>  $pilihan
     * @return array<string, mixed>
     */
    private function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
    ): array {
        return [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'wajib' => false,
            'sumber' => $sumber,
            'satuan' => $satuan,
            'pilihan' => $pilihan,
            'hanya_admin' => $hanyaAdmin,
        ];
    }

    /** Master Viscometer: `PERHITUNGAN!G14` format `General` → `25,02`. */
    public function desimalSuhuEnv(): ?int
    {
        return null;
    }

    /** Master Viscometer: `PERHITUNGAN!G15` format `General` → `56,5`. */
    public function desimalKelembabanEnv(): ?int
    {
        return null;
    }
}
