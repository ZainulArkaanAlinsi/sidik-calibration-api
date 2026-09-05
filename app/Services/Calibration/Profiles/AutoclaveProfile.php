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
    /**
     * Nomor Instruksi Kerja alat ini — `DATABASE` baris 31 (Autoclave).
     *
     * Dicetak di kolom `Calibration Method` sertifikat lewat
     * [CalibrationProfile::kodeMetode]. Dinyatakan di sini, BUKAN dicocokkan
     * dari nama alat: cadangan pencocokan nama mencari "jenis pengukuran" di
     * dalam nama alat, dan meleset begitu pelanggan menamai alatnya di luar
     * kosakata master — yang terbit kolom kosong atau nomor tanpa revisi, di
     * dokumen terakreditasi.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0531_Rev.4';

    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0539_Rev.4';

    /** Titik waktu bawaan (2/4/6/8/10 jam) — INPUT DATA D34:K34. */
    public const JUMLAH_TITIK_WAKTU = 5;

    /** Jumlah disk sensor suhu yang dibawa (Tecnosoft SterilDisk). */
    public const JUMLAH_DISK = 3;

    /** Pengulangan pembacaan tekanan bawaan — INPUT DATA D45:K45. */
    public const JUMLAH_PEMBACAAN_TEKANAN = 5;

    public const SATUAN_SUHU = '°C';

    /**
     * Rentang suhu yang masih punya koreksi kalibrator (`config/autoclave.php`
     * — tabel ketiga disk berhenti di 21 °C dan 140 °C). Dipakai OCR buat nolak
     * bacaan yang mustahil; di luar rentang ini angkanya nggak bisa dikoreksi,
     * jadi lebih baik teknisi yang ngetik.
     */
    public const PITA_SUHU_KALIBRATOR = ['min' => 21.0, 'maks' => 140.0];

    /** Suhu ruang kerja — sama dengan ambang bawaan `config/ocr.suhu`. */
    public const PITA_SUHU_RUANG = ['min' => 5.0, 'maks' => 45.0];

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

    /** Kode lembar yang tercetak di pojok kanan atas formulir. */
    public const KODE_LEMBAR = 'LK-285-IDN';

    /**
     * Baris "Standard Used:" PERSIS kayak yang tercetak di kertas — DUA baris
     * centang, bukan empat. Kertasnya nggabung ketiga disk suhu jadi satu baris
     * ("Disk 1,2,3") karena teknisi emang naruh ketiganya sekaligus atau nggak
     * sama sekali.
     *
     * `anggota` tetap nyimpen ketiga disk satu-satu supaya ketertelusuran
     * per-disk nggak hilang: satu kotak centang di layar, tiga standar tertaut
     * di belakangnya.
     *
     * @var list<array{label: string, cocok: list<string>, anggota: list<array{label: string, cocok: list<string>}>}>
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Temperature Calibrator -Technosoft (Disk 1,2,3)',
            'cocok' => ['Temperature Calibrator 1', '1011001961'],
            'anggota' => [
                ['label' => 'Temperature Calibrator 1 (Tecnosoft/SterilDisk)', 'cocok' => ['Temperature Calibrator 1', '1011001961']],
                ['label' => 'Temperature Calibrator 2 (Tecnosoft/TS01SD)', 'cocok' => ['Temperature Calibrator 2', '1011004038']],
                ['label' => 'Temperature Calibrator 3 (Tecnosoft/TS01SD)', 'cocok' => ['Temperature Calibrator 3', '1011004063']],
            ],
        ],
        [
            'label' => 'Pressure Disk Logger-Technosoft',
            'cocok' => ['Pressure Disk', 'Pressure Disk Logger', '3501009550'],
            'anggota' => [],
        ],
    ];

    /**
     * Unit thermohygro. Kertas Autoklaf cuma MENCETAK tiga kotak centang —
     * TH-2, TH-6, TH-7 — dan cuma itu yang ditandai `tercetak`; layar gambar
     * ketiganya sebagai kotak centang biar sama persis kayak kertasnya.
     *
     * Sisanya TETAP dikirim, cuma nggak tercetak. Ini bukan kelonggaran asal:
     * daftar thermohygro pernah dipersempit "biar teknisi nggak salah pilih",
     * dan sesi master yang kepakai TH-3 jadi nggak punya pilihan yang benar
     * sama sekali — teknisi kepaksa milih unit lain, dan Env. Condition-nya
     * meleset bukan karena salah hitung, tapi karena tabel koreksi unitnya beda.
     * Kertas yang nggak nyetak satu unit bukan bukti unit itu nggak pernah
     * dibawa ke lapangan.
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-2', 'grup' => 'Insitu', 'tercetak' => true],
        ['label' => 'TH-6', 'grup' => 'Insitu', 'tercetak' => true],
        ['label' => 'TH-7', 'grup' => 'Inlab', 'tercetak' => true],
        ['label' => 'TH-1', 'grup' => 'Inlab', 'tercetak' => false],
        ['label' => 'TH-3', 'grup' => 'Inlab', 'tercetak' => false],
        ['label' => 'TH-4', 'grup' => 'Inlab', 'tercetak' => false],
        ['label' => 'TH-5', 'grup' => 'Inlab', 'tercetak' => false],
    ];

    public function kode(): string
    {
        return 'autoclave';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Autoklaf';
    }

    /**
     * Lampiran akreditasi LK-285-IDN no. 48 nulis "Autoklaf" (Indonesia, lihat
     * [namaAlatKemampuan]); lembar kerjanya SIDIK-FM-CAL-0539 & DATABASE nulis
     * "Autoclave" (Inggris). Dua-duanya beredar, jadi dua-duanya didaftarin.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return ['Autoclave'];
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
        array $konteksTitik = [],
    ): ?array {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap();
        $bentuk = $this->tautkanStandar($bentuk, $equipment);
        $bentuk = $this->isiPilihanThermohygro($bentuk, $equipment);

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
     * Susunan lembar dibikin PERSIS ngikut kertas `SIDIK-FM-CAL-0539_Rev.4`:
     * dua panel besar (General Information & Data Result), dan di dalam Data
     * Result SATU tabel — bukan dua — karena di kertas suhu & tekanan dicatat
     * berdampingan pada kolom waktu yang sama.
     *
     * Susunan sebelumnya dipinjam dari lembar pH: bagian bernomor ("1. Name,
     * 2. Manufacturer"), OWNER dipisah dari General Information, suhu &
     * tekanan jadi dua section, dan baris `Indikator Pressure` + `Tekanan atm
     * awal` nggak ada sama sekali. Rapi, tapi bukan bentuk kertas yang dipegang
     * teknisi — dan lembar yang bentuknya beda dari kertasnya bikin baris
     * kebaca geser waktu disalin. Yang geser di sini bukan tata letak: baris
     * ke-5 di kertas itu Indikator Pressure, di layar lama baris ke-5 itu Suhu
     * Ruang. Salah salin satu baris = angka sertifikat yang salah.
     *
     * `grup` = panel biru di kertas, dipakai layar buat nggambar bannernya.
     * `kolom` = kertasnya dua kolom sejajar (kiri kondisi, kanan identitas alat).
     * `di_luar_kertas` = kolom yang MEMANG nggak ada di formulir tapi dibutuhin
     * olah data; ditandai supaya layar bisa misahin dari blok yang tercetak,
     * bukan diselipin di tengah tabel kertas.
     *
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_lembar' => self::KODE_LEMBAR,
            'penerbit' => 'PT. SIDIK',
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
            // Urutan bagian ngikut POLA BERSAMA semua lembar, bukan urutan
            // kertasnya dibaca dari atas.
            //
            // Di kertas Autoclave, General Information tercetak paling atas dan
            // blok Standard di bawah tabel hasil. Dulu urutan di sini ngikut itu
            // — dan jadinya lembar Autoclave satu-satunya dari tujuh belas yang
            // `identitas_alat`-nya BUKAN bagian pertama, sekaligus satu-satunya
            // yang blok standarnya nongol SESUDAH teknisi selesai ngisi tabel.
            // Padahal standar itu yang dipilih duluan sebelum ngukur.
            //
            // Pola bersamanya: identitas alat > pemilik & lokasi > standar yang
            // dipakai > pengukuran > penutup.
            //
            // Kertasnya nggak berubah, dan yang dicetak juga nggak: jalur cetak
            // lembar pindai punya definisinya sendiri dan nggak baca urutan
            // array ini sama sekali. Yang digeser cuma urutan baca di LAYAR.
            //
            // Dijaga `SemuaProfilLembarKerjaTest::test_urutan_bagian_seragam_di_semua_lembar()`.
            'bagian' => [
                $this->bagianIdentitasAlat(),
                $this->bagianPenerimaan(),
                $this->bagianKondisiLokasi(),
                $this->bagianStandarDipakai(),
                $this->bagianHasilPengukuran(),
                $this->bagianPenutup(),
            ],
        ];
    }

    /**
     * Blok teratas General Information — empat baris polos di kertas:
     * Receive Date / Customer / Addresss / Calibration Date.
     *
     * "Addresss" emang tiga huruf s. Ditulis apa adanya karena label di layar
     * harus bisa diadu langsung sama kertasnya waktu lab ngecek; label yang
     * "dibetulin" diam-diam bikin orang ragu ini lembar yang sama atau bukan.
     *
     * @return array<string, mixed>
     */
    private function bagianPenerimaan(): array
    {
        return [
            'kode' => 'informasi_umum',
            'grup' => 'General Information',
            'halaman' => 1,
            'judul' => 'General Information',
            'field' => [
                $this->field('tanggal_terima', 'Receive Date', 'tanggal'),
                $this->field('pemilik_nama', 'Customer', 'teks'),
                $this->field('pemilik_alamat', 'Addresss', 'teks_panjang'),
                $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
            ],
        ];
    }

    /**
     * Kolom KIRI blok kedua General Information: lokasi, kondisi lingkungan,
     * dan kotak centang Thermohygro.
     *
     * @return array<string, mixed>
     */
    private function bagianKondisiLokasi(): array
    {
        return [
            'kode' => 'kondisi_lokasi',
            'grup' => 'General Information',
            'kolom' => 'kiri',
            'halaman' => 1,
            'judul' => 'Location & Environmental Condition',
            'field' => [
                $this->field('lokasi', 'Location of Calibration', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                // Dua-duanya nggak tercetak di kertas — di situ cuma ada satu
                // garis "Location of Calibration". Ikut ke layar, nggak ikut ke
                // lembar cetak.
                //
                // `lokasi_nama` kolom teks bebas buat sesi Insitu: tanpa dia
                // sertifikat Insitu kecetak nama RUANG LAB — tempat yang
                // alatnya nggak pernah ke sana.
                $this->field(
                    'lokasi_nama',
                    'Nama Tempat (Insitu)',
                    'teks',
                    ekstra: ['di_kertas' => false],
                    tampilKalau: self::TAMPIL_KALAU_INSITU,
                ),
                $this->field(
                    'room_id',
                    'Ruangan (Inlab)',
                    'pilihan',
                    sumber: 'master_ruangan',
                    ekstra: ['di_kertas' => false],
                    tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                $this->field('suhu_awal', 'T awal', 'angka', satuan: self::SATUAN_SUHU),
                $this->field('suhu_akhir', 'T akhir', 'angka', satuan: self::SATUAN_SUHU),
                $this->field('kelembaban_awal', 'RH awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'RH akhir', 'angka', satuan: '%RH'),
                $this->field(
                    'thermohygro_standard_id',
                    'Thermohygro used',
                    'pilihan',
                    sumber: 'master_thermohygro',
                    ekstra: ['tampilan' => 'centang'],
                ),
            ],
        ];
    }

    /**
     * Kolom KANAN blok kedua General Information: identitas alat pelanggan.
     *
     * Empat baris terakhir di kertas punya kurung satuan kosong `( )` di
     * ujungnya — satuannya DITULIS teknisi, nggak dipatok formulir. Range
     * tekanan autoklaf datang dalam MPa, bar, atau psi tergantung mereknya,
     * jadi mematok satuan di sini artinya maksa teknisi ngonversi di kepala.
     *
     * @return array<string, mixed>
     */
    private function bagianIdentitasAlat(): array
    {
        return [
            'kode' => 'identitas_alat',
            'grup' => 'General Information',
            'kolom' => 'kanan',
            'halaman' => 1,
            'judul' => 'Equipment Identity',
            'field' => [
                $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Equipment Name', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Manufacturer', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'SN', 'teks'),
                $this->field('range_suhu', 'Range Temp.', 'angka', satuan: self::SATUAN_SUHU, ekstra: ['kurung_satuan' => true]),
                $this->field('resolusi_suhu', 'Resolution Temp.', 'angka', satuan: self::SATUAN_SUHU, ekstra: ['kurung_satuan' => true]),
                $this->field('range_tekanan', 'Range Pressure', 'angka', ekstra: ['kurung_satuan' => true, 'satuan_dari' => 'satuan_tekanan']),
                $this->field('resolusi_tekanan', 'Resolution Pressure', 'angka', ekstra: ['kurung_satuan' => true, 'satuan_dari' => 'satuan_tekanan']),
                // Di kertas satuannya ditulis di dalam kurung `( )` milik
                // Range/Resolution Pressure, bukan sebagai barisnya sendiri.
                $this->field('satuan_tekanan', 'Pressure Unit', 'pilihan', pilihan: self::SATUAN_TEKANAN, ekstra: ['di_kertas' => false]),
            ],
        ];
    }

    /**
     * Panel "Data Result" — SATU tabel, tujuh baris, lima kolom waktu, persis
     * kayak kertasnya:
     *
     *   Temp. Disk 1/2/3 → Indikator Suhu → Indikator Pressure →
     *   Tekanan atm awal → Suhu Ruang
     *
     * Dua baris tengah (`Indikator Pressure`, `Tekanan atm awal`) sebelumnya
     * nggak ada di lembar digital sama sekali; itu bacaan manometer autoklaf
     * per titik waktu, dan tanpa kolomnya teknisi nggak punya tempat nulis
     * angka yang udah dia catat di kertas.
     *
     * Baris Time di kertas isinya JAM beneran (`__:__:__:__`, master INPUT_DATA
     * nulis 02:00:00 … 10:00:00), bukan nomor urut 1–5. Nomor urut dipertahanin
     * cuma sebagai indeks kolom di payload.
     *
     * `tabel_tekanan` ditaruh di sini juga tapi ditandai `di_luar_kertas`:
     * angkanya diunduh dari Pressure Disk Logger, nggak pernah ditulis tangan
     * di lapangan — tapi olah data tekanan mati tanpa dia.
     *
     * @return array<string, mixed>
     */
    private function bagianHasilPengukuran(): array
    {
        $baris = [];
        for ($d = 1; $d <= self::JUMLAH_DISK; $d++) {
            $baris[] = [
                'kode' => "disk_{$d}",
                'label' => "Temp. Disk {$d}",
                'tipe' => 'disk',
                'satuan' => self::SATUAN_SUHU,
                'kode_data' => 'suhu.disk.'.($d - 1),
            ];
        }
        $baris[] = [
            'kode' => 'indikator_suhu',
            'label' => 'Indikator Suhu',
            'tipe' => 'indikator_suhu',
            'satuan' => self::SATUAN_SUHU,
            'kode_data' => 'suhu.indikator',
        ];
        $baris[] = [
            'kode' => 'indikator_pressure',
            'label' => 'Indikator Pressure',
            'tipe' => 'indikator_tekanan',
            'satuan' => null,
            'satuan_dari' => 'satuan_tekanan',
            'kurung_satuan' => true,
            'kode_data' => 'tekanan.indikator_pressure',
        ];
        $baris[] = [
            'kode' => 'tekanan_atm_awal',
            'label' => 'Tekanan atm awal',
            'tipe' => 'tekanan_atm',
            'satuan' => null,
            'satuan_dari' => 'satuan_tekanan',
            'kurung_satuan' => true,
            'kode_data' => 'tekanan.tekanan_atm_awal',
        ];
        $baris[] = [
            'kode' => 'suhu_ruang',
            'label' => 'Suhu Ruang',
            'tipe' => 'suhu_ruang',
            'satuan' => self::SATUAN_SUHU,
            'kode_data' => 'suhu.suhu_ruang',
        ];

        return [
            'kode' => 'hasil_pengukuran',
            'grup' => 'Data Result',
            'halaman' => 1,
            'judul' => 'Calibration Result for Temperature & Pressure',
            'field' => [
                $this->field('set_point', 'Set Point', 'angka', satuan: self::SATUAN_SUHU),
            ],
            // Bentuk yang SAMA, dituturkan dua kali buat dua pembaca yang beda:
            // `matriks` buat layar teknisi, `tabel` buat pipeline OCR
            // (`TemplateLembarKerja`). Pipeline itu cuma ngerti bentuk
            // "baris × pengulangan × kolom-field", dan sebelum ini Autoklaf
            // nggak punya bentuk itu sama sekali — hasilnya `jumlah_sel` 0,
            // yang artinya rangka geometri kosong, lembar cetak tanpa kotak,
            // dan kamera yang nggak akan pernah bisa dinyalain seberapa pun
            // telitinya kertasnya diukur.
            //
            // Kolomnya SATU (`pembacaan`) karena tiap sel Autoklaf isinya satu
            // angka; yang berjajar ke kanan itu titik waktu, jadi titik waktu
            // yang jadi `pengulangan`.
            'tabel' => [$this->tabelPindai()],
            'matriks' => [
                'judul_kolom' => 'Pengukuran Berulang UUT Selama Proses Sterilisasi',
                'titik_waktu' => range(1, self::JUMLAH_TITIK_WAKTU),
                'baris_waktu' => [
                    'kode' => 'waktu',
                    'label' => 'Time',
                    'tipe' => 'jam',
                    'format' => 'HH:mm:ss',
                    'kode_data' => 'waktu',
                ],
                'baris' => $baris,
            ],
            'tabel_tekanan' => [
                'label' => 'Pressure Disk Logger — hasil unduh (Bar)',
                'di_luar_kertas' => true,
                'catatan' => 'Nggak ada di kertas: angkanya diunduh dari Pressure Disk Logger, '
                    .'bukan ditulis teknisi. Tanpa baris ini olah data tekanan nggak jalan.',
                'kolom' => ['kode' => 'tekanan.pembacaan_standar', 'label' => 'Standar Reading', 'satuan' => 'Bar'],
                'pengulangan' => range(1, self::JUMLAH_PEMBACAAN_TEKANAN),
            ],
            'field_di_luar_kertas' => [
                $this->field(
                    'tekanan.uut_setting',
                    'UUT Reading (dipakai hitung)',
                    'angka',
                    ekstra: [
                        'di_luar_kertas' => true,
                        'satuan_dari' => 'satuan_tekanan',
                        'terisi_dari' => 'tekanan.indikator_pressure',
                        'catatan' => 'Master INPUT_DATA nyimpen SATU UUT Reading, kertas nyediain lima kolom. '
                            .'Kalau kelima kolom Indikator Pressure isinya sama, angka itu yang kepakai; '
                            .'kalau beda, harus dipilih di sini — sistem nggak nebak.',
                    ],
                ),
                $this->field('display_tekanan', 'Pressure Display Type', 'pilihan', pilihan: self::DISPLAY_TEKANAN, ekstra: ['di_luar_kertas' => true]),
            ],
        ];
    }

    /**
     * Tabel Data Result dalam bentuk yang dimengerti pipeline OCR.
     *
     * Barisnya URUT KERTAS, termasuk baris `Time` di paling atas. Time ikut
     * digambar karena lembar cetak `ocr:cetak-lembar` menggambar kotaknya dari
     * daftar sel ini — baris yang nggak ada di sini nggak ada kotaknya di
     * kertas, dan teknisi kehilangan tempat nulis jam. Bacanya beda: `tipe`
     * `jam`, bukan angka.
     *
     * `pita` diisi per baris, bukan diturunkan dari nominal. Bawaan
     * `TemplateLembarKerja` bikin rentang "nominal ±10 %", dan lembar ini nggak
     * punya nominal tercetak sama sekali — kotak Set Point-nya kosong waktu
     * lembar dicetak. Rentang suhu diambil dari jangkauan tabel kalibrator di
     * `config/autoclave.php` (21–140 °C): di luar itu koreksinya nggak ada, jadi
     * angkanya memang nggak bisa dipakai walaupun kebaca benar.
     *
     * Dua baris tekanan SENGAJA tanpa rentang. Satuannya beda-beda per alat
     * (MPa, bar, psi), jadi rentang apa pun yang dipatok di sini bakal nolak
     * angka yang benar begitu ketemu autoklaf bersatuan lain.
     *
     * @return array<string, mixed>
     */
    private function tabelPindai(): array
    {
        $baris = [
            [
                'titik_ukur' => 0.0,
                'label' => 'Time',
                'tipe' => 'jam',
            ],
        ];

        for ($d = 1; $d <= self::JUMLAH_DISK; $d++) {
            $baris[] = [
                'titik_ukur' => 0.0,
                'label' => "Temp. Disk {$d}",
                'satuan' => self::SATUAN_SUHU,
                'resolusi' => 0.01,
                'desimal' => 2,
                'pita' => self::PITA_SUHU_KALIBRATOR,
            ];
        }

        $baris[] = [
            'titik_ukur' => 0.0,
            'label' => 'Indikator Suhu',
            'satuan' => self::SATUAN_SUHU,
            // Indikator enclosure autoklaf resolusinya beda-beda per merek
            // (master `INPUT_DATA` nulis 121 bulat). Dibiarkan terbuka biar
            // yang beresolusi 0,1 nggak ditolak sebagai "bukan kelipatan".
            'pita' => self::PITA_SUHU_KALIBRATOR,
        ];

        foreach (['Indikator Pressure', 'Tekanan atm awal'] as $label) {
            $baris[] = [
                'titik_ukur' => 0.0,
                'label' => $label,
                // Satuannya dikosongin (beda-beda per autoklaf), tapi jumlah
                // desimalnya TETAP dikasih tahu — `resolusi_alat.tekanan` di
                // `config/autoclave.php` itu 0,001. Tanpa petunjuk ini `0.112`
                // ditolak sebagai `desimal_ambigu`: tiga digit di belakang
                // pemisah bisa berarti pemisah ribuan, dan sel tanpa rentang
                // nggak punya bukti buat menyingkirkan salah satunya.
                //
                // Cuma `desimal`, BUKAN `resolusi`: resolusi bikin rentang
                // 0,001 × 20 = ±0,02 di sekitar nol, dan itu nolak 0,112 —
                // bacaan yang benar di autoklaf bersatuan MPa.
                'desimal' => 3,
            ];
        }

        $baris[] = [
            'titik_ukur' => 0.0,
            'label' => 'Suhu Ruang',
            'satuan' => self::SATUAN_SUHU,
            'resolusi' => 0.1,
            'desimal' => 1,
            'pita' => self::PITA_SUHU_RUANG,
        ];

        return [
            'tahap' => 'sesudah_adjustment',
            'judul' => 'Calibration Result for Temperature & Pressure',
            'sumbu_pengulangan' => 'kolom',
            'baris' => $baris,
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => 'Nilai', 'satuan' => null],
            ],
            'pengulangan' => range(1, self::JUMLAH_TITIK_WAKTU),
        ];
    }

    /**
     * Blok "Standard Used:" — dua kotak centang, sama kayak kertas.
     *
     * @return array<string, mixed>
     */
    private function bagianStandarDipakai(): array
    {
        return [
            'kode' => 'usage_check',
            'grup' => 'Data Result',
            'halaman' => 1,
            'judul' => 'Standard Used:',
            'baris' => self::STANDARD_TERCETAK,
            'field' => [
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
            ],
        ];
    }

    /**
     * Kaki lembar: kotak Catatan, lalu Calibrated by / Corrected by yang
     * masing-masing punya Name & Sign.
     *
     * @return array<string, mixed>
     */
    private function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'grup' => 'Data Result',
            'halaman' => 1,
            'judul' => 'Catatan:',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Calibrated by — Name', 'teks', sumber: 'otomatis'),
                $this->field('teknisi.tanda_tangan', 'Calibrated by — Sign', 'tanda_tangan', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Corrected by — Name', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.tanda_tangan', 'Corrected by — Sign', 'tanda_tangan', sumber: 'otomatis'),
            ],
        ];
    }

    /**
     * Kolom administratif — semuanya DI LUAR kertas SIDIK-FM-CAL-0539_Rev.4.
     * Ditaruh di bagian sendiri, bukan diselipin ke blok yang tercetak, biar
     * lembar admin tetap bisa diadu baris-per-baris sama kertasnya.
     *
     * @return array<string, mixed>
     */
    private function bagianAdmin(): array
    {
        return [
            'kode' => 'administratif',
            'grup' => 'Di luar formulir — diisi admin',
            'halaman' => 1,
            'judul' => 'Data Administratif (Admin)',
            'di_luar_kertas' => true,
            'field' => [
                $this->field('nomor_order', 'Order Number', 'teks', hanyaAdmin: true),
                $this->field('certificate.nomor', 'Certificate Number', 'teks', sumber: 'otomatis', hanyaAdmin: true),
                $this->field('calibration_method_id', 'Calibration Method', 'pilihan', sumber: 'master_metode'),
                $this->field('suhu.resolusi_alat', 'Resolusi Alat (suhu)', 'angka', satuan: self::SATUAN_SUHU, hanyaAdmin: true),
                $this->field('tekanan.resolusi_alat', 'Resolusi Alat (tekanan)', 'angka', hanyaAdmin: true),
                $this->field('standar_dicek.*.keterangan', 'Keterangan Standar', 'teks', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` (nama/serial).
     *
     * `anggota` ikut ditautkan satu-satu: kertas cuma nyetak satu kotak centang
     * buat ketiga disk suhu, tapi sertifikat tetap butuh serial & nomor
     * sertifikat masing-masing disk.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterStandarTertaut($equipment);

        $tautkan = function (array $baris) use ($master): array {
            $cocok = $this->cocokkanStandar($master, $baris['cocok']);

            return [
                'label' => $baris['label'],
                'standard_id' => $cocok?->id,
                'serial_number' => $cocok?->serial_number,
                'no_sertifikat' => $cocok?->no_sertifikat,
                'tertelusur_ke' => $cocok?->tertelusur_ke,
                'terdaftar' => $cocok !== null,
            ];
        };

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                fn (array $baris): array => [
                    ...$tautkan($baris),
                    'anggota' => array_map($tautkan, $baris['anggota'] ?? []),
                ],
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }
}
