<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil Refractometer (alat ke-4). Metode `SIDIK-IK-CAL-0516_Rev.4`
 * (OIML R 142-E:2008), lembar kerja `SIDIK-FM-CAL-0523_Rev.2`, sertifikat
 * `SIDIK-FM-CAL-2403_Rev.0`. Sumber angka: `Project-PT-Sidik/Refractometer_CSV 2/`.
 *
 * ## Yang bikin alat ini beda dari tiga sebelumnya
 *
 * **Koreksi suhu ada di sisi PEMBACAAN, bukan nilai standar.** pH kebalikannya:
 * buffer-nya yang bergeser ikut suhu (`Standard::nilaiPadaSuhu()`), pembacaan
 * alatnya sah apa adanya. Di sini larutan standarnya tetap 1,33659 — yang
 * dinormalisasi pembacaan alatnya, dari suhu baca ke suhu acuan n20D (20 °C).
 * Makanya [standarBerkurvaSuhu] `false` tapi [rataRataPadaSuhuAcuan] di-override.
 *
 * ```
 * PERHITUNGAN.csv baris 46: Standar 1,33659 | T 27 °C | Observed 1,3362 → Corrected 1,33935
 * SERTIFIKAT.csv  baris 18: Standard 1,33659 | UUT 1,33935 | Correction −0,00276
 * ```
 *
 * **Sheet "Tab Konversi Temperatur" itu linear murni.** 113 baris (19,0–30,2 °C
 * step 0,1) dicek numerik ulang waktu bikin profil ini: selisih maksimum
 * terhadap model linear `1,8e-15` — itu galat pembulatan IEEE, bukan kurva.
 * Jadi tabelnya NGGAK diseed ke DB; yang disimpen dua konstanta di [KOEF_SUHU].
 * Kalau lab ganti tabelnya jadi beneran non-linear, di sinilah tempatnya
 * berubah — bukan bikin tabel lookup baru.
 *
 * ## Dua penyimpangan yang DISENGAJA dari konvensi tiga alat lain
 *
 * Diputusin 6 Agt 2026: **tiru master Excel persis**, walau dua angka di bawah
 * nyimpang dari cara pH/Turbidimeter/Chlorine ngitung. Keduanya DISALIN dari
 * sheet lab, bukan diturunkan — jangan "dibenerin" tanpa lab mbenerin sheet-nya
 * dulu, karena yang keluar bakal beda dari sertifikat yang sudah terbit.
 *
 *  1. **Ketidakpastian larutan standar dibagi divisor 1**, bukan `/k=2`. Sheet
 *     `PERHITUNGAN U95%` kolom Divisor baris "Ketidakpastian Kemurnian Standard
 *     Larutan Refracto" nulis `1`, padahal distribusinya "normal" dan angkanya
 *     U95%. Efeknya komponen ini dua kali lebih besar dari cara tiga alat lain.
 *  2. **`ci` komponen suhu = resolusi alat (0,0001)**, bukan koefisien fisiknya
 *     (0,00045 n20D/°C). Sheet nulis 0,0001 di kolom ci.
 *
 * Ketiga, di `RefractometerCapabilitySeeder`: `u_temperature` diturunin dari
 * U95% termometer 0,07 & sensor 0,036 (sheet U95%), padahal sheet DATABASE di
 * workbook yang SAMA nulis 0,72 & 0,06 — dan 0,72/0,06 itu yang dipakai
 * Turbidimeter & Chlorine buat termometer FISIK yang sama. Lihat docblock
 * seeder-nya.
 *
 * ## Cakupan sekarang: n20D dulu, °Brix nyusul
 *
 * Blok budget °Brix di `PERHITUNGAN U95%.csv` rusak (`#REF!`/`#DIV/0!`) dan
 * komponennya beda total dari n20D — pakai "Berat Sukrosa" & "Berat Molekul
 * Sukrosa" yang nggak ada padanannya di sini. CMC °Brix tetap diseed, tapi
 * `u_temperature`-nya null, jadi [komponenBudget] balik null dan titik °Brix
 * jatuh ke jalur CMC lama (`GumCalculator::hitungDariKemampuan`). Itu disengaja:
 * ngelaporin CMC apa adanya jauh lebih jujur daripada ngarang lima komponen.
 *
 * [KOEF_SUHU] & [rataRataPadaSuhuAcuan] SUDAH nanganin °Brix (0,07/°C) — yang
 * belum cuma daftar komponen budget-nya.
 *
 * Engine budget dicocokin ke sheet `PERHITUNGAN U95%` (titik 1,33659 → uc =
 * 0.00026270364640284643) di `RefractometerBudgetTest`.
 */
class RefractometerProfile extends CalibrationProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0523_Rev.2';

    public const JUMLAH_PENGULANGAN = 5;

    public const SATUAN_N20D = 'n20D';

    public const SATUAN_BRIX = '°Brix';

    /**
     * Suhu acuan indeks bias n20D — huruf "20" di `n20D` itu ini. Semua
     * pembacaan dinormalisasi ke sini sebelum dibandingin sama larutan standar.
     */
    public const SUHU_ACUAN = 20.0;

    /**
     * Kemiringan "Tab Konversi Temperatur" per satuan, dalam satuan-per-°C.
     * Diturunin dari 113 baris tabelnya (lihat docblock kelas).
     */
    public const KOEF_SUHU = [
        self::SATUAN_N20D => 0.00045,
        self::SATUAN_BRIX => 0.07,
    ];

    /** Batas atas-bawah "Tab Konversi Temperatur" — dipakai [deviasiSuhu]. */
    public const SUHU_TABEL_MIN = 19.0;

    public const SUHU_TABEL_MAKS = 30.2;

    /**
     * Resolusi alat — sheet `PERHITUNGAN U95%`, "Ketelitian Baca : 0.0001 n20D".
     * Seragam di dua titik.
     */
    public const RESOLUSI = 0.0001;

    /**
     * Dua titik n20D yang ada di lingkup akreditasi (lampiran LK-285-IDN no. 45
     * & sheet DATABASE "Jenis UTM"). Nilai nominalnya diambil dari larutan
     * standar yang lab beneran punya — 1,33659 & 1,39986 — bukan 1,3366 &
     * 1,3999 yang dibulatkan di tabel CMC.
     *
     * @var list<array{nilai: float, label: string}>
     */
    public const TITIK = [
        ['nilai' => 1.33659, 'label' => '1,33659'],
        ['nilai' => 1.39986, 'label' => '1,39986'],
    ];

    /**
     * Titik yang sama, dibaca di skala °Brix — **larutan fisiknya identik**.
     * `BSAG2.5-0034` dibaca 2,5 °Brix ATAU 1,33659 n20D; `BSAG40-0071` dibaca
     * 40 °Brix ATAU 1,39986 n20D (lihat [STANDARD_TERCETAK]).
     *
     * Ada karena satuan alat nentuin titik standarnya juga, bukan cuma
     * koefisien suhunya. Sebelum ini lembar kerja selalu ngirim dua titik n20D
     * apa pun satuan yang dipilih teknisi — jadi sesi °Brix ngirim
     * `titik_ukur: 1.33659` bareng `satuan: "°Brix"`, dan dikoreksi pakai
     * koefisien °Brix. Nilai standar satu skala, pembacaan skala lain.
     *
     * Angkanya cocok sama CMC yang diseed `RefractometerCapabilitySeeder`
     * (2,5 & 40 °Brix), jadi titiknya tetap dapat budget.
     *
     * @var list<array{nilai: float, label: string}>
     */
    public const TITIK_BRIX = [
        ['nilai' => 2.5, 'label' => '2,5'],
        ['nilai' => 40.0, 'label' => '40'],
    ];

    /** Titik standar per satuan — dipakai layar buat nuker baris tabel. */
    public const TITIK_PER_SATUAN = [
        self::SATUAN_N20D => self::TITIK,
        self::SATUAN_BRIX => self::TITIK_BRIX,
    ];

    /**
     * Baris tabel STANDARD di lembar kerja, dari sheet DATABASE baris 13–18.
     *
     * Dicocokin lewat NAMA doang buat empat larutannya, sengaja bukan serial:
     * satu botol fisik dipakai buat dua satuan sekaligus (BSAG2.5-0034 =
     * 2,5 °Brix DAN 1,33659 n20D; BSAG40-0071 = 40 °Brix DAN 1,39986 n20D),
     * jadi nyocokin lewat serial bakal ketuker baris. Termometernya baru
     * dicocokin lewat serial karena namanya generik.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Refractometer Std Solution 1.33659 n20D',
            'cocok' => ['Refractometer Std Solution 1.33659 n20D'],
        ],
        [
            'label' => 'Refractometer Std Solution 1.39986 n20D',
            'cocok' => ['Refractometer Std Solution 1.39986 n20D'],
        ],
        [
            'label' => 'Refractometer Std Solution 2.5 oBrix',
            'cocok' => ['Refractometer Std Solution 2.5 oBrix'],
        ],
        [
            'label' => 'Refractometer Std Solution 40 oBrix',
            'cocok' => ['Refractometer Std Solution 40 oBrix'],
        ],
        [
            'label' => 'Termometer & Sensor Std.',
            'cocok' => ['Termometer & Sensor Std.', '23P1005'],
        ],
        ['label' => 'PT100/SH1', 'cocok' => ['PT100/SH1', 'SH1/20']],
    ];

    /**
     * Sheet DATABASE refractometer daftarin TH-1 s/d TH-7 lengkap (kolom
     * "NO TH"), beda dari lembar Chlorine yang cuma empat. Grup-nya dari kolom
     * Lokasi di tabel Environmental Meter.
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
        return 'refractometer';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Refractometer';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_REFRACTOMETER;
    }

    public function besaran(): string
    {
        return 'refractive_index';
    }

    /**
     * Satuan yang dipakai alat ini — `n20D` (indeks bias) atau `°Brix` (kadar
     * sukrosa). Satu refractometer bisa nampilin dua-duanya; yang dipilih
     * teknisi kesimpen di `equipments.satuan` (sheet INPUT DATA: "Satuan
     * refracto").
     *
     * Ejaannya dinormalisasi karena lab nulis `oBrix` di Excel, `°Brix` di
     * lampiran akreditasi, dan mobile ngirim apa yang diketik teknisi. Apa pun
     * yang nggak kebaca sebagai Brix dianggap n20D — itu satuan bawaan alatnya
     * dan jalur yang budget-nya udah lengkap.
     */
    public function satuan(Equipment $equipment): string
    {
        $satuan = mb_strtolower(trim((string) $equipment->satuan));

        return str_contains($satuan, 'brix') ? self::SATUAN_BRIX : self::SATUAN_N20D;
    }

    /**
     * Pembacaan alat dinormalisasi dari suhu baca ke suhu acuan 20 °C.
     *
     *     Corrected = Observed + koef × (T − 20)
     *
     * Cek ke sheet: `1,3362 + 0,00045 × (27 − 20) = 1,33935` dan
     * `1,3986 + 0,00045 × (25 − 20) = 1,40085` — dua-duanya cocok sama kolom
     * "Corrected Value" di `PERHITUNGAN.csv` baris 46–47.
     *
     * **Sengaja nggak di-clamp** ke rentang tabel, beda dari [deviasiSuhu].
     * Excel-nya ngitung pakai rumus di sini (baris 48 nunjukin T=0 → −0,009,
     * jauh di luar tabel 19–30,2), bukan lookup. Yang di-clamp cuma komponen
     * budget, yang di sheet-nya emang VLOOKUP beneran.
     *
     * Suhu nggak dicatat → pembacaan dipakai apa adanya. Itu jujur: tanpa suhu
     * kita nggak tau harus digeser ke mana, dan `CalibrationValidator` yang
     * bakal nge-flag suhunya kosong.
     */
    public function rataRataPadaSuhuAcuan(
        float $rataRata,
        ?float $suhuLarutan,
        Equipment $equipment,
    ): float {
        if ($suhuLarutan === null) {
            return $rataRata;
        }

        return $rataRata + self::KOEF_SUHU[$this->satuan($equipment)] * ($suhuLarutan - self::SUHU_ACUAN);
    }

    /**
     * Deviasi "Tab Konversi Temperatur" pada suhu ruang — nilai kolom `U` baris
     * "Ketidakpastian Pengaruh Perbedaan Temperature" di sheet `PERHITUNGAN U95%`.
     *
     *     Deviasi = −koef × (T − 20)
     *
     * Sheet-nya VLOOKUP *approximate* ke tabel step 0,1, jadi suhunya diturunin
     * ke kelipatan 0,1 (bukan dibulatkan): suhu ruang rata-rata 21,05 → baris
     * 21,0 → −0,00045. Itu yang dipakai dua blok budget di sheet.
     *
     * `round(..., 6)` sebelum `floor` itu wajib, bukan hiasan: `21.1 * 10`
     * dalam IEEE754 = `210.99999999999997`, dan `floor`-nya langsung ngasih
     * baris 21,0 — salah satu baris ke bawah tanpa ada yang ngeh.
     *
     * Di luar rentang tabel di-clamp ke ujungnya. Excel bakal `#N/A` di bawah
     * 19 °C; clamp lebih berguna dan tetap konservatif, dan lab nggak pernah
     * ngalibrasi di luar 19–30 °C.
     */
    public function deviasiSuhu(float $suhuRuang, string $satuan): float
    {
        $t = max(self::SUHU_TABEL_MIN, min(self::SUHU_TABEL_MAKS, $suhuRuang));
        $t = floor(round($t * 10, 6)) / 10;

        return -(self::KOEF_SUHU[$satuan] ?? self::KOEF_SUHU[self::SATUAN_N20D]) * ($t - self::SUHU_ACUAN);
    }

    /**
     * Budget **5 komponen**, urutannya persis sheet `PERHITUNGAN U95%`.
     *
     * Balik `null` — jatuh ke jalur CMC, bukan ngarang angka — kalau:
     *  - `u_temperature` kosong (titik °Brix; lihat docblock kelas), atau
     *  - suhu ruang nggak kekirim (komponen 3 nggak bisa dihitung).
     *
     * Dua komponen di sini nyimpang dari konvensi tiga alat lain **dengan
     * sengaja** — lihat docblock kelas sebelum "mbenerin" apa pun di bawah.
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
        if ($kemampuan->u_temperature === null || $suhuRuang === null) {
            return null;
        }

        $satuan = $this->satuan($equipment);
        $sqrt3 = sqrt(3);
        $resolusi = $this->resolusiTitik($titikUkur) ?? (float) $equipment->resolusi;
        $uTemperature = (float) $kemampuan->u_temperature;
        $deviasi = $this->deviasiSuhu($suhuRuang, $satuan);

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                // Divisor 1, BUKAN /k=2. Lihat docblock kelas — ini nyimpang
                // dari tiga alat lain dan itu disengaja.
                'keterangan' => sprintf(
                    'Kemurnian larutan standar %s (U=%s %s, divisor 1 ikut master Excel)',
                    $standard->nama,
                    $standard->ketidakpastian,
                    $standard->satuan_ketidakpastian ?? $satuan,
                ),
                'distribusi' => 'normal',
                'u' => (float) ($standard->ketidakpastian ?? 0.0),
                'ci' => 1.0,
                'vi' => 200,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s', $resolusi, $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000,
            ],
            [
                'sumber' => 'perbedaan_suhu',
                'keterangan' => sprintf(
                    'Pengaruh perbedaan temperature: deviasi tabel konversi %s pada suhu ruang %s °C',
                    $deviasi,
                    $suhuRuang,
                ),
                'distribusi' => 'persegi',
                'u' => $deviasi / $sqrt3,
                'ci' => 1.0,
                'vi' => 50,
            ],
            [
                'sumber' => 'ketidakpastian_temperature',
                // ci = resolusi alat, bukan koefisien fisik 0,00045/°C. Ikut
                // sheet — lihat docblock kelas.
                'keterangan' => sprintf(
                    'UTemperature %s °C (÷k=2), ci = resolusi %s ikut master Excel',
                    $uTemperature,
                    $resolusi,
                ),
                'distribusi' => 'normal',
                'u' => $uTemperature / 2.0,
                'ci' => $resolusi,
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

    /** Resolusi seragam di dua titik (sheet: "Ketelitian Baca : 0.0001 n20D"). */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return self::RESOLUSI;
    }

    /** `null` = resolusinya seragam, mobile nggak perlu mad per baris. */
    public function desimalTitik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Larutan standar refractometer dibaca NOMINAL apa adanya — yang digeser
     * suhu itu PEMBACAAN alatnya ([rataRataPadaSuhuAcuan]), bukan nilai acuan.
     * Kalau ini `true`, `CalibrationValidator` bakal nge-flag tiap sertifikat
     * gara-gara `standards.koefisien_suhu` null, padahal null itu jawaban yang
     * benar buat alat ini.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
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
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Refractometer',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => array_map(fn (array $t): float => $t['nilai'], self::TITIK),
            'satuan' => self::SATUAN_N20D,
            'satuan_suhu' => '°C',
            // Satuan alat ini bisa dua-duanya dan itu ngubah SEMUA angka
            // hilirnya (koefisien suhu, titik standar, CMC). Dikirim sebagai
            // pilihan biar layar input bisa nanya di depan, bukan nebak.
            'pilihan_satuan' => [
                ['nilai' => self::SATUAN_N20D, 'label' => 'n20D (indeks bias)'],
                ['nilai' => self::SATUAN_BRIX, 'label' => '°Brix (kadar sukrosa)'],
            ],
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — '
                .'lembar kerja tetap bisa dikirim. Titik yang datanya belum cukup nggak ikut dihitung, '
                .'dan sertifikatnya baru bisa terbit sesudah dilengkapi admin. Suhu tiap pembacaan '
                .'WAJIB diisi kalau bisa: tanpa itu pembacaan nggak bisa dinormalisasi ke 20 °C.',
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
                        // SENGAJA tanpa `satuan`, beda dari tiga profil lain.
                        //
                        // Nilai yang ditampilin (`equipment.range_resolusi`) udah
                        // bawa satuannya sendiri — "0–53 °Brix / 0,1 °Brix". Buat
                        // pH/Turbidimeter/Chlorine nempelin satuan tetap di situ
                        // aman, satuan alatnya emang cuma satu. Refractometer
                        // nggak: alat yang kecatat °Brix bikin barisnya kebaca
                        // "0–53 °Brix / 0,1 °Brix   n20D" — satu baris yang
                        // membantah dirinya sendiri, di dokumen yang justru
                        // gunanya nyatet satuan dengan benar.
                        //
                        // Ketahuan 7 Agt 2026 waktu lembarnya dibuka di HP pakai
                        // alat Atago MASTER-53M.
                        $this->field('equipment.range_resolusi', '2. Range/Resolution', 'teks', sumber: 'otomatis'),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                        $this->field('alat_merk', '5. Merk/Manufacture', 'teks'),
                        $this->field(
                            'thermohygro_standard_id',
                            '6. Thermohygro Used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                        $this->field('equipment.satuan', '7. Satuan Refracto', 'pilihan', pilihan: [
                            ['nilai' => self::SATUAN_N20D, 'label' => 'n20D'],
                            ['nilai' => self::SATUAN_BRIX, 'label' => '°Brix'],
                        ]),
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
     * Satu tabel hasil: baris = titik standar, kolom = pembacaan + suhu larutan,
     * Repeat 1..5.
     *
     * Kolom `suhu` di sini BUKAN pelengkap kayak di lembar Chlorine — dia yang
     * dipakai [rataRataPadaSuhuAcuan] buat mindahin pembacaan ke 20 °C. Tanpa
     * dia, kolom Correction di sertifikat meleset sebesar koreksi suhunya.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  list<array{nilai: float, label: string}>  $titik
     * @return list<array{titik_ukur: float, label: string}>
     */
    private function barisTitik(array $titik): array
    {
        return array_map(
            fn (array $t): array => [
                'titik_ukur' => $t['nilai'],
                'label' => $t['label'],
            ],
            $titik,
        );
    }

    private function tabelHasil(string $tahap, string $judul): array
    {
        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'baris' => $this->barisTitik(self::TITIK),
            // Dua set baris dikirim SEKALIGUS, bukan lembar kerjanya diambil
            // ulang tiap satuan diganti.
            //
            // Satuannya dipilih DI DALAM formulir (`equipment.satuan`), jadi
            // waktu `GET /calibrations/lembar-kerja` dipanggil backend belum
            // tahu mana yang bakal dipakai. Ngambil ulang tiap kali diganti
            // berarti seluruh isian yang udah diketik teknisi kereset — di
            // lapangan itu jauh lebih mahal daripada satu field ekstra di
            // respons. `baris` di atas tetap ada & tetap n20D biar app versi
            // lama nggak berubah perilakunya.
            'baris_per_satuan' => array_map(
                fn (array $titik): array => $this->barisTitik($titik),
                self::TITIK_PER_SATUAN,
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => self::SATUAN_N20D, 'tipe' => 'angka', 'satuan' => self::SATUAN_N20D],
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
     * **5 desimal, bukan 4** — satu-satunya alat yang nyimpang dari aturan
     * umum, dan angkanya diambil dari sertifikat master yang beneran kecetak.
     *
     * Resolusi alat 0,0001 bakal ngasih 4, dan di 4 desimal U95% runtuh:
     * `0,00053` jadi `0,0005` — kehilangan angka penting di kolom yang justru
     * jadi inti sertifikat kalibrasi. Nilainya pun kepangkas, `1,33935` jadi
     * `1,3394`.
     *
     * pH & Chlorine di master TETAP 2 desimal (= resolusinya), jadi ini BUKAN
     * rumus yang bisa diturunkan ("resolusi + 1" bakal ngerusak dua alat itu).
     * Ini pilihan lab per alat, dan di sinilah tempat nyatetnya.
     *
     * Dibetulin 7 Agt 2026 sesudah sertifikat master diadu langsung ke keluaran
     * app — dua-duanya nyimpen angka yang sama persis (`1,33935`, `0,00052715`),
     * yang beda cuma berapa digit yang kecetak.
     */
    public function desimalSertifikat(): ?int
    {
        return 5;
    }
}
