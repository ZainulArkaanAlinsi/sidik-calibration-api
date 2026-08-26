<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\ThermocoupleCalculator;
use Carbon\Carbon;

/**
 * Profil **Thermocouple** (alat ke-18) — lampiran akreditasi LK-285-IDN
 * "Suhu dan Kelembapan" no. 5, metode `SIDIK-IK-CAL-0529_Rev.2; ASTM E220-13`.
 *
 * Master: `Master_Olah_Data_Suhu_Thermocouple.xlsm`, sesi **0513-CAL-1124**
 * (order 2411.50.I, PT Kaldu Sari Nabati Indonesia, Hanna HI93530 s/n J0037794,
 * 3 Desember 2024, tiga titik 50/100/150 °C).
 *
 * ## Ini BUKAN workbook TIDS yang selama ini ditunggu
 *
 * Kekeliruan ini nyaris terjadi dan pantas dicatat, karena semua tanda
 * permukaannya cocok. Workbook ini memuat PERSIS keempat sheet yang selama ini
 * disebut hilang untuk TIDS — `PERHITUNGAN U95%`, `Variasi axial Dryblok A`,
 * `Variasi axial Dryblok B`, `stdev drywell` — lengkap dengan dryblock Isotech &
 * Techne yang sebelumnya tidak pernah muncul di repo ini. Dan `PERHITUNGAN
 * U95%!D6` menulis dengan huruf besar-kecil yang benar:
 * *"Temperature indikator dengan sensor"*.
 *
 * Yang membantahnya angka, bukan label. Tabel CMC workbook ini
 * (`DATABASE!R5:S7`) berbunyi **0,84 / 1,5 / 3,3 °C** — dan itu baris **no. 5
 * Thermocouple** di lampiran akreditasi, bukan **no. 2 TIDS** yang berbunyi
 * 0,86 / 1,4 / 3,1 °C. Tiga angka, tiga-tiganya beda, dan yang dipakai rumus
 * budget-nya sendiri (`AC34`) tabel yang 0,84.
 *
 * Jadi `D6` itu label basi — workbook ini turunan dari master TIDS dan judul
 * jenis alatnya ikut terbawa waktu disalin. **K2 tetap terbuka**, `TidsProfile`
 * tidak disentuh, dan blokir U95 TIDS tetap berdiri. Sekali lagi: label yang
 * kelihatan meyakinkan kalah oleh tabel yang benar-benar dibaca rumusnya.
 *
 * ## Nomor metode: yang tercetak di master BEDA dari yang diakreditasi
 *
 * `SERTIFIKAT!O13` sesi contoh mencetak `SIDIK-IK-CAL-0502_Rev.3` — itu metode
 * **TITS**. Bukan karena lab memakai metode TITS untuk termokopel, tapi karena
 * dropdown `Metode_Kalibrasi` di workbook Thermocouple berhenti di nomor 24 dan
 * **tidak memuat baris Thermocouple sama sekali**; teknisinya memilih yang
 * paling dekat. Workbook Termometer Gelas — yang daftarnya lebih panjang —
 * memuatnya di nomor 29: `Thermocouple → SIDIK-IK-CAL-0529_Rev.2`, dan itu cocok
 * dengan lampiran akreditasi.
 *
 * Yang dipakai di sini **`SIDIK-IK-CAL-0529_Rev.2`**: lampiran akreditasi
 * dokumen yang mengikat lab, dan nomor metode ikut tercetak di sertifikat yang
 * diaudit. Selisihnya dicatat di `docs/pertanyaan-lab-suhu-3alat.md` supaya lab
 * memutuskan apakah sertifikat 0513-CAL-1124 perlu diperbaiki.
 *
 * ## Titiknya TIDAK tetap
 *
 * Sama seperti TITS: yang menentukan rentang UUT yang datang. [TITIK_SARAN]
 * cuma isian awal layar; yang berlaku angka yang diketik teknisi.
 *
 * ## Yang TIDAK divonis
 *
 * Master tidak punya satu pun kolom batas keberterimaan — [punyaToleransi]
 * `false`, `keputusan` sesi null.
 *
 * @see ThermocoupleCalculator — rantai koreksi & budget, berikut penyimpangan master
 * @see TabelKalibratorSuhu3Alat
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class ThermocoupleProfile extends ProfilSuhuPasangan
{
    /**
     * Nomor formulir lembar kerja — SENGAJA `null`.
     *
     * Seluruh workbook cuma memuat `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet
     * `SERTIFIKAT`, dan itu formulir SERTIFIKAT yang dipakai bersama semua alat,
     * bukan lembar kerjanya. Nomor formulir dokumen terkendali; menaruh nomor
     * karangan di lembar yang ikut diaudit lebih mahal daripada kolom kosong
     * yang jelas kosong. Sama persis alasan TITS `null` sampai formulir cetaknya
     * ketemu.
     */
    public const KODE_DOKUMEN = null;

    /** Metode dari lampiran akreditasi no. 5 — lihat docblock kelas. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0529_Rev.2';

    /** Nomor metode yang TERCETAK di master, disimpan supaya selisihnya bisa diuji. */
    public const KODE_METODE_TERCETAK_MASTER = 'SIDIK-IK-CAL-0502_Rev.3';

    public const SATUAN = '°C';

    public const NOMOR_LINGKUP = 'LK-285-IDN';

    /**
     * Titik awal layar — tiga titik sesi master, dilebarkan ke enam yang
     * mencakup rentang akreditasi (−20…600 °C).
     *
     * @var list<float>
     */
    public const TITIK_SARAN = [50.0, 100.0, 150.0, 200.0, 400.0, 600.0];

    /**
     * Baris STANDARD `SERTIFIKAT!B41:B42` + `DATABASE!Q14:U17` / `Q20:V23`.
     *
     * `cocok` memuat NAMA lebih dulu, baru serial — lihat
     * [ProfilSuhuPasangan::tautkanStandar] soal seri `23P1005` yang dipakai dua
     * baris master sekaligus.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Temperature Calibrator Constant 40T', 'cocok' => ['Temperature Calibrator Constant 40T', '99875850']],
        ['label' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal', 'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005']],
        ['label' => 'PRT Pt-100', 'cocok' => ['PRT Pt-100', 'PRT PT100', 'SH1/20']],
        ['label' => 'Thermocouple Type K', 'cocok' => ['Thermocouple Type K', 'TC-01,02']],
        ['label' => 'Thermocouple Type N', 'cocok' => ['Thermocouple Type N', 'TCN-06,11']],
    ];

    /**
     * Dua dryblock lab, `PERHITUNGAN U95%!U9:AH10`.
     *
     * Rentangnya dicetak di lembar kerjanya sendiri (`INPUT DATA!R25`):
     * `A: -20 ~150 °C`, `B: 150~600 °C`.
     */
    public const DRYBLOCK = [
        ['nilai' => 'A', 'label' => 'A — Isotech Fast Cal Low (−20…150 °C)', 'min' => -20.0, 'maks' => 150.0],
        ['nilai' => 'B', 'label' => 'B — Techne Tecal 700xs (150…600 °C)', 'min' => 150.0, 'maks' => 600.0],
    ];

    /**
     * Label kolom pengulangan sisi STANDAR & sisi UUT.
     *
     * Detiknya bukan hiasan: standar dan UUT dibaca BERGANTIAN dalam satu
     * sapuan 90 detik, dan urutan itu yang bikin dua deret bisa dipasangkan
     * per titik. Tercetak persis begini di `INPUT DATA!D33:K33` & `D50:K50`.
     */
    public const LABEL_STANDAR = ['0″', '20″', '40″', '60″', '80″'];

    public const LABEL_UUT = ['10″', '30″', '50″', '70″', '90″'];

    private ?ThermocoupleCalculator $kalkulator = null;

    public function __construct(private readonly TabelKalibratorSuhu3Alat $tabel = new TabelKalibratorSuhu3Alat) {}

    public function kode(): string
    {
        return 'thermocouple';
    }

    /**
     * Ejaan PERSIS lampiran akreditasi no. 5 — satu kata, tanpa embel-embel.
     *
     * Kunci pencocokan ke `equipments.nama_alat_kemampuan` DAN ke
     * `calibration_capabilities.nama_alat`; beda satu huruf berarti alatnya
     * jatuh ke profil default (pH) tanpa error apa pun.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Thermocouple';
    }

    /**
     * Ejaan yang dipakai pelanggan & master.
     *
     * `Thermocouple Thermometer` itu nama alat di sesi master (`INPUT DATA!E10`).
     * `Termokopel` ejaan Indonesia yang dipakai teknisi.
     *
     * Kunci pendek seperti "TC" SENGAJA tidak didaftarkan: pencocokan menerima
     * kunci yang nempel di TENGAH nama, dan dua huruf akan menyeret alat lain
     * yang kebetulan memuatnya ke lembar termokopel.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Thermocouple Thermometer', 'Termokopel'];
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_THERMOCOUPLE;
    }

    public function besaran(): string
    {
        return 'suhu';
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /** Kolom hasil sertifikat SATU desimal (`SERTIFIKAT!E20:L22` format `0.0`). */
    public function desimalSertifikat(): ?int
    {
        return 1;
    }

    /** `U95` DUA desimal (`SERTIFIKAT!L34` format `0.00`). */
    public function desimalU95(): ?int
    {
        return 2;
    }

    /**
     * Hitung seluruh sesi sekaligus — budget-nya satu untuk semua titik.
     *
     * Tipe sensor standar, dryblock, dan merk kalibrator dibaca dari SESI &
     * `standards`, bukan dari master alat: satu termokopel bisa datang lagi
     * dikalibrasi di dryblock lain, dan tiap kombinasi punya tabel koreksinya
     * sendiri.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard|null, konteks?: array<string, mixed>}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $titik = array_values($titik);
        $konteks = $titik[0]['konteks'] ?? [];
        $standar = $titik[0]['standard'] ?? null;

        $tipeSensor = $this->normalkanTipeSensor($konteks['tipe_sensor'] ?? null);
        $dryblock = strtoupper((string) ($konteks['alat_bantu'] ?? ''));
        $merk = $this->merkKalibrator($standar);

        $kurang = $this->syaratKurang($tipeSensor, $dryblock, $merk, $standar, $equipment);

        if ($kurang !== null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    static fn (array $t): array => ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $kurang],
                    $titik,
                ),
            ];
        }

        $kemampuan = $this->kemampuanUntukTitik($equipment, $titik);

        if ($kemampuan === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    fn (array $t): array => [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => sprintf(
                            'Lab belum punya baris CMC Thermocouple yang mencakup titik %s °C — lampiran '
                            .'akreditasi cuma memuat −20…600 °C dalam tiga pita. Sesinya boleh disimpan, tapi '
                            .'U95-nya nggak bisa diterbitkan.',
                            $this->angka((float) $t['titik_ukur']),
                        ),
                    ],
                    $titik,
                ),
            ];
        }

        $masukan = array_map(
            static fn (array $t): array => [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => (float) $t['titik_ukur'],
                'standar' => $t['konteks']['standar'] ?? [],
                'uut' => $t['konteks']['uut'] ?? [],
                'no_probe' => (int) ($t['konteks']['no_probe'] ?? 0),
            ],
            $titik,
        );

        $hasil = ($this->kalkulator ??= new ThermocoupleCalculator($this->tabel))->hitungSesi($masukan, [
            'merk_kalibrator' => $merk,
            'tipe_sensor' => $tipeSensor,
            'dryblock' => $dryblock,
            'resolusi' => (float) $equipment->resolusi,
            'cmc' => (float) $kemampuan->ketidakpastian_terbaik,
        ]);

        $hitungan = $this->barisHitungan($hasil, $standar, $kemampuan);

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $hasil['belum_dihitung']];
    }

    /**
     * Peringatan sebelum sertifikat terbit — tiga hal yang kalau lolos tidak
     * memunculkan error di mana pun.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];
        $tipeSensor = $this->normalkanTipeSensor($this->atributSesi($sesi, 'tipe_sensor'));
        $dryblock = strtoupper((string) ($this->atributSesi($sesi, 'alat_bantu') ?? ''));

        if ($tipeSensor === null) {
            $peringatan[] = [
                'kode' => 'thermocouple_tipe_sensor_kosong',
                'pesan' => 'Tipe sensor STANDAR belum dipilih. Koreksi kalibrator, ketidakpastiannya, dan '
                    .'drift-nya beda per tipe — tanpa itu sesinya nggak kehitung sama sekali.',
            ];
        }

        if ($dryblock === '') {
            $peringatan[] = [
                'kode' => 'thermocouple_dryblock_kosong',
                'pesan' => 'Dryblock yang dipakai belum dipilih. Dua komponen budget (variasi aksial & antar '
                    .'lubang) diambil dari tabel dryblock-nya masing-masing.',
            ];
        }

        // Set point di luar rentang fisik dryblock yang dicentang. Bukan error —
        // angkanya tetap keluar — tapi variasi aksialnya diukur di rentang lain.
        $blok = collect(self::DRYBLOCK)->firstWhere('nilai', $dryblock);

        if ($blok !== null) {
            $diLuar = $sesi->rawMeasurements()
                ->where('peran_sensor', 'uut')
                ->pluck('titik_ukur')
                ->map(static fn ($v): float => (float) $v)
                ->unique()
                ->filter(static fn (float $t): bool => $t < $blok['min'] || $t > $blok['maks'])
                ->values();

            if ($diLuar->isNotEmpty()) {
                $peringatan[] = [
                    'kode' => 'thermocouple_titik_di_luar_dryblock',
                    'pesan' => sprintf(
                        'Titik %s °C di luar rentang dryblock %s (%s…%s °C) yang dicentang. Angkanya tetap '
                        .'keluar, tapi variasi aksial & antar-lubang yang dipakai diukur di rentang lain.',
                        $diLuar->implode(', '),
                        $dryblock,
                        $this->angka($blok['min']),
                        $this->angka($blok['maks']),
                    ),
                ];
            }
        }

        return $peringatan;
    }

    /**
     * Kalibrator yang menempel ke tiap baris tabel sebagai NILAI AWAL.
     *
     * Yang dipasang Yokogawa, bukan Constant, dan itu bukan urutan abjad: sesi
     * master memakainya, dan sertifikat Constant 40T sudah lewat masa berlaku
     * (28 Agustus 2025). Kalau lab memakai Constant, teknisi mengganti
     * pilihannya — dan profil ini membaca merk dari standar yang benar-benar
     * dikirim, bukan dari daftar ini.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return array_map(
            static fn (float $t): array => [
                'titik' => $t,
                'standar' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005'],
            ],
            self::TITIK_SARAN,
        );
    }

    /**
     * @return list<array{label: string, cocok: list<string>}>
     */
    protected function standardTercetak(): array
    {
        return self::STANDARD_TERCETAK;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bentukLengkap(?Equipment $equipment): array
    {
        $alat = TabelKalibratorSuhu3Alat::ALAT_THERMOCOUPLE;

        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'judul' => 'Calibration Worksheet - Thermocouple',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'larutan_standar' => self::TITIK_SARAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — lembar kerja '
                .'tetap bisa dikirim. Khusus alat ini: TIPE SENSOR STANDAR, DRYBLOCK, dan NO. TERMOKOPEL tiap '
                .'baris wajib diisi. Ketiganya nentuin ANGKA, bukan catatan — tabel koreksi & dua komponen '
                .'budget diambil dari situ. Tiap titik dibaca DUA deret: standar (detik ke-0, 20, 40, 60, 80) '
                .'dan UUT (detik ke-10, 30, 50, 70, 90).',
            'bagian' => [
                ...$this->bagianUmumAtas([
                    $this->field('spesifikasi_alat.rentang_ukur', '5. Rentang Ukur', 'teks', satuan: self::SATUAN),
                    $this->field('spesifikasi_alat.kapasitas', '6. Kapasitas Alat', 'angka', satuan: self::SATUAN),
                    $this->field('spesifikasi_alat.resolusi', '7. Resolusi Indikator', 'angka', satuan: self::SATUAN),
                ]),
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'PENGERJAAN',
                    'field' => [
                        $this->field('tipe_sensor', 'Standar Sensor', 'pilihan', pilihan: array_map(
                            static fn (string $t): array => ['nilai' => $t, 'label' => $t],
                            TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR,
                        )),
                        $this->field('alat_bantu', 'Dry Block Used', 'pilihan', pilihan: array_map(
                            static fn (array $d): array => ['nilai' => $d['nilai'], 'label' => $d['label']],
                            self::DRYBLOCK,
                        )),
                        ...$this->fieldLokasi(),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 1,
                    'judul' => 'DATA HASIL KALIBRASI',
                    'field' => $this->fieldKondisiLingkungan(),
                    'tabel' => [
                        [
                            ...$this->tabelPembacaan(
                                'standar',
                                'Pembacaan Standard',
                                self::TITIK_SARAN,
                                self::SATUAN,
                                'Data Hasil Pengukuran/Pengulangan (PRT1…PRT5)',
                                labelPengulangan: self::LABEL_STANDAR,
                            ),
                            // Kolom tambahan yang cuma alat ini punya: tiap baris
                            // standar menyebut PROBE mana yang dicelup. Tersimpan
                            // ke `raw_measurements.sensor_ke`.
                            'kolom_baris' => [
                                $this->field('no_probe', 'No. Termokopel', 'pilihan', pilihan: $this->pilihanProbe($alat)),
                            ],
                            'catatan' => 'Type N mulai dari nomor 3; PRT PT100 (RTD) selalu nomor 17.',
                        ],
                        $this->tabelPembacaan(
                            'uut',
                            'Pembacaan UUT',
                            self::TITIK_SARAN,
                            self::SATUAN,
                            'Data Hasil Pengukuran/Pengulangan (PRT1…PRT5)',
                            labelPengulangan: self::LABEL_UUT,
                        ),
                    ],
                ],
                $this->bagianPenutup(),
            ],
        ];
    }

    /**
     * Pilihan No. Termokopel untuk seluruh tipe sensor sekaligus, ber-`grup`
     * supaya layar bisa menyaringnya begitu tipe sensor dipilih.
     *
     * @return list<array<string, string>>
     */
    private function pilihanProbe(string $alat): array
    {
        $pilihan = [];

        foreach (TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR as $tipe) {
            foreach ($this->tabel->nomorProbeTersedia($alat, $tipe) as $nomor) {
                $pilihan[] = [
                    'nilai' => (string) $nomor,
                    'label' => sprintf('%d — %s', $nomor, $this->tabel->probe($alat, $tipe, $nomor) ?? '?'),
                    'grup' => $tipe,
                ];
            }
        }

        return $pilihan;
    }

    /**
     * Baris `uncertainty_calculations` tiap titik.
     *
     * Semua titik membawa `uc`/`v_eff`/`k`/`U95` yang SAMA — itu memang yang
     * dicetak sertifikatnya (satu baris `Uncertainty 95% ±` di bawah tabel).
     *
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function barisHitungan(array $hasil, ?Standard $standar, CalibrationCapability $kemampuan): array
    {
        // RSS komponen Type B saja — aturannya identik dengan
        // `GumCalculator::hitungDariBudget()` supaya dua jalur ini tidak berbeda
        // arti untuk kolom yang sama.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] !== 't-student',
            ),
        )));

        $sekarang = Carbon::now();
        $audit = $this->jejakAudit($hasil, $kemampuan);

        return array_map(static fn (array $t): array => [
            'standard_id' => $standar?->id,
            'titik_ke' => $t['titik_ke'],
            // `titik_ukur` menyimpan nilai STANDAR TERKOREKSI, bukan set point.
            // Itu kolom yang dicetak sertifikat sebagai `Standard Reading`
            // (`CertificateSnapshotBuilder`: `standard_value = titik_ukur`), dan
            // aturannya sama untuk TITS & Enclosure. Set point mentahnya tetap
            // hidup di `raw_measurements.titik_ukur`.
            'titik_ukur' => $t['standar_terkoreksi'],
            'rata_rata' => $t['uut_terkoreksi'],
            'error' => $t['uut_terkoreksi'] - $t['standar_terkoreksi'],
            // `SERTIFIKAT!L20 = E20-J20` — standar dikurangi UUT.
            'koreksi' => $t['koreksi'],
            'standar_deviasi' => $t['standar_deviasi_uut'],
            'jumlah_pengulangan' => count($t['pembacaan_uut']),
            // NOL, dan itu bukan kolom yang lupa diisi: budget Thermocouple
            // sembilan komponen dan tidak satu pun keterulangan — lihat
            // catatan audit `type_a_tidak_masuk_budget`.
            'type_a' => 0.0,
            'type_b_components' => [
                ...$audit,
                [
                    'sumber' => 'probe_dipakai',
                    'keterangan' => sprintf(
                        'Probe standar titik ini %s (No. %d) — koreksi probe %s °C, koreksi meter %s °C, '
                        .'index tabel %s °C.',
                        $t['probe'],
                        $t['no_probe'],
                        rtrim(rtrim(number_format($t['koreksi_probe_standar'], 8, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($t['koreksi_meter_standar'], 8, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($t['index_standar'], 2, '.', ''), '0'), '.'),
                    ),
                    'distribusi' => '-',
                    'nilai' => $t['koreksi_probe_standar'],
                ],
            ],
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
            // Nggak divonis — master nggak punya kolom batas keberterimaan.
            'toleransi' => null,
            'keputusan' => null,
            'calculated_at' => $sekarang,
        ], $hasil['titik']);
    }

    /**
     * Baris `type_b_components`: komponen budget + tiap penyimpangan master
     * yang ditiru, berikut konteks sesinya.
     *
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, CalibrationCapability $kemampuan): array
    {
        $audit = array_map(
            static fn (array $k): array => [
                'sumber' => $k['sumber'],
                'keterangan' => $k['keterangan'],
                'distribusi' => $k['distribusi'],
                'nilai' => $k['u'],
                'ci' => $k['ci'],
                'vi' => $k['vi'],
                'disertakan' => $k['disertakan'],
            ],
            $hasil['budget'],
        );

        foreach ($hasil['catatan_audit'] as $catatan) {
            $audit[] = [
                'sumber' => $catatan['kode'],
                'keterangan' => $catatan['pesan'],
                'distribusi' => '-',
                'nilai' => $hasil['ketidakpastian_diperluas'],
            ];
        }

        $audit[] = [
            'sumber' => 'konteks_sesi',
            'keterangan' => sprintf(
                'STDEV terbesar %s °C dari %d titik (nggak masuk budget). CMC %s °C, U95 dilaporkan dari %s.',
                rtrim(rtrim(number_format($hasil['standar_deviasi_maks'], 8, '.', ''), '0'), '.'),
                count($hasil['titik']),
                rtrim(rtrim(number_format($hasil['cmc'], 8, '.', ''), '0'), '.'),
                $hasil['sumber_u95'] === 'cmc' ? 'lantai CMC' : 'hitungan budget',
            ),
            'distribusi' => '-',
            'nilai' => $hasil['ketidakpastian_diperluas'],
            'cmc' => $hasil['cmc'],
            'cmc_id' => $kemampuan->id,
            'index_maks' => $hasil['index_maks'],
            'sumber_u95' => $hasil['sumber_u95'],
            'ketidakpastian_diperluas_hitung' => $hasil['ketidakpastian_diperluas'],
        ];

        return $audit;
    }

    /**
     * Tiga hal yang tanpanya hitungan BUKAN sekadar kurang teliti — dia
     * mengarang.
     */
    private function syaratKurang(?string $tipeSensor, string $dryblock, ?string $merk, ?Standard $standar, Equipment $alat): ?string
    {
        if ($standar === null) {
            return 'Sesi ini belum menunjuk kalibrator mana pun. Koreksi & U95-nya diambil dari sertifikat '
                .'kalibrator, jadi tanpa itu nggak ada yang bisa dihitung.';
        }

        if ($merk === null) {
            return sprintf(
                'Kalibrator "%s" nggak punya tabel koreksi Thermocouple — yang punya cuma %s. Cek kolom '
                .'`merk` baris standar itu di master.',
                $standar->nama,
                implode(' & ', array_map(
                    static fn (string $m): string => TabelKalibratorSuhu3Alat::MERK_TERCETAK[$m] ?? $m,
                    TabelKalibratorSuhu3Alat::MERK,
                )),
            );
        }

        if ($tipeSensor === null) {
            return 'Tipe sensor STANDAR belum dipilih (RTD / Type K / Type N). Koreksi kalibrator, U95-nya, '
                .'dan drift-nya beda per tipe.';
        }

        if ($dryblock === '' || $this->tabel->dryblock($dryblock) === null) {
            return 'Dryblock yang dipakai belum dipilih (A atau B). Variasi aksial & antar-lubang di budget '
                .'diambil dari tabel dryblock-nya masing-masing.';
        }

        if (! is_numeric($alat->resolusi) || (float) $alat->resolusi <= 0.0) {
            return 'Resolusi indikator alat ini belum diisi di master alat. Komponen daya baca budget lahir '
                .'dari situ, jadi tanpa dia budget-nya nggak lengkap.';
        }

        return null;
    }

    /**
     * Baris CMC yang mencakup SELURUH titik sesi.
     *
     * Master memilih pita lewat `IF(AND(U18>=-20,U18<=150),…)` yang punya LUBANG
     * di 150…151 dan 400…401 °C — set point 150,5 tidak mendapat CMC sama sekali
     * dan `AC34` memulangkan teks "cek kapasitas" yang lalu ikut `MAX()`. Yang
     * dipakai di sini pita lampiran akreditasi lewat `calibration_capabilities`,
     * yang bersambung tanpa lubang.
     *
     * @param  list<array<string, mixed>>  $titik
     */
    private function kemampuanUntukTitik(Equipment $alat, array $titik): ?CalibrationCapability
    {
        $maks = max(array_map(static fn (array $t): float => (float) $t['titik_ukur'], $titik));

        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->where('range_min', '<=', $maks)
            ->where('range_max', '>=', $maks)
            ->when(
                $alat->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($alat->organization_id),
            )
            // Pita yang bertumpuk di batasnya (150 °C ada di pita −20…150 DAN
            // 150…400) dimenangkan pita BAWAH — mengikuti rantai `IF` master,
            // `IF(AND(U18>=-20,U18<=150), S5, IF(AND(U18>151,…)))`, yang
            // menyerahkan batas atas ke pita di bawahnya. Sesi contoh berhenti
            // tepat di 150 °C dan sertifikatnya terbit dengan CMC 0,84 — bukan
            // 1,5. Menaruh pita atas duluan di sini akan menaikkan U95
            // sertifikat itu tanpa satu pun angka lain berubah.
            ->orderBy('range_max')
            ->first();
    }

    private function merkKalibrator(?Standard $standar): ?string
    {
        $merk = strtolower(trim((string) $standar?->merk));

        return in_array($merk, TabelKalibratorSuhu3Alat::MERK, true) ? $merk : null;
    }

    /**
     * Terima ejaan master (`PRT PT100`, `Thermocouple Type K`) maupun ejaan repo
     * (`RTD`, `Type K`) — dua-duanya beredar di kertas lab yang sama.
     */
    private function normalkanTipeSensor(mixed $tipe): ?string
    {
        if (! is_string($tipe) || trim($tipe) === '') {
            return null;
        }

        $rapi = trim(preg_replace('/\s+/', ' ', $tipe) ?? '');
        $rapi = str_ireplace(['PRT PT100', 'PT100', 'Thermocouple '], ['RTD', 'RTD', ''], $rapi);

        foreach (TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR as $sah) {
            if (strcasecmp($sah, $rapi) === 0) {
                return $sah;
            }
        }

        return null;
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 2, ',', '.'), '0'), ',');
    }
}
