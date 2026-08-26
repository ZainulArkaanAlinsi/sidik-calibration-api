<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use App\Services\Calibration\ThermohygroCalculator;
use Carbon\Carbon;

/**
 * Profil **Thermohygrometer** (alat ke-20) — lampiran akreditasi LK-285-IDN
 * "Suhu dan Kelembapan" no. 11, metode
 * `SIDIK-IK-CAL-0518_Rev.4 (Perbandingan langsung)`.
 *
 * Master: `Master_Olah_Data_Suhu__Kelembapan.xlsm`, sesi **0312-CAL-624**
 * (order 2406.25.AR, PT Gunung Madu Plantations, NOKLEAD NK5253 s/n TR-001,
 * 2 Juli 2024).
 *
 * ## Alat yang dikalibrasi lab, dan alat yang dipakai lab
 *
 * Kebetulan yang perlu ditulis supaya tidak jadi kekeliruan: unit `TH-1`…`TH-7`
 * yang muncul di dropdown "Environmental Meter Used" di SELURUH lembar kerja
 * repo ini juga thermohygrometer. Bedanya peran, bukan jenis — di lembar lain
 * mereka ALAT UKUR KONDISI RUANGAN (`standards.parameter_kondisi` terisi), di
 * lembar ini yang seperti mereka justru UUT-nya.
 *
 * Karena itu profil ini tetap punya kotak "Environmental Meter Used" sendiri:
 * sesi thermohygro juga dikerjakan di ruangan yang suhunya perlu dicatat, dan
 * yang mencatatnya unit TH lab — bukan UUT yang sedang diperiksa.
 *
 * ## Dua parameter, TIGA budget
 *
 * Satu-satunya alat suhu berparameter dua (°C dan %RH), dan kelembapannya
 * dikalibrasi di DUA chamber karena satu chamber tidak menjangkau seluruh
 * rentang. Uraian lengkapnya — berikut apa yang rusak kalau ketiganya
 * digabung — ada di [ThermohygroCalculator].
 *
 * Chamber TIDAK dipilih teknisi: dia diturunkan dari set point
 * ([ThermohygroCalculator::chamberUntuk]). Yang menentukan kemampuan fisik
 * chamber-nya, dan satu sesi memakai dua-duanya sekaligus — jadi tidak ada satu
 * nilai per sesi yang bisa benar.
 *
 * ## `suhu_ketidakpastian` & `kelembaban_ketidakpastian` akhirnya punya isi
 *
 * Dua kolom itu sudah lama ada di `calibration_sessions` dan selama ini diisi
 * U95 KONDISI LINGKUNGAN (koreksi thermohygro ruangan) di alat lain. Di alat
 * ini artinya bertabrakan: U95 hasil kalibrasinya sendiri juga suhu &
 * kelembapan. Yang tersimpan di dua kolom itu tetap **kondisi lingkungan**,
 * sama seperti 19 alat lain; U95 hasil kalibrasi tinggal di
 * `uncertainty_calculations` per titik seperti biasa. Menukarnya akan membuat
 * satu sesi melaporkan ketidakpastian ruangan sebagai ketidakpastian alat.
 *
 * @see ThermohygroCalculator
 * @see TabelKalibratorSuhu3Alat
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class ThermohygroProfile extends ProfilSuhuPasangan
{
    /** Lihat [ThermocoupleProfile::KODE_DOKUMEN] — alasannya sama persis. */
    public const KODE_DOKUMEN = null;

    /** `DATABASE!C87`, dan cocok dengan lampiran akreditasi no. 11. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0518_Rev.4';

    public const SATUAN_SUHU = '°C';

    public const SATUAN_RH = '%RH';

    public const NOMOR_LINGKUP = 'LK-285-IDN';

    /**
     * Titik awal layar suhu — lima titik sesi master, sama dengan rentang
     * akreditasi 15…50 °C.
     *
     * @var list<float>
     */
    public const TITIK_SUHU = [15.0, 25.0, 35.0, 45.0, 50.0];

    /**
     * Titik awal layar kelembapan — lima titik sesi master, dua di antaranya
     * (30 & 49 %RH) jatuh ke chamber GEA dan tiga sisanya ke Biobase.
     *
     * @var list<float>
     */
    public const TITIK_RH = [30.0, 49.0, 50.0, 70.0, 90.0];

    /** `SERTIFIKAT!B71` — satu standar, dan cuma satu. */
    public const STANDARD_TERCETAK = [
        ['label' => 'Temperature Humidity Meter', 'cocok' => ['Temperature Humidity Meter', '201701023483']],
    ];

    private ?ThermohygroCalculator $kalkulator = null;

    public function __construct(private readonly TabelKalibratorSuhu3Alat $tabel = new TabelKalibratorSuhu3Alat) {}

    public function kode(): string
    {
        return 'thermohygro';
    }

    /** Ejaan PERSIS lampiran akreditasi no. 11. */
    public function namaAlatKemampuan(): string
    {
        return 'Thermohygrometer';
    }

    /**
     * Ejaan yang beredar di kertas & di mulut teknisi.
     *
     * `Thermohygro` sengaja ikut walau pendek: dia sudah dipakai sebagai nama
     * unit standar lab (TH-1…TH-7) di seluruh repo, jadi kalau tidak
     * didaftarkan, alat pelanggan bernama "Thermohygro Digital" tidak ketemu
     * profil ini dan jatuh ke pH tanpa error.
     *
     * **`Hydrometer` SENGAJA tidak didaftarkan, walau kelihatan sekeluarga.**
     * Sempat masuk daftar ini dan langsung ditangkap
     * `ProfilDariNamaAlatTest::test_nama_alat_generik_balik_null`. Hydrometer
     * itu alat DENSITAS (kelompok "Densitas" di lampiran akreditasi, satuan
     * g/mL · °Brix · °Baumé) — bukan kelembapan. Pencocokan registry menerima
     * kunci yang nempel di TENGAH nama, jadi alias itu akan menyeret tiap
     * "Hydrometer …" pelanggan ke lembar thermohygro: teknisi mengisi tabel
     * suhu & %RH untuk alat yang mengukur berat jenis, dan U95 yang terbit
     * lantainya CMC kelembapan 4,8 %RH. Nol error di sepanjang jalur itu.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Thermohygro', 'Thermohygrometer Digital', 'Termohigrometer'];
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_THERMOHYGRO;
    }

    /**
     * Besaran utamanya suhu — itu yang dipakai penomoran sertifikat & filter
     * daftar. Parameter keduanya (%RH) tinggal di tiap titik lewat
     * [satuanTitik].
     */
    public function besaran(): string
    {
        return 'suhu';
    }

    /**
     * Satuan per TITIK, bukan per alat — satu-satunya profil yang begitu.
     *
     * Yang membedakan titik suhu dari titik kelembapan: `parameter` di konteks
     * titiknya. Waktu bentuk lembar kerja digambar, tabel suhu & tabel
     * kelembapan sudah membawa satuannya masing-masing, jadi method ini cuma
     * dipakai jalur yang cuma pegang angka.
     *
     * Rentang akreditasi suhu 15…50 °C dan kelembapan 30…90 %RH tidak
     * bertumpuk, jadi ambang di 90 aman: titik ≤ 90 yang berasal dari tabel
     * kelembapan tetap dikenali lewat konteksnya.
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN_SUHU;
    }

    /** Kolom standar & koreksi DUA desimal (`SERTIFIKAT!E22`/`N22` format `0.00`). */
    public function desimalSertifikat(): ?int
    {
        return 2;
    }

    /** `U95` SATU desimal (`SERTIFIKAT!M29` & `U22` format `0.0`). */
    public function desimalU95(): ?int
    {
        return 1;
    }

    /**
     * Hitung seluruh sesi: satu grup suhu + sampai dua grup kelembapan.
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
        $standar = $titik[0]['standard'] ?? null;

        if ($standar === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    static fn (array $t): array => [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => 'Sesi ini belum menunjuk Temperature Humidity Meter standar. Koreksi & '
                            .'U95-nya diambil dari sertifikat standar itu.',
                    ],
                    $titik,
                ),
            ];
        }

        $kemampuan = [
            ThermohygroCalculator::PARAMETER_SUHU => $this->kemampuan($equipment, 'Suhu'),
            ThermohygroCalculator::PARAMETER_KELEMBABAN => $this->kemampuan($equipment, 'Kelembapan'),
        ];

        $kurang = [];

        foreach ($kemampuan as $parameter => $baris) {
            if ($baris === null) {
                $kurang[] = $parameter;
            }
        }

        if ($kurang !== []) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    static fn (array $t): array => [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => sprintf(
                            'Baris CMC Thermohygrometer parameter %s belum ada di master kemampuan kalibrasi — '
                            .'jalankan CalibrationCapabilitySeeder dulu.',
                            implode(' & ', $kurang),
                        ),
                    ],
                    $titik,
                ),
            ];
        }

        $resolusi = $this->resolusi($equipment);

        $masukan = array_map(
            static fn (array $t): array => [
                'parameter' => (string) ($t['konteks']['parameter'] ?? ThermohygroCalculator::PARAMETER_SUHU),
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => (float) $t['titik_ukur'],
                'standar' => $t['konteks']['standar'] ?? [],
                'uut' => $t['konteks']['uut'] ?? [],
            ],
            $titik,
        );

        $hasil = ($this->kalkulator ??= new ThermohygroCalculator($this->tabel))->hitungSesi($masukan, [
            'resolusi_suhu' => $resolusi['suhu'],
            'resolusi_kelembaban' => $resolusi['kelembaban'],
            'cmc_suhu' => (float) $kemampuan[ThermohygroCalculator::PARAMETER_SUHU]->ketidakpastian_terbaik,
            'cmc_kelembaban' => (float) $kemampuan[ThermohygroCalculator::PARAMETER_KELEMBABAN]->ketidakpastian_terbaik,
        ]);

        $hitungan = [];

        foreach ($hasil['grup'] as $grup) {
            $hitungan = [...$hitungan, ...$this->barisHitungan($grup, $standar, $kemampuan[$grup['parameter']])];
        }

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $hasil['belum_dihitung']];
    }

    /**
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];
        $spesifikasi = $sesi->getAttribute('spesifikasi_alat');
        $spesifikasi = is_array($spesifikasi) ? $spesifikasi : [];

        foreach ([
            'resolusi' => 'Resolusi Suhu',
            'resolusi_kelembaban' => 'Resolusi Humi.',
        ] as $kunci => $label) {
            $nilai = $spesifikasi[$kunci] ?? null;

            if (! is_numeric($nilai) || (float) $nilai <= 0.0) {
                $peringatan[] = [
                    'kode' => 'thermohygro_'.$kunci.'_kosong',
                    'pesan' => sprintf(
                        '%s belum diisi. Komponen `Readability` budget %s lahir dari situ; dikosongin, '
                        .'yang dipakai bawaan master (%s).',
                        $label,
                        $kunci === 'resolusi' ? 'suhu' : 'kelembapan',
                        $kunci === 'resolusi' ? '0,1 °C' : '1 %RH',
                    ),
                ];
            }
        }

        // Titik kelembapan yang jatuh persis di ambang dua chamber. Bukan error,
        // tapi teknisi perlu tahu chamber mana yang budget-nya kepakai.
        $ambang = ThermohygroCalculator::AMBANG_CHAMBER_RH;
        $diAmbang = $sesi->rawMeasurements()
            ->where('peran_sensor', 'standar')
            ->pluck('titik_ukur')
            ->map(static fn ($v): float => (float) $v)
            ->unique()
            ->filter(static fn (float $t): bool => abs($t - $ambang) < 1e-9)
            ->values();

        if ($diAmbang->isNotEmpty()) {
            $peringatan[] = [
                'kode' => 'thermohygro_titik_di_ambang_chamber',
                'pesan' => sprintf(
                    'Titik %s %%RH persis di ambang dua chamber — yang dipakai budget chamber BIOBASE '
                    .'(rentang %s…90 %%RH). Kalau titik itu sebenarnya diukur di chamber GEA, stabilitas & '
                    .'homogenitas yang kepakai bukan milik chamber-nya.',
                    $diAmbang->implode(', '),
                    rtrim(rtrim(number_format($ambang, 1, ',', '.'), '0'), ','),
                ),
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
                'standar' => ['Temperature Humidity Meter', '201701023483'],
            ],
            [...self::TITIK_SUHU, ...self::TITIK_RH],
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
            'judul' => 'Calibration Worksheet - Temperatur & Kelembapan (Thermohygro)',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'larutan_standar' => self::TITIK_SUHU,
            'satuan' => self::SATUAN_SUHU,
            'satuan_suhu' => self::SATUAN_SUHU,
            // Lembar bersatuan CAMPUR — dan ini bukan penanda kosmetik.
            //
            // Tanpa kunci ini, layar teknisi memakai satuan LEMBAR (`°C`) untuk
            // seluruh baris, termasuk lima baris kelembapan. Yang terjadi bukan
            // label yang salah: `measurements[].satuan` ikut kekirim `°C` untuk
            // titik %RH, dan waktu sesi itu dikembalikan admin, kelima baris
            // kelembapan nggak bisa dibedakan lagi dari baris suhu — set point
            // `50` ada di dua-duanya.
            //
            // Mekanismenya yang sama dipakai Conductivity (µS/cm vs mS/cm) dan
            // Spectrophotometer (nm vs %T).
            'satuan_campuran' => true,
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — lembar kerja '
                .'tetap bisa dikirim. Alat ini punya DUA parameter: suhu (°C) dan kelembapan (%RH), '
                .'masing-masing dengan tabel & U95 sendiri. Chamber kelembapan dipilih sistem dari set '
                .'point-nya: ≥ 50 %RH BIOBASE, < 50 %RH GEA.',
            'bagian' => [
                ...$this->bagianUmumAtas([
                    $this->field('spesifikasi_alat.rentang_ukur', '5. Rentang Ukur Suhu', 'teks', satuan: self::SATUAN_SUHU),
                    $this->field('spesifikasi_alat.kapasitas', '6. Kapasitas Suhu', 'teks', satuan: self::SATUAN_SUHU),
                    $this->field('spesifikasi_alat.resolusi', '7. Resolusi Suhu', 'angka', satuan: self::SATUAN_SUHU),
                    $this->field('spesifikasi_alat.rentang_ukur_kelembaban', '8. Rentang Ukur Humi.', 'teks', satuan: self::SATUAN_RH),
                    $this->field('spesifikasi_alat.kapasitas_kelembaban', '9. Kapasitas Humi.', 'teks', satuan: self::SATUAN_RH),
                    $this->field('spesifikasi_alat.resolusi_kelembaban', '10. Resolusi Humi.', 'angka', satuan: self::SATUAN_RH),
                ]),
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'PENGERJAAN',
                    'field' => $this->fieldLokasi(),
                ],
                [
                    'kode' => 'hasil_suhu',
                    'halaman' => 1,
                    'judul' => '1. KALIBRASI SUHU (TEMPERATURE)',
                    'field' => $this->fieldKondisiLingkungan(),
                    'tabel' => [
                        [
                            ...$this->tabelPembacaan(
                                'standar',
                                'Pembacaan Standard [CHAMBER BIOBASE]',
                                self::TITIK_SUHU,
                                self::SATUAN_SUHU,
                                'Data Hasil Pengukuran/Pengulangan (X1…X5)',
                                grup: 'suhu_standar',
                            ),
                            'parameter' => ThermohygroCalculator::PARAMETER_SUHU,
                        ],
                        [
                            ...$this->tabelPembacaan(
                                'uut',
                                'Pembacaan UUT',
                                self::TITIK_SUHU,
                                self::SATUAN_SUHU,
                                'Data Hasil Pengukuran/Pengulangan (X1…X5)',
                                grup: 'suhu_uut',
                            ),
                            'parameter' => ThermohygroCalculator::PARAMETER_SUHU,
                        ],
                    ],
                ],
                [
                    'kode' => 'hasil_kelembaban',
                    'halaman' => 1,
                    'judul' => '2. KALIBRASI KELEMBAPAN (HUMIDITY)',
                    'field' => [],
                    'tabel' => [
                        [
                            ...$this->tabelPembacaan(
                                'standar',
                                'Pembacaan Standard',
                                self::TITIK_RH,
                                self::SATUAN_RH,
                                'Data Hasil Pengukuran/Pengulangan (X1…X5)',
                                grup: 'kelembaban_standar',
                            ),
                            'parameter' => ThermohygroCalculator::PARAMETER_KELEMBABAN,
                            // Chamber ditempel per BARIS supaya layar bisa
                            // menuliskannya di sebelah set point — teknisi harus
                            // tahu unit mana yang dipakai tiap titik, dan
                            // sistem yang menentukannya, bukan dia.
                            'chamber_per_baris' => array_map(
                                static fn (float $t): array => [
                                    'titik_ukur' => $t,
                                    'chamber' => ThermohygroCalculator::chamberUntuk($t),
                                ],
                                self::TITIK_RH,
                            ),
                        ],
                        [
                            ...$this->tabelPembacaan(
                                'uut',
                                'Pembacaan UUT',
                                self::TITIK_RH,
                                self::SATUAN_RH,
                                'Data Hasil Pengukuran/Pengulangan (X1…X5)',
                                grup: 'kelembaban_uut',
                            ),
                            'parameter' => ThermohygroCalculator::PARAMETER_KELEMBABAN,
                        ],
                    ],
                ],
                $this->bagianPenutup(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $grup
     * @return list<array<string, mixed>>
     */
    private function barisHitungan(array $grup, Standard $standar, CalibrationCapability $kemampuan): array
    {
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $grup['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] !== 't-student',
            ),
        )));

        $typeA = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $grup['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] === 't-student',
            ),
        )));

        $sekarang = Carbon::now();
        $audit = $this->jejakAudit($grup, $kemampuan);

        return array_map(static fn (array $t): array => [
            'standard_id' => $standar->id,
            'titik_ke' => $t['titik_ke'],
            // Lihat ThermocoupleProfile::barisHitungan.
            'titik_ukur' => $t['standar_terkoreksi'],
            'rata_rata' => $t['uut_terkoreksi'],
            'error' => $t['uut_terkoreksi'] - $t['standar_terkoreksi'],
            'koreksi' => $t['koreksi'],
            'standar_deviasi' => $t['standar_deviasi_uut'],
            'jumlah_pengulangan' => count($t['pembacaan_uut']),
            'type_a' => $typeA,
            'type_b_components' => $audit,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $grup['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $grup['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $grup['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $grup['u95_sertifikat'],
            'toleransi' => null,
            'keputusan' => null,
            'calculated_at' => $sekarang,
        ], $grup['titik']);
    }

    /**
     * @param  array<string, mixed>  $grup
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $grup, CalibrationCapability $kemampuan): array
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
            $grup['budget'],
        );

        foreach ($grup['catatan_audit'] as $catatan) {
            $audit[] = [
                'sumber' => $catatan['kode'],
                'keterangan' => $catatan['pesan'],
                'distribusi' => '-',
                'nilai' => $grup['ketidakpastian_diperluas'],
            ];
        }

        $audit[] = [
            'sumber' => 'konteks_sesi',
            'keterangan' => sprintf(
                'Grup %s chamber %s (%d titik). STDEV terbesar UUT %s %s. CMC %s %s, U95 dilaporkan dari %s — '
                .'berlaku untuk SELURUH titik grup ini, bukan cuma titik ini.',
                $grup['parameter'],
                strtoupper($grup['chamber']),
                count($grup['titik']),
                rtrim(rtrim(number_format($grup['standar_deviasi_maks_uut'], 8, '.', ''), '0'), '.'),
                $grup['satuan'],
                rtrim(rtrim(number_format($grup['cmc'], 8, '.', ''), '0'), '.'),
                $grup['satuan'],
                $grup['sumber_u95'] === 'cmc' ? 'lantai CMC' : 'hitungan budget',
            ),
            'distribusi' => '-',
            'nilai' => $grup['ketidakpastian_diperluas'],
            'parameter' => $grup['parameter'],
            'chamber' => $grup['chamber'],
            'satuan' => $grup['satuan'],
            'cmc' => $grup['cmc'],
            'cmc_id' => $kemampuan->id,
            'sumber_u95' => $grup['sumber_u95'],
            'ketidakpastian_diperluas_hitung' => $grup['ketidakpastian_diperluas'],
        ];

        return $audit;
    }

    /**
     * Daya baca kedua parameter, dari `spesifikasi_alat` sesi kalau ada.
     *
     * Bawaan mengikuti master (`INPUT DATA!E16 = 0,1 °C`, `E19 = 1 %RH`) supaya
     * sesi yang kolom itu belum diisi tetap menghasilkan budget yang bisa
     * dibaca — dengan peringatan, bukan diam-diam.
     *
     * @return array{suhu: float, kelembaban: float}
     */
    private function resolusi(Equipment $alat): array
    {
        $suhu = is_numeric($alat->resolusi) && (float) $alat->resolusi > 0.0 ? (float) $alat->resolusi : 0.1;

        $rh = $alat->getAttribute('resolusi_kelembaban');
        $rh = is_numeric($rh) && (float) $rh > 0.0 ? (float) $rh : 1.0;

        return ['suhu' => $suhu, 'kelembaban' => $rh];
    }

    private function kemampuan(Equipment $alat, string $parameter): ?CalibrationCapability
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->where('parameter', $parameter)
            ->when(
                $alat->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($alat->organization_id),
            )
            ->first();
    }
}
