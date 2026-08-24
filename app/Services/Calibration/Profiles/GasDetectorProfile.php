<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Multi Gas Detector (alat ke-10). Metode `SIDIK-IK-CAL-0536_Rev.0`
 * (DATABASE baris 36), form sertifikat `SIDIK-FM-CAL-2403_Rev. 0`. Master
 * `Gas Detector Uli Skin (std Rigaz).xlsm` (LK-285-IDN), sesi asli
 * **001-CAL-226** (order 2602.03.A.NK, PT Unilever Indonesia Skin Care
 * Factory, Honeywell Microclip XL, 2 Februari 2026).
 *
 * ## EMPAT gas sekaligus, empat satuan berbeda
 *
 * Satu sesi menghasilkan EMPAT baris sertifikat, masing-masing satu jenis gas
 * dengan satuan & resolusi sendiri:
 *
 *   gas   rentang      resolusi   satuan   standar botol
 *   CO    0–1999       1          ppm      101 ppm
 *   H2S   0–200        1          ppm      25 ppm
 *   CH4   0–100        1          %LEL     50 %LEL  (= 2,5 % volume)
 *   O2    0–30         0,1        %        17,9 %
 *
 * Ini bukan "satu besaran banyak titik" kayak pH 4/7/10 — empat besaran yang
 * kebetulan diukur satu alat. Tapi bentuk datanya TETAP muat di "titik ukur +
 * pengulangan", jadi profil ini jalan lewat `GumCalculator` biasa, bukan
 * kalkulator sendiri kayak Autoklaf. Yang membedakan tiap baris cuma satuan,
 * resolusi, dan `remark`-nya, dan ketiganya sudah punya hook di kelas induk.
 *
 * ## Yang bikin budgetnya beda dari sembilan alat sebelumnya
 *
 * **1. Komponen suhu & tekanan pakai Δ, bukan U95 sertifikat.** Delapan alat
 * lain menyusun `u` komponen suhu dari ketidakpastian sertifikat termometer
 * standar (`UTemperature = √((U_term/2)² + (U_sensor/2)²)`). Master Gas
 * Detector tidak: `M12 = PERHITUNGAN!M19` dan `M19 = ABS(F19−E19)` — itu
 * PERGESERAN ruangan selama kerja, angka yang lahir dari sesi ini sendiri.
 * Sama untuk tekanan (`M13 = PERHITUNGAN!M21/10`, hPa → kPa).
 *
 * Karena `u`-nya datang dari sesi, bukan dari master alat, [komponenBudget]
 * membacanya lewat `$konteksTitik['delta_suhu']` & `['delta_tekanan']` —
 * parameter yang ditambahkan ke kelas induk khusus untuk ini. Tanpa Δ
 * lengkap (salah satu ujung kosong) profil balik `null`: jatuh ke jalur CMC,
 * bukan mengarang komponen bernilai nol yang diam-diam mengecilkan U95.
 *
 * **2. Ada komponen TEKANAN, dan itu alasan tekanan udara masuk database.**
 * Sensor elektrokimia membaca tekanan parsial gas, jadi konsentrasi yang
 * ditampilkan bergerak mengikuti tekanan barometrik. `ci = 0,98 % × titik`
 * (`W13 = 0.98%*C7`) — koefisien sensitivitas tekanan, pasangan dari
 * `ci = 0,34 % × titik` untuk suhu (`W12 = 0.34%*C7`). Dua angka itu
 * konstanta karakteristik sensor, dipakai identik di keempat blok gas.
 *
 * **3. Pembagi tekanan √5, bukan √3.** Master menandai distribusinya `rect.`
 * (yang seharusnya √3) tapi selnya `P13 = 5` dengan `T13 = M13/SQRT(P13)`.
 * Suhu di baris atasnya `P12 = SQRT(3)` dengan `T12 = M12/P12`, jadi ini
 * bukan salah baca kolom — dua baris bersebelahan memang dihitung beda.
 * Yang dipakai angka master; lihat [PEMBAGI_TEKANAN] dan
 * `docs/pertanyaan-lab-gas-detector.md`.
 *
 * **4. `k` t-student penuh, tanpa kunci.** `AB17 = TINV(0.05, AB16)` di
 * keempat blok — jalur bawaan `GumCalculator::agregasiBudget()`, jadi
 * [faktorCakupanTetap] sengaja tidak di-override. Bandingkan Viscometer yang
 * masternya mengunci 2.
 *
 * **5. Tanpa lantai CMC.** `DATABASE!S5:S8` (kolom CMC) kosong seluruhnya dan
 * sel `AB19` membacanya sebagai 0, jadi `MAX(U, 0) = U` di keempat gas. Lab
 * belum punya klaim terakreditasi untuk gas detector — `ketidakpastian_terbaik
 * = 0` di `GasDetectorCapabilitySeeder`, dan `GumCalculator` sudah menuliskan
 * itu apa adanya di jejak audit ("tanpa lantai CMC").
 *
 * ## Yang TIDAK divonis
 *
 * Master tidak punya satu pun sel yang membandingkan hasil ke batas
 * keberterimaan, dan sertifikatnya berhenti di kolom `U95%`. Sama seperti
 * Autoklaf & DO Meter: [punyaToleransi] `false`, `keputusan` sesi null.
 *
 * ## Tiga kejanggalan master yang TIDAK ditiru
 *
 *  1. **Label standar di `INPUT DATA` T24:T27 masih "pH 4 / pH 7 / pH 10 /
 *     thermo&sensor"** — sisa salin-tempel dari template pH. Isinya sendiri
 *     sudah benar (`Standar Gas Mixture (CO)` dst). Yang dipakai isinya.
 *  2. **Resolusi H2S & CH4 dikunci ke sel CO.** `M28`/`M46` menulis
 *     `$G$6*0.5` — `$G$6` itu resolusi CO, absolut. Blok O2 menulis relatif
 *     (`G60*0.5`) dan karena itu benar. Ketiga gas ppm/%LEL kebetulan
 *     beresolusi 1 jadi hasilnya sama di sesi ini, tapi begitu ada alat
 *     dengan resolusi H2S 0,1 angkanya salah tanpa ada yang tahu. Di sini
 *     resolusi dibaca per gas ([resolusiTitik]).
 *  3. **`k` di sertifikat diambil dari blok O2 saja.** `SERTIFIKAT!U28 =
 *     'PERHITUNGAN U95%'!AB71`, padahal keempat gas punya `k` sendiri
 *     (1,9715 / 2,0106 / 1,9744 / 1,9717). Di sini tiap baris membawa
 *     `k`-nya sendiri lewat jalur `faktor_cakupan_k` biasa — sama seperti
 *     alat lain yang U95-nya per titik.
 *
 * Budget dicocokkan ke sheet `PERHITUNGAN U95%` keempat gas di
 * `GasDetectorBudgetTest`.
 *
 * @see docs/pertanyaan-lab-gas-detector.md
 */
class GasDetectorProfile extends CalibrationProfile
{
    /**
     * Nomor formulir LEMBAR KERJA-nya belum ada.
     *
     * Sembilan alat lain punya nomornya tercetak di footer kertas dan disalin
     * ke sini. Workbook Gas Detector tidak memuatnya: sheet `FORM VALIDASI`-nya
     * masih salinan mentah template pH (baris 4 menulis "pH Meter"), dan
     * satu-satunya nomor formulir yang muncul di seluruh berkas
     * `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet `SERTIFIKAT` — itu formulir
     * SERTIFIKAT, dipakai bersama semua alat, bukan lembar kerjanya.
     *
     * Sengaja `null`, bukan ditebak. Nomor formulir itu dokumen terkendali;
     * mengarang satu nomor yang "kelihatan pas" (FM-CAL-0540 misalnya) berarti
     * menaruh nomor palsu di lembar kerja yang ikut dicetak dan diaudit —
     * kesalahan yang lebih mahal daripada kolom kosong yang jelas kosong.
     * Diminta ke lab di `docs/pertanyaan-lab-gas-detector.md`.
     */
    public const KODE_DOKUMEN = null;

    /**
     * Metode kalibrasi, `DATABASE!C77` baris 36 — dan ini yang benar-benar
     * tercetak di sertifikat (`SERTIFIKAT!T13`).
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0536_Rev.0';

    /**
     * TIGA pengulangan, bukan lima.
     *
     * `INPUT DATA` D38:D40 cuma punya tiga baris Repeat, dan budget-nya ikut:
     * `P9 = SQRT(3)` (pembagi √n) dengan `R9 = 3-1 = 2` (derajat kebebasan).
     * Dua sel itu saling mengonfirmasi, jadi ini memang tiga — bukan lima yang
     * dua barisnya kebetulan kosong.
     *
     * Konsekuensinya `vi` Type A cuma 2, dan itu yang bikin `v_eff` H2S jatuh
     * ke 48,56 (k = 2,0106, jauh dari 1,96). Wajar: tiga pembacaan memang
     * sedikit.
     */
    public const JUMLAH_PENGULANGAN = 3;

    /**
     * Koefisien sensitivitas suhu, `PERHITUNGAN U95%!W12 = 0.34%*C7`.
     * Dikali NILAI TITIK, jadi kontribusinya proporsional konsentrasi.
     */
    public const CI_PERSEN_SUHU = 0.0034;

    /** Koefisien sensitivitas tekanan, `W13 = 0.98%*C7`. */
    public const CI_PERSEN_TEKANAN = 0.0098;

    /**
     * Pembagi komponen tekanan: **√5**, bukan √3.
     *
     * Master menandai distribusinya `rect.` di kolom J — dan distribusi
     * persegi pembaginya √3. Tapi selnya bilang lain: `P13 = 5` (angka bulat)
     * dan `T13 = M13/SQRT(P13)`. Baris suhu tepat di atasnya menulis
     * `P12 = SQRT(3)` lalu `T12 = M12/P12` — pola yang beda, di dua baris
     * bersebelahan, di keempat blok gas tanpa kecuali.
     *
     * Konsisten empat kali berarti disengaja, bukan sel yang keliru sekali.
     * Yang dipakai angka master (√5 ≈ 2,2360679) karena itu yang mereproduksi
     * `uc` keempat gas; ditanyakan ke lab apakah label `rect.`-nya yang perlu
     * dibetulkan atau pembaginya.
     */
    public const PEMBAGI_TEKANAN = 5.0;

    /**
     * Derajat kebebasan komponen resolusi: **60**.
     *
     * Delapan alat lain memakai 1.000.000 (praktis tak hingga) untuk komponen
     * yang sama. Master Gas Detector memakai 60 (`R10`/`R28`/`R46`/`R64`,
     * keempatnya), dan angka itu yang mereproduksi `v_eff` keempat gas —
     * 206,096 / 48,557 / 166,960 / 203,351. Diseragamkan ke 1.000.000,
     * keempatnya meleset.
     */
    public const VI_RESOLUSI = 60;

    /** Derajat kebebasan sertifikat kalibrator, suhu, & tekanan — `R11`/`R12`/`R13`. */
    public const VI_STANDAR = 200;

    /**
     * Ketidakpastian sertifikat botol gas Rigas: **5 % dari konsentrasi
     * nominal**, k=2.
     *
     * `DATABASE!V11 = 5%` dipakai sebagai pengali di ketiga baris berikutnya
     * (`V14 = V11*25`, `V15 = V11*50`, `V16 = V11*17.9`); baris CO menulis
     * rumusnya panjang (`V13 = 5%*101`) tapi angkanya sama. Disimpan di
     * `standards.ketidakpastian` per botol oleh `GasDetectorSeeder`, bukan
     * dihitung ulang di sini — kalau lab ganti pemasok dengan U95 lain, yang
     * berubah cukup barisnya di master standar.
     */
    public const PERSEN_U95_STANDAR = 0.05;

    /**
     * Batas geser waktu titik ukur dicocokkan ke gas.
     *
     * Empat gas nominalnya 101 / 25 / 50 / 17,9 — yang paling berdekatan H2S
     * (25) dan O2 (17,9), berjarak 7,1. Batas 3,0 menyerap botol pengganti
     * yang konsentrasinya sedikit berbeda (25 → 26 ppm) tanpa pernah bisa
     * menyentuh tetangganya.
     *
     * Kalau lab suatu saat memakai botol yang membuat dua gas berdekatan,
     * [peringatanSesi] yang menangkapnya — bukan angka yang diam-diam salah
     * satuan.
     */
    public const TOLERANSI_TITIK = 3.0;

    /**
     * Empat gas yang diukur, dengan nilai nominal botol standar Rigas yang
     * dipakai lab sekarang (`DATABASE!S13:S16`).
     *
     * `nilai` cuma dipakai untuk MENCOCOKKAN titik ke gas ([gasUntukTitik]),
     * bukan sebagai nilai acuan hitungan — yang itu datang dari
     * `titik_ukur` yang ditulis teknisi dari label botolnya sendiri.
     *
     * `resolusi` dari `INPUT DATA` E18:E21, `satuan` dari I18:I21.
     *
     * `desimal_sertifikat` & `desimal_u95` dari FORMAT SEL `SERTIFIKAT`
     * J24:U27 — dan dua-duanya beda per baris DAN beda antar kolom di baris
     * yang sama:
     *
     *   gas    Standard   UUT     Correction   U95
     *   CO     `0`        `0`     `0`          `0.0`
     *   H2S    `0`        `0`     `0`          `0.0`
     *   CH4    General    `0`     `0`          `0.0`
     *   O2     General    `0.0`   `0.0`        `0.00`
     *
     * Oksigen dapat satu desimal lebih banyak di semua kolom karena rentang
     * ukurnya 30 % dengan resolusi 0,1 — tiga gas lain rentangnya ratusan
     * sampai ribuan dengan resolusi 1. Dipukul rata nol desimal, `U95`
     * oksigen 0,887 % kecetak `1` dan kadar 16,73 % kecetak `17`.
     *
     * `standar` sengaja cuma berisi NAMA, tanpa serial. Keempat botol Rigas
     * ber-S/N `WO0125576` yang sama — satu order pengisian, empat campuran —
     * jadi serial di sini bukan pembeda melainkan perangkap: pencocokan yang
     * menerimanya memulangkan botol yang sama untuk keempat titik, dan
     * sertifikat mencetak "Carbon Monoxide (CO)" di baris oksigen.
     *
     * @var list<array{kode: string, nilai: float, label: string, satuan: string, resolusi: float, desimal: int, desimal_sertifikat: int, desimal_u95: int, rentang: float, remark: string, standar: list<string>}>
     */
    public const GAS = [
        [
            'kode' => 'CO',
            'nilai' => 101.0,
            'label' => 'CO',
            'satuan' => 'ppm',
            'resolusi' => 1.0,
            'desimal' => 0,
            'desimal_sertifikat' => 0,
            'desimal_u95' => 1,
            'rentang' => 1999.0,
            'remark' => 'Carbon Monoxide (CO)',
            'standar' => ['Standar Gas Mixture (CO)'],
        ],
        [
            'kode' => 'H2S',
            'nilai' => 25.0,
            'label' => 'H2S',
            'satuan' => 'ppm',
            'resolusi' => 1.0,
            'desimal' => 0,
            'desimal_sertifikat' => 0,
            'desimal_u95' => 1,
            'rentang' => 200.0,
            'remark' => 'Hydrogen Sulfide (H₂S)',
            'standar' => ['Standar Gas Mixture (H₂S)'],
        ],
        [
            'kode' => 'CH4',
            'nilai' => 50.0,
            'label' => 'CH4',
            'satuan' => '%LEL',
            'resolusi' => 1.0,
            'desimal' => 0,
            'desimal_sertifikat' => 0,
            'desimal_u95' => 1,
            'rentang' => 100.0,
            'remark' => 'Methane (CH4)',
            'standar' => ['Standar Gas Mixture (CH4)'],
        ],
        [
            'kode' => 'O2',
            'nilai' => 17.9,
            'label' => 'O2',
            'satuan' => '%',
            'resolusi' => 0.1,
            'desimal' => 1,
            'desimal_sertifikat' => 1,
            'desimal_u95' => 2,
            'rentang' => 30.0,
            'remark' => 'Oxygen (O2)',
            'standar' => ['Standar Gas Mixture (O2)'],
        ],
    ];

    /**
     * Baris tabel STANDARD di lembar kerja — keempat botol gas Rigas plus
     * thermobarometer yang dipakai mencatat kondisi ruangan.
     *
     * Keempat botol ber-S/N sama (`WO0125576`): satu order pengisian, empat
     * campuran. Pencocokan ke master `standards` karena itu lewat NAMA dulu;
     * serialnya cuma cadangan.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Standar Gas Mixture (CO) — 101 ppm', 'cocok' => ['Standar Gas Mixture (CO)']],
        ['label' => 'Standar Gas Mixture (H₂S) — 25 ppm', 'cocok' => ['Standar Gas Mixture (H₂S)']],
        ['label' => 'Standar Gas Mixture (CH4) — 2,5 % (50 %LEL)', 'cocok' => ['Standar Gas Mixture (CH4)']],
        ['label' => 'Standar Gas Mixture (O2) — 17,9 %', 'cocok' => ['Standar Gas Mixture (O2)']],
        ['label' => 'Thermobarometer Lutron', 'cocok' => ['Thermobarometer Lutron', 'AM02225']],
    ];

    /**
     * Alat pencatat kondisi lingkungan yang bisa dipilih.
     *
     * **Cuma Thermobarometer Lutron**, dan itu bukan penyempitan sewenang-wenang
     * seperti yang pernah gagal di profil lain: TH-1..TH-7 tidak mengukur
     * TEKANAN sama sekali (sertifikat kalibrasinya cuma dua parameter), dan
     * tanpa tekanan dua dari lima komponen budget gas detector tidak bisa
     * disusun. Unit yang secara fisik tidak bisa memberi angkanya memang tidak
     * boleh muncul di daftar.
     *
     * Begitu lab mengalibrasi thermohygro lain untuk tekanan, yang ditambah
     * satu baris di `database/data/thermohygro-lab.json` — daftar ini
     * membacanya lewat `parameter_kondisi['tekanan']`, bukan lewat nama.
     */
    public const PARAMETER_KONDISI_WAJIB = 'tekanan';

    public function kode(): string
    {
        return 'gas_detector';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Gas Detector';
    }

    /**
     * Lembar kerja & sertifikatnya nyebut "Multi Gas Detector" (dan itu juga
     * `nama_alat` alat contohnya di `GasDetectorSeeder`), sementara baris
     * kemampuannya nulis "Gas Detector" — lihat [namaAlatKemampuan]. Sebagian
     * data alat pelanggan nulisnya nempel tanpa spasi. Ketiganya didaftarin.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Multi Gas Detector', 'GasDetector'];
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_GAS_DETECTOR;
    }

    public function besaran(): string
    {
        return 'gas';
    }

    /**
     * Tidak divonis PASS/FAIL — master tidak punya kolom batas keberterimaan
     * dan sertifikatnya berhenti di `U95%`. Lihat docblock kelas.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * U95 dicetak sebagai KOLOM per baris (`SERTIFIKAT!U22 = 'U95% (±)'`),
     * bukan satu baris ringkas di bawah tabel. Wajib: keempat gas punya U95
     * yang besarnya berbeda-beda (5,05 ppm / 1,54 ppm / 2,62 %LEL / 0,89 %)
     * dan satuannya pun beda — diringkas jadi satu baris, tiga dari empat
     * angka hilang dari dokumen.
     */
    public function u95PerTitik(): bool
    {
        return true;
    }

    /**
     * Satuan berbeda per baris — `ppm` / `ppm` / `%LEL` / `%`. Ini alasan
     * utama hook [satuanTitik] ada di kelas induk.
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return $this->gasUntukTitik($titikUkur)['satuan'] ?? null;
    }

    /**
     * Resolusi per gas — 1 ppm / 1 ppm / 1 %LEL / 0,1 %.
     *
     * Dibaca per gas, BUKAN dikunci ke sel CO seperti master (lihat kejanggalan
     * nomor 2 di docblock kelas). Di sesi contoh hasilnya sama; di alat dengan
     * resolusi H2S 0,1 hasilnya baru berbeda — dan yang benar yang ini.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return $this->gasUntukTitik($titikUkur)['resolusi'] ?? null;
    }

    /** Turunan resolusi: 0 desimal untuk ppm & %LEL, 1 untuk O2. */
    public function desimalTitik(float $titikUkur): ?int
    {
        return $this->gasUntukTitik($titikUkur)['desimal'] ?? null;
    }

    /**
     * Desimal kolom hasil sertifikat, dari format sel `SERTIFIKAT` J24:R27 —
     * nol untuk CO/H2S/CH4, SATU untuk O2. Lihat tabel di docblock [GAS].
     *
     * Tanpa ini jalur umum menurunkannya dari `equipments.resolusi`, dan kolom
     * itu cuma muat SATU angka (1 ppm, ikut gas berentang terbesar). Akibatnya
     * baris oksigen kecetak `18 | 17 | 1` — padahal yang diukur 17,9 % dengan
     * alat beresolusi 0,1 %.
     */
    public function desimalSertifikatTitik(float $titikUkur): ?int
    {
        return $this->gasUntukTitik($titikUkur)['desimal_sertifikat'] ?? null;
    }

    /**
     * Desimal kolom `U95%`, dari format sel `SERTIFIKAT` U24:U27 — SATU untuk
     * CO/H2S/CH4, DUA untuk O2.
     *
     * Satu lebih banyak dari kolom hasil di baris yang sama, dan itu memang
     * yang tertulis di master: `U95` di sini angka kecil (0,89-5,05) yang
     * kehilangan artinya kalau dibulatkan sekasar kolom di sebelahnya.
     */
    public function desimalU95Titik(float $titikUkur): ?int
    {
        return $this->gasUntukTitik($titikUkur)['desimal_u95'] ?? null;
    }

    /** Kolom "Reference Gas" di sertifikat (`SERTIFIKAT!C22`). */
    public function remarkTitik(float $titikUkur): ?string
    {
        return $this->gasUntukTitik($titikUkur)['remark'] ?? null;
    }

    /**
     * Campuran gas dibaca NOMINAL apa adanya — konsentrasi botol tidak
     * dikoreksi suhu. Suhu ruangan tetap dicatat karena masuk budget lewat
     * komponen `pengaruh_suhu`, tapi lewat Δ-nya, bukan lewat kurva.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Catatan konversi yang tercetak DI BAWAH tabel hasil
     * (`SERTIFIKAT!C29:J30`).
     *
     * Bukan hiasan: kolom CH4 dilaporkan dalam `%LEL` sementara label botolnya
     * `2.5 %` volume. Tanpa baris ini pembaca sertifikat tidak punya cara tahu
     * kedua angka itu besaran yang sama.
     */
    public function catatanAtasTabelHasil(CalibrationSession $sesi): ?string
    {
        return 'Correction factor from % to %LEL — Methane (CH4) = 2,5 % = 50 %LEL';
    }

    /**
     * Pasangan titik → botol gas, biar tiap baris tabel membawa `standard_id`
     * miliknya sendiri. Dari situ sertifikat mendapat komposisi, lot, dan
     * ketertelusuran per gas.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return array_map(
            fn (array $g): array => ['titik' => $g['nilai'], 'standar' => $g['standar']],
            self::GAS,
        );
    }

    /**
     * Peringatan yang muncul di layar teknisi & admin SEBELUM sertifikat
     * terbit.
     *
     * Dua hal yang dijaga, dan dua-duanya jenis kesalahan yang tidak
     * memunculkan error di mana pun kalau lolos:
     *
     *  1. **Dua titik terpetakan ke gas yang sama.** Titik dicocokkan ke gas
     *     lewat nilainya ([gasUntukTitik]); kalau lab memakai botol yang
     *     membuat dua gas berdekatan, satu baris bisa mencetak satuan
     *     tetangganya. Angkanya benar, satuannya salah — dan satuan salah di
     *     sertifikat gas beracun bukan salah ketik biasa.
     *  2. **Tekanan udara belum dicatat.** Tanpa itu [komponenBudget] balik
     *     `null` dan seluruh sesi jatuh ke jalur CMC — yang untuk alat ini
     *     berarti CMC 0, jadi U95-nya lahir dari jalur cadangan tanpa komponen
     *     tekanan sama sekali. Lebih baik teknisi tahu sekarang, waktu
     *     barometernya masih di tangan.
     *
     * Bentuk kembaliannya `[kode, pesan]`, sama seperti profil lain — BUKAN
     * daftar string. Sempat berupa string di sini sendirian, dan itu bikin
     * `CalibrationValidator::periksaPeringatanProfil()` meledak begitu ada sesi
     * Gas Detector diperiksa:
     *
     *     Argument #1 ($p) must be of type array, string given
     *
     * Validatornya emang mbungkus tiap temuan lewat `fn (array $p)`, dan dua
     * profil lain (Viscometer, Conductivity) udah balikin array dari dulu.
     * Ketahuannya baru waktu ada yang mencet CHECK di lembar Gas Detector —
     * sampai situ nggak ada yang pernah lewat jalur ini.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];

        $terpakai = [];
        foreach ($sesi->uncertaintyCalculations as $titik) {
            $gas = $this->gasUntukTitik((float) $titik->titik_ukur);

            if ($gas === null) {
                $peringatan[] = [
                    'kode' => 'gas_titik_tak_dikenal',
                    'pesan' => sprintf(
                        'Titik ke-%d (%s) nggak kena satu pun dari empat gas yang dikenal '
                        .'(CO ±101, H2S ±25, CH4 ±50, O2 ±17,9). Satuan & resolusi barisnya bakal kosong '
                        .'di sertifikat — cek angka standarnya.',
                        $titik->titik_ke,
                        $titik->titik_ukur,
                    ),
                ];

                continue;
            }

            $terpakai[$gas['kode']][] = (int) $titik->titik_ke;
        }

        foreach ($terpakai as $kode => $titikKe) {
            if (count($titikKe) > 1) {
                $peringatan[] = [
                    'kode' => 'gas_titik_kembar',
                    'pesan' => sprintf(
                        'Titik ke-%s sama-sama kebaca sebagai %s. Satu di antaranya bakal kecetak '
                        .'pakai satuan yang salah — betulin nilai standarnya dulu sebelum sertifikat terbit.',
                        implode(' & ke-', $titikKe),
                        $kode,
                    ),
                ];
            }
        }

        if ($sesi->tekanan_awal === null || $sesi->tekanan_akhir === null) {
            $peringatan[] = [
                'kode' => 'gas_tekanan_belum_lengkap',
                'pesan' => 'Tekanan udara awal/akhir belum lengkap. Dua dari lima komponen '
                    .'ketidakpastian Gas Detector (pengaruh suhu & tekanan) nggak bisa disusun tanpa itu, '
                    .'jadi U95 yang keluar bukan angka yang dipakai master.',
            ];
        }

        return $peringatan;
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap();
        $bentuk = $this->tautkanStandar($bentuk);
        $bentuk = $this->tautkanStandarTitik($bentuk);
        $bentuk = $this->isiPilihanThermobarometer($bentuk);

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
     * Budget **5 komponen**, urutannya persis sheet `PERHITUNGAN U95%`
     * (baris 9–13 untuk CO, 27–31 H2S, 45–49 CH4, 63–67 O2).
     *
     * Balik `null` kalau Δ suhu atau Δ tekanan belum ada — sesi jatuh ke jalur
     * CMC, bukan menyusun budget yang dua komponennya diam-diam nol. Komponen
     * bernilai nol tidak sekadar "kurang teliti": dia mengecilkan `uc`, menaikkan
     * `v_eff`, mengecilkan `k`, dan U95 yang keluar lebih kecil dari yang bisa
     * dipertanggungjawabkan — arah kesalahan yang paling berbahaya di dokumen
     * terakreditasi.
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
        array $konteksTitik = [],
    ): ?array {
        $deltaSuhu = $konteksTitik['delta_suhu'] ?? null;
        $deltaTekanan = $konteksTitik['delta_tekanan'] ?? null;

        if ($deltaSuhu === null || $deltaTekanan === null) {
            return null;
        }

        $deltaSuhu = (float) $deltaSuhu;
        // hPa → kPa. Master melakukannya di sel `M13 = PERHITUNGAN!M21/10`,
        // sebelum dibagi √5 — jadi konversinya di `u`, bukan di `ci`.
        $deltaTekananKpa = ((float) $deltaTekanan) / 10.0;

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;
        $resolusi = $this->resolusiTitik($titikUkur) ?? (float) $equipment->resolusi;
        $satuan = $this->satuanTitik($titikUkur, $equipment) ?? $equipment->satuan;

        return [
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan (Type A)', $n),
                'distribusi' => 't-student',
                'u' => $typeA,
                'ci' => 1.0,
                'vi' => max($n - 1, 1),
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s', $resolusi, $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_RESOLUSI,
            ],
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat botol %s (U=%s %s, k=%s)',
                    $standard->nama,
                    $standard->ketidakpastian,
                    $standard->satuan_ketidakpastian ?? $satuan,
                    $kStandar,
                ),
                'distribusi' => 'normal',
                'u' => ($standard->ketidakpastian ?? 0.0) / $kStandar,
                'ci' => 1.0,
                'vi' => self::VI_STANDAR,
            ],
            [
                'sumber' => 'pengaruh_suhu',
                'keterangan' => sprintf(
                    'Pergeseran suhu ruang Δ%s °C (÷√3), ci 0,34%%·%s',
                    $deltaSuhu,
                    $titikUkur,
                ),
                'distribusi' => 'persegi',
                'u' => $deltaSuhu / $sqrt3,
                'ci' => self::CI_PERSEN_SUHU * $titikUkur,
                'vi' => self::VI_STANDAR,
            ],
            [
                'sumber' => 'pengaruh_tekanan',
                'keterangan' => sprintf(
                    'Pergeseran tekanan udara Δ%s kPa (÷√5), ci 0,98%%·%s',
                    $deltaTekananKpa,
                    $titikUkur,
                ),
                'distribusi' => 'persegi',
                'u' => $deltaTekananKpa / sqrt(self::PEMBAGI_TEKANAN),
                'ci' => self::CI_PERSEN_TEKANAN * $titikUkur,
                'vi' => self::VI_STANDAR,
            ],
        ];
    }

    /**
     * Gas yang berlaku di titik ini, atau `null` kalau tidak ada yang cukup
     * dekat. Dipakai [satuanTitik], [resolusiTitik], [desimalTitik],
     * [remarkTitik], dan [peringatanSesi].
     *
     * Yang menang gas TERDEKAT dalam [TOLERANSI_TITIK], bukan yang pertama
     * lolos — supaya urutan konstanta tidak diam-diam menentukan hasil.
     *
     * @return array{kode: string, nilai: float, label: string, satuan: string, resolusi: float, desimal: int, desimal_sertifikat: int, desimal_u95: int, rentang: float, remark: string, standar: list<string>}|null
     */
    public function gasUntukTitik(float $titikUkur): ?array
    {
        $pilih = null;
        $jarakTerdekat = null;

        foreach (self::GAS as $gas) {
            $jarak = abs($titikUkur - $gas['nilai']);

            if ($jarak > self::TOLERANSI_TITIK) {
                continue;
            }

            if ($jarakTerdekat === null || $jarak < $jarakTerdekat) {
                $jarakTerdekat = $jarak;
                $pilih = $gas;
            }
        }

        return $pilih;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Multi Gas Detector',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_map(fn (array $g): float => $g['nilai'], self::GAS),
            // Alat bersatuan campuran — tiap baris membawa satuannya sendiri
            // lewat `tabel.baris[].satuan`. Kunci ini sengaja null biar
            // frontend nggak menempelkan satu satuan ke semua kolom.
            'satuan' => null,
            // Penanda eksplisit buat aplikasi teknisi: `satuan: null` sendirian
            // kebaca sebagai string kosong di sana (`as String? ?? ''`), dan
            // dari situ layar nggak punya cara tahu bedanya "alat ini campur
            // satuan" dan "backend-nya belum ngisi". Conductivity &
            // Spectrophotometer sudah mengirimnya dari dulu; alat ini
            // ketinggalan waktu ditambahkan.
            'satuan_campuran' => true,
            'satuan_suhu' => '°C',
            'satuan_tekanan' => 'hPa',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — '
                .'lembar kerja tetap bisa dikirim. Titik yang datanya belum cukup nggak ikut dihitung, '
                .'dan sertifikatnya baru bisa terbit sesudah dilengkapi admin. Khusus alat ini, '
                .'TEKANAN UDARA awal & akhir wajib buat dapat ketidakpastian yang benar.',
            'bagian' => [
                [
                    'kode' => 'identitas_alat',
                    'halaman' => 1,
                    'judul' => 'EQUIPMENT IDENTITY AND CUSTOMER DATA',
                    'field' => [
                        $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                        $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', '1. Name', 'teks', sumber: 'otomatis'),
                        $this->field('alat_merk', '2. Merk/Manufacture', 'teks'),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                    ],
                    // Rentang & resolusi ditulis PER GAS, bukan satu baris —
                    // `INPUT DATA` E14:I21 punya empat baris masing-masing.
                    'baris_rentang' => array_map(
                        fn (array $g): array => [
                            'gas' => $g['kode'],
                            'rentang' => $g['rentang'],
                            'resolusi' => $g['resolusi'],
                            'satuan' => $g['satuan'],
                        ],
                        self::GAS,
                    ),
                ],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'OWNER',
                    'field' => [
                        $this->field('pemilik_nama', '1. Name', 'teks'),
                        $this->field('pemilik_alamat', '2. Address', 'teks_panjang'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'STANDARD',
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
                        $this->field('lokasi', '1. Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'Inlab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('room_id', 'Ruangan', 'pilihan', sumber: 'master_ruangan'),
                        $this->field(
                            'calibration_method_id',
                            '2. Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION RESULT',
                    'field' => [
                        $this->field('suhu_awal', 'Env. Condition — First', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'Env. Condition — First', 'angka', satuan: '%RH'),
                        // Baris KETIGA blok kondisi lingkungan — cuma alat ini
                        // yang punya. Lihat migrasi 2026_08_20_100000.
                        $this->field('tekanan_awal', 'Env. Condition — First', 'angka', satuan: 'hPa'),
                        $this->field('suhu_akhir', 'Env. Condition — End', 'angka', satuan: '°C'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End', 'angka', satuan: '%RH'),
                        $this->field('tekanan_akhir', 'Env. Condition — End', 'angka', satuan: 'hPa'),
                        $this->field(
                            'thermohygro_standard_id',
                            'Environmental Meter Used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                    ],
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before Adjustment Reading'),
                        $this->tabelHasil('sesudah_adjustment', 'After Adjustment Reading'),
                    ],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 1,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                        $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
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
                $this->field('tekanan_ketidakpastian', 'U95% Tekanan', 'angka', sumber: 'otomatis', satuan: 'hPa', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * Satu tabel hasil: baris = gas, kolom = pembacaan, Repeat 1..3.
     *
     * Beda dari sembilan alat lain — TIDAK ada kolom suhu per pembacaan.
     * Master gas detector memang tidak punya (`INPUT DATA` G38:M40 cuma empat
     * kolom pembacaan), dan wajar: yang masuk budget suhu RUANGAN, bukan suhu
     * media yang diukur seperti larutan buffer.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul): array
    {
        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'baris' => array_map(
                fn (array $g): array => [
                    'titik_ukur' => $g['nilai'],
                    'label' => $g['label'],
                    'satuan' => $g['satuan'],
                    'resolusi' => $g['resolusi'],
                    'desimal' => $g['desimal'],
                    'remark' => $g['remark'],
                ],
                self::GAS,
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => 'Reading', 'tipe' => 'angka', 'satuan' => null],
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
            ->get(['id', 'nama', 'serial_number', 'no_sertifikat', 'tertelusur_ke', 'parameter_kondisi']);

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
     * Isi pilihan "Environmental Meter Used" dengan unit yang PUNYA kalibrasi
     * tekanan.
     *
     * Disaring lewat `parameter_kondisi['tekanan']`, bukan lewat daftar nama:
     * yang menentukan unitnya bisa memberi angka tekanan atau tidak, dan itu
     * ada di datanya sendiri. Begitu lab mengalibrasi TH lain untuk tekanan,
     * unit itu muncul di sini tanpa file ini disentuh.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermobarometer(array $bentuk): array
    {
        $pilihan = Standard::query()
            ->whereNotNull('parameter_kondisi')
            ->get(['id', 'nama', 'parameter_kondisi'])
            ->filter(fn (Standard $s): bool => ($s->parameter_kondisi[self::PARAMETER_KONDISI_WAJIB] ?? null) !== null)
            ->map(fn (Standard $s): array => [
                'nilai' => (string) $s->id,
                'label' => $s->nama,
                'grup' => 'Punya kalibrasi tekanan',
            ])
            ->values()
            ->all();

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
     * @param  list<array<string, string>>  $pilihan
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
}
