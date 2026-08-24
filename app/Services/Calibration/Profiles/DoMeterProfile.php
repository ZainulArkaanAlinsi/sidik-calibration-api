<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil DO Meter (alat ke-9). Metode `SIDIK-IK-CAL-0530_Rev.2`, form
 * `SIDIK-FM-CAL-0532_Rev.2` — satu halaman (`Page 1 of 1`). Master
 * `Master Olah Data_DO Meter.xlsm` (LK-285-IDN), sesi asli **0566-CAL-624**
 * (LPKL PDAM Tirtawening, Mettler Toledo Five Go, Juni 2024).
 *
 * ## TITIK = 8,77 mg/L — BUKAN 0,00 yang tercetak di form
 *
 * Lembar kerja cetak Rev.2 nulis `Solution Standard 0.00` dan baris STANDARD
 * "Zero Oxygen Std. 0.0 mg/l". Master-nya bilang lain, dan ini pola yang SAMA
 * PERSIS kayak Chlorin Meter (form nulis 0,4/4, akreditasi & master pakai
 * 1,74/1,83):
 *
 *  - **Sheet INPUT DATA** `G32` = 8,77 (Standard), pembacaan alat 8,82–8,83.
 *  - **Sheet DATABASE** baris standar: "Oxygen Standard Solution 8.77 mg/L"
 *    (Supelco/Merck, U95 0,15, k=2), dan CMC Laboratory `S5` = 0,16 mg/L —
 *    satu-satunya titik yang ADA CMC-nya (baris 6–9 kosong).
 *  - **Sheet SERTIFIKAT** nyetak Standard 8,77 / Instrument 8,824 / Correction
 *    −0,054 / U95 0,16.
 *
 * "Zero Oxygen" di form itu larutan buat NGE-NOL-IN alat sebelum diukur, bukan
 * titik kalibrasinya. Titik yang dilaporkan & terakreditasi 8,77 mg/L. Kalau
 * lab nambah titik lain (5,51 mg/L ada standarnya di DATABASE, tapi CMC-nya
 * belum diisi), yang diurus dulu CMC di kemampuan — bukan file ini.
 *
 * ## %O2 (20,9) SENGAJA nggak diolah
 *
 * Master punya kolom %O2 di INPUT DATA & blok kedua di SERTIFIKAT, TAPI seluruh
 * perhitungannya `#DIV/0!`/`#REF!`: blok budget %O2 nggak punya komponen suhu
 * yang kebagi (M40 `#DIV/0!`), dan pembacaan %O2-nya nol semua. Itu keluaran
 * rusak di workbook, bukan hasil terakreditasi — jadi profil ini cuma ngolah
 * mg/L, sama kayak yang beneran kecetak di sertifikat. Form teknisi juga cuma
 * nyediain kolom mg/l + °C, nggak ada kolom %O2.
 *
 * ## Beda inti dari Chlorin Meter
 *
 *  - **Satu titik**, bukan dua.
 *  - Komponen pengenceran `ci`-nya bisa DITURUNKAN dari sheet
 *    (`ci = Uc_pengenceran/100 · titik`), nggak kayak Chlorine yang ci-nya
 *    konstanta yang nggak ketebak. Uc pengenceran-nya sendiri sama angkanya
 *    (0,01650599966678783), cuma dipakai beda: Chlorine ÷1000 lalu ÷2 jadi
 *    `u`; DO Meter ÷1000 ÷2 jadi `u` (sama) TAPI ci = (Uc/100)·titik.
 *
 * Budget dicocokin ke sheet `PERHITUNGAN U95%` (uc = 0,07510912040209242,
 * v_eff = 201,15502343260252, U = 0,14810290560096973) di `DoMeterBudgetTest`.
 */
class DoMeterProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0532_Rev.2';

    public const JUMLAH_PENGULANGAN = 5;

    public const SATUAN = 'mg/L';

    /** Resolusi alat — INPUT DATA `E16` = 0,01 mg/L (seragam, satu titik). */
    public const RESOLUSI = 0.01;

    /**
     * Batas geser titik ukur waktu dicocokin ke pasangan standar. Longgar
     * secukupnya (0,05 mg/L) — di sini cuma ada satu titik, jadi risiko ketuker
     * nol; angka ini murni buat nyerap selisih pembulatan.
     */
    private const TOLERANSI_TITIK = 0.05;

    /**
     * U95% micropipette & labu ukur dari kepala sheet `PERHITUNGAN U95%`
     * (DATABASE `V17`/`V18`, dua-duanya k=2), dipakai [ucPengenceran].
     *
     * CATATAN satuan: `V17` di sheet = 0,89 dan dibagi 1000 di dalam rumus
     * (`Q16 = V17/2/1000`), jadi kontribusi micropipette-nya 0,000445. Ditulis
     * apa adanya (0,89) biar sejajar sama sheet; pembagian /1000-nya di
     * [ucPengenceran].
     */
    public const U95_MICROPIPETTE = 0.89;

    public const U95_LABU_UKUR = 0.033;

    /**
     * Satu titik DO yang lab BENERAN punya CMC-nya (DATABASE `S5`). Lihat
     * docblock kelas soal kenapa bukan 0,00.
     *
     * `remark` = keterangan parameter buat sertifikat. DO Meter cuma satu
     * parameter (oksigen terlarut), jadi satu baris.
     *
     * @var list<array{nilai: float, label: string, remark: string}>
     */
    public const TITIK = [
        ['nilai' => 8.77, 'label' => '8,77', 'remark' => 'Dissolved Oxygen'],
    ];

    /**
     * Baris tabel STANDARD di form. Larutan oksigen 8,77 & 5,51 mg/L dua-duanya
     * kecetak di "Standard used" sertifikat asli (dibawa ke lapangan), plus RTD
     * & Victor yang sama kayak lembar alat lain. Yang jadi TITIK cuma 8,77.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Oxygen Standard Solution 8.77 mg/L',
            'cocok' => ['Oxygen Standard Solution 8.77 mg/L', 'LRAD6897'],
        ],
        [
            'label' => 'Oxygen Standard Solution 5.51 mg/L',
            'cocok' => ['Oxygen Standard Solution 5.51 mg/L', 'LRAD9311'],
        ],
        ['label' => 'RTD Sensor/SH1/20', 'cocok' => ['Termometer & Sensor Std.', 'SH1/20', '23P1005']],
        ['label' => 'Victor 14+/992613877', 'cocok' => ['Victor 14+', '992613877']],
    ];

    /**
     * Thermohygro yang tercetak di kertas DO Meter: TH-2, TH-6, TH-7 (Insitu),
     * TH-4 (Inlab). Sama kebijakannya kayak profil lain — SEMUA unit tetap
     * dikirim biar teknisi bisa milih unit yang beneran dibawa; kertas yang
     * cuma nyetak empat kotak bukan alasan menghalangi yang lain.
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
        return 'do_meter';
    }

    public function namaAlatKemampuan(): string
    {
        return 'DO Meter';
    }

    /**
     * Lampiran akreditasi & DATABASE nulis "DO Meter" (pakai spasi); sebagian
     * data alat pelanggan nulisnya nempel. Dua-duanya didaftarin — yang nyampe
     * ke sini teks bebas, bukan enum.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['DOMeter'];
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_DO_METER;
    }

    public function besaran(): string
    {
        return 'do';
    }

    /**
     * DO Meter NGGAK divonis PASS/FAIL. Master `Master Olah Data_DO Meter.xlsm`
     * nggak punya kolom batas keberterimaan (MPE) sama sekali, dan sertifikat
     * aslinya cuma nyetak Standard/Instrument/Correction/U95 tanpa vonis —
     * sama kayak Autoklaf. `keputusan` sesi jadi null, bukan dikarang PASS.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * `k` di sertifikat DO Meter dicetak TANPA desimal.
     *
     * Master nyimpen 1,9718365067798587 di sel `SERTIFIKAT!V26`, tapi selnya
     * diformat `0` — jadi yang kecetak di sertifikat asli **`2`**, bukan
     * `1,97`. Sama kasusnya kayak Spectrophotometer.
     *
     * Nilai cocok ≠ bentuk cocok: tanpa override ini angkanya benar tapi
     * dokumennya beda dari yang selama ini diterima pelanggan, dan itu jenis
     * selisih yang baru ketahuan waktu ada yang mbandingin dua sertifikat
     * berdampingan. Blok hasil lain (`E24`/`N24`/`S24`/`Q25`) formatnya `0.00`
     * — dua desimal, dan itu sudah ditangani jalur `desimal` biasa.
     */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /**
     * Keterangan parameter buat kolom "Remark" sertifikat, dicocokin ke titik
     * terdekat.
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
     * Resolusi seragam — cuma satu titik, tapi method-nya tetap ada biar
     * sejajar sama profil lain.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return self::RESOLUSI;
    }

    /**
     * `null` = resolusinya seragam, mobile nggak perlu mad per baris.
     */
    public function desimalTitik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Larutan oksigen dibaca NOMINAL apa adanya — nilai standar 8,77 nggak
     * dikoreksi suhu di master (`C21 = INPUT DATA G32` langsung, nggak ada
     * kolom koreksi suhu larutan). Suhu tetap dicatat teknisi karena masuk
     * budget lewat komponen `ketidakpastian_temperature`, bukan buat ngoreksi
     * nilai acuannya.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Titik 8,77 dibaca pakai larutan oksigen 8,77 mg/L. Satu pasangan, tapi
     * tetap dideklarasiin biar baris tabel bawa `standard_id`-nya dari master —
     * dari situ sertifikat dapat lot & ketertelusuran.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return [
            ['titik' => 8.77, 'standar' => ['Oxygen Standard Solution 8.77 mg/L', 'LRAD6897']],
        ];
    }

    /**
     * Ketidakpastian baku gabungan faktor pengenceran, dalam **liter**.
     *
     *   Uc(ml) = √((U95_micropipette/2/1000)² + (U95_labu_ukur/2)²)
     *          = √(0,000445² + 0,0165²) = 0,01650599966678783
     *   Uc(L)  = Uc(ml) / 1000 = 1,650599966678783e-05
     *
     * Sama angka mentahnya kayak Chlorin Meter (micropipette & labu ukur unit
     * yang sama), cuma DO Meter mecahnya beda: micropipette dibagi 1000 DI
     * DALAM akar (V17/2/1000), bukan sesudahnya. Hasil akhirnya identik.
     */
    public static function ucPengenceran(): float
    {
        return sqrt(
            (self::U95_MICROPIPETTE / 2 / 1000) ** 2 + (self::U95_LABU_UKUR / 2) ** 2
        );
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
     * Budget **5 komponen** DO Meter buat titik 8,77 mg/L, urutannya persis
     * sheet `PERHITUNGAN U95%`. Balik `null` kalau UTemperature belum keisi di
     * kemampuannya — jatuh ke jalur CMC lama, bukan ngarang angka.
     *
     * Struktur identik Chlorin Meter, DUA hal yang perlu diperhatiin:
     *
     *  1. **Komponen suhu** `normal ÷2`, ci `(UTemperature/400)·titik` — sama
     *     rumus persis kayak Chlorine & Turbidimeter.
     *  2. **`faktor_pengenceran`** — `u = Uc_pengenceran(L)/2`, ci
     *     `(Uc_pengenceran_ml/100)·titik`. Chlorine ci-nya konstanta yang nggak
     *     ketebak; DO Meter ci-nya beneran ketebak dari sheet. `u`-nya identik
     *     dua alat (8,253e-06) karena micropipette & labu ukurnya unit sama.
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
        if ($kemampuan->u_temperature === null) {
            return null;
        }

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;
        $uTemperature = (float) $kemampuan->u_temperature;
        $resolusi = $this->resolusiTitik($titikUkur) ?? (float) $equipment->resolusi;

        // Uc pengenceran dalam ml (0,01650599966678783), buat ci dan buat
        // diturunin ke L (÷1000) sebagai `u`.
        $ucPengenceranMl = self::ucPengenceran();
        $ucPengenceranL = $ucPengenceranMl / 1000;

        // ci turunan titik: sama pola suhu, pembaginya 400 vs 100.
        $ciSuhu = ($uTemperature / 400.0) * $titikUkur;
        $ciPengenceran = ($ucPengenceranMl / 100.0) * $titikUkur;

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
                    'Pengenceran micropipette + labu ukur, Uc=%s L (÷k=2), ci (%s/100)·%s',
                    $ucPengenceranL, $ucPengenceranMl, $titikUkur,
                ),
                'distribusi' => 'normal',
                'u' => $ucPengenceranL / 2.0,
                'ci' => $ciPengenceran,
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
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - DO Meter',
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
                        // Thermohygro di kertas DO Meter ada di kolom kanan blok
                        // CALIBRATION RESULT (kotak centang TH-2/6/7/4), bukan di
                        // EQUIPMENT IDENTITY kayak lembar pH/Chlorine.
                        $this->field(
                            'thermohygro_standard_id',
                            'Thermohygro Used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
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
     * Satu tabel hasil: baris = titik standar (cuma 8,77), kolom = pembacaan
     * mg/L + suhu larutan, Repeat 1..5. Ada dua tabel: Before & After
     * adjustment — sertifikat pakai yang After (master `N24 = PERHITUNGAN G42`).
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
