<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Autoklaf (alat ke-8). Metode `SIDIK-IK-CAL-0531_Rev.4`, form
 * `SIDIK-FM-CAL-0539_Rev.4`. Master `Master Olah Data_Autoclave.xlsm`
 * (LK-285-IDN).
 *
 * Beda TOTAL dari tujuh alat sebelumnya, dan itu sebabnya olah datanya nggak
 * lewat `GumCalculator` sama sekali melainkan `AutoclaveCalculator`:
 *
 *  - SATU sesi = DUA besaran: Suhu (°C) & Tekanan (bar/MPa). Bukan satu besaran
 *    banyak titik.
 *  - Suhu diukur 3 disk sensor (Tecnosoft SterilDisk) sekaligus, tiap disk
 *    beberapa titik waktu (2/4/6/8/10 jam) pada SATU set point (mis. 121 °C),
 *    ditambah pembacaan Indikator enclosure autoklaf & suhu ruang tiap titik
 *    waktu. Keluarannya bukan cuma koreksi: juga Kestabilan (SS), Keseragaman
 *    (KS), Variasi Keseluruhan (VK) — metrik kinerja autoklaf.
 *  - Tekanan diukur satu titik pakai Pressure Disk Logger; UUT setting dikonversi
 *    dari satuan alat (MPa/Psi/…) ke bar sebelum dihitung, hasil diturunkan lagi
 *    ke satuan alat buat dicetak.
 *
 * Karena bentuk datanya nggak muat di "titik ukur + pengulangan", `komponenBudget`
 * balik `null` (profil ini nggak dilayani jalur budget per-titik). Perhitungan
 * dijalanin lewat `POST /calibrations/autoclave/preview` -> `AutoclaveCalculator`.
 *
 * Profil ini tetap terdaftar di `CalibrationProfileRegistry` supaya jalur yang
 * nyapu semua alat (mis. daftar template OCR) tetap kenal Autoklaf, dan supaya
 * `GET /calibrations/lembar-kerja?profil=autoclave` bisa balikin lembar
 * kerjanya.
 */
class AutoclaveProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0539_Rev.4';

    /** Titik waktu bawaan (2/4/6/8/10 jam) — INPUT DATA D34:K34. */
    public const JUMLAH_TITIK_WAKTU = 5;

    /** Jumlah disk sensor suhu yang dibawa (Tecnosoft SterilDisk). */
    public const JUMLAH_DISK = 3;

    /** Pengulangan pembacaan tekanan bawaan — INPUT DATA D45:K45. */
    public const JUMLAH_PEMBACAAN_TEKANAN = 5;

    public const SATUAN_SUHU = '°C';

    /**
     * Satuan tekanan yang bisa dipilih teknisi — master `DATABASE!R20:R27`.
     * `faktor` = pengali ke bar (buat referensi frontend; konversi resminya di
     * `AutoclaveCalculator`).
     *
     * @var list<array{nilai: string, label: string, faktor: float}>
     */
    public const SATUAN_TEKANAN = [
        ['nilai' => 'Bar', 'label' => 'Bar', 'faktor' => 1.0],
        ['nilai' => 'MPa', 'label' => 'MPa', 'faktor' => 10.0],
        ['nilai' => 'kPa', 'label' => 'kPa', 'faktor' => 0.01],
        ['nilai' => 'Psi', 'label' => 'Psi', 'faktor' => 0.0689475729],
        ['nilai' => 'kg/cm2', 'label' => 'kg/cm²', 'faktor' => 0.980665],
        ['nilai' => 'inHg', 'label' => 'inHg', 'faktor' => 0.033863886666667],
        ['nilai' => 'mmHg', 'label' => 'mmHg', 'faktor' => 0.0013332239],
        ['nilai' => 'Pa', 'label' => 'Pa', 'faktor' => 0.00001],
    ];

    /** Tipe display alat tekanan — master `PERHITUNGAN U95%!X25:X28`. */
    public const DISPLAY_TEKANAN = [
        ['nilai' => 'Digital', 'label' => 'Digital'],
        ['nilai' => 'Analog 1', 'label' => 'Analog (rasio jarum:NST 1/2)'],
        ['nilai' => 'Analog 2', 'label' => 'Analog (rasio jarum:NST 1/5)'],
        ['nilai' => 'Analog 3', 'label' => 'Analog (rasio jarum:NST 1/10)'],
    ];

    /**
     * Standar yang tercetak di lembar Autoklaf — 3 Temperature Calibrator +
     * Pressure Disk Logger (Tecnosoft). Dicocokin ke master `standards`
     * lewat nama/serial. Belum keseed = `terdaftar: false`, layar tetap jalan.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Temperature Calibrator 1 (Tecnosoft/SterilDisk)', 'cocok' => ['Temperature Calibrator 1', '1011001961']],
        ['label' => 'Temperature Calibrator 2 (Tecnosoft/TS01SD)', 'cocok' => ['Temperature Calibrator 2', '1011004038']],
        ['label' => 'Temperature Calibrator 3 (Tecnosoft/TS01SD)', 'cocok' => ['Temperature Calibrator 3', '1011004063']],
        ['label' => 'Pressure Disk Logger (Tecnosoft/PressureDisk 05)', 'cocok' => ['Pressure Disk', 'Pressure Disk Logger', '3501009550']],
    ];

    /** Sama kayak profil lain — semua unit thermohygro lab, Insitu vs Inlab. */
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
        return 'autoclave';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Autoklaf';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_AUTOCLAVE;
    }

    public function besaran(): string
    {
        return 'suhu_tekanan';
    }

    /**
     * Autoklaf nggak divonis PASS/FAIL lewat satu kolom `equipments.toleransi`:
     * sertifikat masternya cuma nyetak koreksi, U95, dan metrik kinerja
     * (Kestabilan/Keseragaman/Variasi) tanpa satu sel keberterimaan pun. Sama
     * kayak Conductivity.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Autoklaf nggak lewat jalur budget per-titik `GumCalculator` — olah datanya
     * di `AutoclaveCalculator`. Balik `null` supaya, kalau toh ada yang nyoba
     * ngelewatin sesi Autoklaf ke jalur generik, dia jatuh ke perilaku lama
     * dengan aman, bukan ngarang budget yang salah bentuk.
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
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
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
     * Lembar Autoklaf NGGAK BISA dituturkan ke pembaca foto AI.
     *
     * Dua penanda bawaan (`kolom_suhu`, `standar_di_baris`) cuma sanggup
     * menggambarkan lembar "titik ukur × Repeat". Kertas ini matriks: tujuh
     * baris yang besarannya campur — tiga disk suhu, indikator suhu, indikator
     * tekanan, tekanan atmosfer, suhu ruang — plus satu baris JAM, semuanya
     * melintang lima titik waktu. Nggak ada kombinasi dua penanda itu yang
     * benar.
     *
     * Sebelum ini profil ini nggak override apa pun, jadi dia diam-diam kebagian
     * bentuk lembar pH. Akibatnya bukan error: model diminta membaca "tiap sel
     * isinya pembacaan + suhu" untuk kertas yang barisnya bukan titik ukur, dan
     * yang balik itu angka yang bentuknya wajar tapi mendarat di baris yang
     * salah — kegagalan paling mahal di fitur ini, dan yang paling nggak
     * bergejala.
     *
     * Ditolak di depan, bukan dicoba. Jalur pindai LOKAL (`POST /worksheet-scans`)
     * yang memang paham bentuk matriks ini tetap jalan begitu geometri lembarnya
     * terverifikasi.
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => true, 'standar_di_baris' => false, 'didukung' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Autoclave',
            'metode' => 'SIDIK-IK-CAL-0531_Rev.4',
            'besaran' => ['Suhu', 'Tekanan'],
            'satuan_suhu' => self::SATUAN_SUHU,
            'jumlah_disk' => self::JUMLAH_DISK,
            'jumlah_titik_waktu' => self::JUMLAH_TITIK_WAKTU,
            'jumlah_pembacaan_tekanan' => self::JUMLAH_PEMBACAAN_TEKANAN,
            'satuan_tekanan_pilihan' => self::SATUAN_TEKANAN,
            'display_tekanan_pilihan' => self::DISPLAY_TEKANAN,
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin. '
                .'Suhu butuh minimal 1 disk terisi; tekanan butuh minimal 1 pembacaan. '
                .'Titik waktu yang kosong nggak ikut dirata-rata.',
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
                        $this->field('alat_merk', '2. Manufacturer', 'teks'),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number', 'teks'),
                        $this->field('range_suhu', '5. Range Temperature', 'angka', satuan: self::SATUAN_SUHU),
                        $this->field('resolusi_suhu', '6. Resolution Temperature', 'angka', satuan: self::SATUAN_SUHU),
                        $this->field('range_tekanan', '7. Range Pressure', 'angka'),
                        $this->field('resolusi_tekanan', '8. Resolution Pressure', 'angka'),
                        $this->field('satuan_tekanan', '9. Pressure Unit', 'pilihan', pilihan: self::SATUAN_TEKANAN),
                        $this->field('display_tekanan', '10. Pressure Display Type', 'pilihan', pilihan: self::DISPLAY_TEKANAN),
                        $this->field('thermohygro_standard_id', '11. Thermohygro used', 'pilihan', sumber: 'master_thermohygro'),
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
                    'judul' => 'STANDARD USED',
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
                        $this->field('calibration_method_id', '2. Calibration Method', 'pilihan', sumber: 'master_metode', hanyaAdmin: true),
                    ],
                ],
                [
                    'kode' => 'kondisi_lingkungan',
                    'halaman' => 1,
                    'judul' => 'ENVIRONMENTAL CONDITION',
                    'field' => [
                        $this->field('suhu_awal', 'T — Awal', 'angka', satuan: self::SATUAN_SUHU),
                        $this->field('suhu_akhir', 'T — Akhir', 'angka', satuan: self::SATUAN_SUHU),
                        $this->field('kelembaban_awal', 'RH — Awal', 'angka', satuan: '%RH'),
                        $this->field('kelembaban_akhir', 'RH — Akhir', 'angka', satuan: '%RH'),
                    ],
                ],
                $this->bagianHasilSuhu(),
                $this->bagianHasilTekanan(),
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
     * Section CALIBRATION RESULT FOR TEMPERATURE — matriks: baris = 3 disk +
     * Indikator + Suhu Ruang, kolom = titik waktu. `matriks_suhu` adalah kunci
     * bespoke Autoklaf; frontend render sebagai tabel (lihat handoff).
     *
     * @return array<string, mixed>
     */
    private function bagianHasilSuhu(): array
    {
        $baris = [];
        for ($d = 1; $d <= self::JUMLAH_DISK; $d++) {
            $baris[] = ['kode' => "disk_{$d}", 'label' => "Temp. Disk {$d}", 'tipe' => 'disk', 'satuan' => self::SATUAN_SUHU];
        }
        $baris[] = ['kode' => 'indikator', 'label' => 'Indikator Suhu', 'tipe' => 'indikator', 'satuan' => self::SATUAN_SUHU];
        $baris[] = ['kode' => 'suhu_ruang', 'label' => 'Suhu Ruang', 'tipe' => 'suhu_ruang', 'satuan' => self::SATUAN_SUHU];

        return [
            'kode' => 'hasil_suhu',
            'halaman' => 2,
            'judul' => 'CALIBRATION RESULT FOR TEMPERATURE',
            'field' => [
                $this->field('set_point', 'Set Point', 'angka', satuan: self::SATUAN_SUHU),
            ],
            'matriks_suhu' => [
                'titik_waktu' => range(1, self::JUMLAH_TITIK_WAKTU),
                'label_waktu' => 'Waktu pengambilan data (jam)',
                'baris' => $baris,
            ],
        ];
    }

    /**
     * Section CALIBRATION RESULT FOR PRESSURE — satu titik, N pembacaan disk
     * logger (bar) + UUT setting (satuan alat) + tekanan atm awal.
     *
     * @return array<string, mixed>
     */
    private function bagianHasilTekanan(): array
    {
        return [
            'kode' => 'hasil_tekanan',
            'halaman' => 2,
            'judul' => 'CALIBRATION RESULT FOR PRESSURE',
            'field' => [
                $this->field('tekanan.uut_setting', 'UUT Setting', 'angka'),
                $this->field('tekanan.tekanan_atm_awal', 'Tekanan atm awal', 'angka'),
            ],
            'tabel_tekanan' => [
                'label' => 'Pengukuran Berulang UUT Selama Proses Sterilisasi (Bar)',
                'kolom' => ['kode' => 'pembacaan_standar', 'label' => 'Standar Reading', 'satuan' => 'Bar'],
                'pengulangan' => range(1, self::JUMLAH_PEMBACAAN_TEKANAN),
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
            ],
        ];
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` (nama/serial).
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
