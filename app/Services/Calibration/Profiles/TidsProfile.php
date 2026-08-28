<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\TabelStandarTids;
use App\Services\Calibration\TidsCalculator;
use Carbon\Carbon;

/**
 * Profil TIDS — **Temperatur Indikator dengan Sensor** (alat ke-17). Metode
 * `SIDIK-IK-CAL-0503_Rev.6`, lembar kerja `SIDIK-FM-CAL-0506 Rev.4`
 * (`LK-285-IDN`, "Calibration Work Sheet - Temperature Indikator With Sensors").
 *
 * ## Workbook master-nya SUDAH ADA (28 Agt 2026) — K2 ditutup
 *
 * File ini pernah berisi blok panjang berjudul "TAHAP 1 SAJA: bentuk lembar
 * kerja + jalur simpan, BUKAN budget", karena TIDS satu-satunya profil yang
 * lahir tanpa workbook olah data lab. Blok itu dicabut: pemilik proyek
 * mengirim **dua workbook TIDS ber-password** yang keduanya berkop
 * `KALIBRASI TEMPERATURE INDIKATOR DENGAN SENSOR (TIDS)` & bernomor lingkup
 * `LK-285-IDN` —
 *
 *   `… TIDS - Recorder Graptech.xlsm`   sesi contoh `071-CAL-325`
 *   `… TIDS - Yokogawa K,N.xlsm`        sesi contoh Thermometer Bola Basah
 *
 * Keempat sheet yang selama ini disebut hilang (`PERHITUNGAN U95%`,
 * `Variasi axial Dryblok A`, `… B`, `stdev drywell`) ada lengkap di dua-duanya,
 * dan tabel CMC-nya berbunyi **0,86 / 1,4 / 3,1 °C** — baris no. 2 TIDS di
 * lampiran akreditasi, bukan no. 5 Thermocouple (0,84 / 1,5 / 3,3) yang 27 Agt
 * 2026 nyaris dipakai sebagai penggantinya. Kali ini label DAN angkanya cocok.
 *
 * Mesin hitungnya di [TidsCalculator], tabelnya di [TabelStandarTids].
 *
 * ## DUA workbook = DUA keluarga standar, bukan dua alat baru
 *
 * Yang membedakan kedua workbook bukan alat yang dikalibrasi melainkan standar
 * yang dipakai mengalibrasinya: Temperature Recorder Graptech GL840 (koreksi
 * per KANAL) versus kalibrator blok Constant 40T / Yokogawa CA 150 (koreksi per
 * tipe sensor). Pola yang sama sudah dipakai TITS (dua workbook: fungsi Measure
 * & Source) dan Enclosure (dua workbook: Recorder & Constant/Yokogawa) — satu
 * profil, beberapa keluarga standar. Memecahnya jadi dua profil mustahil:
 * `CalibrationProfileRegistry` melempar `LogicException` begitu dua profil
 * mengaku ejaan nama alat yang sama, dan lampiran akreditasi cuma punya satu
 * baris untuk alat ini.
 *
 * ## Yang dibalik workbook: LIMA ULANGAN, bukan lima UUT
 *
 * Ini koreksi tafsir, dan datangnya dari master. Kepala kolom PDF
 * `SIDIK-FM-CAL-0506 Rev.4` berbunyi `0" (UUT1)`…`90" (UUT5)` dan dulu dibaca
 * sebagai LIMA ALAT dalam satu lembar — sampai-sampai keputusan "1 sesi 5 UUT
 * vs 5 sesi terpisah" (K1) ditahan menunggu jawaban lab. Dua workbook menulis
 * kolom yang sama sebagai `0" (PRT1)`…`80" (PRT5)` lalu memakainya sebagai
 * `AVERAGE` + `STDEV` **per baris**:
 *
 *   satu baris  = satu set point
 *   lima kolom  = lima ULANGAN, standar & UUT dibaca bergantian tiap 10 detik
 *
 * Jadi K1 gugur — tidak pernah ada lima UUT — dan bentuk lembarnya ternyata
 * PASANGAN deret, sekeluarga dengan Thermocouple, Termometer Gelas &
 * Thermohygrometer ([ProfilSuhuPasangan]). Yang berubah di sini karena itu
 * bukan bentuk tabelnya (tetap 5 kolom × N baris, label cetaknya tetap `UUT1`
 * karena itu yang tertulis di kertas yang dipegang teknisi) melainkan ARTINYA:
 * [butuhPasanganStandarUut] menyala, dan tabel Pembacaan Standard akhirnya
 * punya tempat simpan — sebelumnya `simpan_ke` `null`, artinya 35 kotak yang
 * diisi teknisi tidak pernah sampai ke server.
 *
 * ## Uji titik es 0 °C akhirnya punya arti
 *
 * Pertanyaan lama "dua angka titik es itu buat apa" dijawab kedua master dengan
 * rumus yang sama — `O35 = 0.5 * ABS(awal − akhir)` — dan hasilnya jadi
 * komponen budget **Drift UUT**, distribusi persegi. Bukan syarat lolos, bukan
 * sekadar catatan.
 *
 * ## Empat penyimpangan master yang DITIRU
 *
 * Keempatnya menggeser U95 dan tidak satu pun memunculkan error; uraiannya di
 * docblock [TidsCalculator]. Yang penting di file ini: keempatnya dinaikkan ke
 * layar lewat [peringatanSesi], bukan cuma ditinggal di jejak audit — tiga di
 * antaranya menggeser U95 ke arah lebih KECIL.
 *
 * ## Yang TIDAK divonis
 *
 * Lembar kerjanya tidak punya satu pun kolom batas keberterimaan — tidak ada
 * "Tolerance", tidak ada "Result", tidak ada kolom PASS/FAIL. Sama seperti
 * TITS, Autoklaf, DO Meter, Gas Detector, dan Enclosure: [punyaToleransi]
 * `false`, `keputusan` sesi null. Ini BUKAN konsekuensi dari budget yang belum
 * ada — kalaupun workbook-nya datang besok, kolomnya tetap tidak ada di
 * kertasnya.
 *
 * @see TidsCalculator — mesin hitungnya, berikut empat penyimpangan master
 * @see TabelStandarTids — tabel koreksi/U95/drift kedua keluarga standar
 * @see docs/pertanyaan-lab-tids-workbook.md — yang masih perlu dijawab lab
 * @see docs/permintaan-user-7.md — K1 & K2, dua-duanya ditutup workbook ini
 * @see TitsProfile — saudaranya, "tanpa sensor"
 */
class TidsProfile extends CalibrationProfile
{
    /** Nomor formulir lembar kerja — tercetak di footer PDF-nya. */
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0506_Rev.4';

    /**
     * Metode kalibrasi, dari baris CMC lampiran akreditasi
     * (`database/data/kemampuan-kalibrasi.json`, kelompok "Suhu dan
     * Kelembapan" no. 2: `SIDIK-IK-CAL-0503_Rev.6; SNSU PK.S-02:2021`).
     *
     * Yang diambil cuma nomor IK-nya; `SNSU PK.S-02:2021` itu acuan teknis di
     * belakangnya, bukan nomor dokumen terkendali lab.
     */
    public const KODE_METODE = 'SIDIK-IK-CAL-0503_Rev.6';

    /** Satuan tunggal seluruh alat ini — seluruh kolom lembarnya `oC`. */
    public const SATUAN = '°C';

    /** Nomor lingkup akreditasi yang tercetak di kop lembar kerjanya. */
    public const NOMOR_LINGKUP = 'LK-285-IDN';

    /**
     * Lima ULANGAN per deret — bukan lima alat.
     *
     * Konstanta ini dulu bernama `JUMLAH_UUT` dan docblock-nya berbunyi
     * "bukan lima pengulangan — lima ALAT". Dua workbook master membantahnya:
     * kolomnya dinamai `PRT1`…`PRT5` dan dipakai `AVERAGE(D:I)` + `STDEV(D:I)`
     * per BARIS, jadi lima kolom itu memang lima pembacaan berulang atas satu
     * alat. Lihat blok "LIMA ULANGAN, bukan lima UUT" di docblock kelas.
     */
    public const PENGULANGAN = 5;

    /**
     * Baris set point yang TERCETAK di kertasnya — tujuh, di kedua tabel.
     *
     * Dihitung dari garis tabel PDF-nya (tabel Pembacaan Standard y 319…432,
     * tabel Pembacaan Alat y 511…624; tujuh baris ~15,4 pt di masing-masing),
     * bukan dari kebiasaan alat lain.
     */
    public const BARIS_SETPOINT_KERTAS = 7;

    /**
     * Detik pembacaan STANDARD, satu per UUT — kepala kolom tabel pertama:
     * `0" (UUT1)`, `20" (UUT2)`, `40" (UUT3)`, `60" (UUT4)`, `80" (UUT5)`.
     *
     * @var list<int>
     */
    public const DETIK_STANDARD = [0, 20, 40, 60, 80];

    /**
     * Detik pembacaan ALAT YANG DIKALIBRASI — kepala kolom tabel kedua:
     * `10" (UUT1)`, `30" (UUT2)`, `50" (UUT3)`, `70" (UUT4)`, `90" (UUT5)`.
     *
     * Selalu 10 detik SESUDAH pembacaan standar pasangannya. Selisih itu yang
     * bikin tiap UUT punya acuannya sendiri, bukan berbagi satu angka.
     *
     * @var list<int>
     */
    public const DETIK_UUT = [10, 30, 50, 70, 90];

    /**
     * Batas rentang CMC TIDS di lampiran akreditasi: −20 … 600 °C, tiga pita
     * (0,86 / 1,4 / 3,1 °C).
     *
     * Dipakai TEKS PERINGATAN saja — angka CMC yang (nanti) masuk hitungan
     * dibaca dari master `calibration_capabilities`, supaya lab bisa
     * memperbaruinya tanpa rilis kode. Sama pola dengan
     * `TitsProfile::CMC_TIPE_SENSOR`.
     */
    public const CMC_MIN = -20.0;

    /** Lihat [CMC_MIN]. */
    public const CMC_MAKS = 600.0;

    /**
     * Dryblock yang dicentang teknisi — dua kotak di kop lembar kerjanya.
     *
     * Nilainya sengaja slug huruf kecil, bukan label cetaknya: label boleh
     * berubah ejaan waktu formulirnya direvisi, kunci penyimpanan tidak boleh.
     *
     * @var list<array{nilai: string, label: string}>
     */
    public const DRYBLOCK = [
        ['nilai' => 'isotech', 'label' => 'A (Isotech)'],
        ['nilai' => 'techne', 'label' => 'B (Techne)'],
    ];

    /**
     * Baris kalibrator di blok `Standard used:` — dua kotak centang.
     *
     * Kunci pencocokannya (nama & serial) sengaja SAMA PERSIS dengan
     * `TitsProfile::STANDARD_TERCETAK`: dua alat itu unit fisik yang sama,
     * sudah ter-seed lewat `TitsSeeder`, dan mendaftarkannya ulang dengan ejaan
     * lain berarti master `standards` punya dua baris untuk satu kalibrator.
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        [
            'label' => 'Temperature Calibrator/Constant/40T/99875850',
            'cocok' => ['Temperature Calibrator Constant 40T', '99875850'],
            'keluarga' => 'constant',
        ],
        [
            'label' => 'Temperature Calibrator/Yokogawa/CA150 Handy Cal/23P1005',
            'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005'],
            'keluarga' => 'yokogawa',
        ],
        // Baris ketiga, ditambahkan 28 Agt 2026 bersama workbook Recorder.
        //
        // Dicocokkan lewat SERIAL, bukan nama: kertas TIDS menulis "Graptech",
        // master `standards` (lewat `EnclosureSeeder`) menulis "Graphtech", dan
        // dua ejaan itu nggak akan pernah ketemu lewat nama. Serialnya sama —
        // memang satu kotak fisik yang dipakai tiga lembar (TITS, Enclosure,
        // TIDS). Pola & alasan yang sama persis dengan
        // `EnclosureProfileBase::STANDARD_TERCETAK`.
        [
            'label' => 'Temperature Recorder/Graptech/GL840/C305B1470',
            'cocok' => ['C305B1470'],
            'keluarga' => 'recorder',
        ],
    ];

    /**
     * Ejaan sensor acuan di kertas → kosakata repo (`TabelStandarTids::TIPE_SENSOR`).
     *
     * Kertasnya menulis `Thermocouple Type-K` (pakai tanda hubung),
     * master menulis `Thermocouple Type K` (tanpa), dan berkas data memakai
     * `Type K`. Tiga ejaan untuk satu barang; petanya tinggal di sini supaya
     * `normalkanTipeSensor()` nggak jadi tebak-tebakan string.
     *
     * @var array<string, string>
     */
    public const TIPE_SENSOR_TERCETAK = [
        'Thermocouple Type-K' => 'Type K',
        'Thermocouple Type-N' => 'Type N',
        'Sensor RTD/PT 100' => 'RTD',
    ];

    /**
     * Sensor acuan yang dicentang — kolom kanan blok `Standard used:`.
     *
     * Ada DI SISI STANDAR, bukan di sisi alat, dan itu bukan salah baca:
     * "dengan sensor" di nama alat menunjuk UUT yang sensornya ikut
     * dikalibrasi. Yang di daftar ini sensor LAB yang membaca suhu dryblock
     * sebagai acuan.
     *
     * @var list<string>
     */
    public const SENSOR_STANDAR_TERCETAK = [
        'Thermocouple Type-K',
        'Thermocouple Type-N',
        'Sensor RTD/PT 100',
    ];

    /**
     * Thermohygro yang tercetak di kop, berikut lokasi pemakaiannya —
     * `Inlab : TH-4`, `Insitu : TH-2 / TH-6 / TH-7`.
     *
     * Empat nama itu sudah ada di master `standards` (`ThermohygroSeeder`,
     * TH-1..TH-7), jadi yang dikirim ke HP baris tercetaknya + `standard_id`
     * yang sudah ketaut — bukan daftar kedua yang hidup sendiri.
     *
     * @var list<array{nama: string, lokasi: string}>
     */
    public const THERMOHYGRO_TERCETAK = [
        ['nama' => 'TH-4', 'lokasi' => 'Inlab'],
        ['nama' => 'TH-2', 'lokasi' => 'Insitu'],
        ['nama' => 'TH-6', 'lokasi' => 'Insitu'],
        ['nama' => 'TH-7', 'lokasi' => 'Insitu'],
    ];

    /**
     * Isi dropdown "Thermohygro Used" — MASTER lengkap, bukan empat di kop.
     *
     * Pembagian tugasnya sengaja: `THERMOHYGRO_TERCETAK` di atas empat yang
     * tercetak di kertas (disodorkan duluan di layar), sedangkan dropdown-nya
     * harus memuat ketujuh unit yang ada di master. Kalau teknisi kebetulan
     * memakai TH-1/TH-3/TH-5 di lembar ini, dia tetap bisa memilihnya.
     *
     * TH-7 digrup `Insitu` di sini — SENGAJA, dan ikut kop lembar ini sendiri
     * (`THERMOHYGRO_TERCETAK` di atas: `Insitu : TH-2 / TH-6 / TH-7`). Grup itu
     * memang beda-beda per lembar karena yang menentukan CETAKANNYA, bukan
     * tempat unitnya diparkir: `ConductivityProfile` juga menaruh TH-7 di
     * Insitu mengikuti `SIDIK-FM-CAL-0510_Rev.5`, sementara lembar lain
     * menaruhnya di Inlab. Yang dipilih teknisi `standard_id` yang sama persis
     * — ini murni soal di bawah judul mana kotaknya muncul.
     *
     * Jadi jangan "diseragamkan" ke satu daftar global: yang bakal terjadi kop
     * dan dropdown di lembar yang sama saling bertentangan.
     *
     * @var list<array{label: string, grup: string}>
     */
    public const THERMOHYGRO_PILIHAN = [
        ['label' => 'TH-1', 'grup' => 'Inlab'],
        ['label' => 'TH-3', 'grup' => 'Inlab'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
        ['label' => 'TH-5', 'grup' => 'Inlab'],
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
        ['label' => 'TH-7', 'grup' => 'Insitu'],
    ];

    private ?TidsCalculator $kalkulator = null;

    public function __construct(private readonly TabelStandarTids $tabel = new TabelStandarTids) {}

    public function kode(): string
    {
        return 'tids';
    }

    /**
     * Dua deret per set point — standar & UUT dibaca bergantian tiap 10 detik.
     *
     * Menyala 28 Agt 2026 bersama workbook master. Sebelumnya `false`, dan
     * akibatnya bukan sekadar bentuk: jalur datar `measurements[].pembacaan`
     * cuma punya tempat buat SATU deret, jadi seluruh tabel Pembacaan Standard
     * (35 kotak yang beneran diisi teknisi di lapangan) nggak pernah sampai ke
     * server. `tabelPembacaan()` waktu itu menyatakannya jujur lewat
     * `simpan_ke: null`; sekarang tempatnya ada.
     */
    public function butuhPasanganStandarUut(): bool
    {
        return true;
    }

    /**
     * SATU U95 per sesi, dicetak sebagai baris di bawah tabel — bukan kolom per
     * titik. `SERTIFIKAT!L34 = 'PERHITUNGAN U95%'!AC42`, satu sel.
     */
    public function u95PerTitik(): bool
    {
        return false;
    }

    /**
     * Kolom hasil sertifikat SENGAJA ikut aturan umum (`null`), beda dari TITS
     * yang memakukannya di satu desimal.
     *
     * Dua workbook TIDS memformat kolom yang sama BEDA — `SERTIFIKAT!E20:L33`
     * `0.00` di workbook Recorder, `0.0` di workbook Constant/Yokogawa — dan
     * bedanya bukan acak: sel resolusi UUT yang tercetak di baris
     * `Capacity/Graduation` ikut bergeser bareng (`I14` `0.00` lawan `0.0`,
     * untuk UUT beresolusi 0,01 lawan 0,2 °C).
     *
     * Jadi yang menentukan RESOLUSI ALATNYA, bukan jenis lembarnya — dan itu
     * persis aturan umum yang sudah dipakai `Organization::desimalSertifikat`.
     * Memakukannya di sini berarti memilih salah satu dari dua sesi contoh dan
     * membuat sesi lainnya tercetak beda dari sertifikatnya sendiri.
     */
    public function desimalSertifikat(): ?int
    {
        return null;
    }

    /**
     * `U95` SATU desimal — `SERTIFIKAT!L34` format `0.0` di KEDUA workbook,
     * termasuk yang kolom hasilnya dua desimal.
     *
     * Ini yang bikin hook-nya perlu ada: tanpa dia U95 ikut desimal kolom
     * hasil, dan sesi ber-UUT resolusi 0,01 mencetak `1,62` di baris yang
     * master-nya menulis `1,6`.
     */
    public function desimalU95(): ?int
    {
        return 1;
    }

    /**
     * `k` NOL desimal, mengikuti `SERTIFIKAT!O35` (format `0`) di kedua
     * workbook.
     *
     * Ditiru karena itu bentuk sertifikat yang sudah terbit, walau artinya
     * `k = 1,99` tercetak `2`. Sama seperti TITS & ketiga alat suhu pasangan.
     */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /** Suhu ruang SATU desimal — `SERTIFIKAT!P14` format `0.0`, kedua workbook. */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /**
     * Kelembaban NOL desimal — `SERTIFIKAT!P15` format `0`, kedua workbook.
     *
     * Beda dari suhu di baris tepat di atasnya, dan beda dari TITS yang
     * memakai satu desimal untuk dua-duanya. Yang menentukan selnya.
     */
    public function desimalKelembabanEnv(): ?int
    {
        return 0;
    }

    /**
     * Pemeriksa `pembacaan_bukan_kelipatan_resolusi` DIMATIKAN.
     *
     * Alasan yang sama persis dengan [ProfilSuhuPasangan]: setengah dari angka
     * di lembar ini dibaca di layar STANDAR (recorder 0,01 °C · Constant
     * 0,1 °C), bukan di layar UUT yang `equipments.resolusi` menggambarkannya.
     * Diadu ke penggaris yang salah, tiap pembacaan standar jadi tuduhan salah
     * ketik atas angka yang disalin apa adanya dari kertas — 25 tuduhan per
     * sesi, kelas kesalahan yang sudah kejadian di TITS dan di baris Suhu Ruang
     * Enclosure.
     */
    public function pembacaanDiadukeResolusi(): bool
    {
        return false;
    }

    /**
     * Ejaan PERSIS lampiran akreditasi `LK-285-IDN` no. 2 — dan tiga hurufnya
     * gampang meleset sekaligus:
     *
     *  - **"Temperatur"**, bukan "Temperature" (beda dari baris TITS di
     *    atasnya, yang justru pakai ejaan Inggris);
     *  - **"Indikator"**, bukan "Indicator";
     *  - **"dengan"** huruf kecil, bukan "Dengan".
     *
     * Meleset satu huruf tidak melempar error apa pun:
     * `CalibrationProfileRegistry::untukNamaAlat()` jatuh ke profil default
     * (pH), dan teknisi dapat lembar buffer 4/7/10 untuk indikator suhu. Itu
     * jebakan yang sudah terbukti sekali di repo ini — lihat
     * `docs/permintaan-user-7.md`, bagian "Jebakan yang sudah terbukti".
     */
    public function namaAlatKemampuan(): string
    {
        return 'Temperatur Indikator dengan Sensor';
    }

    /**
     * Ejaan lain yang beneran muncul di data.
     *
     * Judul lembar kerjanya sendiri menulis "Temperature Indikator With
     * Sensors" — campur Inggris-Indonesia, jamak — sementara lampiran
     * akreditasi menulis "Temperatur Indikator dengan Sensor". Nama alat
     * pelanggan yang diketik teknisi bisa jatuh di antara keduanya.
     *
     * SEMUA alias di sini memuat "dengan"/"with", jadi tidak ada satu pun yang
     * bisa nempel ke lembar TITS yang kuncinya "tanpa" — pencocokan di
     * `CalibrationProfileRegistry::kodeProfilDariNama()` menerima kunci yang
     * nempel di TENGAH nama, dan dua alat ini cuma beda satu kata itu.
     *
     * Singkatan "TIDS" sengaja TIDAK didaftarkan, alasan yang sama persis
     * dengan "TITS" di `TitsProfile::aliasNama()`: empat huruf terlalu pendek
     * untuk dicocokkan di tengah nama, dan satu nama alat yang kebetulan
     * memuatnya akan diam-diam dapat lembar suhu.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return [
            'Temperature Indikator dengan Sensor',
            'Temperature Indicator dengan Sensor',
            'Temperature Indikator With Sensors',
            'Temperature Indicator with Sensor',
        ];
    }

    /**
     * Kode rumus SENDIRI, bukan menumpang `gum-tits`.
     *
     * Menumpang akan membuat `RumusKalibrasi::formulaUntukProfil()` memulangkan
     * versi rumus TITS untuk sesi TIDS — dan versi rumus itulah yang tercatat
     * di sesi sebagai "aturan yang dipakai waktu sesi ini dihitung". Nomor
     * versi yang salah di jejak audit lebih buruk daripada tidak ada nomor
     * sama sekali: yang pertama menjawab pertanyaan asesor dengan jawaban yang
     * keliru, yang kedua kelihatan kosong.
     */
    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_TIDS;
    }

    public function besaran(): string
    {
        return 'suhu';
    }

    /** Satuan tunggal — seluruh kolom lembarnya `oC`. */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /**
     * Tidak divonis PASS/FAIL — lembar kerjanya tidak punya kolom batas
     * keberterimaan. Lihat docblock kelas.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * Sensor lab dibaca NOMINAL — tidak ada kurva suhu larutan di alat ini.
     * Tanpa ini `CalibrationValidator` menandai tiap sesi TIDS `valid: false`
     * gara-gara `standards.koefisien_suhu` NULL, padahal NULL memang jawaban
     * yang benar untuk termokopel.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Kertas TIDS: dua tabel `titik ukur × kolom`, dan sekarang BISA difoto —
     * lewat jalur LOKAL saja.
     *
     * ## `didukung` TETAP `false`, `lokal` yang dinyalakan
     *
     * **Menaikkan `didukung` bukan cuma soal bentuk.** Penanda itu menggerbangi
     * `POST /raw-measurements/extract-from-photo`, yang MENGIRIM FOTO LEMBAR
     * KERJA PELANGGAN KE LAYANAN PIHAK KETIGA (Gemini/Anthropic). Sempat
     * dinaikkan di sini supaya tombol lokalnya hidup (27 Agt 2026) — dan itu
     * diam-diam membuat lembar TIDS ikut memenuhi syarat dikirim keluar begitu
     * `VISION_AKTIF` menyala, padahal yang dimaui cuma kamera on-device.
     * Ketahuan waktu review, dan dibetulkan dengan MEMISAHKAN gerbangnya, bukan
     * dengan menerima pelebarannya.
     *
     * Alasan lama `didukung: false` juga masih berdiri apa adanya: dua tabel
     * interval yang berpasangan per 10 detik memang tidak bisa digambarkan dua
     * penanda bentuk yang dipunya jalur cloud.
     *
     * ## Yang dinyalakan: `lokal`
     *
     * Jalur lokal menjangkar PER TABEL, bukan per lembar — dan di situlah
     * alasan penolakan jalur cloud berhenti berlaku. Dua tabel yang
     * berpasangan itu difoto satu-satu, dan masing-masing memang berbentuk
     * baris × kolom:
     *
     *  - **baris** dijangkar tulisan `Set point 1`…`Set point 7` yang tercetak
     *    di kolom kiri. Bukan angkanya — set point TIDS memang kosong di
     *    kertas.
     *  - **kolom** dijangkar `0" (UUT1)`…`90" (UUT5)`, yang sudah dikirim di
     *    `pengulangan_uut[].label`.
     *
     * ## Yang harus sudah beres di sisi HP sebelum ini dinyalakan
     *
     * Tiga hal, dan ketiganya baru mendarat 27 Agt 2026 — menyalakan penanda
     * ini lebih dulu cuma menghasilkan tombol yang tiap jepretannya nol sel:
     *
     *  1. Baris ber-`titik_ukur: null` **kebaca**. Sebelumnya cast keras di HP
     *     membuangnya diam-diam, jadi lembar ini terbuka tanpa satu pun kotak.
     *  2. `pengulangan_uut[].label` dipakai sebagai jangkar kolom.
     *  3. Tabel `Pembacaan Standard` menampilkan keterangan `simpan_ke: null`
     *     — tanpa itu, kamera cuma mempercepat pengisian 35 kotak yang memang
     *     belum punya tempat di server.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung: bool, lokal: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return [
            'kolom_suhu' => false,
            'standar_di_baris' => true,
            // Jalur CLOUD tetap DITOLAK — lihat docblock di atas.
            'didukung' => false,
            // Jalur ON-DEVICE hidup.
            'lokal' => true,
        ];
    }

    /** TIDS tidak punya pasangan titik→standar tetap — set point-nya kosong di kertas. */
    public function standarPerTitik(): array
    {
        return [];
    }

    /**
     * SENGAJA `null` — TIDS tidak punya budget per titik. U95-nya lahir sekali
     * per sesi lewat [hitungPerGrup], persis seperti ketiga alat suhu pasangan
     * dan TITS.
     *
     * Balik `null` bikin `GumCalculator::hitungTitik()` jatuh ke jalur CMC apa
     * adanya kalau suatu saat terpanggil — bukan menyusun budget karangan yang
     * kelihatan sah. Jalur itu praktis tidak terpakai: `susunPengukuran()`
     * memanggil [hitungPerGrup] lebih dulu dan hook itu selalu menjawab.
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
     * Hitung seluruh sesi sekaligus — budget-nya satu untuk semua titik.
     *
     * Keluarga standar, tipe sensor & dryblock dibaca dari SESI, bukan dari
     * master alat: satu indikator bisa datang lagi dikalibrasi pakai kalibrator
     * lain, dan tiap kombinasi punya tabel koreksinya sendiri.
     *
     * Sesi yang syaratnya kurang tidak "gagal" — dia memulangkan `hitungan`
     * kosong plus alasan per titik yang kebaca teknisi. Pengukuran mentahnya
     * tetap tersimpan utuh; yang ditahan cuma barisnya di
     * `uncertainty_calculations`.
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

        // Dua ejaan per pilihan, dan dua-duanya beredar: kolom sesi
        // (`tipe_sensor` / `alat_bantu`, dipakai lembar TITS & Thermocouple)
        // dan peta `spesifikasi_alat` (dipakai lembar ini sejak sebelum jalur
        // pasangan ada). Yang menentukan versi APK teknisi, bukan pilihan kita
        // — jadi dibaca dua-duanya, kolom sesi menang.
        $spesifikasi = (array) ($konteks['spesifikasi_alat'] ?? []);

        $keluarga = $this->keluargaStandar($standar);
        $tipeSensor = $this->normalkanTipeSensor(
            $konteks['tipe_sensor'] ?? null,
        ) ?? $this->normalkanTipeSensor($spesifikasi['sensor_standar'] ?? null);
        $dryblock = $this->kodeDryblock(
            $konteks['alat_bantu'] ?? null,
        ) ?? $this->kodeDryblock($spesifikasi['dryblock'] ?? null);

        $kurang = $this->syaratKurang($keluarga, $tipeSensor, $dryblock, $standar, $equipment);

        if ($kurang !== null) {
            return $this->semuaDitahan($titik, $kurang);
        }

        $kemampuan = $this->kemampuanUntukTitik($equipment, $titik);

        if ($kemampuan === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    fn (array $t): array => [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => sprintf(
                            'Lab belum punya baris CMC TIDS yang mencakup set point %s °C — lampiran '
                            .'akreditasi cuma memuat %s…%s °C dalam tiga pita. Sesinya boleh disimpan, tapi '
                            .'U95-nya nggak bisa diterbitkan.',
                            $this->angka((float) $t['titik_ukur']),
                            $this->angka(self::CMC_MIN),
                            $this->angka(self::CMC_MAKS),
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
                // `no_probe` dipakai apa adanya — kolom yang sama sudah dikirim
                // jalur pasangan buat Thermocouple, dan artinya identik ("No.
                // Termokopel baris ini"). Kunci baru cuma akan bikin
                // `CalibrationController::susunPasanganStandarUut()` punya dua
                // nama untuk satu kolom `raw_measurements.sensor_ke`.
                'no_sensor' => (int) ($t['konteks']['no_probe'] ?? 0),
            ],
            $titik,
        );

        $hasil = ($this->kalkulator ??= new TidsCalculator($this->tabel))->hitungSesi($masukan, [
            'keluarga_standar' => $keluarga,
            'tipe_sensor' => $tipeSensor,
            'dryblock' => $dryblock,
            'resolusi' => (float) $equipment->resolusi,
            'cmc' => (float) $kemampuan->ketidakpastian_terbaik,
            // Sama pola dengan tipe sensor & dryblock: kolom sesi `titik_es`
            // dulu, peta `spesifikasi_alat` sebagai cadangan.
            'titik_es' => $this->pasanganTitikEs(
                $konteks['titik_es'] ?? [],
                $spesifikasi,
            ),
        ]);

        $hitungan = $this->barisHitungan($hasil, $standar, $kemampuan);

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $hasil['belum_dihitung']];
    }

    /**
     * Peringatan sesi — dipasang sejak awal, bukan ditambal belakangan.
     *
     * Empat hal, dan keempatnya jenis kesalahan yang tidak memunculkan error
     * di mana pun kalau lolos:
     *
     *  1. **Empat penyimpangan master yang ditiru.** Muncul begitu keluarga
     *     standar sesi ini diketahui, karena tiga di antaranya menggeser U95 ke
     *     arah lebih KECIL — dan sertifikat yang understate ketidakpastiannya
     *     itu temuan asesor, bukan sekadar angka yang kurang rapi. Rinciannya
     *     di docblock [TidsCalculator]; yang di sini menahan tombol APPROVE
     *     sampai ada manusia yang membacanya.
     *  2. **Set point di luar rentang CMC (−20…600 °C).** Ini yang diminta
     *     dipasang dari awal, dan alasannya konkret: sensor acuan yang
     *     tercetak di lembar kerjanya sendiri (Thermocouple Type-K & Type-N)
     *     berlaku sampai 1000 °C di lampiran akreditasi, jauh di atas 600 °C
     *     tempat CMC TIDS berhenti. Artinya teknisi BISA memasang set point
     *     900 °C, alatnya sanggup, sensornya sanggup — dan yang tidak sanggup
     *     cuma klaim akreditasi lab, satu-satunya hal yang tidak kelihatan dari
     *     meja kerja.
     *  3. **Dryblock belum dicentang.** Koreksi Isotech dan Techne beda —
     *     keseragaman media 0,47 °C lawan 0,1 °C, stabilitas 0,0005 lawan
     *     0,03 — jadi merk yang tidak tercatat berarti dua komponen budget
     *     tidak punya angka. Ini peringatan yang menyelamatkan data, bukan cuma
     *     kerapian.
     *  4. **Baris CMC hilang dari master.** Pola & pesan mengikuti
     *     `enclosure_cmc_kosong` di `EnclosureProfileBase`.
     *  5. **Tipe sensor standar belum dipilih.** Koreksi meter, koreksi sensor,
     *     U95 & drift semuanya dibaca per tipe — tanpa itu sesinya nggak
     *     kehitung sama sekali.
     *  6. **Uji titik es 0 °C belum diisi.** Komponen `Drift UUT` lahir dari
     *     selisih Awal–Akhir; kosong bikin komponennya nol, dan nol itu klaim
     *     ("alatnya nggak drift sama sekali"), bukan ketiadaan data.
     *
     * Cek yang butuh database ditahan di belakang `$sesi->exists`: kontrak
     * `peringatanSesi()` juga dipanggil ke sesi in-memory
     * (`Tests\Unit\PeringatanProfilBentukTest`), dan query di situ bikin
     * SELURUH pemeriksaan profil meledak, bukan cuma peringatan alat ini.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];

        if ($this->dryblockSesi($sesi) === null) {
            $peringatan[] = [
                'kode' => 'tids_dryblock_kosong',
                'pesan' => 'Dryblock (A Isotech / B Techne) belum dicentang. Keseragaman & stabilitas kedua '
                    .'blok beda jauh (0,47 lawan 0,1 °C · 0,0005 lawan 0,03 °C), dan dua-duanya komponen '
                    .'budget — tanpa dicentang, sesi ini nggak kehitung sama sekali.',
            ];
        }

        if ($this->tipeSensorSesi($sesi) === null) {
            $peringatan[] = [
                'kode' => 'tids_tipe_sensor_kosong',
                'pesan' => 'Tipe sensor STANDAR (Type K / Type N / RTD PT100) belum dipilih. Koreksi meter, '
                    .'koreksi sensor, U95 sertifikat, dan drift-nya semua dibaca per tipe — tanpa itu sesinya '
                    .'nggak kehitung sama sekali.',
            ];
        }

        if (count($this->titikEsSesi($sesi)) < 2) {
            $peringatan[] = [
                'kode' => 'tids_titik_es_kosong',
                'pesan' => 'Uji titik es 0 °C (Pembacaan Awal & Akhir) belum lengkap. Selisih dua angka itu '
                    .'jadi komponen budget `Drift UUT` (½ × selisih, ÷√3) di kedua master. Dikosongkan, '
                    .'komponennya jadi NOL — dan nol di situ artinya "alat ini nggak drift sama sekali", '
                    .'klaim yang nggak pernah diukur siapa pun.',
            ];
        }

        if (! $sesi->exists) {
            return $peringatan;
        }

        $keluarga = $this->keluargaStandar($sesi->standard);

        if ($keluarga !== null) {
            $peringatan[] = $keluarga === TidsCalculator::KELUARGA_RECORDER
                ? [
                    'kode' => 'tids_master_recorder_sel_tetap',
                    'pesan' => 'Workbook Recorder mengambil TIGA angka budget dari sel tetap, bukan dari '
                        .'tabelnya sendiri: U95 kalibrator `T30` = 0,83 °C (tabel Type K berbunyi 0,67), U95 '
                        .'sensor literal 0,14 °C (tabel berbunyi 0,44 Type K / 0,76 Type N), dan drift '
                        .'kalibrator `AM9` = −0,2 °C — sel di tabel KOREKSI, bukan di `Tabel_Drift_Recorder` '
                        .'(0,25 / 0,5) yang ada & nggak dipakai siapa pun. Ditiru apa adanya supaya cocok '
                        .'dengan sertifikat yang sudah terbit; angka pembandingnya ada di jejak audit sesi '
                        .'ini. Perlu keputusan manajer teknis sebelum dipakai terus.',
                ]
                : [
                    'kode' => 'tids_master_tiga_komponen_tidak_dijumlah',
                    'pesan' => 'Workbook Constant/Yokogawa menghitung dua belas komponen budget lalu menjumlah '
                        .'sembilan — `AC36 = SUM(AC24:AD32)` berhenti sebelum Self Heating, Interpolasi & '
                        .'Drift UUT. Workbook Recorder untuk alat yang SAMA menjumlah keduabelasnya. Ditiru '
                        .'per workbook, jadi U95 sesi ini lebih KECIL daripada kalau ketiganya ikut; angka '
                        .'pembandingnya ada di jejak audit sesi ini. Perlu keputusan manajer teknis.',
                ];
        }

        foreach ($sesi->uncertaintyCalculations as $baris) {
            $setpoint = (float) $baris->titik_ukur;

            if ($setpoint >= self::CMC_MIN && $setpoint <= self::CMC_MAKS) {
                continue;
            }

            $peringatan[] = [
                'kode' => 'tids_titik_luar_cmc',
                'pesan' => sprintf(
                    'Set point ke-%d (%s °C) di luar rentang CMC TIDS (%s…%s °C). Sensor acuan yang tercetak '
                    .'di lembar ini (Type-K & Type-N) sanggup sampai 1000 °C, jadi titik segini wajar diambil '
                    .'— tapi lab belum mengklaimnya di lampiran akreditasi, dan hasilnya nggak boleh terbit '
                    .'sebagai hasil terakreditasi.',
                    $baris->titik_ke,
                    rtrim(rtrim(number_format($setpoint, 2, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format(self::CMC_MIN, 2, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format(self::CMC_MAKS, 2, ',', '.'), '0'), ','),
                ),
            ];
        }

        if ($this->kemampuanTids($sesi->equipment) === null) {
            $peringatan[] = [
                'kode' => 'tids_cmc_kosong',
                'pesan' => sprintf(
                    'CMC buat "%s" belum ada di master kemampuan kalibrasi — jalankan '
                    .'CalibrationCapabilitySeeder dulu.',
                    $this->namaAlatKemampuan(),
                ),
            ];
        }

        return $peringatan;
    }

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap();

        if ($untukAdmin) {
            $bentuk['bagian'][] = $this->bagianAdmin();
        }

        return $this->tautkanStandar($this->isiPilihanThermohygro($bentuk, $equipment), $equipment);
    }

    /**
     * Isi pilihan "Thermohygro Used".
     *
     * Lembar ini punya DUA jalur ke unit thermohygro, dan sebelum ini
     * dua-duanya mati:
     *
     *  1. dropdown `thermohygro_standard_id` — nggak pernah diisi sama sekali,
     *     jadi `pilihan`-nya `[]` dan layar teknisi jatuh ke teks mati;
     *  2. `baris_thermohygro` di kop — keisi, tapi dicocokkan ke koleksi
     *     `whereNull('parameter_kondisi')` milik `tautkanStandar()`, yang
     *     menurut definisi NGGAK memuat satu pun thermohygro. Keempat barisnya
     *     selalu pulang `terdaftar: false`.
     *
     * Yang kedua lebih halus karena kelihatan bekerja: barisnya ada, labelnya
     * benar, cuma `standard_id`-nya null. Nomor 2 diperbaiki di
     * `tautkanStandar()`, nomor 1 di sini.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = Standard::query()
            ->whereNotNull('parameter_kondisi')
            // Disaring ke lab pemilik alat: dropdown yang menawarkan
            // termohigrometer lab lain bikin koreksi kondisi lingkungan
            // dibaca dari sertifikat lab itu, lalu kecetak di sertifikat
            // lab ini.
            ->when(
                $equipment?->organization_id !== null,
                fn ($q) => $q->where('organization_id', $equipment->organization_id),
            )
            ->pluck('id', 'nama');

        $pilihan = [];
        foreach (self::THERMOHYGRO_PILIHAN as $unit) {
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
     * Bentuk lembar kerja, urut seperti kertasnya dibaca dari atas.
     *
     * Label & urutannya diambil dari PDF `SIDIK-FM-CAL-0506 Rev.4` apa adanya,
     * termasuk yang campur bahasa ("Thermohygro Used", "Dryblock Used",
     * "Standard used:") dan yang ejaannya nyeleneh ("Kelembapan", bukan
     * "Kelembaban" seperti kolom database-nya). Yang dibaca teknisi harus sama
     * dengan yang dipegang teknisi.
     *
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'kode_metode' => self::KODE_METODE,
            'nomor_lingkup' => self::NOMOR_LINGKUP,
            'judul' => 'Calibration Work Sheet - Temperature Indikator With Sensors',
            'jumlah_pengulangan' => self::PENGULANGAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => self::SATUAN,
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Tiap SET POINT dibaca lima kali bergantian per 10 detik: 0" standar, '
                .'10" alat, 20" standar, 30" alat, dan seterusnya sampai 90". Jadi satu baris = satu set '
                .'point, dan lima kolomnya lima ULANGAN — bukan lima alat. Kolom yang belum bisa diisi di '
                .'lapangan boleh dikosongin, KECUALI tiga ini yang nentuin ANGKA: TIPE SENSOR STANDAR, '
                .'DRYBLOCK (A Isotech / B Techne), dan NO. TERMOKOPEL tiap baris. Uji titik es 0 °C juga '
                .'diisi — selisih Awal–Akhir jadi komponen budget Drift UUT.',
            // Penanda buat layar: sejak 28 Agt 2026 alat ini SUDAH menerbitkan
            // U95. Kuncinya tetap dikirim (bukan dihapus) karena HP membacanya
            // buat memutuskan apakah panel hasil digambar — dihilangkan, versi
            // APK lama jatuh ke cabang "belum ada" yang default-nya.
            'budget_ketidakpastian' => [
                'tersedia' => true,
                'sumber' => 'Master_Olah_Data_Suhu_TIDS — Recorder Graptech & Yokogawa K,N (28 Agt 2026)',
                'catatan' => 'Dua belas komponen, satu budget untuk seluruh sesi, lantai CMC 0,86 / 1,4 / '
                    .'3,1 °C. Keluarga standar (Recorder / Constant / Yokogawa) nentuin tabel koreksinya. '
                    .'Empat penyimpangan master ditiru apa adanya dan dilaporkan lewat peringatan sesi.',
            ],
            'sumbu_uut' => $this->sumbuUut(),
            // Urutan bagian ngikut POLA BERSAMA semua lembar, bukan urutan
            // kertasnya dibaca dari atas.
            //
            // Di `SIDIK-FM-CAL-0506 Rev.4` kotak dryblock tercetak di atas blok
            // `Standard used:`. Dulu urutan di sini ngikut itu — satu-satunya
            // lembar dari tujuh belas yang `usage_check`-nya nggak duduk di
            // posisi ketiga. Teknisi yang pindah alat jadi harus nyari letak
            // blok standar dari nol tiap ganti lembar.
            //
            // Kertasnya nggak berubah, dan yang dicetak juga nggak: jalur cetak
            // lembar pindai punya definisinya sendiri (`bentukPindaiFoto()` +
            // template OCR) dan nggak baca urutan array ini sama sekali. Yang
            // digeser cuma urutan baca di LAYAR.
            //
            // Dijaga `SemuaProfilLembarKerjaTest::test_urutan_bagian_seragam_di_semua_lembar()`.
            'bagian' => [
                $this->bagianIdentitas(),
                $this->bagianPemilik(),
                $this->bagianStandard(),
                $this->bagianDryblock(),
                $this->bagianTitikEs(),
                $this->bagianDataKalibrasi(),
                $this->bagianPenutup(),
            ],
        ];
    }

    /**
     * Sumbu waktu pembacaan — kunci yang DULU bernama "sumbu lima UUT".
     *
     * Isinya tidak berubah bentuknya (aplikasi yang sudah terpasang
     * membacanya), tapi ARTINYA berubah 28 Agt 2026 waktu dua workbook master
     * turun: kelimanya ULANGAN, bukan lima alat pelanggan. Yang dikoreksi di
     * sini `keputusan_skema` & `catatan` — dua kunci yang memang ditaruh dulu
     * supaya jawabannya bisa masuk tanpa mengubah bentuk.
     *
     * Kunci ini tetap terpisah dari `jumlah_pengulangan`/`pengulangan` karena
     * alasan yang belum berubah: aplikasi teknisi membaca yang pertama sebagai
     * int dan yang kedua sebagai daftar angka, jadi keterangan berbentuk objek
     * di dua kunci itu bikin lembarnya gagal kebuka atau kebuka tanpa satu pun
     * kolom pembacaan.
     *
     * @return array<string, mixed>
     */
    private function sumbuUut(): array
    {
        return [
            'jumlah' => self::PENGULANGAN,
            'daftar' => array_map(
                static fn (int $i): array => [
                    'kode' => 'uut_'.$i,
                    'nomor' => $i,
                    // Label CETAK-nya, bukan label master. Kertas yang dipegang
                    // teknisi (`SIDIK-FM-CAL-0506 Rev.4`) menulis `UUT1`;
                    // workbook menulis `PRT1` untuk kolom yang sama. Yang
                    // menang kertasnya — layar harus bisa diadu dengan kolom
                    // yang lagi dipandang teknisi, dan jangkar OCR-nya pun
                    // dicocokkan ke tulisan tercetak itu.
                    'label' => 'UUT'.$i,
                    'label_master' => 'PRT'.$i,
                    'detik_standard' => self::DETIK_STANDARD[$i - 1],
                    'detik_uut' => self::DETIK_UUT[$i - 1],
                ],
                range(1, self::PENGULANGAN),
            ),
            'keputusan_skema' => 'lima_ulangan',
            'catatan' => 'DIJAWAB 28 Agt 2026 oleh workbook master, bukan oleh lab: lima kolom ini lima '
                .'ULANGAN atas satu alat, bukan lima alat. Dua workbook TIDS menamai kolom yang sama '
                .'`PRT1`…`PRT5` lalu memakainya sebagai AVERAGE + STDEV per baris, dan satu baris = satu set '
                .'point. Jadi pertanyaan lama "1 sesi 5 UUT vs 5 sesi terpisah" gugur — nggak pernah ada lima '
                .'UUT. Yang bikin tafsir lama masuk akal: kertasnya sendiri cuma punya SATU blok identitas '
                .'alat untuk lima kolom itu; ternyata memang cuma satu alat.',
        ];
    }

    /**
     * Blok kop kiri-tengah-kanan: identitas alat, kondisi ruangan, lokasi,
     * thermohygro.
     *
     * @return array<string, mixed>
     */
    private function bagianIdentitas(): array
    {
        return [
            'kode' => 'identitas_alat',
            'halaman' => 1,
            'judul' => 'Identitas Alat dan Data Customer',
            'field' => [
                // `equipment_id` WAJIB ada di sini.
                //
                // Bukan kelengkapan: tombol kirim di HP menahan sesi yang
                // alatnya belum dipilih, jadi profil yang lupa memasang field
                // ini menghasilkan lembar yang bisa diisi penuh lalu TIDAK BISA
                // dikirim sama sekali. Itu yang kejadian di lima profil
                // Enclosure (lihat docs/permintaan-user-7.md).
                $this->field('equipment_id', 'Nama Alat', 'pilihan', sumber: 'master_alat'),
                $this->field('equipment.nama_alat', 'Nama Alat', 'teks', sumber: 'otomatis'),
                $this->field('alat_merk', 'Merk', 'teks'),
                $this->field('alat_model', 'Type', 'teks'),
                $this->field('alat_serial_number', 'No. Seri', 'teks'),
                $this->field('spesifikasi_alat.rentang_ukur', 'Rentang Ukur', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.kapasitas', 'Kapasitas Alat', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.resolusi', 'Resolusi Alat', 'angka', satuan: self::SATUAN),
                $this->field('tanggal_terima', 'Tgl. Diterima', 'tanggal'),
                $this->field('tanggal_kalibrasi', 'Tgl. Kalibrasi', 'tanggal'),
                // Kertasnya menulis "Suhu Ruangan : awal ___ akhir ___ oC" —
                // satu baris, dua kotak. Di sini dua field, karena kolom
                // database-nya memang dua.
                $this->field('suhu_awal', 'Suhu Ruangan — awal', 'angka', satuan: self::SATUAN),
                $this->field('suhu_akhir', 'Suhu Ruangan — akhir', 'angka', satuan: self::SATUAN),
                $this->field('kelembaban_awal', 'Kelembapan — awal', 'angka', satuan: '%RH'),
                $this->field('kelembaban_akhir', 'Kelembapan — akhir', 'angka', satuan: '%RH'),
                $this->field('lokasi', 'Lokasi Kalibrasi', 'pilihan', pilihan: [
                    ['nilai' => 'lab', 'label' => 'Inlab'],
                    ['nilai' => 'onsite', 'label' => 'Insitu'],
                ]),
                $this->field(
                    'room_id',
                    'Ruangan (Inlab)',
                    'pilihan',
                    sumber: 'master_ruangan',
                    tampilKalau: self::TAMPIL_KALAU_INLAB,
                ),
                // Kolom teks bebas nama tempat buat Insitu. Ikut sejak profil
                // ini lahir, bukan ditambal belakangan seperti sepuluh profil
                // lama yang sertifikat Insitu-nya sempat mencetak nama ruang
                // lab padahal kerjanya di tempat pelanggan. Sepuluh itu udah
                // nyusul — sekarang semua lembar punya pasangan kotak ini.
                $this->field('lokasi_nama', 'Nama Tempat (Insitu)', 'teks', tampilKalau: self::TAMPIL_KALAU_INSITU),
                $this->field(
                    'thermohygro_standard_id',
                    'Thermohygro Used',
                    'pilihan',
                    sumber: 'master_thermohygro',
                ),
                $this->field(
                    'calibration_method_id',
                    'Calibration Methode',
                    'pilihan',
                    sumber: 'master_metode',
                ),
            ],
            // Empat thermohygro yang TERCETAK di kop, berikut lokasi
            // pemakaiannya. Dikirim terpisah dari dropdown master di atas
            // supaya layar bisa menyodorkan empat yang benar duluan — di
            // master ada TH-1..TH-7, dan tiga di antaranya tidak pernah
            // dipakai untuk lembar ini.
            'baris_thermohygro' => self::THERMOHYGRO_TERCETAK,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bagianPemilik(): array
    {
        return [
            'kode' => 'pemilik',
            'halaman' => 1,
            'judul' => 'Data Customer',
            'field' => [
                $this->field('pemilik_nama', 'Nama Customer', 'teks'),
                $this->field('pemilik_alamat', 'Alamat Customer', 'teks_panjang'),
            ],
        ];
    }

    /**
     * Dryblock — dua kotak centang.
     *
     * Di kertasnya kotak ini dicetak di pojok kanan blok identitas, bukan di
     * blok `Standard used:` di bawahnya. Tetap dipisah jadi bagian sendiri di
     * sini karena artinya beda dari sekelilingnya: yang lain identitas alat
     * PELANGGAN, yang ini alat LAB — dan salah centang mengubah koreksi yang
     * dipakai, bukan cuma teks di sertifikat.
     *
     * Disimpan di `spesifikasi_alat.dryblock` — peta JSON bebas yang sudah ada
     * di `calibration_sessions`, jadi Tahap 1 tidak butuh migrasi sama sekali.
     * Nilainya slug (`isotech`/`techne`), bukan label cetaknya; lihat
     * [DRYBLOCK].
     *
     * @return array<string, mixed>
     */
    private function bagianDryblock(): array
    {
        return [
            'kode' => 'dryblock',
            'halaman' => 1,
            'judul' => 'Dryblock Used',
            'field' => [
                $this->field('spesifikasi_alat.dryblock', 'Dryblock Used', 'pilihan', pilihan: array_map(
                    static fn (array $d): array => ['nilai' => $d['nilai'], 'label' => $d['label']],
                    self::DRYBLOCK,
                )),
            ],
        ];
    }

    /**
     * Blok `Standard used:` — dua kolom kotak centang di kertasnya: kalibrator
     * di kiri, sensor acuan di kanan.
     *
     * `baris` (kalibrator) pakai bentuk `usage_check` yang sama dengan TITS,
     * jadi `CalibrationController::simpanUsageCheck()` menyimpannya lewat
     * pivot `standar_dicek` yang sudah ada — tanpa kode baru.
     *
     * `baris_sensor_standar` kunci sendiri karena ketiga sensor itu BELUM ada
     * di master `standards` (dicek: nol hasil untuk Type-K/Type-N/PT 100
     * sebagai baris standar TIDS). Menaut paksa ke baris yang tidak ada
     * menghasilkan `standard_id` null yang kelihatan seperti teknisi lupa
     * mencentang; kunci terpisah menyatakan keadaannya apa adanya.
     *
     * @return array<string, mixed>
     */
    private function bagianStandard(): array
    {
        return [
            'kode' => 'usage_check',
            'halaman' => 1,
            'judul' => 'Standard used',
            'baris' => self::STANDARD_TERCETAK,
            'baris_sensor_standar' => array_map(
                static fn (string $nama): array => ['label' => $nama, 'terdaftar' => false],
                self::SENSOR_STANDAR_TERCETAK,
            ),
            'field' => [
                $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                // Kolom SESI `tipe_sensor` — kolom yang sama dipakai lembar
                // TITS & Thermocouple, dan itu yang dibaca `hitungPerGrup()`.
                // Nilainya kosakata repo (`Type K`), bukan ejaan kertasnya,
                // karena `CalibrationRequest` menyaringnya lewat
                // `Rule::in(TabelKalibratorSuhu::TIPE_SENSOR)` — label cetaknya
                // tetap yang muncul di layar.
                $this->field('tipe_sensor', 'Sensor Standard', 'pilihan', pilihan: array_map(
                    static fn (string $cetak): array => [
                        'nilai' => self::TIPE_SENSOR_TERCETAK[$cetak],
                        'label' => $cetak,
                    ],
                    array_keys(self::TIPE_SENSOR_TERCETAK),
                )),
                // Kunci LAMA, tetap dikirim. APK yang sudah terpasang menulis
                // ke sini, dan `hitungPerGrup()` membacanya sebagai cadangan —
                // dicabut, sesi dari APK lama sampai ke server tanpa tipe
                // sensor dan seluruh titiknya ditahan.
                $this->field('spesifikasi_alat.sensor_standar', 'Sensor Standard (lama)', 'pilihan', pilihan: array_map(
                    static fn (string $nama): array => ['nilai' => $nama, 'label' => $nama],
                    self::SENSOR_STANDAR_TERCETAK,
                )),
            ],
        ];
    }

    /**
     * Uji titik es 0 °C — dua kotak, Awal & Akhir.
     *
     * Kotak kecil di pojok kanan kertasnya, dan satu-satunya bagian lembar ini
     * yang belum jelas GUNANYA. Yang tercetak cuma dua kolom angka; tidak ada
     * batas, tidak ada rumus, tidak ada keterangan apa yang terjadi kalau
     * selisih Awal–Akhir besar. Tiga tafsir yang mungkin (komponen budget
     * drift sensor / syarat lolos sebelum sesi diterima / catatan saja)
     * menghasilkan tiga U95 yang berbeda, jadi yang dikerjakan sekarang cuma
     * MENYIMPAN dua angkanya — utuh, apa adanya, di
     * `spesifikasi_alat.titik_es_awal` & `.titik_es_akhir`.
     *
     * Disimpan sekarang walau belum dipakai justru karena itu: kalau kolomnya
     * tidak ada, sesi yang dikerjakan hari ini tidak akan punya angka titik es
     * waktu aturannya akhirnya turun, dan yang hilang data lapangan yang tidak
     * bisa diambil ulang.
     *
     * @return array<string, mixed>
     */
    private function bagianTitikEs(): array
    {
        return [
            'kode' => 'titik_es',
            'halaman' => 1,
            'judul' => 'Pengujian di titik es 0˚C',
            'field' => [
                // Kode `titik_es_N`, bukan `spesifikasi_alat.titik_es_awal`
                // yang dipakai sampai 27 Agt 2026.
                //
                // Kolom sesi `titik_es` itu tempat kanoniknya (dipakai lembar
                // Termometer Gelas, dibaca `CalibrationResource`, dan ikut
                // dipulangkan ke jalur hitung ulang), dan HP sudah tahu memetakan
                // `titik_es_N` ke situ. Peta `spesifikasi_alat` tetap DIBACA
                // sebagai cadangan di `hitungPerGrup()` — sesi yang sudah
                // telanjur tersimpan dari APK lama tetap kehitung, bukan
                // kehilangan komponen `Drift UUT`-nya.
                $this->field('titik_es_1', 'Awal', 'angka', satuan: self::SATUAN),
                $this->field('titik_es_2', 'Akhir', 'angka', satuan: self::SATUAN),
            ],
            'catatan' => 'Selisih Awal–Akhir jadi komponen budget `Drift UUT` (½ × selisih, distribusi '
                .'persegi, ÷√3) — `O35 = 0.5 * ABS(awal − akhir)` di kedua workbook master. Dikosongkan, '
                .'komponennya jadi NOL, dan nol di situ artinya "alat ini nggak drift sama sekali".',
        ];
    }

    /**
     * Dua tabel `Data Kalibrasi`, persis urutan kertasnya: Pembacaan Standard
     * dulu, lalu Pembacaan Alat yang Dikalibrasi.
     *
     * @return array<string, mixed>
     */
    private function bagianDataKalibrasi(): array
    {
        return [
            'kode' => 'hasil',
            'halaman' => 1,
            'judul' => 'Data Kalibrasi',
            'field' => [],
            'tabel' => [
                [
                    ...$this->tabelPembacaan(
                        tahap: 'pembacaan_standard',
                        judul: 'Pembacaan Standard',
                        detik: self::DETIK_STANDARD,
                        peran: 'standar',
                        simpanKe: 'measurements[].standar',
                    ),
                    // Kolom tambahan yang cuma tabel standar punya: tiap baris
                    // menyebut TERMOKOPEL mana yang dicelup. Tersimpan ke
                    // `raw_measurements.sensor_ke`, dan dia yang memilih kolom
                    // tabel koreksi — Type N nomor 3 → kanal CH1, Type K nomor
                    // 1 → CH1, RTD selalu 17.
                    //
                    // `pilihan` WAJIB berisi, dan `grup`-nya WAJIB nama tipe
                    // sensornya. Layar HP (`_BarisNoProbe`) menggambar kolom
                    // ini sebagai dropdown yang daftarnya disaring `grup ==
                    // tipe_sensor` — dikirim kosong, dropdown-nya lahir tanpa
                    // satu pun pilihan dan lembarnya nggak bisa diisi sama
                    // sekali, tanpa satu pun error.
                    'kolom_baris' => [
                        $this->field('no_probe', 'No. Termokopel', 'pilihan', pilihan: $this->pilihanSensor()),
                    ],
                    'catatan' => 'Type N mulai dari nomor 3 (TCN3…TCN12); Type K nomor 1..16 (TCK-01…TCK-16); '
                        .'PRT PT100 (RTD) selalu nomor 17. Nomornya nentuin kolom tabel koreksi, jadi salah '
                        .'nomor = salah koreksi, bukan cuma salah catatan.',
                ],
                $this->tabelPembacaan(
                    tahap: 'pembacaan_uut',
                    judul: 'Pembacaan Alat yang Dikalibrasi',
                    detik: self::DETIK_UUT,
                    peran: 'uut',
                    simpanKe: 'measurements[].uut',
                ),
            ],
        ];
    }

    /**
     * Satu tabel pembacaan: baris = set point, kolom = lima UUT pada detiknya
     * masing-masing.
     *
     * ## Set point-nya KOSONG, dan itu disengaja
     *
     * Kertasnya mencetak tujuh baris set point kosong — tidak ada satu angka
     * pun tercetak di kolom Setpoint, beda dari lembar pH (4/7/10) atau
     * Turbidimeter (1/100/1000). Jadi `titik_ukur` dikirim `null` dan
     * `titik_bisa_diubah` `true`.
     *
     * Menyodorkan deret saran seperti `TitsProfile::TITIK_SARAN` sempat
     * dipertimbangkan lalu dibuang: saran di layar dibaca teknisi sebagai
     * "titik yang biasa dipakai lab", padahal tidak ada satu dokumen pun yang
     * menyebutkannya. Yang muncul akan jadi angka karangan yang lama-lama
     * dianggap prosedur.
     *
     * ## `pengulangan` tetap daftar angka polos
     *
     * Isinya nomor ulangan (1..5), dan bentuknya wajib tetap `list<int>` karena
     * aplikasi teknisi menyaringnya `whereType<num>()`. Keterangan lengkap tiap
     * kolom (detik + label cetaknya) menyusul di `pengulangan_uut` &
     * `pengulangan_arah` — dua kunci yang dibaca ke peta yang SAMA di sisi HP
     * (`lembar_kerja.dart`), jadi dikirim dua-duanya: yang pertama buat APK
     * yang sudah terpasang, yang kedua nama kanoniknya. Lihat
     * `Tests\Feature\BentukLembarKerjaKompatibelTest`.
     *
     * @param  list<int>  $detik
     * @return array<string, mixed>
     */
    private function tabelPembacaan(string $tahap, string $judul, array $detik, string $peran, ?string $simpanKe): array
    {
        $label = array_map(
            static fn (int $i): array => [
                'ke' => $i,
                'uut' => 'UUT'.$i,
                'detik' => $detik[$i - 1],
                // Persis seperti tercetak di kepala kolomnya: `0" (UUT1)`.
                // Workbook menulis `PRT1` untuk kolom yang sama — yang menang
                // kertasnya, karena ini juga jangkar sumbu mendatar jalur foto.
                'label' => sprintf('%d" (UUT%d)', $detik[$i - 1], $i),
            ],
            range(1, self::PENGULANGAN),
        );

        return [
            'tahap' => $tahap,
            // `grup` SENGAJA nggak diisi, beda dari ketiga lembar pasangan yang
            // lain (yang mengisinya `standar`/`uut`).
            //
            // `TemplateLembarKerja::tabel()` mengunci identitas tabel ke
            // `grup ?? tahap`, dan identitas itu masuk ke KUNCI SEL berkas
            // geometri OCR (`database/ocr-templates/tids-v1.json`) yang sudah
            // ada, sudah dipakai, dan kertasnya sudah bisa dicetak. Dua tabel
            // lembar ini `tahap`-nya memang sudah beda, jadi `grup` nggak
            // dibutuhkan buat memisahkan keduanya — dan mengisinya cuma akan
            // menulis ulang 70 kunci sel di berkas v1 yang sama, bikin kertas
            // yang sudah tercetak nggak kebaca lagi tanpa satu pun error.
            //
            // `peran` di bawah yang mengangkut arti standar/UUT — itu yang
            // dibaca `susunPasanganStandarUut()` & layar tabel pasangan di HP.
            // Beda dari `tahap`: `raw_measurements.tahap` artinya
            // sebelum/sesudah adjustment, yang dibedakan di sini SIAPA yang
            // membaca.
            'peran' => $peran,
            'judul' => $judul,
            'satuan' => self::SATUAN,
            'judul_nilai' => 'Setpoint',
            'judul_pengulangan' => 'Data Kalibrasi/Ulangan (oC)',
            'titik_bisa_diubah' => true,
            'baris' => array_map(
                static fn (int $n): array => [
                    'nomor' => $n,
                    'titik_ukur' => null,
                    'label' => 'Set point '.$n,
                    'satuan' => self::SATUAN,
                ],
                range(1, self::BARIS_SETPOINT_KERTAS),
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => self::SATUAN, 'tipe' => 'angka', 'satuan' => self::SATUAN],
            ],
            'pengulangan' => range(1, self::PENGULANGAN),
            // SATU kunci saja, dan sengaja yang lama.
            //
            // `pengulangan_arah` (nama kanonik, dipakai TITS & lembar pasangan)
            // dibaca ke peta yang SAMA di sisi HP, jadi mengirim dua-duanya
            // nggak menambah apa pun — tapi `BentukPindaiFotoCocokTabelTest`
            // menyapu kedua kunci lalu menuntut labelnya unik per tabel, dan
            // daftar yang sama dikirim dua kali kelihatan seperti sepuluh kolom
            // dengan lima nama kembar. Penjaga itu benar; yang salah kalau kita
            // mengirim alias.
            'pengulangan_uut' => $label,
            // Ke mana kolom tabel ini disimpan.
            //
            // Sampai 27 Agt 2026 tabel Pembacaan Standard mengirim `null` di
            // sini — pernyataan jujur waktu itu: `raw_measurements.tahap`
            // artinya as-found/as-left, bukan standar/UUT, jadi 35 kotak yang
            // diisi teknisi memang nggak punya tempat. Sekarang punya:
            // [butuhPasanganStandarUut] menyala dan `susunPasanganStandarUut()`
            // menyimpannya lewat sumbu `peran_sensor` yang sudah ada sejak
            // Enclosure. NOL kolom baru di tabel pengukuran.
            'simpan_ke' => $simpanKe,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bagianPenutup(): array
    {
        return [
            'kode' => 'penutup',
            'halaman' => 1,
            'judul' => 'Catatan & Tanda Tangan',
            'field' => [
                $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                $this->field('teknisi.nama', 'Dikalibrasi Oleh', 'teks', sumber: 'otomatis'),
                $this->field('reviewer.nama', 'Diperiksa Oleh', 'teks', sumber: 'otomatis'),
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
                $this->field('suhu_ketidakpastian', 'U95% Suhu', 'angka', sumber: 'otomatis', satuan: self::SATUAN, hanyaAdmin: true),
                $this->field('kelembaban_ketidakpastian', 'U95% Kelembaban', 'angka', sumber: 'otomatis', satuan: '%RH', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * Cocokin baris kalibrator & thermohygro tercetak ke master `standards`
     * (nama/serial). Bentuk keluarannya sama persis dengan
     * `TitsProfile::tautkanStandar()` supaya layar `usage_check` yang sudah ada
     * di HP tidak perlu tahu ini alat baru.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk, ?Equipment $equipment = null): array
    {
        $master = $this->masterStandarTertaut($equipment);

        // Thermohygro DIAMBIL TERPISAH, dan itu bukan rapi-rapi.
        //
        // `$master` di atas sengaja `whereNull('parameter_kondisi')` — itu
        // saringan buat KALIBRATOR di baris `usage_check`. Tapi
        // `ThermohygroSeeder` SELALU mengisi `parameter_kondisi` (di situlah
        // koreksi suhu/kelembapannya disimpan), jadi saringan yang sama
        // membuang habis TH-1..TH-7.
        //
        // Sebelum ini `baris_thermohygro` di kop dicocokkan ke `$master` itu
        // juga, jadi keempat barisnya mustahil ketemu: labelnya kecetak benar,
        // tapi `standard_id`-nya selalu null dan `terdaftar` selalu false.
        // Gagalnya nggak bersuara karena barisnya tetap terkirim utuh.
        $masterThermohygro = $this->masterThermohygro($equipment, ['id', 'nama', 'no_sertifikat']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) === 'usage_check') {
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
                    $bentuk['bagian'][$i]['baris'],
                );

                continue;
            }

            if (($bagian['kode'] ?? null) !== 'identitas_alat') {
                continue;
            }

            // Thermohygro dicocokin lewat NAMA, dan cuma nama.
            //
            // `ThermohygroSeeder` menyimpan TH-1..TH-7 dengan `nama` persis
            // seperti yang tercetak di kop lembar kerja. Serial-nya tidak
            // dipakai di sini karena serial thermohygro tidak dijamin unik
            // antar unit — masalah yang sama sudah pernah mendarat di
            // sertifikat lewat `tautkanStandarTitik()` (empat botol gas Rigas
            // ber-S/N sama).
            $bentuk['bagian'][$i]['baris_thermohygro'] = array_map(
                static function (array $baris) use ($masterThermohygro): array {
                    $cocok = $masterThermohygro->first(static fn (Standard $s): bool => $s->nama === $baris['nama']);

                    return [
                        'label' => $baris['nama'],
                        'lokasi' => $baris['lokasi'],
                        'standard_id' => $cocok?->id,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bagian['baris_thermohygro'],
            );
        }

        return $bentuk;
    }

    /**
     * Dryblock yang tercatat di sesi, atau `null` kalau belum dicentang.
     *
     * Dibaca dari `spesifikasi_alat` — peta JSON bebas milik sesi. Nilainya
     * dinormalkan huruf kecil karena yang mengisi bisa HP (slug) maupun impor
     * lembar lama (label cetaknya).
     */
    private function dryblockSesi(CalibrationSession $sesi): ?string
    {
        // `spesifikasi_alat` boleh NULL di sesi lama (kolomnya nullable dan
        // baru ada sejak Agustus 2026), jadi jangan langsung di-indeks —
        // `null['dryblock']` cuma warning, tapi warning itu muncul di tiap
        // pemeriksaan sesi dan menenggelamkan log yang beneran perlu dibaca.
        $spesifikasi = $sesi->spesifikasi_alat;
        $nilai = is_array($spesifikasi) ? ($spesifikasi['dryblock'] ?? null) : null;

        // Kolom `alat_bantu` (`A`/`B`) jadi cadangan: itu ejaan yang dipakai
        // lembar Thermocouple, dan APK yang sudah menyeragamkan dua lembar suhu
        // ini mengirimnya lewat situ.
        if (! is_string($nilai) || trim($nilai) === '') {
            $nilai = $sesi->getAttribute('alat_bantu');
        }

        if (! is_string($nilai)) {
            return null;
        }

        $bersih = strtolower(trim($nilai));

        if ($bersih === 'a' || $bersih === 'b') {
            return $bersih === 'a' ? 'isotech' : 'techne';
        }

        foreach (self::DRYBLOCK as $d) {
            if ($bersih === $d['nilai'] || str_contains($bersih, $d['nilai'])) {
                return $d['nilai'];
            }
        }

        return null;
    }

    /**
     * Daftar No. Termokopel yang sah, digrup per TIPE SENSOR.
     *
     * Gabungan kedua keluarga standar, dan itu disengaja: keluarga standar baru
     * ketahuan waktu teknisi mencentang barisnya di blok `Standard used`,
     * sementara bentuk lembar kerja dikirim sebelum itu. Nomor yang nggak punya
     * tabel di keluarga yang akhirnya dipilih (mis. RTD 17 di recorder) ditahan
     * `TidsCalculator::hitungTitik()` dengan alasan yang kebaca — bukan
     * disembunyikan dari dropdown, karena teknisi yang nggak melihat nomornya
     * akan mengira alatnya salah, bukan pasangannya.
     *
     * `grup` = nama tipe sensornya. Layar HP menyaring daftar ini pakai kunci
     * itu; aturannya ("Type N mulai dari 3") sengaja TIDAK ditulis ulang di HP.
     *
     * @return list<array{nilai: string, label: string, grup: string}>
     */
    private function pilihanSensor(): array
    {
        $pilihan = [];

        foreach (TabelStandarTids::TIPE_SENSOR as $tipe) {
            $nomor = [];

            foreach (TabelStandarTids::KELUARGA as $keluarga) {
                $nomor = [...$nomor, ...$this->tabel->nomorSensorTersedia($keluarga, $tipe)];
            }

            $nomor = array_values(array_unique($nomor));
            sort($nomor);

            foreach ($nomor as $n) {
                $pilihan[] = [
                    'nilai' => (string) $n,
                    'label' => sprintf('%d — %s', $n, $tipe === 'RTD' ? 'PRT PT100' : $tipe.' No. '.$n),
                    'grup' => $tipe,
                ];
            }
        }

        return $pilihan;
    }

    /**
     * Semua titik ditahan dengan satu alasan yang sama.
     *
     * @param  list<array<string, mixed>>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    private function semuaDitahan(array $titik, string $alasan): array
    {
        return [
            'hitungan' => [],
            'belum_dihitung' => array_map(
                static fn (array $t): array => ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $alasan],
                $titik,
            ),
        ];
    }

    /**
     * Empat hal yang tanpanya hitungan BUKAN sekadar kurang teliti — dia
     * mengarang. Balik `null` kalau semuanya ada.
     */
    private function syaratKurang(
        ?string $keluarga,
        ?string $tipeSensor,
        ?string $dryblock,
        ?Standard $standar,
        Equipment $alat,
    ): ?string {
        if ($standar === null) {
            return 'Sesi ini belum menunjuk standar mana pun. Koreksi meter, koreksi sensor, U95 & drift-nya '
                .'semua dibaca dari sertifikat standar yang dipakai — tanpa itu nggak ada satu angka pun yang '
                .'bisa dihitung.';
        }

        if ($keluarga === null) {
            return sprintf(
                'Standar "%s" belum dikenali sebagai salah satu dari tiga keluarga yang punya tabel TIDS '
                .'(Temperature Recorder Graptech GL840 · Constant 40T · Yokogawa CA 150). Pastikan barisnya '
                .'dicentang di blok "Standard used" lembar kerja ini.',
                $standar->nama ?? '(tanpa nama)',
            );
        }

        if ($tipeSensor === null) {
            return 'Tipe sensor STANDAR belum dipilih. Koreksi meter, koreksi sensor, U95 sertifikat & '
                .'drift-nya semua dibaca per tipe (Type K / Type N / RTD PT100) — tanpa itu nggak ada baris '
                .'tabel yang bisa dibuka.';
        }

        if ($dryblock === null) {
            return 'Dryblock belum dicentang (A Isotech / B Techne). Keseragaman & stabilitas media kalibrasi '
                .'dua-duanya komponen budget, dan angkanya beda jauh antar blok (0,47 lawan 0,1 °C · 0,0005 '
                .'lawan 0,03 °C).';
        }

        if (! is_numeric($alat->resolusi) || (float) $alat->resolusi <= 0.0) {
            return 'Resolusi alat belum keisi di master alat. Komponen `Readability UUT` budget-nya lahir dari '
                .'situ (resolusi ÷ 2 ÷ √3), jadi nol berarti komponennya hilang tanpa jejak.';
        }

        return null;
    }

    /**
     * Baris CMC yang mencakup SET POINT TERTINGGI sesi ini.
     *
     * `AC41` master: `IF(U22<=150, S5, IF(U22<=400, S6, IF(U22<=600, S7, "cek
     * rentang")))` — dan `U22` itu `MAX` set point UUT, bukan titik per baris.
     * Jadi satu titik panas menaikkan lantai CMC seluruh sesi; arah konservatif
     * yang memang dipilih master.
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
            // `IF(U22<=150, S5, IF(AND(U22>151,U22<=400), S6, …))`, yang
            // menyerahkan batas atas ke pita di bawahnya. Aturan & alasan yang
            // sama persis dengan `ThermocoupleProfile::kemampuanUntukTitik()`.
            //
            // Rantai `IF` master sendiri BOLONG di (150, 151] dan (400, 401]:
            // set point 150,5 °C nggak masuk cabang mana pun dan sel-nya
            // memulangkan teks "cek rentang". Yang itu TIDAK ditiru — pita
            // ter-seed nggak punya celah, jadi 150,5 dapat 1,4 °C. Meniru
            // lubangnya berarti menahan sesi yang sah gara-gara batas pita yang
            // ditulis dua kali dengan angka beda.
            ->orderBy('range_max')
            ->first();
    }

    /**
     * Baris `uncertainty_calculations` sesi ini.
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
        // `GumCalculator::hitungDariBudget()` supaya dua jalur ini nggak berbeda
        // arti untuk kolom yang sama.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] !== 't-student',
            ),
        )));

        $typeA = 0.0;

        foreach ($hasil['budget'] as $k) {
            if ($k['disertakan'] && $k['distribusi'] === 't-student') {
                $typeA = (float) $k['u'];
            }
        }

        $sekarang = Carbon::now();
        $audit = $this->jejakAudit($hasil, $kemampuan);

        return array_map(static fn (array $t): array => [
            'standard_id' => $standar?->id,
            'titik_ke' => $t['titik_ke'],
            // `titik_ukur` menyimpan nilai STANDAR TERKOREKSI, bukan set point.
            // Itu kolom yang dicetak sertifikat sebagai `Standard Reading`
            // (`CertificateSnapshotBuilder`: `standard_value = titik_ukur`), dan
            // aturannya sama untuk TITS, Enclosure & ketiga alat suhu pasangan.
            // Set point mentahnya tetap hidup di `raw_measurements.titik_ukur`.
            'titik_ukur' => $t['standar_terkoreksi'],
            'rata_rata' => $t['uut_terkoreksi'],
            'error' => $t['uut_terkoreksi'] - $t['standar_terkoreksi'],
            // `SERTIFIKAT!L20 = E20−J20` — standar dikurangi UUT.
            'koreksi' => $t['koreksi'],
            'standar_deviasi' => $t['standar_deviasi_uut'],
            'jumlah_pengulangan' => count($t['pembacaan_uut']),
            // Keterulangan TIDS DIPAKAI (beda dari Thermocouple, yang
            // menghitungnya lalu membuangnya): `N30 = 'PERHITUNGAN FC'!M23`,
            // komponen ke-7 budget. Angkanya sama untuk semua titik karena
            // yang masuk cuma STDEV terbesar sesi ini.
            'type_a' => $typeA,
            'type_b_components' => $audit,
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
     * Baris `type_b_components`: komponen budget + tiap penyimpangan master yang
     * ditiru, berikut konteks sesinya.
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
                'STDEV standar terbesar %s °C (masuk budget), STDEV UUT terbesar %s °C (nggak dipakai master). '
                .'Set point tertinggi %s °C, index tabel %s °C, rentang uji titik es %s °C. CMC %s °C, U95 '
                .'dilaporkan dari %s.',
                $this->angka($hasil['standar_deviasi_maks']),
                $this->angka($hasil['standar_deviasi_maks_uut']),
                $this->angka($hasil['set_point_maks']),
                $hasil['index_maks'] === null ? '-' : $this->angka((float) $hasil['index_maks']),
                $this->angka($hasil['rentang_titik_es']),
                $this->angka($hasil['cmc']),
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
     * Keluarga tabel standar dari baris `standards` yang dipakai sesi.
     *
     * Dicocokkan lewat SERIAL dulu, baru merk. Serial yang menang bukan
     * kerapian: recorder yang sama tercatat "Graptech" di kertas TIDS dan
     * "Graphtech" di master `standards`, jadi pencocokan lewat merk saja
     * memulangkan `null` untuk standar yang jelas-jelas terdaftar — dan `null`
     * di situ berarti seluruh sesinya ditahan.
     */
    private function keluargaStandar(?Standard $standar): ?string
    {
        if ($standar === null) {
            return null;
        }

        $serial = strtoupper(trim((string) $standar->serial_number));

        foreach (TabelStandarTids::KELUARGA_SERTIFIKAT as $kode => $sertifikat) {
            if ($serial !== '' && $serial === strtoupper($sertifikat['serial'])) {
                return $kode;
            }
        }

        $teks = strtolower(trim((string) $standar->merk).' '.trim((string) $standar->nama));

        foreach ([
            'recorder' => ['recorder', 'graptech', 'graphtech', 'gl840'],
            'yokogawa' => ['yokogawa'],
            'constant' => ['constant'],
        ] as $kode => $kunci) {
            foreach ($kunci as $k) {
                if (str_contains($teks, $k)) {
                    return $kode;
                }
            }
        }

        return null;
    }

    /**
     * Terima ejaan kertas (`Thermocouple Type-K`, `Sensor RTD/PT 100`), ejaan
     * master (`Thermocouple Type K`, `PRT PT100`) maupun ejaan repo (`Type K`,
     * `RTD`) — ketiganya beredar di dokumen lab yang sama.
     */
    private function normalkanTipeSensor(mixed $tipe): ?string
    {
        if (! is_string($tipe) || trim($tipe) === '') {
            return null;
        }

        $rapi = trim((string) preg_replace('/\s+/', ' ', $tipe));

        foreach (self::TIPE_SENSOR_TERCETAK as $cetak => $kanonik) {
            if (strcasecmp($cetak, $rapi) === 0) {
                return $kanonik;
            }
        }

        $rapi = str_ireplace(
            ['PRT PT100', 'PT 100', 'PT100', 'Thermocouple ', 'Sensor ', 'Type-'],
            ['RTD', 'RTD', 'RTD', '', '', 'Type '],
            $rapi,
        );
        $rapi = trim((string) preg_replace('#^RTD/RTD$#i', 'RTD', trim($rapi)));

        foreach (TabelStandarTids::TIPE_SENSOR as $sah) {
            if (strcasecmp($sah, $rapi) === 0) {
                return $sah;
            }
        }

        return null;
    }

    /**
     * Kode dryblock yang dimengerti [TidsCalculator] (`A`/`B`) dari apa pun yang
     * dikirim HP: slug lembar ini (`isotech`/`techne`), kolom `alat_bantu`
     * lembar Thermocouple (`A`/`B`), atau label cetaknya (`A (Isotech)`).
     */
    private function kodeDryblock(mixed $nilai): ?string
    {
        if (! is_string($nilai) || trim($nilai) === '') {
            return null;
        }

        $bersih = strtolower(trim($nilai));

        if ($bersih === 'a' || str_contains($bersih, 'isotech')) {
            return 'A';
        }

        if ($bersih === 'b' || str_contains($bersih, 'techne')) {
            return 'B';
        }

        return null;
    }

    /**
     * Dua pembacaan uji titik es yang tercatat di sesi — kolom `titik_es`
     * (jalur pasangan) dengan cadangan `spesifikasi_alat.titik_es_awal/akhir`
     * (bentuk lembar TIDS sejak sebelum jalur pasangan ada).
     *
     * @return list<float>
     */
    private function titikEsSesi(CalibrationSession $sesi): array
    {
        $spesifikasi = $sesi->spesifikasi_alat;

        return $this->pasanganTitikEs(
            (array) ($sesi->getAttribute('titik_es') ?? []),
            is_array($spesifikasi) ? $spesifikasi : [],
        );
    }

    /**
     * Dua angka uji titik es dari mana pun datangnya.
     *
     * @param  array<int|string, mixed>  $daftar
     * @param  array<string, mixed>  $spesifikasi
     * @return list<float>
     */
    private function pasanganTitikEs(array $daftar, array $spesifikasi): array
    {
        $angka = array_values(array_filter($daftar, 'is_numeric'));

        if (count($angka) >= 2) {
            return array_map('floatval', $angka);
        }

        $pasangan = array_values(array_filter(
            [$spesifikasi['titik_es_awal'] ?? null, $spesifikasi['titik_es_akhir'] ?? null],
            'is_numeric',
        ));

        return count($pasangan) >= 2 ? array_map('floatval', $pasangan) : [];
    }

    /**
     * Tipe sensor standar yang tercatat di sesi — kolom `tipe_sensor` dengan
     * cadangan `spesifikasi_alat.sensor_standar` (ejaan kertas, dari APK lama).
     */
    private function tipeSensorSesi(CalibrationSession $sesi): ?string
    {
        $dariKolom = $this->normalkanTipeSensor($this->atributSesi($sesi, 'tipe_sensor'));

        if ($dariKolom !== null) {
            return $dariKolom;
        }

        $spesifikasi = $sesi->spesifikasi_alat;

        return $this->normalkanTipeSensor(
            is_array($spesifikasi) ? ($spesifikasi['sensor_standar'] ?? null) : null,
        );
    }

    /**
     * Ambil nilai atribut sesi, apa pun nama kolomnya menyimpan. Salinan sengaja
     * dari [ProfilSuhuPasangan::atributSesi] — profil ini bukan turunannya.
     */
    private function atributSesi(CalibrationSession $sesi, string $kunci): mixed
    {
        $langsung = $sesi->getAttribute($kunci);

        if ($langsung !== null && $langsung !== '') {
            return $langsung;
        }

        $tambahan = $sesi->getAttribute('atribut_tambahan');

        return is_array($tambahan) ? ($tambahan[$kunci] ?? null) : null;
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 6, ',', '.'), '0'), ',');
    }

    /**
     * Satu baris CMC TIDS mana pun dari master, cuma buat menjawab "barisnya
     * ada apa nggak".
     *
     * Sengaja TIDAK memilih baris per rentang — yang memilih pita
     * [kemampuanUntukTitik], dan yang di sini cuma menjawab pertanyaan
     * "master-nya ter-seed apa belum" buat peringatan sesi.
     *
     * Tapi DISARING per organisasi, dan itu wajib biarpun yang dicari cuma
     * "ada apa nggak". Tanpa saringan, `first()` menyisir seluruh
     * `calibration_capabilities` lintas lab: lab yang belum punya baris TIDS
     * sendiri TIDAK akan diperingatkan selama ada lab lain yang punya, dan
     * lembar kerjanya lolos tanpa satu pun tanda bahwa CMC-nya kosong.
     * Peringatan yang mendiamkan diri karena data lab sebelah itu lebih buruk
     * daripada tidak ada peringatan sama sekali.
     */
    private function kemampuanTids(?Equipment $equipment = null): ?CalibrationCapability
    {
        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->when(
                $equipment?->organization_id !== null,
                fn ($q) => $q->milikOrganisasi($equipment->organization_id),
            )
            ->first();
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
        ?array $tampilKalau = null,
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
            'tampil_kalau' => $tampilKalau,
        ];
    }
}
