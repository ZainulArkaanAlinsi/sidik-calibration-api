<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Chlorin Meter (alat ke-3). Metode `SIDIK-IK-CAL-0524_Rev.1`, form
 * `SIDIK-FM-CAL-0531_Rev.2` — satu halaman (`Page 1 of 1`).
 *
 * ## TITIK = 1,74 & 1,83 mg/L — BUKAN 0,40 & 4,00 yang tercetak di form
 *
 * Lembar kerja cetak Rev.2 yang dipegang teknisi nulis `Solution Standard 0.40`
 * & `4.00`, dan baris STANDARD-nya "Chlorine Std. Solutions 0.4 / 4 mg/l".
 * Tiga sumber yang lebih baru bilang lain, dan ketiganya sepakat:
 *
 *  - **Lampiran akreditasi LK-285-IDN no. 42** (berlaku 28 Okt 2024–27 Okt
 *    2029): titik 1,74 mg/L (CMC 0,091) & 1,83 mg/L (CMC 0,08).
 *  - **Sheet DATABASE** workbook `Master Olah Data_Chlorine Meter`: Jenis
 *    Rentang 1,74 & 1,83 dengan CMC yang sama persis; standar fisiknya
 *    "Chlorine Standard Solution 1.74 mg/L" (U95 0,09) & "Chlorine Standar
 *    Cuvettes 1.83 mg/L" (U95 0,06), dua-duanya Supelco/Merck.
 *  - **Sesi asli 0189-CAL-624** (Hanna HI97711, Juni 2024): standar 1,74 &
 *    1,83, pembacaan 1,76 & 1,86.
 *
 * Sheet FORM VALIDASI revisi #6 (3 Apr 2024) nyatet "Update std Chlorine 4 mgl
 * (MRA/ISO 17034)" — set standarnya emang sempat gonta-ganti dan form cetaknya
 * ketinggalan. Yang dipakai yang ADA DI LINGKUP AKREDITASI: kalibrasi di titik
 * luar lampiran nggak bisa jadi sertifikat berakreditasi. Diputusin 5 Agt 2026.
 * Kalau lab mau balik ke 0,4/4, yang diurus dulu lampirannya — bukan file ini.
 *
 * ## Beda inti dari pH & Turbidimeter
 *
 *  - **Dua titik**, bukan tiga.
 *  - Resolusi **seragam** 0,01 mg/L di dua titik, jadi `resolusi`/`desimal` per
 *    baris sengaja NGGAK dikirim ke mobile (null = seragam). Cuma alat yang
 *    resolusinya beda per titik (Turbidimeter) yang butuh itu.
 *  - Budget **5 komponen** — ada `faktor_pengenceran` yang nggak dipunyai dua
 *    alat sebelumnya, dan komponen suhunya `normal ÷2` (pH) tapi ci-nya
 *    turunan titik (Turbidimeter). Lihat [komponenBudget].
 *
 * Engine budget dicocokin ke sheet `PERHITUNGAN U95%` (titik 1,74 → uc =
 * 0.045137721442932245) di `ChlorineBudgetTest`.
 */
class ChlorineProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0531_Rev.2';

    public const JUMLAH_PENGULANGAN = 5;

    public const SATUAN = 'mg/L';

    /**
     * Resolusi alat — SATU angka buat dua titik (sheet PERHITUNGAN U95%,
     * "Ketelitian Baca : 0.01 mg/L"). Ditaruh di sini, bukan per titik, karena
     * emang seragam; `resolusiTitik()` balikin ini apa pun titiknya.
     */
    public const RESOLUSI = 0.01;

    /**
     * Batas geser titik ukur dari nilai nominalnya waktu dicocokin.
     *
     * `titik_ukur` yang kesimpen itu nilai standar SESUDAH koreksi suhu (mis.
     * 1,7401 buat nominal 1,74), jadi perbandingan persis nggak akan pernah
     * ketemu. 0,05 mg/L cukup longgar buat nyerap koreksi, dan masih jauh lebih
     * kecil dari jarak antar-titik (1,74 ↔ 1,83 = 0,09) jadi nggak mungkin
     * ketuker.
     */
    private const TOLERANSI_TITIK = 0.05;

    /**
     * Dua titik standar chlorine yang lab BENERAN punya. Lihat docblock kelas
     * soal kenapa bukan 0,40 & 4,00.
     *
     * @var list<array{nilai: float, label: string, remark: string}>
     */
    public const TITIK = [
        // `remark` = kolom "Remark" di sertifikat asli lab. Dua titik chlorine
        // itu parameter yang BEDA, bukan cuma dua level di besaran yang sama:
        // 1,74 mg/L ngukur klorin bebas, 1,83 mg/L klorin total. Tanpa kolom
        // ini pelanggan nggak punya cara tau baris mana yang mana.
        ['nilai' => 1.74, 'label' => '1,74', 'remark' => 'Free Chlorine'],
        ['nilai' => 1.83, 'label' => '1,83', 'remark' => 'Total Chlorine'],
    ];

    /**
     * Keterangan parameter buat kolom "Remark" di sertifikat, dicocokin ke
     * titik ukur terdekat.
     *
     * Dicocokin pakai toleransi, bukan `===`: `titik_ukur` yang kesimpen itu
     * nilai standar setelah koreksi suhu (mis. 1,7401), jadi perbandingan
     * persis nggak akan pernah ketemu.
     */
    public function remarkTitik(float $titikUkur): ?string
    {
        foreach (self::TITIK as $t) {
            if (abs($titikUkur - $t['nilai']) <= self::TOLERANSI_TITIK) {
                return $t['remark'];
            }
        }

        return null;
    }

    /**
     * Koefisien sensitivitas faktor pengenceran (sheet `PERHITUNGAN U95%`,
     * kolom `ci` baris "Ketidakpastian Faktor Pengenceran" — sama di dua titik).
     *
     * **Angka ini NGGAK bisa diturunkan dari isi sheet.** Semua komponen lain
     * ketebak rumusnya dan udah dicocokin ulang sampai digit terakhir; yang ini
     * cuma muncul sebagai nilai jadi. Disalin apa adanya dari master lab, BUKAN
     * dikarang — kalau suatu saat micropipette/labu ukurnya diganti, angka ini
     * yang mesti diminta ulang ke lab, jangan dihitung sendiri.
     */
    public const CI_PENGENCERAN = 0.00028720439420210824;

    /**
     * U95% micropipette & labu ukur dari kepala sheet `PERHITUNGAN U95%`
     * (dua-duanya k=2), dipakai [ucPengenceran].
     */
    public const U95_MICROPIPETTE_ML = 0.00089;

    public const U95_LABU_UKUR_ML = 0.033;

    /**
     * Baris tabel STANDARD. Dua larutan chlorine namanya diambil apa adanya
     * dari sheet DATABASE; RTD & Victor sama kayak lembar pH & Turbidimeter.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Chlorine Standard Solution 1.74 mg/L',
            'cocok' => ['Chlorine Standard Solution 1.74 mg/L', 'QC1065-2ML'],
        ],
        [
            'label' => 'Chlorine Standar Cuvettes 1.83 mg/L',
            'cocok' => ['Chlorine Standar Cuvettes 1.83 mg/L', 'LRAD8911'],
        ],
        ['label' => 'RTD Sensor/SH1/20', 'cocok' => ['Termometer & Sensor Std.', 'SH1/20', '23P1005']],
        ['label' => 'Victor 14+/992613877', 'cocok' => ['Victor 14+', '992613877']],
    ];

    /**
     * Dua titik chlorine itu parameter yang BEDA (free vs total), masing-masing
     * punya larutannya sendiri — ketuker berarti sertifikatnya nyebut parameter
     * yang salah, bukan cuma angka yang meleset.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return [
            ['titik' => 1.74, 'standar' => ['Chlorine Standard Solution 1.74 mg/L', 'QC1065-2ML']],
            ['titik' => 1.83, 'standar' => ['Chlorine Standar Cuvettes 1.83 mg/L', 'LRAD8911']],
        ];
    }

    /**
     * SEMUA unit thermohygro lab (TH-1..TH-7), dikelompokkan Insitu vs Inlab.
     *
     * Daftar ini dulu dipersempit jadi empat unit, dengan alasan "biar teknisi
     * nggak bisa milih unit yang secara prosedur nggak boleh dipakai". Niatnya
     * bener, akibatnya kebalikannya: sertifikat pH master `012-CAL-524` MEMAKAI
     * TH-3 — dan TH-3 nggak ada di daftar. Teknisi jadi nggak punya pilihan
     * yang benar sama sekali, lalu mau nggak mau milih unit lain.
     *
     * Itu yang kejadian 10 Agt 2026: pH kepilih TH-2, Turbidimeter TH-7,
     * Chlorine TH-2. Env. Condition ketiganya meleset dari master BUKAN karena
     * salah hitung — pembacaan suhu & kelembabannya sama persis — tapi karena
     * tabel koreksi yang kepakai punya unit yang beda.
     *
     * Yang milih unit itu teknisi yang megang alatnya, dan dia tau unit mana
     * yang beneran dibawa. Sistem nggak punya dasar buat nebak itu, jadi
     * jangan menghalangi.
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
        return 'chlorine_meter';
    }

    /**
     * Lampiran akreditasi nulisnya "Chlorin Meter" (tanpa 'e') — itu yang
     * dicocokin `CalibrationProfileRegistry::untukNamaAlat()`, dan itu juga
     * yang diseed `ChlorineCapabilitySeeder`. Form kerjanya nulis "Chlorine
     * Meter"; mobile ngenalin dua-duanya.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Chlorin Meter';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_CHLORINE;
    }

    public function besaran(): string
    {
        return 'chlorine';
    }

    /**
     * Ketidakpastian baku gabungan faktor pengenceran, dalam **liter**.
     *
     *   Uc(ml) = √((U95_micropipette/2)² + (U95_labu_ukur/2)²)
     *          = √(0,000445² + 0,0165²) = 0,01650599966678783 ml
     *
     * Sheet mindahin ini ke liter sebelum masuk budget (kolom `U` baris
     * "Ketidakpastian Faktor Pengenceran" = 1,650599966678783e-05).
     */
    public static function ucPengenceran(): float
    {
        return sqrt(
            (self::U95_MICROPIPETTE_ML / 2) ** 2 + (self::U95_LABU_UKUR_ML / 2) ** 2
        ) / 1000;
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
     * Budget **5 komponen** chlorine buat satu titik, urutannya persis sheet
     * `PERHITUNGAN U95%`. Balik `null` kalau UTemperature belum keisi di
     * kemampuannya — jatuh ke jalur CMC lama, bukan ngarang angka.
     *
     * Dua hal yang beda dari dua profil sebelumnya:
     *
     *  1. **Komponen suhu `normal ÷2`** (kayak pH), TAPI ci-nya turunan titik
     *     `(UTemperature/400)·titik` (kayak Turbidimeter). Turbidimeter pakai
     *     `persegi ÷√3`; kalau ikut itu di sini, uc-nya meleset dari sheet.
     *  2. **`faktor_pengenceran`** — komponen yang cuma ada di alat ini, karena
     *     larutan standarnya diencerkan pakai micropipette + labu ukur sebelum
     *     dibaca. pH & turbidity dibaca langsung dari botolnya.
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
        $resolusi = $this->resolusiTitik($titikUkur) ?? (float) $equipment->resolusi;
        $ucPengenceran = self::ucPengenceran();

        // Sama rumusnya kayak Turbidimeter: ci = (UTemperature/400)·titik.
        // Dicek ulang ke sheet: titik 1,74 → 0,0015714280925323944 dan titik
        // 1,83 → 0,0016527088559392426, dua-duanya cocok.
        $ciSuhu = ($uTemperature / 400.0) * $titikUkur;

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U=%s %s, k=%s)',
                    $standard->nama, $standard->ketidakpastian, $standard->satuan_ketidakpastian ?? self::SATUAN, $kStandar,
                ),
                'distribusi' => 'normal',
                'u' => ($standard->ketidakpastian ?? 0.0) / $kStandar,
                'ci' => 1.0,
                'vi' => 200,
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
                    'UTemperature %s °C (÷k=2), ci (%s/400)·%s',
                    $uTemperature, $uTemperature, $titikUkur,
                ),
                'distribusi' => 'normal',
                'u' => $uTemperature / 2.0,
                'ci' => $ciSuhu,
                'vi' => 200,
            ],
            [
                'sumber' => 'faktor_pengenceran',
                'keterangan' => sprintf(
                    'Pengenceran micropipette + labu ukur, Uc=%s L (÷k=2), ci %s',
                    $ucPengenceran, self::CI_PENGENCERAN,
                ),
                'distribusi' => 'normal',
                'u' => $ucPengenceran / 2.0,
                'ci' => self::CI_PENGENCERAN,
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
     * Resolusi seragam — sama buat dua titik. Sengaja tetap ada method-nya
     * (bukan dihapus) supaya sejajar sama profil lain yang resolusinya beda
     * per titik.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return self::RESOLUSI;
    }

    /**
     * `null` = resolusinya seragam, mobile nggak perlu mad per baris. Lihat
     * `BarisTabelHasil.desimal` di sisi mobile.
     */
    public function desimalTitik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Larutan chlorine dibaca NOMINAL apa adanya — `ChlorineSeeder` sengaja nulis
     * `koefisien_suhu = null`, dan sheet `PERHITUNGAN` juga nggak punya kolom
     * koreksi suhu larutan. Suhu tetap dicatat teknisi (25,8 °C di sesi asli)
     * karena masuk budget lewat komponen `ketidakpastian_temperature`, bukan
     * buat ngoreksi nilai acuan.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Chlorine Meter',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_map(fn (array $t): float => $t['nilai'], self::TITIK),
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — '
                .'lembar kerja tetap bisa dikirim. Titik yang datanya belum cukup nggak ikut dihitung, '
                .'dan sertifikatnya baru bisa terbit sesudah dilengkapi admin.',
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
                        $this->field('equipment.range_resolusi', '2. Range/Resolution', 'teks', sumber: 'otomatis', satuan: self::SATUAN),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                        $this->field('alat_merk', '5. Merk/Manufacture', 'teks'),
                        $this->field(
                            'thermohygro_standard_id',
                            '6. Thermohygro Used',
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
                        // Label ngikut kertasnya: "Inlab" & "Insitu", bukan
                        // "In lab" kayak lembar pH.
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
     * Satu tabel hasil: baris = titik standar, kolom = pembacaan mg/L + suhu
     * larutan, Repeat 1..5.
     *
     * **`resolusi`/`desimal` sengaja nggak ikut** — resolusinya seragam 0,01,
     * dan di sisi mobile `null` itu artinya "seragam". Ngirim angka di sini
     * cuma bikin mobile mad per baris padahal nggak perlu.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul): array
    {
        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'baris' => array_map(
                fn (array $t): array => [
                    'titik_ukur' => $t['nilai'],
                    'label' => $t['label'],
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
}
