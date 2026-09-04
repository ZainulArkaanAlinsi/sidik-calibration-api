<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Calibration\ThermocoupleCalculator;
use App\Services\Calibration\ThermohygroCalculator;
use App\Services\Calibration\ThermometerGlassCalculator;

/**
 * Induk tiga alat suhu yang lembar kerjanya berbentuk **pasangan deret** —
 * Thermocouple, Termometer Gelas, Thermohygrometer.
 *
 * ## Apa yang bikin ketiganya sekeluarga
 *
 * Di sepuluh alat pertama, nilai standar itu KONSTANTA yang datang dari master
 * `standards` (buffer pH 4,01 tetap 4,01; larutan turbidity 10 NTU tetap 10).
 * Yang diulang cuma pembacaan UUT, dan kolom `Correction` mengadu rata-rata itu
 * ke nilai nominal.
 *
 * Ketiga alat ini tidak begitu. UUT dan probe standar dicelup ke sumber suhu
 * yang sama (dryblock / oilbath / climatic chamber), lalu **dua-duanya dibaca**
 * — bergantian, lima kali masing-masing. Jadi:
 *
 *  - Nilai standar itu **data sesi**, bukan konstanta master. Dia punya
 *    rata-rata, STDEV, dan koreksi kalibratornya sendiri.
 *  - Satu titik ukur menghasilkan **sepuluh** angka, bukan lima.
 *  - Kolom `Correction` = standar TERKOREKSI − UUT, dua-duanya turunan sesi.
 *
 * Itu sebabnya ketiganya menyalakan [butuhPasanganStandarUut] dan mengambil alih
 * [hitungPerGrup]: jalur datar `measurements[i].pembacaan` cuma punya tempat
 * buat SATU deret, dan deret yang hilang justru sisi kiri kolom `Correction`.
 *
 * ## Yang SENGAJA tidak diseragamkan
 *
 * Godaan terbesar kelas induk seperti ini adalah menyeragamkan rantai
 * koreksinya. Jangan — ketiganya beda dan bedanya fisik, bukan gaya penulisan:
 *
 *   Thermocouple  UUT punya meter sendiri  → UUT dikoreksi meter kalibrator
 *   Gelas         UUT dibaca dengan MATA   → UUT apa adanya
 *   Thermohygro   UUT dibaca dari layarnya → UUT apa adanya
 *
 * Rantai koreksi karena itu tinggal di kalkulator masing-masing, dan kelas ini
 * cuma mengurus BENTUK lembar kerjanya. Uraian tiap penyimpangan ada di
 * docblock kalkulatornya sendiri.
 *
 * @see ThermocoupleCalculator
 * @see ThermometerGlassCalculator
 * @see ThermohygroCalculator
 */
abstract class ProfilSuhuPasangan extends CalibrationProfile
{
    /** Lima pembacaan per deret, kedua sisi — `INPUT DATA` kolom `X1`…`X5`. */
    public const PENGULANGAN = 5;

    /**
     * Unit thermohygro "Environmental Meter Used".
     *
     * Ketiga master mencetak `Thermohygro Used` dengan pilihan TH-1..TH-7 lewat
     * rumus `IF(E23=1,"TH-1",…)` yang sama persis. Grup Inlab/Insitu ikut
     * penggolongan kanonik seperti TITS — kertasnya sendiri tidak mencetak grup.
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

    /** Dua deret per titik — lihat docblock kelas. */
    public function butuhPasanganStandarUut(): bool
    {
        return true;
    }

    /**
     * Tidak divonis PASS/FAIL.
     *
     * Ketiga master berhenti di baris `Uncertainty 95%`: tidak ada kolom
     * `Tolerance`, tidak ada `Result`, tidak ada PASS/FAIL. Sama seperti TITS,
     * TIDS, Autoklaf, DO Meter, Gas Detector, dan Enclosure.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * SATU U95 per sesi (Thermohygro: satu per grup), dicetak sebagai baris di
     * bawah tabel — bukan kolom per titik. Itu memang bentuk budget-nya.
     */
    public function u95PerTitik(): bool
    {
        return false;
    }

    /** `k` nol desimal di ketiga master (`SERTIFIKAT` format `0`). */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /** Suhu ruang satu desimal — `PERHITUNGAN FC` blok kondisi lingkungan. */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /** Kelembaban ruang nol desimal (`SERTIFIKAT!P15` format `0`). */
    public function desimalKelembabanEnv(): ?int
    {
        return 0;
    }

    /**
     * Kalibrator dibaca NOMINAL — tidak ada kurva suhu larutan di ketiga alat
     * ini. Koreksinya datang dari tabel sertifikat kalibrator, bukan polinomial.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * SENGAJA `null` — ketiganya tidak punya budget per titik. U95-nya lahir
     * sekali per sesi (atau per grup) lewat [hitungPerGrup]. Balik `null` bikin
     * `GumCalculator::hitungTitik()` jatuh ke jalur CMC apa adanya kalau suatu
     * saat terpanggil, bukan menyusun budget karangan yang kelihatan sah.
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
        return null;
    }

    /**
     * Pemeriksa `pembacaan_bukan_kelipatan_resolusi` DIMATIKAN.
     *
     * Premisnya — tiap angka yang dicatat dibaca di layar UUT, dan layar itu
     * punya satu daya baca tetap — tidak berlaku di sini: setengah dari angka
     * yang masuk lembar ini dibaca di layar KALIBRATOR, yang resolusinya lain
     * (0,01 °C untuk Yokogawa vs 0,1 °C untuk indikator UUT sesi contoh).
     * Diadu ke `equipments.resolusi`, tiap pembacaan standar jadi tuduhan salah
     * ketik atas angka yang disalin apa adanya dari master lab.
     *
     * Kelas kesalahan yang sama sudah kejadian di TITS (25 tuduhan palsu per
     * sesi) dan di baris Suhu Ruang Enclosure (20 per sesi): penggaris yang
     * salah tidak menghasilkan error, dia menghasilkan kebenaran yang dibalik.
     */
    public function pembacaanDiadukeResolusi(): bool
    {
        return false;
    }

    /**
     * Satu angka per sel — TIDAK ada kolom suhu di dalam tiap pengulangan.
     *
     * Bawaan [CalibrationProfile::bentukPindaiFoto] `kolom_suhu = true`, dan
     * itu memang bentuk lima lembar pertama: tiap sel lembar pH memuat SEPASANG
     * angka (pembacaan + °C yang dicatat bersamaan). Ketiga lembar ini tidak —
     * [tabelPembacaan] cuma mengirim satu kolom (`pembacaan`), dan suhu ruangnya
     * dicatat sekali di blok kondisi lingkungan, bukan per sel.
     *
     * Bedanya bukan kerapian penulisan: prompt & skema JSON yang dikirim ke
     * pembaca foto dibangun dari penanda ini. Dibiarkan `true`, modelnya diminta
     * membaca kolom °C yang tidak pernah ada di kertasnya — dan yang balik bukan
     * error, tapi angka yang dikarang supaya kolomnya kelihatan terisi. Alasan
     * yang sama persis dipakai `didukung` menolak lembar Autoklaf & grid
     * Enclosure; lihat docblock bawaannya.
     *
     * `standar_di_baris` tetap `false`: set point turun ke bawah dan
     * pengulangan berjajar ke kanan, sama seperti lembar pH. Yang berbeda dari
     * pH cuma dari MANA nilai standarnya datang (dibaca teknisi di tabel
     * sebelahnya, bukan konstanta master) — dan itu tidak mengubah bentuk
     * kertas yang dilihat pembaca foto.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => false, 'standar_di_baris' => false, 'didukung' => true];
    }

    /**
     * Bentuk lembar kerja lengkap + penautan master.
     *
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap($equipment);

        if ($untukAdmin) {
            $bentuk['bagian'][] = $this->bagianAdmin();
        }

        // `$equipment` diteruskan ke DUA-DUANYA, dan itu saringan organisasi —
        // bukan argumen kosmetik. Tanpa dia, baris standar lembar ini tertaut
        // ke master milik lab LAIN; lihat `masterStandarTertaut()`.
        return $this->tautkanStandarTitik(
            $this->tautkanStandar($this->isiPilihanThermohygro($bentuk, $equipment), $equipment),
            $equipment,
        );
    }

    /**
     * Bentuk lembar kerja alat ini — disusun tiap profil turunan.
     *
     * @return array<string, mixed>
     */
    abstract protected function bentukLengkap(?Equipment $equipment): array;

    /**
     * Baris STANDARD yang tercetak di lembar kerjanya.
     *
     * @return list<array{label: string, cocok: list<string>}>
     */
    abstract protected function standardTercetak(): array;

    /**
     * Satu tabel pembacaan — satu peran (standar / UUT), baris = titik ukur,
     * kolom = pengulangan.
     *
     * `peran` yang membedakannya dari `tahap`: `raw_measurements.tahap` itu enum
     * dua nilai (`sebelum_adjustment`/`sesudah_adjustment`) yang artinya sudah
     * dipakai alat ber-adjustment, sementara yang dibedakan di sini SIAPA yang
     * membaca. Dua sumbu yang beda arti; menumpangkan peran ke `tahap` akan
     * bikin sesi ini kelihatan punya data "sebelum adjustment" yang tidak pernah
     * diambil siapa pun.
     *
     * @param  list<float>  $titikSaran
     * @return array<string, mixed>
     */
    protected function tabelPembacaan(
        string $peran,
        string $judul,
        array $titikSaran,
        string $satuan,
        string $judulPengulangan,
        bool $titikBisaDiubah = true,
        ?string $grup = null,
        array $labelPengulangan = [],
    ): array {
        return [
            'tahap' => 'sesudah_adjustment',
            // `grup` WAJIB beda tiap tabel, dan ini bukan kosmetik.
            //
            // `TemplateLembarKerja::tabel()` mengunci identitas tabel ke
            // `grup ?? tahap`. Kedua tabel di lembar ini ber-`tahap` sama
            // (`sesudah_adjustment`) karena memang satu tahap — yang beda
            // SIAPA yang membaca. Tanpa `grup`, tabel UUT menimpa tabel
            // standar di peta sel, dan berkas geometri OCR-nya lahir dengan
            // setengah sel — kelihatan sah, isinya separuh. Persis kelas
            // kegagalan yang sudah tercatat untuk tiga tabel Spectrophotometer.
            'grup' => $grup ?? $peran,
            'peran' => $peran,
            'judul' => $judul,
            'satuan' => $satuan,
            // Kepala kolom KIRI. Di lembar ini isinya set point, bukan nilai
            // standar yang dipatok master — nilai standarnya justru dibaca
            // teknisi dan masuk tabel di sebelahnya.
            'judul_nilai' => 'Set Point',
            'judul_pengulangan' => $judulPengulangan,
            // Label tiap kolom pengulangan — `0" (PRT1)`, `20" (PRT2)`, dst.
            //
            // Ditaruh PER KOLOM, bukan disambung jadi satu kalimat panjang di
            // `judul_pengulangan`: kepala tabel tingginya dipatok, jadi kalimat
            // yang membungkus jadi tiga baris meluber keluar kotaknya. Dan
            // memang begitu bentuk kertasnya — detiknya tercetak di atas tiap
            // kolom, bukan di judul gabungannya.
            'pengulangan_arah' => array_map(
                static fn (int $i): array => [
                    'ke' => $i,
                    'label' => $labelPengulangan[$i - 1] ?? sprintf('X%d', $i),
                ],
                range(1, self::PENGULANGAN),
            ),
            'titik_bisa_diubah' => $titikBisaDiubah,
            'baris' => array_map(
                static fn (float $t): array => [
                    'titik_ukur' => $t,
                    'label' => rtrim(rtrim(number_format($t, 2, ',', '.'), '0'), ',').' '.$satuan,
                    'satuan' => $satuan,
                ],
                $titikSaran,
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => $satuan, 'tipe' => 'angka', 'satuan' => $satuan],
            ],
            // Daftar ANGKA, bukan daftar objek: aplikasi teknisi menyaringnya
            // `whereType<num>()`, jadi daftar objek lolos tanpa error tapi
            // menghasilkan nol kolom pembacaan — lembar kerja yang terbuka rapi
            // dan tidak bisa diisi.
            'pengulangan' => range(1, self::PENGULANGAN),
        ];
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` lab.
     *
     * Master-nya diambil lewat [masterStandarTertaut], bukan query sendiri.
     * Versi pertama berkas ini MENYALIN query-nya — dan ikut menyalin lubang
     * yang sudah ditutup untuk tiga belas profil lain: tanpa saringan
     * organisasi, baris standar di sini tertaut ke master milik lab LAIN, dan
     * yang ikut ke layar nomor sertifikat & ketertelusuran lab itu. Persis
     * kejadian yang diramalkan `StandarTidakBocorAntarLabTest`: *"profil ke-18
     * bakal menyalin salinan yang mana pun yang kebetulan dia lihat."*
     *
     * Pencocokannya juga lewat [cocokkanStandar] — nama dulu, baru serial.
     * Aturan yang sama, dan alasannya lahir sebagian dari lembar INI: dua baris
     * master lab berbagi seri `23P1005` (sensor RTD dan kalibrator Yokogawa
     * yang menempel padanya).
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    protected function tautkanStandar(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterStandarTertaut($equipment);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $this->cocokkanStandar($master, $baris['cocok']);

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'merk' => $cocok?->merk,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $this->standardTercetak(),
            );
        }

        return $bentuk;
    }

    /**
     * Bagian kop lembar kerja yang sama di ketiga alat.
     *
     * @param  list<array<string, mixed>>  $fieldSpesifik  kotak identitas khusus alat ini
     * @return list<array<string, mixed>>
     */
    protected function bagianUmumAtas(array $fieldSpesifik): array
    {
        return [
            [
                'kode' => 'identitas_alat',
                'halaman' => 1,
                'judul' => 'EQUIPMENT IDENTITY AND CUSTOMER DATA',
                'field' => [
                    $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                    $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                    $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                    $this->field('equipment.nama_alat', '1. Nama Alat', 'teks', sumber: 'otomatis'),
                    $this->field('alat_merk', '2. Merk', 'teks'),
                    $this->field('alat_model', '3. Type', 'teks'),
                    $this->field('alat_serial_number', '4. No. Seri', 'teks'),
                    ...$fieldSpesifik,
                ],
            ],
            [
                'kode' => 'pemilik',
                'halaman' => 1,
                'judul' => 'IDENTITAS CUSTOMER',
                'field' => [
                    $this->field('pemilik_nama', '1. Nama Customer', 'teks'),
                    $this->field('pemilik_alamat', '2. Alamat Customer', 'teks_panjang'),
                ],
            ],
            [
                'kode' => 'usage_check',
                'halaman' => 1,
                'judul' => 'STANDARD',
                'baris' => $this->standardTercetak(),
                'field' => [
                    $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                    $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                ],
            ],
        ];
    }

    /**
     * Kotak lokasi & metode — identik di ketiga alat, dan wajib ada di SEMUA
     * lembar (permintaan 2 pemilik proyek, dijaga `SemuaProfilLembarKerjaTest`).
     *
     * @return list<array<string, mixed>>
     */
    protected function fieldLokasi(): array
    {
        return [
            $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                ['nilai' => 'lab', 'label' => 'Inlab'],
                ['nilai' => 'onsite', 'label' => 'Insitu'],
            ]),
            // Tanpa kotak ini sertifikat Insitu mencetak nama RUANG LAB —
            // tempat yang alatnya tidak pernah ke sana.
            $this->field('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
            $this->field('room_id', 'Ruangan (Inlab)', 'pilihan', sumber: 'master_ruangan', tampilKalau: self::TAMPIL_KALAU_INLAB),
            $this->field('calibration_method_id', 'Calibration Method', 'pilihan', sumber: 'master_metode', hanyaAdmin: true),
        ];
    }

    /**
     * Kotak kondisi lingkungan — identik di ketiga alat.
     *
     * @return list<array<string, mixed>>
     */
    protected function fieldKondisiLingkungan(): array
    {
        return [
            $this->field('suhu_awal', 'Suhu Ruangan — Awal', 'angka', satuan: '°C'),
            $this->field('suhu_akhir', 'Suhu Ruangan — Akhir', 'angka', satuan: '°C'),
            $this->field('kelembaban_awal', 'Kelembaban — Awal', 'angka', satuan: '%RH'),
            $this->field('kelembaban_akhir', 'Kelembaban — Akhir', 'angka', satuan: '%RH'),
            $this->field('thermohygro_standard_id', 'Environmental Meter Used', 'pilihan', sumber: 'master_thermohygro'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 1,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Calculated by', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Signed by', 'teks', sumber: 'otomatis'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bagianAdmin(): array
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
     * Ambil nilai atribut sesi, apa pun nama kolomnya menyimpan.
     *
     * `atribut_tambahan` itu tempat kolom yang cuma dipunyai sebagian alat;
     * membacanya lewat satu pintu bikin profil tidak perlu tahu mana yang
     * kolom nyata dan mana yang JSON.
     */
    protected function atributSesi(CalibrationSession $sesi, string $kunci): mixed
    {
        $langsung = $sesi->getAttribute($kunci);

        if ($langsung !== null && $langsung !== '') {
            return $langsung;
        }

        $tambahan = $sesi->getAttribute('atribut_tambahan');

        return is_array($tambahan) ? ($tambahan[$kunci] ?? null) : null;
    }
}
