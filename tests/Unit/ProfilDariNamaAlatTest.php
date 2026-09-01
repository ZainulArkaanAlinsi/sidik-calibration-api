<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga `CalibrationProfileRegistry::kodeProfilDariNama()` — jalur yang
 * ngasih tau HP lembar kerja mana yang harus dibuka buat satu nama alat.
 *
 * Kenapa test ini ada: sampai sekarang tabel penerjemahnya (26 ejaan → 16 kode
 * profil) HARDCODED di APK. Konsekuensinya diam: admin nambah nama alat baru
 * di server, dan alat itu mustahil dapat lembar yang bener sampai ada rilis
 * mobile — nggak ada error, nggak ada log, teknisi cuma dapat form generik.
 * Sekarang tabelnya di backend, dan yang dijaga di sini dua hal yang sama
 * diamnya kalau bolong:
 *
 *  1. **Ada profil yang nggak kecapai satu nama pun.** Profil terdaftar tapi
 *     ejaannya nggak nyambung ke `nama_alat` mana pun = lembar kerjanya nggak
 *     akan pernah kebuka dari picker. Kodenya sehat, testnya hijau, alatnya
 *     nggak ada.
 *  2. **Alat generik dikasih lembar orang lain.** `untukNamaAlat()` yang lama
 *     JATUH KE pH kalau nggak ketemu; kalau jalur ini ikut-ikutan, Buret bakal
 *     dikirim ke HP sebagai `ph_meter` dan teknisi ngisi lembar pH buat buret.
 *     null harus beneran null.
 *
 * @see CalibrationProfileRegistry::kodeProfilDariNama()
 */
class ProfilDariNamaAlatTest extends TestCase
{
    private CalibrationProfileRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new CalibrationProfileRegistry;
    }

    /**
     * Profil yang namanya NGGAK ada di lampiran akreditasi, berikut nama yang
     * beneran dipakai di data: kode profil => nama_alat baris kemampuannya.
     *
     * Gas Detector dikalibrasi lab ini tapi CMC-nya nol — dia nggak punya baris
     * di `kemampuan-kalibrasi.json`, barisnya lahir dari
     * `GasDetectorCapabilitySeeder` (`nama_alat` = "Gas Detector"). Tetap harus
     * kecapai: kartunya muncul di picker lewat `GET /api/categories/{kode}`
     * yang baca tabel `calibration_capabilities`, bukan baca JSON-nya.
     *
     * @var array<string, string>
     */
    private const LUAR_LAMPIRAN = [
        'gas_detector' => 'Gas Detector',
    ];

    /**
     * Tiap profil terdaftar harus kecapai lewat minimal satu nama alat yang
     * beneran ada di data — bukan lewat ejaan yang cuma hidup di kepala orang
     * yang nulis profilnya.
     */
    public function test_tiap_profil_kecapai_dari_nama_alat_yang_beneran_ada(): void
    {
        $namaData = [...$this->namaAlatLampiran(), ...array_values(self::LUAR_LAMPIRAN)];

        $kecapai = [];
        foreach ($namaData as $nama) {
            $kode = $this->registry->kodeProfilDariNama($nama);

            if ($kode !== null) {
                $kecapai[$kode][] = $nama;
            }
        }

        $tanpaNama = [];
        foreach ($this->registry->semua() as $profil) {
            if (! isset($kecapai[$profil->kode()])) {
                $tanpaNama[] = $profil->kode().' ('.$profil->namaAlatKemampuan().')';
            }
        }

        $this->assertSame([], $tanpaNama, implode("\n", [
            'Profil ini nggak kecapai dari satu nama alat pun, jadi lembar kerjanya',
            'nggak akan pernah kebuka dari picker HP. Betulin `namaAlatKemampuan()`',
            'atau tambah ejaannya ke `aliasNama()` profil yang bersangkutan: ',
            ...$tanpaNama,
        ]));
    }

    /**
     * [LUAR_LAMPIRAN] cuma boleh dihuni profil yang emang nggak punya baris di
     * lampiran. Begitu alatnya masuk daftar akreditasi, barisnya di sini harus
     * dicabut — kalau dibiarin, daftar pengecualian pelan-pelan jadi tempat
     * numpuk yang nutupin profil yang beneran bolong.
     */
    public function test_daftar_pengecualian_nggak_numpuk(): void
    {
        $dariLampiran = [];
        foreach ($this->namaAlatLampiran() as $nama) {
            $kode = $this->registry->kodeProfilDariNama($nama);

            if ($kode !== null) {
                $dariLampiran[$kode] = true;
            }
        }

        foreach (array_keys(self::LUAR_LAMPIRAN) as $kode) {
            $this->assertArrayNotHasKey(
                $kode,
                $dariLampiran,
                "Profil '{$kode}' udah kecapai dari lampiran akreditasi — cabut dari LUAR_LAMPIRAN.",
            );
        }
    }

    /**
     * Nama alat yang emang nggak punya lembar khusus HARUS balik null, bukan
     * jatuh ke profil mana pun.
     *
     * Isinya sengaja diambil dari lampiran akreditasi juga — ini alat yang
     * beneran dikalibrasi lab ini, cuma belum (atau nggak akan) punya lembar
     * kerja sendiri.
     *
     * "Temperatur Indikator dengan Sensor" DULU ada di daftar ini dan sekarang
     * dicabut: alat itu punya `TidsProfile` sejak profil ke-17 ditambahkan.
     * Yang dijaga sekarang pindah ke [test_tids_dapat_lembarnya_sendiri] —
     * bukan "harus null" lagi, melainkan "harus `tids`, dan jangan sampai
     * `tits` atau `ph_meter`". Dua alat itu cuma beda satu kata ("dengan" vs
     * "tanpa") dan pencocokannya nerima kunci yang nempel di tengah nama, jadi
     * bahayanya nggak hilang — cuma ganti bentuk.
     *
     * Yang paling rawan tersisa: "TITS", singkatan yang SENGAJA nggak
     * didaftarin. Empat huruf terlalu pendek buat dicocokin di tengah nama.
     *
     * @return array<string, array{string}>
     */
    public static function namaGenerik(): array
    {
        return [
            'TITS (singkatan, sengaja nggak didaftarin)' => ['TITS'],
            'TIDS (singkatan, sengaja nggak didaftarin)' => ['TIDS'],
            // Termometer Gelas, Thermohygrometer & Thermocouple PINDAH dari
            // sini 26 Agt 2026: ketiganya sekarang punya lembar kerjanya
            // sendiri (alat ke-18..20), dari tiga workbook master yang turun
            // dari lab. Yang menjaga arah sebaliknya —
            // `test_tiga_alat_suhu_dapat_lembarnya_sendiri` di bawah.
            'Buret Digital' => ['Buret Digital'],
            'Gelas Ukur' => ['Gelas Ukur'],
            'Picnometer' => ['Picnometer'],
            'Hydrometer' => ['Hydrometer'],
            'Micrometer' => ['Micrometer'],
            'Dial Indicator' => ['Dial Indicator'],
            'Flow Meter Cairan (Totalizer)' => ['Flow Meter Cairan (Totalizer)'],
            // Timbangan PINDAH dari sini 31 Agt 2026: sekarang punya lembar
            // kerjanya sendiri (alat ke-21, kelompok Massa), dari tiga workbook
            // master yang turun dari lab. Yang menjaga arah sebaliknya —
            // `test_timbangan_dapat_lembarnya_sendiri` di bawah.
            'Pressure Gauge' => ['Pressure Gauge'],
            // `Timer/Stopwatch` PINDAH dari sini 1 Sep 2026, bareng
            // `Centrifuge` & `Infrared Tachometer` yang memang belum pernah
            // ada di daftar ini: ketiganya sekarang punya lembar kerjanya
            // sendiri (alat ke-22..24, kelompok "Waktu dan Frekuensi"), dari
            // tiga workbook master yang turun dari lab. Yang menjaga arah
            // sebaliknya — `test_alat_waktu_frekuensi_dapat_lembarnya_sendiri`.
            'kosong' => [''],
            'spasi doang' => ['   '],
        ];
    }

    /**
     * Arah sebaliknya buat alat ke-22..24: nama yang HARUS mendarat di ketiga
     * profil kelompok "Waktu dan Frekuensi".
     *
     * Yang paling rawan `Stopwatch` — `INPUT DATA!E10` master menulisnya
     * begitu, tanpa satu pun kata "Timer" di dalamnya. Tanpa alias, alat itu
     * jatuh ke form generik dan seluruh mesin hitung waktunya nggak pernah
     * kepanggil.
     *
     * Tiga nama diadu supaya jelas TETAP bukan milik kelompok ini:
     * `Thermohygrometer` (memuat "meter", bukan alat waktu), `Flow Meter
     * Cairan (Flowrate)` (satuannya Lpm — per MENIT, tapi besarannya aliran),
     * dan `Dial Indicator` (panjang).
     *
     * @return array<string, array{string, string|null}>
     */
    public static function namaWaktuFrekuensi(): array
    {
        return [
            'lampiran akreditasi no. 37' => ['Timer/Stopwatch', 'timer_stopwatch'],
            'nama sesi contoh master' => ['Stopwatch', 'timer_stopwatch'],
            'stopwatch bermerk' => ['Stopwatch Casio HS-80TW', 'timer_stopwatch'],
            'timer saja' => ['Timer', 'timer_stopwatch'],
            'lampiran akreditasi no. 38' => ['Centrifuge', 'centrifuge'],
            'centrifuge bermerk' => ['Centrifuge Hettich EBA 200', 'centrifuge'],
            'lampiran akreditasi no. 39' => ['Infrared Tachometer', 'tachometer'],
            'tachometer pendek' => ['Tachometer', 'tachometer'],
            'tachometer digital' => ['Digital Tachometer', 'tachometer'],
            'suhu, BUKAN waktu' => ['Thermohygrometer', 'thermohygro'],
            'aliran per menit, BUKAN waktu' => ['Flow Meter Cairan (Flowrate)', null],
            'panjang, BUKAN waktu' => ['Dial Indicator', null],
        ];
    }

    #[DataProvider('namaWaktuFrekuensi')]
    public function test_alat_waktu_frekuensi_dapat_lembarnya_sendiri(string $nama, ?string $harap): void
    {
        $this->assertSame(
            $harap,
            $this->registry->kodeProfilDariNama($nama),
            "'{$nama}' mendarat di profil yang salah.",
        );
    }

    /**
     * Arah sebaliknya buat alat ke-21: nama yang HARUS mendarat di `timbangan`.
     *
     * Yang paling rawan `Moisture Analyzer` — sesi contoh master gram
     * (`019-CAL-425`, Mettler Toledo HB53) namanya persis itu, dan tidak ada
     * satu pun kata "timbangan" di dalamnya. Tanpa alias, alat itu jatuh ke
     * form generik dan seluruh mesin hitung massanya nggak pernah kepanggil.
     *
     * `Hydrometer` ikut diadu DI SINI supaya jelas dia tetap BUKAN timbangan:
     * dia alat densitas, dan kesalahan sekeluarga persis pernah nyaris lolos
     * waktu dia didaftarkan sebagai alias Thermohygro (§11).
     *
     * @return array<string, array{string, string|null}>
     */
    public static function namaTimbangan(): array
    {
        return [
            'nama lampiran akreditasi' => ['Timbangan (Elektronik, mekanik)', 'timbangan'],
            'nama pendek' => ['Timbangan', 'timbangan'],
            'analitik' => ['Timbangan Analitik Ohaus PA224', 'timbangan'],
            'neraca' => ['Neraca Analitik', 'timbangan'],
            'balance' => ['Precision Balance', 'timbangan'],
            'sesi contoh master gram' => ['Moisture Analyzer', 'timbangan'],
            'densitas, BUKAN timbangan' => ['Hydrometer', null],
        ];
    }

    #[DataProvider('namaTimbangan')]
    public function test_timbangan_dapat_lembarnya_sendiri(string $nama, ?string $harap): void
    {
        $this->assertSame(
            $harap,
            $this->registry->kodeProfilDariNama($nama),
            "'{$nama}' mendarat di profil yang salah.",
        );
    }

    #[DataProvider('namaGenerik')]
    public function test_nama_alat_generik_balik_null(string $nama): void
    {
        $this->assertNull(
            $this->registry->kodeProfilDariNama($nama),
            "'{$nama}' nggak punya lembar khusus — harusnya null, bukan profil yang kebetulan nempel.",
        );
    }

    /**
     * TIDS dapat lembarnya SENDIRI — bukan lembar TITS, bukan lembar pH.
     *
     * Tiga kegagalan yang dijaga sekaligus, dan ketiganya diam:
     *
     *  1. **Jatuh ke pH.** `untukNamaAlat()` fallback-nya profil default, jadi
     *     satu huruf meleset di `namaAlatKemampuan()` bikin teknisi dapat
     *     lembar buffer 4/7/10 buat indikator suhu — tanpa error di mana pun.
     *  2. **Ketuker sama TITS.** Dua nama itu cuma beda satu kata ("dengan" vs
     *     "tanpa") dan `kodeProfilDariNama()` nerima kunci yang nempel di
     *     TENGAH nama. Kalau salah satu profil bikin alias yang kelewat pendek,
     *     yang ketuker bukan judul lembarnya doang — TITS mengalibrasi
     *     indikator tanpa sensor pakai kalibrator sebagai sensor tiruan, TIDS
     *     mengalibrasi lima alat bersensor sekaligus di dryblock.
     *  3. **Ejaan lampiran yang meleset.** Yang mengikat "Temperatur" (bukan
     *     "Temperature") dan "dengan" huruf kecil — persis seperti tertulis di
     *     `database/data/kemampuan-kalibrasi.json`.
     */
    public function test_tids_dapat_lembarnya_sendiri(): void
    {
        $nama = 'Temperatur Indikator dengan Sensor';

        $this->assertSame('tids', $this->registry->kodeProfilDariNama($nama));
        $this->assertSame('tids', $this->registry->untukNamaAlat($nama)->kode());

        // Saudaranya tetap di lembarnya sendiri — dijaga di baris yang sama
        // supaya yang menukarnya ketahuan di satu test, bukan dua.
        $this->assertSame('tits', $this->registry->kodeProfilDariNama('Temperature Indicator tanpa Sensor'));
    }

    /**
     * Salinan BEKU tabel `_profilKhusus` yang dulu nangkring di APK
     * (`instrument_picker_screen.dart`). Jawaban server wajib sama persis
     * dengan jawaban APK lama — kalau nggak, rilis mobile berikutnya
     * diam-diam mindahin alat ke lembar yang beda dari yang dipakai teknisi
     * kemarin.
     *
     * Ini pin regresi, BUKAN sumber kebenaran: yang dipakai kode produksi
     * `aliasNama()` di tiap profil. Hilangnya satu baris di sini = alias yang
     * kecabut waktu dipindah ke backend.
     *
     * @return array<string, array{string, string}> [nama, kode_harap]
     */
    public static function ejaanLamaApk(): array
    {
        $peta = [
            'ph meter' => 'ph_meter',
            'turbidimeter' => 'turbidimeter',
            'chlorin meter' => 'chlorine_meter',
            'chlorine meter' => 'chlorine_meter',
            'refractometer' => 'refractometer',
            'conductivity meter' => 'conductivity_meter',
            'conductivitymeter' => 'conductivity_meter',
            'spectrophotometer' => 'spectrophotometer',
            'spektrofotometer' => 'spectrophotometer',
            'spectrofotometer' => 'spectrophotometer',
            'viscometer' => 'viscometer',
            'do meter' => 'do_meter',
            'dometer' => 'do_meter',
            'gas detector' => 'gas_detector',
            'multi gas detector' => 'gas_detector',
            'gasdetector' => 'gas_detector',
            'autoklaf' => 'autoclave',
            'autoclave' => 'autoclave',
            'temperature indicator tanpa sensor' => 'tits',
            'temperature indikator tanpa sensor' => 'tits',
            'oven' => 'oven',
            'furnace' => 'furnace',
            'bath' => 'bath',
            'inkubator' => 'inkubator',
            'incubator' => 'inkubator',
            'refrigerator' => 'refrigerator',
        ];

        $kasus = [];
        foreach ($peta as $nama => $kode) {
            $kasus[$nama] = [$nama, $kode];
        }

        return $kasus;
    }

    #[DataProvider('ejaanLamaApk')]
    public function test_semua_ejaan_apk_lama_masih_kecocokin(string $nama, string $kodeHarap): void
    {
        $this->assertSame($kodeHarap, $this->registry->kodeProfilDariNama($nama));
    }

    /**
     * Pencocokannya harus setara `profilLembarKerjaUntuk()` di HP: huruf
     * besar/kecil diabaikan, spasi beruntun dirapikan, dan kunci boleh nempel
     * di TENGAH nama.
     *
     * Yang terakhir itu bukan kemewahan — dua pemanggil di HP ngoper nama alat
     * PELANGGAN, bukan nama jenis alat: "Visible Spectrofotometer",
     * "Turbidimeter Hach", "pH Meter Mettler Toledo". Nggak satu pun cocok
     * persis, dan waktu cocoknya masih harus persis, semuanya jatuh ke pH.
     *
     * @return array<string, array{string, string}> [nama, kode_harap]
     */
    public static function namaAlatPelanggan(): array
    {
        return [
            'huruf besar semua' => ['TURBIDIMETER', 'turbidimeter'],
            'spasi dobel & pinggir' => ['  pH   Meter  ', 'ph_meter'],
            'merk nempel di belakang' => ['Turbidimeter Hach', 'turbidimeter'],
            'merk nempel di belakang (2 kata)' => ['pH Meter Mettler Toledo', 'ph_meter'],
            'kata sifat di depan' => ['Visible Spectrofotometer', 'spectrophotometer'],
            'jenis bath di depan' => ['Water Bath', 'bath'],
            'nama lembar kerja' => ['Multi Gas Detector', 'gas_detector'],
        ];
    }

    #[DataProvider('namaAlatPelanggan')]
    public function test_cocok_walau_nempel_di_tengah_nama(string $nama, string $kodeHarap): void
    {
        $this->assertSame($kodeHarap, $this->registry->kodeProfilDariNama($nama));
    }

    /**
     * Kunci terpanjang dicoba duluan.
     *
     * Namanya karangan — nggak ada alat "Refrigerator Bath" di lab — dan itu
     * memang gunanya: hari ini nggak ada satu pun nama nyata yang memuat dua
     * kunci milik profil BERBEDA, jadi urutannya nggak kelihatan dari data.
     * Yang dikunci di sini aturannya, bukan kasusnya: begitu nanti ada ejaan
     * yang jadi bagian dari ejaan lain (mis. alat ke-17 bernama "... Meter"),
     * nama panjang harus mendarat di kunci paling spesifik, bukan di kunci
     * pendek yang kebetulan disebut duluan di `daftarProfil()`.
     */
    public function test_kunci_terpanjang_menang(): void
    {
        $this->assertSame('refrigerator', $this->registry->kodeProfilDariNama('Refrigerator Bath'));
    }

    /**
     * Semua `nama_alat` di lampiran akreditasi LK-285-IDN (48 alat).
     *
     * @return list<string>
     */
    private function namaAlatLampiran(): array
    {
        $path = database_path('data/kemampuan-kalibrasi.json');

        /** @var array{kelompok_pengukuran: list<array{alat: list<array{nama_alat: string}>}>} $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $nama = [];
        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                $nama[] = $alat['nama_alat'];
            }
        }

        return $nama;
    }

    /**
     * Tiga alat suhu yang mendarat 26 Agt 2026 dapat lembarnya SENDIRI.
     *
     * Arah sebaliknya dari [test_nama_alat_generik_balik_null], dan sengaja
     * ditulis eksplisit: sebelum ini ketiganya memang jatuh ke jalur generik,
     * jadi kalau salah satu profilnya suatu saat dicabut, yang terjadi bukan
     * error melainkan alat terakreditasi yang diam-diam balik ke CMC generik
     * dengan U95 lebih kecil daripada yang diakui akreditasi.
     */
    public function test_tiga_alat_suhu_dapat_lembarnya_sendiri(): void
    {
        foreach ([
            'Thermocouple' => 'thermocouple',
            'Termometer Gelas' => 'thermometer_glass',
            'Thermohygrometer' => 'thermohygro',
        ] as $nama => $kode) {
            $this->assertSame(
                $kode,
                $this->registry->kodeProfilDariNama($nama),
                "'{$nama}' harusnya dapat lembar `{$kode}`, bukan jatuh ke jalur generik.",
            );
        }
    }
}
