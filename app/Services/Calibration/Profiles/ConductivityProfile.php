<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Conductivity Meter (alat ke-5). Metode `SIDIK-IK-CAL-0507_Rev.6`;
 * ASTM D 1125-2023, perbandingan langsung dengan larutan standar.
 *
 * Sumber: `Master Olah Data_Conductivity.xlsm` (terenkripsi, dibuka 10 Agt 2026).
 * Sheet yang jadi acuan: `INPUT DATA`, `PERHITUNGAN`, `PERHITUNGAN U95%`,
 * `DATABASE`, `SERTIFIKAT STYLE 1/2`.
 *
 * ## TIGA titik, dan titik tengahnya berganti satuan
 *
 * Master punya EMPAT kolom pengukuran (F/H/J/L), tapi H dan J adalah standar
 * yang SAMA (Supelco/Merck LRAD9052) dibaca dalam dua satuan berbeda —
 * operator ngisi salah satu, dan pilihan itu yang nentuin style sertifikat
 * (catatan `INPUT DATA!K57`). Sheet budget mengonfirmasi: cuma ada TIGA blok
 * titik, dan blok ke-2 nyalain sumbernya sendiri —
 * `C21 = IF('INPUT DATA'!H39="", J37, H37)`.
 *
 * Jadi di sini: 3 titik, titik tengah punya dua varian satuan.
 *
 * ## Beda inti dari empat profil sebelumnya
 *
 *  - Nilai acuan larutan DIGESER IKUT SUHU lewat polinomial kuadrat per
 *    larutan (`Standard::nilaiPadaSuhu()`), bukan nominal apa adanya. Ini
 *    sisi yang sama kayak buffer pH — yang bergeser nilai acuannya, bukan
 *    pembacaan alatnya. Makanya [standarBerkurvaSuhu] `true` dan
 *    [rataRataPadaSuhuAcuan] TIDAK di-override.
 *  - Budget 4 komponen. Komponen suhunya **normal ÷2** (pH & turbidimeter:
 *    rect ÷√3), dengan `ci = (u_temperature/100) · titik` — sel W11/W25/W39
 *    sheet `PERHITUNGAN U95%`.
 *  - Resolusi & desimal cetak beda per titik: 0,1 µS/cm (1 desimal),
 *    1 µS/cm (0 desimal), 0,01 mS/cm (2 desimal) — sel INPUT DATA E17/G17/I17
 *    dan format sel `SERTIFIKAT STYLE 2` C35/C36/C41.
 *
 * ## Yang SENGAJA tidak diambil dari master
 *
 * Empat keputusan audit yang menyimpang dari isi sel, semuanya berpijak pada
 * bukti lain di dalam workbook yang sama. Lihat `docs/audit-conductivity.md`
 * buat dasar lengkapnya; ringkasnya:
 *
 *  - **E-2** `PERHITUNGAN!J34/J47` isinya `=AVERAGE(...)`, bukan selisih.
 *    Sel itu BUNTU — nggak ada sheet yang baca. Correction yang beneran
 *    nyampe sertifikat dihitung di `SERTIFIKAT!P40 = C40-J40`, yaitu
 *    `Standar − Rata-rata`. Pola itu yang dipakai, buat keempat kolom.
 *  - **E-4** slot standar ke-4 (`Conduct 80000`) dicentang TRUE tapi baris
 *    DATABASE-nya kosong, nggak punya kolom ukur, nggak punya CMC. Nggak
 *    dibikin titik.
 *  - **E-5** lihat [KOEF_SUHU] di bawah.
 *  - **Q3** master nggak punya satu pun sel yang mbandingin hasil sama batas
 *    toleransi, dan kedua sheet sertifikat cuma nyetak `Correction` + `U95%`
 *    lalu berhenti. Jadi alat ini NGGAK ngasih vonis lulus/gagal. Caranya
 *    bukan flag baru: alat conductivity diseed `toleransi = null`, dan
 *    `GumCalculator::keputusan()` balikin `null` buat toleransi kosong. Kalau
 *    lab nanti nerbitin batas keberterimaan di instruksi kerjanya, isi
 *    `equipments.toleransi` — nggak ada kode yang perlu disentuh.
 */
class ConductivityProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-IK-CAL-0507_Rev.6';

    public const JUMLAH_PENGULANGAN = 5;

    public const SATUAN_MIKRO = 'µS/cm';

    public const SATUAN_MILI = 'mS/cm';

    /**
     * Titik ukur, dalam satuan NATIVE-nya masing-masing (dua titik pertama
     * µS/cm, titik ketiga mS/cm) — persis kayak master, yang emang nyampur
     * satuan dalam satu lembar.
     *
     * `varian_mili` di titik tengah = nilai yang sama dibaca dalam mS/cm.
     * Titik itu punya DUA bentuk; yang kepakai tergantung satuan pembacaan
     * alat yang dipilih di sesi (lihat [titikUntukSatuan]).
     *
     * @var list<array{nilai: float, satuan: string, resolusi: float, desimal: int, varian_mili: array{nilai: float, satuan: string, resolusi: float, desimal: int}|null}>
     */
    public const TITIK = [
        [
            'nilai' => 25.0,
            'satuan' => self::SATUAN_MIKRO,
            'resolusi' => 0.1,
            'desimal' => 1,
            'varian_mili' => null,
        ],
        [
            'nilai' => 1412.0,
            'satuan' => self::SATUAN_MIKRO,
            'resolusi' => 1.0,
            'desimal' => 0,
            'varian_mili' => [
                'nilai' => 1.412,
                'satuan' => self::SATUAN_MILI,
                'resolusi' => 0.001,
                'desimal' => 3,
            ],
        ],
        [
            'nilai' => 111.0,
            'satuan' => self::SATUAN_MILI,
            'resolusi' => 0.01,
            'desimal' => 2,
            'varian_mili' => null,
        ],
    ];

    /**
     * Polinomial kurva suhu tiap larutan standar, `y = a·T² + b·T + c`, dalam
     * satuan native titiknya. Diseed ke `standards.koefisien_suhu` dan dipakai
     * `Standard::nilaiPadaSuhu()` — yang kebetulan MEMANG udah kuadrat, jadi
     * nggak ada mekanisme baru yang perlu dibikin.
     *
     * Asal tiap baris:
     *
     *  - **25 µS/cm** → sheet `nilai koefisien sensitifitas` A23 nyatain
     *    kurvanya `y = 25`. Konstan; larutan ini emang dianggap nggak
     *    bergantung suhu. Ditulis eksplisit `a=0, b=0, c=25` biar niatnya
     *    kebaca, bukan dibiarin `null` yang artinya "datanya belum diisi".
     *  - **1412 µS/cm** → sheet yang sama A39: `y = 2E-13x² + 27x + 738`.
     *  - **111 mS/cm** → sheet yang sama A55: `y = 0,0042x² + 1,732x + 64,88`,
     *    dan dieksekusi beneran di `PERHITUNGAN!L26`.
     *
     * ## E-5 — DIBETULKAN: 1412 µS/cm ikut polinomial, bukan angka ketik
     *
     * `PERHITUNGAN!H26` isinya angka ketik `1412`, tanpa koreksi suhu. Larutan
     * yang PERSIS SAMA di kolom sebelahnya (`J26`, varian mS/cm) dikoreksi
     * pakai polinomial `2E-13·T² + 27·T + 738`, dan sheet
     * `nilai koefisien sensitifitas` A39 mendokumentasikan polinomial itu tepat
     * di bawah judul "DR 1412 μS/cm". Jadi satu botol larutan dapat dua
     * perlakuan berbeda di dalam satu workbook, dan cuma SATU yang punya dasar
     * tertulis.
     *
     * Yang punya dasar itu yang dipakai — keputusan user, 10 Agt 2026, sesudah
     * angka geserannya ditunjukkan lebih dulu. Akibatnya suhu larutan yang
     * dicatat teknisi buat kolom ini jadi KEPAKAI, bukan kebuang; dan larutan
     * yang sama nggak lagi dinilai beda cuma gara-gara satuan yang dipilih
     * operator.
     *
     * ## Yang berubah dari master, dan sebesar apa
     *
     * Pada suhu yang BENERAN kecatat di sesi contoh — 24,92 °C, bukan 25 —
     * polinomialnya ngasih **1410,84 µS/cm** lawan `1412` yang diketik master:
     *
     *   nilai acuan   1412  →  1410,84    (geser 1,16 µS/cm)
     *   Correction      −1  →  −2,16      (geser 1,16 µS/cm)
     *
     * Budget ketidakpastiannya TIDAK ikut berubah: `ci` dihitung dari nilai
     * NOMINAL titik (lihat [komponenBudget]), jadi Uc, v_eff, k, dan U95% tetap
     * cocok master sampai belasan digit. Geserannya sendiri jauh di dalam U95%
     * titik itu (±8,1) maupun CMC-nya (5,1) — yang dilaporkan tetap `1413 ± 8`.
     *
     * Selisih 1,16 itu DIKUNCI di
     * `ConductivityBudgetTest::test_titik_1412_dikoreksi_suhu_bukan_angka_ketik`,
     * lengkap sama nilai master-nya. Kalau suatu saat ada yang mbalikin ke
     * konstanta, test-nya merah dan alasannya kebaca — bukan ketemu setahun
     * kemudian waktu sertifikatnya dipertanyakan.
     *
     * Kalau lab mau balik persis ke master: pakai [KOEF_SUHU_1412_MASTER].
     *
     * @var array<string, array{a: float, b: float, c: float}>
     */
    public const KOEF_SUHU = [
        '25' => ['a' => 0.0, 'b' => 0.0, 'c' => 25.0],
        '1412' => ['a' => 2.0e-13, 'b' => 27.0, 'c' => 738.0],
        '111' => ['a' => 0.0042, 'b' => 1.732, 'c' => 64.88],
    ];

    /**
     * Perlakuan ASLI master buat larutan 1412 µS/cm: angka ketik `1412` di
     * `PERHITUNGAN!H26`, tanpa koreksi suhu.
     *
     * Disimpen — bukan dibuang — karena dia yang jadi pembanding di test, dan
     * karena kalau lab suatu saat mau balik ke perilaku master, jalannya cukup
     * nunjuk konstanta ini.
     */
    public const KOEF_SUHU_1412_MASTER = ['a' => 0.0, 'b' => 0.0, 'c' => 1412.0];

    /**
     * Baris tabel STANDARD di lembar kerja: 3 larutan Supelco/Merck (DATABASE
     * R13/R14/R15) + termometer & sensor + PT100 (R17/S18). Empat baris
     * pertama itu yang dicentang operator di master (`INPUT DATA` Y19..Y23).
     *
     * `Conduct 80000` sengaja NGGAK ada — lihat E-4 di docblock kelas.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Conductivity Std Solution 25 µS/cm', 'cocok' => ['Conductivity Std Solution 25 µS/cm', 'LRAD7693']],
        ['label' => 'Conductivity Std Solution 1412 µS/cm', 'cocok' => ['Conductivity Std Solution 1412 µS/cm', 'LRAD9052']],
        ['label' => 'Conductivity Std Solution 111 mS/cm', 'cocok' => ['Conductivity Std Solution 111 mS/cm', 'HC56824055']],
        ['label' => 'Termometer & Sensor Std.', 'cocok' => ['Termometer & Sensor Std.', '23P1005']],
        ['label' => 'PT100/SH1', 'cocok' => ['PT100/SH1', 'SH1/20', 'PT100']],
    ];

    /**
     * Semua unit thermohygro lab. Alasan daftarnya utuh (bukan dipersempit)
     * sama persis kayak yang ditulis panjang di `TurbidimeterProfile`: pernah
     * dipersempit, dan sertifikat master malah pakai unit yang nggak ada di
     * daftar. Master conductivity sendiri pakai TH-6.
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-1', 'grup' => 'Inlab'],
        ['label' => 'TH-3', 'grup' => 'Inlab'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
        ['label' => 'TH-5', 'grup' => 'Inlab'],
        ['label' => 'TH-7', 'grup' => 'Inlab'],
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
    ];

    public function kode(): string
    {
        return 'conductivity_meter';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Conductivitymeter';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_CONDUCTIVITY;
    }

    public function besaran(): string
    {
        return 'conductivity';
    }

    /**
     * Nilai acuan larutan bergeser ikut suhu (polinomial [KOEF_SUHU]), jadi
     * `koefisien_suhu` yang NULL di master standar conductivity beneran
     * berarti "datanya bolong" dan pantas di-flag validator.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return true;
    }

    /**
     * Pasangan tercetak titik → larutan. Titik tengah didaftarin dua kali
     * (varian µS/cm dan mS/cm) karena dua-duanya nunjuk botol yang sama.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return [
            ['titik' => 25.0, 'standar' => ['Conductivity Std Solution 25 µS/cm', 'LRAD7693']],
            ['titik' => 1412.0, 'standar' => ['Conductivity Std Solution 1412 µS/cm', 'LRAD9052']],
            ['titik' => 1.412, 'standar' => ['Conductivity Std Solution 1412 µS/cm', 'LRAD9052']],
            ['titik' => 111.0, 'standar' => ['Conductivity Std Solution 111 mS/cm', 'HC56824055']],
        ];
    }

    public function resolusiTitik(float $titikUkur): ?float
    {
        return $this->titikTerdekat($titikUkur)['resolusi'];
    }

    public function desimalTitik(float $titikUkur): ?int
    {
        return $this->titikTerdekat($titikUkur)['desimal'];
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false): array
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
     * Budget 4 komponen conductivity buat satu titik — sheet `PERHITUNGAN U95%`
     * blok baris 7–20 (25 µS/cm), 21–34 (1412 µS/cm), 35–47 (111 mS/cm).
     *
     * Balik `null` kalau `u_temperature` belum keisi di kemampuannya, biar
     * jatuh ke jalur CMC lama — bukan ngarang angka. Sama polanya kayak
     * turbidimeter & chlorine.
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

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;
        $uTemperature = (float) $kemampuan->u_temperature;
        $satuan = $this->satuanTitik($titikUkur);
        $resolusi = $this->resolusiTitik($titikUkur) ?? (float) $equipment->resolusi;

        // U95% sertifikat larutan disimpen dalam satuan native larutannya
        // (µS/cm buat dua yang pertama, mS/cm buat yang ketiga). Titik tengah
        // varian mS/cm baca botol yang sama, jadi U-nya dibagi 1000 —
        // sel `M23 = IF(I21="mS/cm", DATABASE!V14/1000, DATABASE!V14)`.
        $uStandar = ($standard->ketidakpastian ?? 0.0) / $kStandar;

        if ($satuan === self::SATUAN_MILI && $standard->satuan_ketidakpastian === self::SATUAN_MIKRO) {
            $uStandar /= 1000.0;
        }

        // Koefisien sensitivitas suhu: ci = (u_temperature/100)·titik — sel
        // W11/W25/W39.
        //
        // Titik yang dipakai itu NILAI NOMINAL botolnya, BUKAN nilai yang udah
        // dikoreksi suhu. Master ngambilnya langsung dari sheet input —
        // `C35 = 'INPUT DATA'!L37` = 111, padahal nilai acuan terkoreksi di
        // baris yang sama 111,193568. Sama buat dua titik lain (C7 = F37 = 25,
        // C21 = H37 = 1412).
        //
        // `$titikUkur` yang masuk ke sini SUDAH terkoreksi suhu (GumCalculator
        // ngegantinya lewat `Standard::nilaiPadaSuhu()` sebelum manggil profil),
        // jadi kalau dipakai mentah, ci titik 111 keluar 0,401685 — bukan
        // 0,4009850994737834 kayak master. Makanya disnap balik ke nominal.
        $ciSuhu = ($uTemperature / 100.0) * $this->titikTerdekat($titikUkur)['nilai'];

        // Catatan audit yang sengaja dibiarkan berdiri, bukan dibetulin:
        // `u_temperature` muncul DUA KALI di komponen ini — di `u` lewat ÷2,
        // dan di `ci` lewat /100 — jadi `u·ci` sebanding sama
        // `u_temperature²`. Menurut GUM, `ci` semestinya turunan parsial
        // ∂(konduktivitas)/∂T dan nggak bergantung ketidakpastian termometer
        // sama sekali. Nggak diubah: master itu source of truth, dan sertifikat
        // yang udah terbit pakai rumus ini. Kalau suatu saat ada asesmen KAN
        // yang nanya asal-usul `ci`, ini titik yang bakal ditanyain.

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U=%s %s, k=%s)',
                    $standard->nama,
                    $standard->ketidakpastian,
                    $standard->satuan_ketidakpastian ?? $satuan,
                    $kStandar,
                ),
                'distribusi' => 'normal',
                'u' => $uStandar,
                'ci' => 1.0,
                'vi' => 200,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s (titik %s)', $resolusi, $satuan, $titikUkur),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
            ],
            [
                'sumber' => 'ketidakpastian_temperature',
                'keterangan' => sprintf(
                    'UTemperature %s °C (÷2), ci (%s/100)·%s',
                    $uTemperature,
                    $uTemperature,
                    $titikUkur,
                ),
                'distribusi' => 'normal',
                'u' => $uTemperature / 2.0,
                'ci' => $ciSuhu,
                'vi' => 200,
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
     * Satuan native titik ini (`µS/cm` atau `mS/cm`).
     */
    public function satuanTitik(float $titikUkur): string
    {
        return $this->titikTerdekat($titikUkur)['satuan'];
    }

    /**
     * Style sertifikat buat sesi ini, dari satuan yang dipakai baca titik
     * tengah — catatan `INPUT DATA!K57`, diverifikasi dari isi kedua sheet:
     *
     *  - **style 1** nyetak 25 µS/cm · 1,412 mS/cm · 111 mS/cm
     *  - **style 2** nyetak 25 µS/cm · 1412 µS/cm · 111 mS/cm
     *
     * @param  list<float>  $titikUkur  titik yang beneran diukur di sesi ini
     */
    public function styleSertifikat(array $titikUkur): int
    {
        foreach ($titikUkur as $t) {
            if (abs($t - 1.412) < 0.05) {
                return 1;
            }
        }

        return 2;
    }

    /**
     * Peringatan buat admin kalau sesi ini makai varian mS/cm titik tengah —
     * jalur yang di master NGGAK PERNAH kepakai dan rumusnya bermasalah.
     *
     * Balik `null` kalau sesinya nggak nyentuh varian itu.
     *
     * Dipasang sebagai PERINGATAN, bukan penghalang keras, ngikutin idiom yang
     * udah ada di `approve()`: admin bisa lanjut sadar-sadar lewat
     * `abaikan_peringatan: true`. Ngeblok total bakal ngilangin fitur yang
     * mungkin emang lab butuh; diem aja bakal nerbitin angka dari rumus yang
     * satu-satunya jejaknya di master adalah nilai ngaco.
     *
     * @param  list<float>  $titikUkur
     */
    public function peringatanVarianMili(array $titikUkur): ?string
    {
        if ($this->styleSertifikat($titikUkur) !== 1) {
            return null;
        }

        return 'Titik tengah dibaca dalam mS/cm (sertifikat style 1). Di master Excel, jalur '
            .'ini nggak pernah keisi sama sekali — kolomnya kosong, dan satu-satunya angka '
            .'yang pernah dia keluarin 0,738 mS/cm, hasil polinomial suhu dievaluasi di T=0 '
            .'karena kolom suhunya kosong. Dua cacat master di jalur ini udah dibetulin: '
            .'Correction pakai selisih (bukan =AVERAGE(...)), dan nilai acuannya dikoreksi '
            .'suhu pakai polinomial yang sama persis dengan varian µS/cm, jadi satu botol '
            .'nggak lagi dinilai beda gara-gara satuan. Yang tersisa: jalur ini belum pernah '
            .'diadu sama sesi nyata. Periksa angkanya sebelum approve.';
    }

    /**
     * Titik kalibrasi thermohygro yang paling dekat ke nilai terukur.
     *
     * ## E-6 / Q4 — kenapa "terdekat", dan kenapa itu bukan tebakan
     *
     * Master ngambil koreksi thermohygro lewat `VLOOKUP(H16, Index_suhuTH, 3, 0)`
     * — pencocokan PERSIS. Tapi `H16` bukan hasil hitungan: dia diketik
     * operator, dan di sesi contoh isinya 29,75 padahal suhu ruang terukur
     * 25,45 °C. Nggak ada satu pun formula di workbook yang ngehubungin dua
     * angka itu, jadi aturannya emang nggak tertulis.
     *
     * Yang bisa dibuktikan: nilai yang diketik SELALU salah satu titik
     * kalibrasi TH yang dipakai, dan selalu yang paling dekat. TH-6 punya titik
     * suhu {14,64 · 19,33 · 29,75 · 39,34 · 49,95} — terdekat ke 25,45 adalah
     * 29,75 (selisih 4,30 lawan 6,12 ke 19,33). ✓ cocok pilihan operator.
     * Titik RH-nya {28,67 · 46,83 · 67,23 · 76,79 · 86,52} — terdekat ke 54,5
     * adalah 46,83 (7,67 lawan 12,73). ✓ cocok juga.
     *
     * Dua-duanya kena, jadi "titik terdekat" mereproduksi pilihan manual
     * operator tanpa perlu nebak. Itu yang dipakai sebagai nilai awal.
     *
     * Admin tetap bisa nimpa lewat dropdown — pilihannya DIBATASI ke titik
     * kalibrasi TH yang kepilih, karena VLOOKUP-nya exact match: isian bebas
     * cuma bisa ngasih dua hasil, angka yang bener atau `#N/A`.
     *
     * @param  list<float>  $titikKalibrasi  standard indication dari sertifikat TH
     */
    public static function titikIndeksTerdekat(float $terukur, array $titikKalibrasi): ?float
    {
        if ($titikKalibrasi === []) {
            return null;
        }

        $terdekat = $titikKalibrasi[0];

        foreach ($titikKalibrasi as $t) {
            if (abs($t - $terukur) < abs($terdekat - $terukur)) {
                $terdekat = $t;
            }
        }

        return $terdekat;
    }

    /**
     * Conductivity Meter nggak divonis PASS/FAIL — Q3. `toleransi` NULL di
     * alat ini artinya "emang nggak ada", bukan "belum diisi", jadi validator
     * nggak boleh nahan penerbitan gara-gara itu.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * µS/cm → mS/cm buat pengecekan rentang alat.
     *
     * Lembar ini nyampur satuan: titik 25 & 1412 dicatat dalam µS/cm, sementara
     * rentang alatnya `0–100 mS/cm`. Tanpa konversi, pembacaan 1413 µS/cm
     * ke-flag "jauh di luar rentang — kemungkinan komanya kegeser", padahal
     * 1413 µS/cm = 1,413 mS/cm, jelas di dalam rentang.
     *
     * Faktor 1000-nya bukan pengetahuan umum yang saya bawa dari luar — master
     * makai konversi yang sama persis di tiga tempat: `M23` (U kalibrator
     * `DATABASE!V14/1000`), `AB20`, dan `AB34` (U95% µS/cm → mS/cm).
     */
    public function nilaiDalamSatuanAlat(float $nilai, ?string $satuanTitik, Equipment $equipment): float
    {
        $satuanAlat = trim((string) $equipment->satuan);

        if ($satuanTitik === self::SATUAN_MIKRO && $satuanAlat === self::SATUAN_MILI) {
            return $nilai / 1000.0;
        }

        if ($satuanTitik === self::SATUAN_MILI && $satuanAlat === self::SATUAN_MIKRO) {
            return $nilai * 1000.0;
        }

        return $nilai;
    }

    /**
     * Master Conductivity: sel `T14` di kedua sheet sertifikat format `0.0`
     * → `25,8`. Baris kelima buat tabel di [CalibrationProfile::desimalSuhuEnv].
     */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /** Master Conductivity: sel `T15` format `0` → `51` (nilai 51,33). */
    public function desimalKelembabanEnv(): ?int
    {
        return 0;
    }

    /**
     * Label titik buat tampilan: nol belakang dibuang HANYA di bagian desimal.
     */
    private function labelTitik(float $nilai, int $desimal): string
    {
        $label = number_format($nilai, $desimal, '.', '');

        return str_contains($label, '.') ? rtrim(rtrim($label, '0'), '.') : $label;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Conductivity Meter',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_map(fn (array $t): float => $t['nilai'], self::TITIK),
            // Alat ini nyampur satuan dalam SATU lembar — dua titik pertama
            // µS/cm, titik ketiga mS/cm. `satuan` tunggal nggak cukup, jadi
            // yang mengikat itu `satuan` per baris tabel hasil.
            'satuan' => null,
            'satuan_campuran' => true,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Suhu larutan WAJIB diisi buat tiap titik yang pembacaannya diisi — '
                .'nilai acuan larutan digeser ikut suhu, jadi titik tanpa suhu nggak bisa dihitung. '
                .'Titik yang datanya belum cukup nggak ikut dihitung, dan sertifikatnya baru bisa '
                .'terbit sesudah dilengkapi admin.',
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
                        $this->field('equipment.range_resolusi', '2. Range/Resolution', 'teks', sumber: 'otomatis'),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                        $this->field('alat_merk', '5. Merk/Manufacture', 'teks'),
                        $this->field(
                            'thermohygro_standard_id',
                            '6. Thermohygro used',
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
                            ['nilai' => 'lab', 'label' => 'In lab'],
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
                        $this->field('suhu_akhir', 'Env. Condition — End', 'angka', satuan: '°C'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End', 'angka', satuan: '%RH'),
                    ],
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before adjustment Reading'),
                        $this->tabelHasil('sesudah_adjustment', 'After adjustment Reading'),
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
            ],
        ];
    }

    /**
     * Satu tabel hasil. Baris = titik standar; titik tengah muncul DUA KALI
     * (varian µS/cm dan mS/cm) karena master emang nyediain dua kolom buat
     * botol yang sama, dan teknisi ngisi salah satu. Yang keisi nentuin style
     * sertifikatnya.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul): array
    {
        $baris = [];

        foreach (self::TITIK as $t) {
            $varian = [$t];

            if ($t['varian_mili'] !== null) {
                $varian[] = $t['varian_mili'];
            }

            foreach ($varian as $v) {
                $baris[] = [
                    'titik_ukur' => $v['nilai'],
                    'label' => $this->labelTitik($v['nilai'], $v['desimal']),
                    'satuan' => $v['satuan'],
                    'resolusi' => $v['resolusi'],
                    'desimal' => $v['desimal'],
                    // Dua baris titik tengah saling meniadakan: begitu salah
                    // satu diisi, yang lain dikunci layar. Tanpa penanda ini,
                    // teknisi bisa ngisi dua-duanya dan sistem punya dua nilai
                    // buat satu botol.
                    'eksklusif_dengan' => $t['varian_mili'] !== null
                        ? ($v['satuan'] === self::SATUAN_MILI ? $t['nilai'] : $t['varian_mili']['nilai'])
                        : null,
                ];
            }
        }

        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'baris' => $baris,
            'kolom' => [
                // Label satuan dikosongin di level kolom — tiap BARIS bawa
                // satuannya sendiri (µS/cm atau mS/cm).
                ['kode' => 'pembacaan', 'label' => 'Reading', 'tipe' => 'angka', 'satuan' => null],
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

    /**
     * Baris [TITIK] yang paling dekat ke titik ukur, varian mS/cm titik tengah
     * ikut dipertimbangkan.
     *
     * @return array{nilai: float, satuan: string, resolusi: float, desimal: int}
     */
    private function titikTerdekat(float $titikUkur): array
    {
        $kandidat = [];

        foreach (self::TITIK as $t) {
            $kandidat[] = [
                'nilai' => $t['nilai'],
                'satuan' => $t['satuan'],
                'resolusi' => $t['resolusi'],
                'desimal' => $t['desimal'],
            ];

            if ($t['varian_mili'] !== null) {
                $kandidat[] = $t['varian_mili'];
            }
        }

        // Dibandingin RELATIF, bukan selisih mutlak: titik 1,412 dan 25 beda
        // tiga orde besaran, jadi selisih mutlak bakal selalu milih yang
        // kecil-kecil buat nilai yang deket nol.
        $terdekat = $kandidat[0];
        $skor = static fn (array $k): float => abs($k['nilai'] - $titikUkur) / max(abs($k['nilai']), 1e-9);

        foreach ($kandidat as $k) {
            if ($skor($k) < $skor($terdekat)) {
                $terdekat = $k;
            }
        }

        return $terdekat;
    }
}
