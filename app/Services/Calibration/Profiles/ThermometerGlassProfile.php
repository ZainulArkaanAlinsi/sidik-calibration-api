<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\ThermometerGlassCalculator;
use Carbon\Carbon;

/**
 * Profil **Termometer Gelas** (alat ke-19) — lampiran akreditasi LK-285-IDN
 * "Suhu dan Kelembapan" no. 4, metode `SIDIK-IK-CAL-0527`.
 *
 * Master: `Master_Olah_Data_Suhu_Thermometer_Glass.xlsm`, sesi **0135-CAL-125**
 * (order 2501.16.G, PT Unilever Indonesia Tbk Skin Care Factory, Alla France
 * analog s/n IND-140, 31 Januari 2025, lima titik 30…100 °C).
 *
 * ## Satu-satunya alat di repo ini yang UUT-nya tanpa elektronik
 *
 * Yang dikalibrasi kolom raksa di dalam gelas: tidak ada layar, tidak ada
 * sinyal, tidak ada resolusi digital. Konsekuensinya menembus ke tiga tempat,
 * dan ketiganya gampang "dirapikan" jadi salah:
 *
 *  1. **Sisi UUT tidak dikoreksi apa pun** — lihat [ThermometerGlassCalculator].
 *  2. **Kolom UUT tercetak NOL desimal** (`SERTIFIKAT!J21` format `0`), karena
 *     skala terkecilnya 1 °C. Kolom standar di sebelahnya satu desimal. Dua
 *     desimal berbeda dalam satu tabel itu memang bentuk kertasnya.
 *  3. **`Resolusi Alat` = skala terkecil**, bukan daya baca digital — dan dia
 *     masuk budget lewat `N25 = K16/2` dengan `vi` tak hingga.
 *
 * ## Tipe pencelupan menentukan cara ukur, dan tercetak
 *
 * `Partial` / `Total` / `Complete Immersion` (`DATABASE!Q32:R35`). Sertifikatnya
 * mencetaknya sebagai baris tersendiri persis di atas tabel hasil
 * (`SERTIFIKAT!E19`), jadi sertifikat yang menyebut tipe salah menggambarkan
 * kondisi ukur yang tidak pernah terjadi.
 *
 * ## Uji titik es itu KOMPONEN budget
 *
 * Tiga pembacaan di titik es selama 30 menit (`INPUT DATA!N29:Q33`), dan yang
 * dipakai RENTANGNYA (`Tmax − Tmin`), bukan rata-rata atau STDEV-nya. Di sesi
 * contoh ketiganya nol jadi komponennya hilang dari pandangan — itulah yang
 * bikin dia gampang dikira catatan. Lihat kalkulatornya.
 *
 * ## Nomor metode: master Rev.1, lampiran akreditasi Rev.0
 *
 * `DATABASE!A95:C95` menulis `Thermometer Glass → SIDIK-IK-CAL-0527_Rev.1`,
 * sementara lampiran akreditasi menulis `SIDIK-IK-CAL-0527_Rev.0`. Yang dipakai
 * **Rev.1**: nomor IK-nya sama, yang beda cuma nomor revisi, dan revisi yang
 * lebih baru berarti dokumen labnya sudah diperbarui sesudah lampiran dicetak.
 * Beda arah dari Thermocouple — di sana yang berselisih nomor IK-nya sendiri
 * (0502 vs 0529), yaitu metode alat LAIN. Dicatat di
 * `docs/pertanyaan-lab-suhu-3alat.md`.
 *
 * @see ThermometerGlassCalculator
 * @see TabelKalibratorSuhu3Alat
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class ThermometerGlassProfile extends ProfilSuhuPasangan
{
    /** Lihat [ThermocoupleProfile::KODE_DOKUMEN] — alasannya sama persis. */
    public const KODE_DOKUMEN = null;

    /** `DATABASE!C95`, revisi yang lebih baru daripada lampiran — lihat docblock kelas. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0527_Rev.1';

    /** Yang tertulis di lampiran akreditasi, disimpan supaya selisihnya bisa diuji. */
    public const KODE_METODE_LAMPIRAN = 'SIDIK-IK-CAL-0527_Rev.0';

    public const SATUAN = '°C';

    public const NOMOR_LINGKUP = 'LK-285-IDN';

    /**
     * Titik awal layar — lima titik sesi master.
     *
     * @var list<float>
     */
    public const TITIK_SARAN = [30.0, 50.0, 60.0, 80.0, 100.0];

    /**
     * Probe standar termometer gelas SELALU PRT PT100 nomor 17.
     *
     * `INPUT DATA!B33:B37` semuanya 17, dan catatan di sebelahnya menulis
     * *"Sensor yang dipakai PRT Pt 100"*. Bukan pilihan teknisi: oilbath cuma
     * punya satu probe acuan terpasang. Karena itu tidak ada dropdown No.
     * Termokopel di lembar ini — satu-satunya dari tiga alat ini yang begitu.
     */
    public const NO_PROBE = 17;

    public const TIPE_SENSOR_STANDAR = 'RTD';

    /** `SERTIFIKAT!B42:B43` — kalibrator + probe acuan. */
    public const STANDARD_TERCETAK = [
        ['label' => 'Temperature Calibrator Constant 40T', 'cocok' => ['Temperature Calibrator Constant 40T', '99875850']],
        ['label' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal', 'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005']],
        ['label' => 'PRT PT100', 'cocok' => ['PRT Pt-100', 'PRT PT100', 'SH1/20']],
    ];

    /**
     * Dua oilbath lab, `PERHITUNGAN U95%!U9:AH10`.
     *
     * Variasi spasial & stabilitasnya BEDA (0,57/0,07 vs 0,50/0,10 °C), jadi
     * pilihan ini menggeser dua komponen budget.
     */
    public const OILBATH = [
        ['nilai' => 'satu', 'label' => 'Oil Bath 1 (SIDIK/079/2022)'],
        ['nilai' => 'dua', 'label' => 'Oil Bath 2 (SIDIK/080/2022)'],
    ];

    private ?ThermometerGlassCalculator $kalkulator = null;

    public function __construct(private readonly TabelKalibratorSuhu3Alat $tabel = new TabelKalibratorSuhu3Alat) {}

    public function kode(): string
    {
        return 'thermometer_glass';
    }

    /** Ejaan PERSIS lampiran akreditasi no. 4 — Indonesia, bukan Inggris. */
    public function namaAlatKemampuan(): string
    {
        return 'Termometer Gelas';
    }

    /**
     * Ejaan Inggris judul lembar kerjanya (`KALIBRASI THERMOMETER GLASS`) dan
     * nama alat sesi master (`INPUT DATA!E10 = 'Thermometer Glass'`), plus dua
     * sebutan yang lazim dipakai pelanggan.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Thermometer Glass', 'Termometer Gelas Cairan', 'Glass Thermometer'];
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_THERMOMETER_GLASS;
    }

    public function besaran(): string
    {
        return 'suhu';
    }

    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /** Kolom standar & koreksi SATU desimal (`SERTIFIKAT!E21`/`L21` format `0.0`). */
    public function desimalSertifikat(): ?int
    {
        return 1;
    }

    /** `U95` SATU desimal (`SERTIFIKAT!L35` format `0.0`). */
    public function desimalU95(): ?int
    {
        return 1;
    }

    /**
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

        $oilbath = strtolower((string) ($konteks['alat_bantu'] ?? ''));
        $merk = $this->merkKalibrator($standar);

        $kurang = $this->syaratKurang($oilbath, $merk, $standar, $equipment);

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
                            'Lab belum punya baris CMC Termometer Gelas yang mencakup titik %s °C — lampiran '
                            .'akreditasi cuma memuat 0…200 °C dalam dua pita.',
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
            ],
            $titik,
        );

        $hasil = ($this->kalkulator ??= new ThermometerGlassCalculator($this->tabel))->hitungSesi($masukan, [
            'merk_kalibrator' => $merk,
            'tipe_sensor' => self::TIPE_SENSOR_STANDAR,
            'no_probe' => self::NO_PROBE,
            'oilbath' => $oilbath,
            'resolusi' => (float) $equipment->resolusi,
            // `K17 = Resolusi STD` — daya baca KALIBRATOR, bukan UUT. Diambil
            // dari master `standards`; kalau belum diisi dipakai 0,1 °C, angka
            // yang tercetak di `DATABASE!V14` untuk kedua kalibrator lab.
            'resolusi_standar' => $this->resolusiStandar($standar),
            'cmc' => (float) $kemampuan->ketidakpastian_terbaik,
            'titik_es' => $konteks['titik_es'] ?? [],
        ]);

        $hitungan = $this->barisHitungan($hasil, $standar, $kemampuan);

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $hasil['belum_dihitung']];
    }

    /**
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];

        if (trim((string) ($this->atributSesi($sesi, 'alat_bantu') ?? '')) === '') {
            $peringatan[] = [
                'kode' => 'gelas_oilbath_kosong',
                'pesan' => 'Oilbath yang dipakai belum dipilih. Variasi spasial & stabilitas bath di budget '
                    .'diambil dari tabel oilbath-nya masing-masing, dan angkanya beda antar unit.',
            ];
        }

        if (trim((string) ($this->atributSesi($sesi, 'tipe_pencelupan') ?? '')) === '') {
            $peringatan[] = [
                'kode' => 'gelas_tipe_pencelupan_kosong',
                'pesan' => 'Tipe pencelupan (Partial / Total / Complete Immersion) belum dipilih. Dia tercetak '
                    .'di sertifikat persis di atas tabel hasil.',
            ];
        }

        $titikEs = $this->atributSesi($sesi, 'titik_es');

        if (! is_array($titikEs) || array_filter($titikEs, static fn ($v): bool => $v !== null && $v !== '') === []) {
            $peringatan[] = [
                'kode' => 'gelas_titik_es_kosong',
                'pesan' => 'Uji titik es (3 pembacaan, 30 menit) belum diisi. Rentangnya (Tmax − Tmin) itu '
                    .'komponen budget, bukan catatan — dikosongin, komponennya dihitung nol.',
            ];
        }

        return $peringatan;
    }

    /**
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
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'judul' => 'Calibration Worksheet - Thermometer Glass',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'larutan_standar' => self::TITIK_SARAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — lembar kerja '
                .'tetap bisa dikirim. Khusus alat ini: OILBATH dan TIPE PENCELUPAN wajib dipilih, dan UJI '
                .'TITIK ES diisi lebih dulu (Pre-Evaluation) karena rentangnya masuk budget. Tiap titik dibaca '
                .'DUA deret: standar (detik ke-0, 20, 40, 60, 80) dan UUT (detik ke-10, 30, 50, 70, 90).',
            'bagian' => [
                ...$this->bagianUmumAtas([
                    $this->field('spesifikasi_alat.rentang_ukur', '5. Rentang Ukur', 'teks', satuan: self::SATUAN),
                    $this->field('spesifikasi_alat.kapasitas', '6. Kapasitas Alat', 'angka', satuan: self::SATUAN),
                    $this->field('spesifikasi_alat.resolusi', '7. Resolusi Alat (skala terkecil)', 'angka', satuan: self::SATUAN),
                ]),
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'PENGERJAAN',
                    'field' => [
                        $this->field('tipe_pencelupan', 'Thermometer Type', 'pilihan', pilihan: array_map(
                            static fn (array $t): array => ['nilai' => $t['label'], 'label' => $t['label']],
                            $this->tabel->tipeThermometer(),
                        )),
                        $this->field('alat_bantu', 'Oilbath Used', 'pilihan', pilihan: array_map(
                            static fn (array $o): array => ['nilai' => $o['nilai'], 'label' => $o['label']],
                            self::OILBATH,
                        )),
                        ...$this->fieldLokasi(),
                    ],
                ],
                [
                    'kode' => 'pre_evaluasi',
                    'halaman' => 1,
                    'judul' => '1. Pre-Evaluation (UUT) — Ice Point 30 menit',
                    'field' => [
                        // Kodenya TANPA titik (`titik_es_1`, bukan
                        // `titik_es.0`). Kode bertitik punya arti sendiri di
                        // aplikasi teknisi: `FieldLembarKerja.turunan` membaca
                        // titik sebagai "kolom TURUNAN yang diisi sistem", dan
                        // kolom turunan nggak dapat kotak isian sama sekali.
                        // Ketiganya kegambar rapi sebagai judul bagian, tanpa
                        // satu pun tempat mengetik — dan uji titik es itu
                        // komponen budget, bukan catatan.
                        $this->field('titik_es_1', 'Ice Point X1', 'angka', satuan: self::SATUAN),
                        $this->field('titik_es_2', 'Ice Point X2', 'angka', satuan: self::SATUAN),
                        $this->field('titik_es_3', 'Ice Point X3', 'angka', satuan: self::SATUAN),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 1,
                    'judul' => 'DATA HASIL KALIBRASI',
                    'field' => $this->fieldKondisiLingkungan(),
                    'tabel' => [
                        $this->tabelPembacaan(
                            'standar',
                            '2. Pembacaan Standard',
                            self::TITIK_SARAN,
                            self::SATUAN,
                            'Data Hasil Pengukuran',
                            labelPengulangan: ThermocoupleProfile::LABEL_STANDAR,
                        ),
                        $this->tabelPembacaan(
                            'uut',
                            '3. Pembacaan UUT',
                            self::TITIK_SARAN,
                            self::SATUAN,
                            'Data Hasil Pengukuran',
                            labelPengulangan: ThermocoupleProfile::LABEL_UUT,
                        ),
                    ],
                ],
                $this->bagianPenutup(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function barisHitungan(array $hasil, ?Standard $standar, CalibrationCapability $kemampuan): array
    {
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] !== 't-student',
            ),
        )));

        $typeA = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] === 't-student',
            ),
        )));

        $sekarang = Carbon::now();
        $audit = $this->jejakAudit($hasil, $kemampuan);

        return array_map(static fn (array $t): array => [
            'standard_id' => $standar?->id,
            'titik_ke' => $t['titik_ke'],
            // Lihat ThermocoupleProfile::barisHitungan — `titik_ukur` menyimpan
            // nilai STANDAR TERKOREKSI, kolom yang dicetak sertifikat sebagai
            // `Standard Reading`.
            'titik_ukur' => $t['standar_terkoreksi'],
            // Rata-rata pembacaan UUT apa adanya — termometer gelas dibaca
            // dengan mata, nggak ada koreksi instrumen di jalur itu.
            'rata_rata' => $t['uut_terkoreksi'],
            'error' => $t['uut_terkoreksi'] - $t['standar_terkoreksi'],
            'koreksi' => $t['koreksi'],
            'standar_deviasi' => $t['standar_deviasi_uut'],
            'jumlah_pengulangan' => count($t['pembacaan_uut']),
            'type_a' => $typeA,
            'type_b_components' => $audit,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
            'toleransi' => null,
            'keputusan' => null,
            'calculated_at' => $sekarang,
        ], $hasil['titik']);
    }

    /**
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
                'Probe standar %s, index tabel tertinggi %s °C. STDEV terbesar standar %s °C & UUT %s °C, '
                .'rentang uji titik es %s °C. CMC %s °C, U95 dilaporkan dari %s.',
                (string) $hasil['probe'],
                rtrim(rtrim(number_format((float) $hasil['index_maks'], 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($hasil['standar_deviasi_maks_standar'], 8, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($hasil['standar_deviasi_maks_uut'], 8, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($hasil['rentang_titik_es'], 8, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($hasil['cmc'], 8, '.', ''), '0'), '.'),
                $hasil['sumber_u95'] === 'cmc' ? 'lantai CMC' : 'hitungan budget',
            ),
            'distribusi' => '-',
            'nilai' => $hasil['ketidakpastian_diperluas'],
            'cmc' => $hasil['cmc'],
            'cmc_id' => $kemampuan->id,
            'probe' => $hasil['probe'],
            'rentang_titik_es' => $hasil['rentang_titik_es'],
            'sumber_u95' => $hasil['sumber_u95'],
            'ketidakpastian_diperluas_hitung' => $hasil['ketidakpastian_diperluas'],
        ];

        return $audit;
    }

    private function syaratKurang(string $oilbath, ?string $merk, ?Standard $standar, Equipment $alat): ?string
    {
        if ($standar === null) {
            return 'Sesi ini belum menunjuk kalibrator mana pun. Koreksi & U95-nya diambil dari sertifikat '
                .'kalibrator, jadi tanpa itu nggak ada yang bisa dihitung.';
        }

        if ($merk === null) {
            return sprintf(
                'Kalibrator "%s" nggak punya tabel koreksi Termometer Gelas — yang punya cuma Constant & '
                .'Yokogawa. Cek kolom `merk` baris standar itu di master.',
                $standar->nama,
            );
        }

        if ($oilbath === '' || $this->tabel->oilbath($oilbath) === null) {
            return 'Oilbath yang dipakai belum dipilih (satu atau dua). Variasi spasial & stabilitas bath di '
                .'budget diambil dari tabel oilbath-nya masing-masing.';
        }

        if (! is_numeric($alat->resolusi) || (float) $alat->resolusi <= 0.0) {
            return 'Skala terkecil termometer belum diisi di master alat. Komponen `Resolusi Alat yang '
                .'dikalibrasi` lahir dari situ.';
        }

        return null;
    }

    /**
     * Daya baca KALIBRATOR (`PERHITUNGAN U95%!K17`), bukan UUT.
     *
     * Bawaan 0,1 °C — angka yang tercetak di `DATABASE!V14` untuk Constant DAN
     * Yokogawa. Dipakai cuma kalau master `standards` belum mengisinya; begitu
     * lab mengisi kolomnya, yang berlaku angka lab.
     */
    private function resolusiStandar(?Standard $standar): float
    {
        $resolusi = $standar?->getAttribute('resolusi');

        return is_numeric($resolusi) && (float) $resolusi > 0.0 ? (float) $resolusi : 0.1;
    }

    /**
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
            ->orderByDesc('ketidakpastian_terbaik')
            ->first();
    }

    private function merkKalibrator(?Standard $standar): ?string
    {
        $merk = strtolower(trim((string) $standar?->merk));

        return in_array($merk, TabelKalibratorSuhu3Alat::MERK, true) ? $merk : null;
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 2, ',', '.'), '0'), ',');
    }
}
