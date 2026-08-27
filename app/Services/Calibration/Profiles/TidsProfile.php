<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;

/**
 * Profil TIDS — **Temperatur Indikator dengan Sensor** (alat ke-17). Metode
 * `SIDIK-IK-CAL-0503_Rev.6`, lembar kerja `SIDIK-FM-CAL-0506 Rev.4`
 * (`LK-285-IDN`, "Calibration Work Sheet - Temperature Indikator With Sensors").
 *
 * ## TAHAP 1 SAJA: bentuk lembar kerja + jalur simpan. BUKAN budget.
 *
 * Ini yang paling penting dibaca sebelum menambah apa pun ke file ini. Enam
 * belas profil sebelumnya lahir dengan cara yang sama: workbook master lab
 * dibuka, direproduksi sampai digit terakhir, baru budget-nya ditulis. TIDS
 * TIDAK punya workbook itu. Empat workbook yang dikirim ke repo
 * (`Master Olah Data_Suhu_TITS fungsi Measure/Source`, `… Enclosure Recorder`,
 * `… Enclosure Constant Yokogawa`) semuanya TITS & Enclosure.
 *
 * Yang ADA cuma potongan: `database/data/tids-cache-master.json` — 3.452 sel
 * lima master TIDS yang terselamatkan dari cache tautan luar keempat workbook
 * itu (`docs/tids-dari-cache-tautan-luar.md`). Itu angka lab sendiri, bukan
 * turunan, dan sudah cukup untuk beberapa hal. Yang TIDAK cukup justru
 * bagiannya yang dibutuhkan di sini: cache tautan luar cuma menyimpan sel yang
 * benar-benar DITARIK workbook pemanggilnya, dan keempat sheet yang menyusun
 * budget nol sel semua —
 *
 *   `PERHITUNGAN U95%` · `Variasi axial Dryblok A` · `Variasi axial Dryblok B`
 *   · `stdev drywell`
 *
 * Tanpa keempatnya, tiap angka di budget TIDS cuma bisa dikarang atau
 * dianalogikan dari TITS — dan analogi TITS itu bukan sekadar kurang teliti:
 * dia terbit di sertifikat terakreditasi, kelihatan sah, dan yang menemukannya
 * asesor. Jadi [komponenBudget] balik `null` dan [hitungPerGrup] MEMBLOKIR
 * seluruh titik dengan alasan yang kebaca — bukan diam-diam jatuh ke jalur CMC
 * generik yang akan memulangkan U95 yang kelihatan wajar. Uraian lengkapnya di
 * [hitungPerGrup].
 *
 * ## Apa yang masih harus diminta ke lab
 *
 *  1. **Workbook olah data TIDS aslinya**, khusus keempat sheet di atas. Dia
 *     yang menentukan komponen apa saja yang masuk, pembaginya, derajat
 *     kebebasannya, dan apakah U95-nya lahir per titik atau sekali untuk
 *     seluruh sesi (TITS sekali, Spectrophotometer per kelompok — dua alat di
 *     repo ini saja sudah beda).
 *  2. **Tabel koreksi dryblock A & B.** Lembar kerjanya menyuruh teknisi
 *     mencentang `A (Isotech)` atau `B (Techne)`, dan struktur workbook yang
 *     ke-cache mengonfirmasi dua dryblock itu memang punya sheet variasi
 *     aksial sendiri-sendiri — dua-duanya nol sel. Kata "Isotech" dan "Techne"
 *     belum pernah muncul di repo ini di luar file ini.
 *  3. **Aturan uji titik es 0 °C.** Lembarnya minta pembacaan Awal & Akhir di
 *     titik es, tapi tidak menyebut apa yang dilakukan terhadap dua angka itu:
 *     jadi komponen budget (drift sensor selama sesi), jadi syarat lolos, atau
 *     cuma catatan. Tiga tafsir, tiga U95 yang berbeda. Cache-nya memang punya
 *     117 sel sheet `FC` dari `Melting Point_TIDS_Limas.xlsx`, tapi itu uji
 *     titik LELEH — barang lain, dan menyamakannya dengan titik es cuma karena
 *     dua-duanya "titik perubahan fasa" persis jenis lompatan yang bikin angka
 *     salah kelihatan beralasan.
 *  4. **CMC mana yang berlaku.** Master TIDS 2022 yang ke-cache menulis 1,5 °C
 *     rata (1,6 khusus Type S); lampiran akreditasi LK-285-IDN menulis 0,86 /
 *     1,4 / 3,1 °C per pita, dan itu yang sudah ter-seed di
 *     `calibration_capabilities`. Yang menentukan lantai U95 cuma boleh satu.
 *     Yang dipakai di sini lampiran akreditasi — itu dokumen yang mengikat lab
 *     — tapi selisihnya bukan pembulatan, jadi pertanyaannya tetap terbuka.
 *
 * Sampai keempatnya jelas, yang benar adalah lembar kerjanya jalan dan
 * angkanya TIDAK terbit. Itu keadaan yang jujur, bukan pekerjaan setengah
 * jadi.
 *
 * ## Lima UUT sekaligus — sumbu yang belum punya rumah di skema
 *
 * Tidak ada padanannya di 16 profil lain. Satu lembar TIDS mengalibrasi LIMA
 * alat sekaligus di dryblock yang sama, dan pembacaannya diambil selang-seling
 * tiap 10 detik dalam satu sapuan 90 detik per set point:
 *
 *   0″ standar(UUT1) · 10″ UUT1 · 20″ standar(UUT2) · 30″ UUT2 · … · 90″ UUT5
 *
 * Jadi tiap UUT punya pembacaan standarnya SENDIRI, diambil 10 detik sebelum
 * pembacaan alatnya. Itu bukan lima salinan dari satu angka.
 *
 * Keputusan arsitekturnya — **1 sesi berisi 5 UUT** vs **5 sesi terpisah** —
 * BELUM diambil, dan sengaja tidak diambil di sini. Yang dikerjakan file ini
 * cuma menuturkan sumbu itu di [bentukLembarKerja] (kunci `sumbu_uut` +
 * `pengulangan_uut`), supaya layar HP bisa menggambar tabelnya sekarang tanpa
 * mengunci keputusan yang belum diambil. `calibration_sessions`,
 * `uncertainty_calculations`, dan `certificates` TIDAK disentuh.
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
 * @see docs/tids-dari-cache-tautan-luar.md — apa yang terselamatkan & apa yang nggak
 * @see database/data/tids-cache-master.json — 3.452 sel master TIDS 2022
 * @see docs/permintaan-user-7.md — K1 (5 UUT) & K2 (workbook TIDS)
 * @see TitsProfile — saudaranya, "tanpa sensor", yang punya oracle lengkap
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
     * Lima UUT dalam satu lembar. Bukan lima pengulangan — lima ALAT.
     *
     * Dibedakan tegas dari `jumlah_pengulangan` di profil lain karena artinya
     * berlawanan: mengecilkan kolom pengulangan cuma mengurangi berapa kali
     * satu alat dibaca, sementara mengecilkan angka ini berarti ada alat
     * pelanggan yang hilang dari lembar tanpa jejak.
     */
    public const JUMLAH_UUT = 5;

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
        ],
        [
            'label' => 'Temperature Calibrator/Yokogawa/CA150 Handy Cal/23P1005',
            'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005'],
        ],
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

    public function kode(): string
    {
        return 'tids';
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
     * SENGAJA `null` — belum ada satu komponen pun yang bisa dipertanggung-
     * jawabkan. Lihat docblock kelas & [hitungPerGrup].
     *
     * Balik `null` di sini SAJA tidak cukup untuk menahan angka karangan:
     * `GumCalculator::hitungTitik()` menganggap `null` sebagai "profil ini
     * belum punya budget khusus" lalu jatuh ke jalur CMC generik, yang tetap
     * memulangkan U95. Yang benar-benar menutup pintunya [hitungPerGrup].
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
     * Ambil alih perhitungan seluruh sesi — lalu TOLAK semuanya, dengan alasan.
     *
     * ## Kenapa hook ini di-override padahal tidak menghitung apa pun
     *
     * Ini satu-satunya cara menahan angka karangan dari dalam profil.
     * `CalibrationController::susunPengukuran()` memanggil `hitungPerGrup()`
     * lebih dulu; hanya kalau hasilnya `null` dia jatuh ke
     * `GumCalculator::hitungTitik()` per titik. Dan `hitungTitik()` TIDAK
     * butuh [komponenBudget] untuk memulangkan angka — kalau profilnya balik
     * `null`, dia memakai jalur CMC generik: `U95 = CMC` baris kemampuan yang
     * rentangnya memuat titik itu. Baris CMC TIDS sudah ter-seed (0,86 / 1,4 /
     * 3,1 °C), jadi jalur itu akan sukses, dan tiap sesi TIDS akan menerbitkan
     * U95 yang kelihatan sah.
     *
     * Kelihatan sah, tapi bukan hasil perhitungan: CMC itu ketidakpastian
     * TERBAIK yang bisa dicapai lab pada kondisi terbaik — lantai, bukan
     * jawaban. Sertifikat yang mencetaknya sebagai U95 sesi ini mengklaim
     * kondisi terbaik untuk sesi yang komponen budget-nya belum pernah
     * disusun. Itu temuan audit, dan yang menemukannya bukan kita.
     *
     * Jadi yang dipulangkan: `hitungan` KOSONG, dan tiap titik masuk
     * `belum_dihitung` dengan alasan yang kebaca teknisi. Sesinya tetap
     * TERSIMPAN — pengukuran mentahnya utuh di `raw_measurements`, lembar
     * kerjanya tetap bisa dikirim dari lapangan — cuma tidak ada satu baris
     * `uncertainty_calculations` yang lahir. Persis pola yang sudah dipakai
     * TITS waktu mode/tipe sensornya belum dipilih: menolak menghitung itu
     * jawaban, bukan kegagalan.
     *
     * Begitu ketiga bahan di docblock kelas ada, yang berubah cukup isi method
     * ini — bentuk lembar kerja, jalur simpan, registry, dan peringatan sesi
     * tidak perlu disentuh lagi.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        return [
            'hitungan' => [],
            'belum_dihitung' => array_map(
                static fn (array $t): array => [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Budget ketidakpastian TIDS belum ada, jadi titik ini sengaja NGGAK dihitung. '
                        .'Sheet PERHITUNGAN U95% & variasi aksial dryblock dari master TIDS belum ada di '
                        .'sistem (nol sel di cache tautan luar), dan tanpa itu komponen budget-nya cuma bisa '
                        .'dikarang — angka karangan yang terbit di sertifikat terakreditasi jauh lebih mahal '
                        .'daripada kolom yang jelas kosong. Pembacaannya tetap tersimpan utuh dan bakal '
                        .'kehitung begitu workbook-nya masuk; yang belum ada cuma rumusnya.',
                ],
                array_values($titik),
            ),
        ];
    }

    /**
     * Peringatan sesi — dipasang sejak awal, bukan ditambal belakangan.
     *
     * Empat hal, dan keempatnya jenis kesalahan yang tidak memunculkan error
     * di mana pun kalau lolos:
     *
     *  1. **Budget belum ada.** Selalu muncul, tanpa syarat, selama file ini
     *     belum punya rumus. Ini yang menahan tombol APPROVE supaya tidak ada
     *     sertifikat TIDS terbit tanpa ada manusia yang sadar U95-nya kosong.
     *  2. **Set point di luar rentang CMC (−20…600 °C).** Ini yang diminta
     *     dipasang dari awal, dan alasannya konkret: sensor acuan yang
     *     tercetak di lembar kerjanya sendiri (Thermocouple Type-K & Type-N)
     *     berlaku sampai 1000 °C di lampiran akreditasi, jauh di atas 600 °C
     *     tempat CMC TIDS berhenti. Artinya teknisi BISA memasang set point
     *     900 °C, alatnya sanggup, sensornya sanggup — dan yang tidak sanggup
     *     cuma klaim akreditasi lab, satu-satunya hal yang tidak kelihatan dari
     *     meja kerja.
     *  3. **Dryblock belum dicentang.** Koreksi Isotech dan Techne beda, jadi
     *     merk yang tidak tercatat berarti pembacaan mentahnya tidak akan bisa
     *     dikoreksi belakangan — bahkan sesudah workbook-nya datang. Ini
     *     peringatan yang menyelamatkan data, bukan cuma kerapian.
     *  4. **Baris CMC hilang dari master.** Pola & pesan mengikuti
     *     `enclosure_cmc_kosong` di `EnclosureProfileBase`.
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
        $peringatan = [[
            'kode' => 'tids_budget_belum_ada',
            'pesan' => 'Sesi TIDS belum bisa menghasilkan angka ketidakpastian. Sheet PERHITUNGAN U95% & '
                .'variasi aksial dryblock dari master TIDS, tabel koreksi dryblock Isotech/Techne, dan aturan '
                .'uji titik es 0 °C belum ada satu pun di sistem, jadi budget-nya sengaja dikosongkan — bukan '
                .'gagal hitung. Lembar kerjanya tetap boleh disimpan; yang nggak boleh sertifikatnya terbit '
                .'seolah-olah U95-nya sudah dihitung.',
        ]];

        if ($this->dryblockSesi($sesi) === null) {
            $peringatan[] = [
                'kode' => 'tids_dryblock_kosong',
                'pesan' => 'Dryblock (A Isotech / B Techne) belum dicentang. Koreksi kedua dryblock beda, dan '
                    .'kalau merknya nggak kecatat sekarang, pembacaan sesi ini nggak bakal bisa dikoreksi '
                    .'belakangan — termasuk nanti waktu workbook TIDS-nya sudah masuk.',
            ];
        }

        if (! $sesi->exists) {
            return $peringatan;
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
            // Lima UUT, BUKAN lima pengulangan — lihat `sumbu_uut` di bawah.
            // Tetap dikirim karena aplikasi teknisi membacanya buat menentukan
            // lebar tabel, dan mengosongkannya bikin tabelnya digambar nol
            // kolom.
            'jumlah_pengulangan' => self::JUMLAH_UUT,
            'satuan' => self::SATUAN,
            'satuan_suhu' => self::SATUAN,
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Satu lembar TIDS mengalibrasi LIMA alat sekaligus di dryblock yang sama. '
                .'Tiap set point dibaca selang-seling per 10 detik: 0" standar UUT1, 10" UUT1, 20" standar '
                .'UUT2, 30" UUT2, dan seterusnya sampai 90" UUT5. Kolom yang belum bisa diisi di lapangan '
                .'boleh dikosongin. DRYBLOCK (A Isotech / B Techne) wajib dicentang — koreksinya beda per '
                .'merk, dan tanpa itu pembacaannya nggak bisa dikoreksi belakangan.',
            // Penanda JUJUR buat layar: alat ini belum menerbitkan U95.
            //
            // Dikirim sebagai data, bukan cuma ditulis di komentar, supaya HP
            // bisa menampilkan keadaannya ke teknisi SEBELUM dia mengisi 70
            // kotak angka — bukan sesudah, waktu tombol hitungnya tidak
            // memulangkan apa-apa dan kelihatan seperti bug.
            'budget_ketidakpastian' => [
                'tersedia' => false,
                'alasan' => 'Workbook olah data TIDS dari lab belum lengkap. Yang ada di sistem baru '
                    .'potongan dari cache tautan luar, dan justru empat sheet yang menyusun budget '
                    .'(PERHITUNGAN U95%, Variasi axial Dryblok A & B, stdev drywell) nol sel semua. Tanpa '
                    .'itu komponen budget-nya cuma bisa dikarang atau dianalogikan dari TITS, dan angka '
                    .'seperti itu di sertifikat terakreditasi adalah temuan audit. Pembacaan tetap tersimpan '
                    .'dan bakal kehitung begitu workbook-nya masuk.',
                'butuh' => [
                    'Workbook olah data TIDS asli — sheet PERHITUNGAN U95%, Variasi axial Dryblok A & B, stdev drywell',
                    'Tabel koreksi dryblock A (Isotech) & B (Techne)',
                    'Aturan uji titik es 0 °C — jadi komponen budget, syarat lolos, atau cuma catatan',
                    'Kepastian CMC: master 2022 nulis 1,5 °C rata (1,6 Type S), lampiran akreditasi 0,86/1,4/3,1 °C',
                ],
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
     * Sumbu LIMA UUT — kunci BARU, bukan mengubah tipe kunci lama.
     *
     * Alasannya sama persis dengan `judul_nilai_per_mode` & `pengulangan_arah`
     * milik TITS: aplikasi teknisi yang sudah terpasang membaca
     * `jumlah_pengulangan` sebagai int dan `pengulangan` sebagai daftar angka.
     * Menaruh keterangan UUT di dua kunci itu bikin lembarnya gagal kebuka
     * (`as int` melempar `TypeError`) atau kebuka tanpa satu pun kolom
     * pembacaan (`whereType<num>()` membuang objeknya diam-diam). Yang baru
     * masuk di kunci sendiri; yang lama tetap bentuknya.
     *
     * `jumlah` di sini yang OTORITATIF, bukan `jumlah_pengulangan` di atas.
     * `CalibrationProfile::setelKolomPengulangan()` menulis ulang tiap kunci
     * bernama `jumlah_pengulangan`/`pengulangan`, jadi permintaan
     * `?pengulangan=3` akan mengecilkan lembar ini jadi tiga kolom — dan untuk
     * alat lain itu benar (mengurangi berapa kali satu alat dibaca), sementara
     * di sini artinya DUA ALAT PELANGGAN hilang dari lembar. `jumlah` tidak
     * kena penulisan ulang itu karena namanya beda, jadi layar yang menggambar
     * dari sini selalu dapat lima.
     *
     * @return array<string, mixed>
     */
    private function sumbuUut(): array
    {
        return [
            'jumlah' => self::JUMLAH_UUT,
            'daftar' => array_map(
                static fn (int $i): array => [
                    'kode' => 'uut_'.$i,
                    'nomor' => $i,
                    'label' => 'UUT'.$i,
                    'detik_standard' => self::DETIK_STANDARD[$i - 1],
                    'detik_uut' => self::DETIK_UUT[$i - 1],
                ],
                range(1, self::JUMLAH_UUT),
            ),
            // Keputusan yang BELUM diambil, ditulis di tempat yang kebaca
            // orang berikutnya alih-alih di riwayat percakapan.
            'keputusan_skema' => 'belum_diambil',
            'catatan' => 'Lembar ini menuturkan lima UUT, tapi `calibration_sessions` masih satu sesi = satu '
                .'alat. Keputusan "1 sesi 5 UUT" vs "5 sesi terpisah" belum diambil, jadi Tahap 1 sengaja '
                .'berhenti di bentuknya. Perhatikan juga: kertasnya sendiri cuma punya SATU blok identitas '
                .'alat (Nama Alat / Merk / Type / No. Seri) untuk lima kolom UUT, jadi formulir resminya pun '
                .'belum bisa mencatat lima nomor seri. Itu pertanyaan buat lab, bukan lubang yang boleh '
                .'ditambal dengan menebak lima kolom identitas yang nggak pernah dicetak.',
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
                $this->field('spesifikasi_alat.sensor_standar', 'Sensor Standard', 'pilihan', pilihan: array_map(
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
                $this->field('spesifikasi_alat.titik_es_awal', 'Awal', 'angka', satuan: self::SATUAN),
                $this->field('spesifikasi_alat.titik_es_akhir', 'Akhir', 'angka', satuan: self::SATUAN),
            ],
            'catatan' => 'Dua angka ini disimpan apa adanya. Aturan pemakaiannya (jadi komponen budget, '
                .'syarat lolos, atau cuma catatan) belum ada dari lab, jadi belum ada yang menghitungnya.',
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
                $this->tabelPembacaan(
                    tahap: 'pembacaan_standard',
                    judul: 'Pembacaan Standard',
                    detik: self::DETIK_STANDARD,
                    simpanKe: null,
                ),
                $this->tabelPembacaan(
                    tahap: 'pembacaan_uut',
                    judul: 'Pembacaan Alat yang Dikalibrasi',
                    detik: self::DETIK_UUT,
                    simpanKe: 'measurements[].pembacaan',
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
     * Isinya nomor UUT (1..5), bukan nomor pengulangan — tapi bentuknya wajib
     * tetap `list<int>` karena aplikasi teknisi menyaringnya
     * `whereType<num>()`. Keterangan lengkap tiap kolom (detik + label UUT)
     * menyusul di `pengulangan_uut`, kunci baru yang boleh diabaikan aplikasi
     * lama. Lihat `Tests\Feature\BentukLembarKerjaKompatibelTest`.
     *
     * @param  list<int>  $detik
     * @return array<string, mixed>
     */
    private function tabelPembacaan(string $tahap, string $judul, array $detik, ?string $simpanKe): array
    {
        return [
            'tahap' => $tahap,
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
            'pengulangan' => range(1, self::JUMLAH_UUT),
            'pengulangan_uut' => array_map(
                static fn (int $i): array => [
                    'ke' => $i,
                    'uut' => 'UUT'.$i,
                    'detik' => $detik[$i - 1],
                    // Persis seperti tercetak di kepala kolomnya: `0" (UUT1)`.
                    'label' => sprintf('%d" (UUT%d)', $detik[$i - 1], $i),
                ],
                range(1, self::JUMLAH_UUT),
            ),
            // Ke mana kolom tabel ini disimpan hari ini — `null` = BELUM ada
            // tempatnya.
            //
            // Ditulis eksplisit, bukan dibiarkan tersirat, karena tabel
            // Pembacaan Standard adalah deret angka yang benar-benar diambil
            // teknisi di lapangan dan hari ini TIDAK ADA kolom yang
            // menampungnya: `raw_measurements.tahap` artinya as-found /
            // as-left (sebelum/sesudah adjustment), bukan standar/UUT, dan
            // `CalibrationController` memaksa nilainya sendiri. Layar HP wajib
            // membaca kunci ini sebelum menyalakan tombol kirim untuk tabel
            // ini — kalau tidak, teknisi mengisi 35 kotak yang hilang tanpa
            // pesan apa pun.
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

        if (! is_string($nilai)) {
            return null;
        }

        $bersih = strtolower(trim($nilai));

        foreach (self::DRYBLOCK as $d) {
            if ($bersih === $d['nilai'] || str_contains($bersih, $d['nilai'])) {
                return $d['nilai'];
            }
        }

        return null;
    }

    /**
     * Satu baris CMC TIDS mana pun dari master, cuma buat menjawab "barisnya
     * ada apa nggak".
     *
     * Sengaja TIDAK memilih baris per rentang: ketiga pita TIDS (−20…150,
     * 150…400, 400…600) dipilih berdasarkan set point, dan pemilihan itu
     * bagian dari perhitungan yang belum ada. Menaruh logikanya sekarang
     * berarti menulis separuh jalur budget yang tidak ada yang bisa
     * memverifikasinya.
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
