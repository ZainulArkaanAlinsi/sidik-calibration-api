<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Turbidimeter (alat ke-2). Metode `SIDIK-IK-CAL-0523`, form
 * `SIDIK-FM-CAL-0530_Rev.2` (DATABASE row).
 *
 * TITIK = 3 (1 / 100 / 1000 NTU) — bukan 5. Form PDF-nya nyetak 5 kolom
 * (0,04/15/100/750/2000), TAPI master data lab (sheet DATABASE workbook) cuma
 * punya 3 standar turbidity yang beneran dimiliki (Supelco/Merck LRAD7304/
 * 7305/7089), dengan U95 sertifikat 0,04/3/21 & CMC 0,041/3,1/22. Sertifikat
 * trial 0189-CAL-624 juga pakai 3 titik itu. Jadi yang punya ANGKA ASLI cuma
 * 1/100/1000; 15 & 750 NTU belum ada standarnya. Kalau lab nambah standar itu +
 * sertifikatnya, tinggal tambah baris di sini + seeder.
 *
 * Beda inti dari pH, dan tiap beda HIDUP DI FILE INI doang:
 *  - Titik 1 / 100 / 1000 NTU (pH: 4 / 7 / 10), resolusi beda per titik
 *    (0,01 / 0,1 / 1 — INPUT DATA E17/F17/G17).
 *  - Nilai standar = NOMINAL apa adanya (turbidity nggak punya kurva suhu),
 *    jadi nggak perlu `koefisien_suhu`.
 *  - Budget ketidakpastian **4 komponen** (pH: 5) — nggak ada "pengaruh
 *    perbedaan suhu". Komponen suhunya rect (÷√3), ci = (UTemperature/400)·titik.
 *
 * Engine budget dicocokin ke sheet `PERHITUNGAN U95%` (titik 1000 → uc =
 * 10.508511455997626, sel AB44) di `TurbidimeterBudgetTest`.
 */
class TurbidimeterProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0530_Rev.2';

    public const JUMLAH_PENGULANGAN = 5;

    /**
     * Tiga titik standar turbidity yang lab BENERAN punya (DATABASE S13/S14/S15),
     * dengan resolusi per titik (INPUT DATA E17/F17/G17). `desimal` dikirim ke
     * mobile biar angkanya dipad tanpa buang nol belakang (999 tetap "999",
     * 4.60 tetap "4.60").
     *
     * @var list<array{nilai: float, resolusi: float, desimal: int}>
     */
    public const TITIK = [
        ['nilai' => 1.0, 'resolusi' => 0.01, 'desimal' => 2],
        ['nilai' => 100.0, 'resolusi' => 0.1, 'desimal' => 1],
        ['nilai' => 1000.0, 'resolusi' => 1.0, 'desimal' => 0],
    ];

    public const SATUAN = 'NTU';

    /**
     * Baris tabel STANDARD: 3 larutan turbidity yang dimiliki lab + RTD Sensor +
     * Victor 14+ (RTD & Victor sama kayak lembar pH). Dicocokin ke master
     * `standards` lewat nama/serial.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Turbidity Standard 1 NTU', 'cocok' => ['Turbidity Standard 1 NTU']],
        ['label' => 'Turbidity Standard 100 NTU', 'cocok' => ['Turbidity Standard 100 NTU']],
        ['label' => 'Turbidity Standard 1000 NTU', 'cocok' => ['Turbidity Standard 1000 NTU']],
        ['label' => 'RTD Sensor/SH1/20', 'cocok' => ['Termometer & Sensor Std.', 'SH1/20', '23P1005']],
        ['label' => 'Victor 14+/992613877', 'cocok' => ['Victor 14+', '992613877']],
    ];

    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
        ['label' => 'TH-7', 'grup' => 'Insitu'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
    ];

    public function kode(): string
    {
        return 'turbidimeter';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Turbidimeter';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_TURBIDI;
    }

    public function besaran(): string
    {
        return 'turbidity';
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false): array
    {
        $bentuk = $this->bentukLengkap();
        $bentuk = $this->tautkanStandar($bentuk);
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
     * Budget 4 komponen turbidimeter buat satu titik. Balik `null` kalau
     * UTemperature (dari sertifikat termometer & sensor) belum keisi di
     * kemampuannya — biar jatuh ke jalur CMC lama, bukan ngarang angka.
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
    ): ?array {
        if ($kemampuan->u_temperature === null) {
            return null;
        }

        $sqrt3 = sqrt(3);
        $kStandar = $standard->faktor_cakupan ?: 2.0;
        $uTemperature = (float) $kemampuan->u_temperature;
        $resolusi = $this->resolusiTitik($titikUkur) ?? (float) $equipment->resolusi;

        // Koefisien sensitivitas suhu turbidimeter: ci = (UTemperature/400)·titik
        // (sel W13/W27/W41 di sheet PERHITUNGAN U95%). Beda dari pH yang
        // nyimpen ci konstanta per titik — di sini dia turunan dari nilai
        // titiknya, makanya dihitung, bukan diseed.
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
                'keterangan' => sprintf('Daya baca alat %s %s (titik %s)', $resolusi, self::SATUAN, $titikUkur),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
            ],
            [
                'sumber' => 'ketidakpastian_temperature',
                'keterangan' => sprintf(
                    'UTemperature %s °C (÷√3), ci (%s/400)·%s',
                    $uTemperature, $uTemperature, $titikUkur,
                ),
                'distribusi' => 'persegi',
                'u' => $uTemperature / $sqrt3,
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
     * Resolusi buat titik ukur ini, dari [TITIK]. Dicocokin ke titik terdekat
     * biar geseran kecil nilai standar (mis. 999,8 vs 1000) tetap ketemu.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return $this->titikTerdekat($titikUkur)['resolusi'];
    }

    public function desimalTitik(float $titikUkur): ?int
    {
        return $this->titikTerdekat($titikUkur)['desimal'];
    }

    /**
     * Standar turbidity dibaca NOMINAL apa adanya — `TurbidimeterSeeder` sengaja
     * nulis `koefisien_suhu = null`. Sama alasannya kayak chlorine: suhu masuk
     * budget lewat komponen suhunya sendiri, bukan buat ngegeser nilai acuan.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Label titik buat tampilan: nol belakang dibuang HANYA di bagian desimal.
     * "1.00"→"1", "100.0"→"100", "1000"→"1000" (bukan "1").
     */
    private function labelTitik(float $nilai, int $desimal): string
    {
        $label = number_format($nilai, $desimal, '.', '');

        return str_contains($label, '.') ? rtrim(rtrim($label, '0'), '.') : $label;
    }

    /**
     * Baris [TITIK] yang nilainya paling dekat ke titik ukur.
     *
     * @return array{nilai: float, resolusi: float, desimal: int}
     */
    private function titikTerdekat(float $titikUkur): array
    {
        $terdekat = self::TITIK[0];
        foreach (self::TITIK as $t) {
            if (abs($t['nilai'] - $titikUkur) < abs($terdekat['nilai'] - $titikUkur)) {
                $terdekat = $t;
            }
        }

        return $terdekat;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Turbidimeter',
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
     * Satu tabel hasil: baris = titik standar (bawa `resolusi`/`desimal`),
     * kolom = pembacaan NTU + suhu larutan, Repeat 1..5.
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
                    // Label = nilai apa adanya. Nol belakang dibuang HANYA di
                    // bagian desimal ("1.00"→"1"), bilangan bulat utuh ("1000"
                    // tetap "1000" — jangan sampai kestrip jadi "1").
                    'label' => $this->labelTitik($t['nilai'], $t['desimal']),
                    // Resolusi & desimal per titik — dipakai layar buat mad angka
                    // ke jumlah desimal yang bener tanpa buang nol belakang.
                    'resolusi' => $t['resolusi'],
                    'desimal' => $t['desimal'],
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
