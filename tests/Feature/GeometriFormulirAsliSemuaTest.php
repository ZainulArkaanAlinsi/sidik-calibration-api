<?php

namespace Tests\Feature;

use App\Http\Requests\WorksheetScanRequest;
use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\PistonVolumeProfile;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Services\Ocr\FormulirAsli;
use App\Services\Ocr\PemrosesScanLembarKerja;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga SEMUA peta formulir asli (`database/ocr-templates/asli/*.peta.json`)
 * — kontrak yang dipakai siapa pun yang memetakan formulir baru.
 * `docs/PANDUAN-OCR-LEMBAR-KERJA.md` §5 langkah 3–4.
 *
 * Menggantikan empat test kelompok (Kimia, Suhu, Mekanik, Dimensi; 12 Okt
 * 2026). Dua lapis:
 *
 * 1. **Kontrak umum** — tiap peta, lewat data provider (kegagalan menyebut
 *    petanya): peta ke PDF, ke draf, ke bentuk lembar profil, dan ke server
 *    (`FormulirAsli`). Peta baru otomatis ikut tersapu.
 * 2. **Keputusan per formulir** — tabel konstanta di kepala kelas: hash draf,
 *    sidik jari jumlah kunci, pengecualian yang sudah diputuskan, arah baca
 *    kertas, label kotak berlabel, nominal Micrometer. Mengubahnya = keputusan
 *    yang kelihatan di diff, bukan toleransi yang dilonggarkan diam-diam.
 *
 * `GeometriFormulirAsliPhTest` tetap berdiri untuk pemeriksaan khusus pH.
 *
 * Dua blok "tanpa nasib" yang sama-sama wajib beralasan, dan keduanya BUKAN
 * duplikat — arahnya berlawanan:
 *
 * - `abaikan` = kotak KERTAS yang tidak dipetakan ke kunci (kertas → profil).
 * - `tanpa_kotak` = kunci/kode PROFIL yang tidak punya kotak di kertas
 *   (profil → kertas). Opsional; peta yang memakainya wajib menutup SELURUH
 *   sisa kunci sel profil — [test_tanpa_kotak_menutup_sisa_lembar_profil].
 *   Peta tanpa blok itu dijaga sidik jari jumlah kunci ([SIDIK_JARI]).
 *
 * Satu peta = satu formulir untuk satu `template_id`. Kertas yang dipakai
 * beberapa profil (Enclosure 0504) = beberapa peta yang berbagi SATU draf.
 */
class GeometriFormulirAsliSemuaTest extends TestCase
{
    // Bentuk lembar profil menarik master `standards`.
    use RefreshDatabase;

    private const FOLDER_ASLI = 'database/ocr-templates/'.FormulirAsli::FOLDER;

    private const FOLDER_PDF = 'Project-PT-Sidik/worksheet_alat_calibration';

    /** Kosakata `cara` — PANDUAN §4. */
    private const CARA = [
        'isian' => ['angka', 'tanggal', 'banding', 'usulan'],
        'centang' => ['centang'],
    ];

    /**
     * `worksheet_scan_cells.field_id` varchar(30) — isian & centang tersimpan
     * dengan `field_id` = kodenya. Lebih panjang = SQLite diam, MySQL strict
     * menolak saat menyimpan pindai.
     */
    private const PANJANG_FIELD_ID = 30;

    /** Rute crop `worksheet-scans/{scan}/sel/{kunci}/crop`; kunci isian = `isian|{kode}`. */
    private const POLA_KODE_ISIAN = '/^[A-Za-z0-9_.\-]+$/';

    /** Kode dasar (segmen terakhir) isian kondisi lingkungan yang dibuka tahap 1. */
    private const LINGKUNGAN = ['suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir'];

    /**
     * `sumber_kotak` yang sah per bagian peta → jenis kotak draf tempat
     * titiknya dicari. Yang pertama = bawaan. Sama dengan `FormulirAsli`.
     */
    private const SUMBER_KOTAK = [
        'sel' => ['sel', 'isian'],
        'isian' => ['isian', 'sel'],
        'centang' => ['centang'],
    ];

    /** Dua sel bertetangga berbagi satu garis; lebih dari setengah poin = tumpang-tindih sungguhan. */
    private const TOLERANSI_PT = 0.5;

    /**
     * sha256 tiap draf. Draf = keluaran `docs/skrip/draf-geometri-dari-pdf.py`
     * APA ADANYA (§5 langkah 3) — diadu byte per byte ke keluaran skrip yang
     * dibangkitkan ulang 9 Okt 2026, 36/36 identik. Salah di draf = betulkan
     * skripnya, jalankan ulang, salin lagi, lalu perbarui hash ini.
     */
    private const SHA_DRAF = [
        'formulir-0504.draf.json' => '0473622af3fcd852012a278ba24912a607dabc962945b3b061b749559e5e7055',
        'formulir-0505.draf.json' => '34962baaaa1469c35f6dbd7f0aebab365e18b1dc1dc81143b5dcac33da9e83b2',
        'formulir-0506.draf.json' => '1980ed132ae5a384cf03ec36c364760be841e4624e2926abead2092ae6073caa',
        'formulir-0507.draf.json' => 'b3463bd4b842f6135c80efe0bc6ebb5b98c02aa3f536f6c4f9bacf19cac1e47f',
        'formulir-0508.draf.json' => '4c1e98d501824bc2255bd99bf5bd4886db9a46b8cfd64223f58e25da7cb0285e',
        'formulir-0508A.draf.json' => '9d8bc2f98a06c4ac8887fb93074d9f1e72f233d88bd1741eb31d36ef71fd2680',
        'formulir-0510.draf.json' => 'c8ab98a128240b86fee1710eaa2b68a5a817f20b19dba9ef2f301f9ca66a300f',
        'formulir-0511.draf.json' => 'fc90e36e2aca4dfa50d51f751646d7d33d9cf992432d437be4a26e9687741e22',
        'formulir-0512.draf.json' => '34878dd7ed8e27ef6616cbc458d0554f24e1d9ac837419ae8aebd0ebbef33b0f',
        'formulir-0513.draf.json' => '802649c6691dd6a12b805a2aac2e64f32a49370c22c378435384d7a5999932c3',
        'formulir-0514.draf.json' => '600968e1f3f634f03e3791ee5a7a347782e8ea73f7685ac7460862a2850be5f8',
        'formulir-0515.draf.json' => '9f7d4adefec7a85c8a75a2dec12634037c3a47ddac31272d8d6475051149b26e',
        'formulir-0519.draf.json' => 'fde2c2bd0e8c82ae40e414c26bd535d40210230ca2fcd2f3b5de361c1e71a077',
        'formulir-0520.draf.json' => 'da8c46acf1809ffba9d1a146ae0a1fb83361ce85590e6098476fef5d4cfe993b',
        'formulir-0521.draf.json' => 'b686125361bb66484d86d54650e9447dc0c4ce8e96607452a1ead86dfb38dabe',
        'formulir-0522A.draf.json' => '444e6a0220c4cc76139f967a366c0e21d6ffcc897c51b70d66484325d2a10da3',
        'formulir-0522B.draf.json' => '58e9003c816704f01d9cbf1f06992fea7d14410b9abbecc8b23337317e777e07',
        'formulir-0522C.draf.json' => '2521001d795d432777295ed71012c568b2d78b99b7b70d427229a25be521d524',
        'formulir-0522D.draf.json' => 'afaf1bb310dacae4a2f790e2e22bea1166adae93de89d9530cbe152ad03571e1',
        'formulir-0523.draf.json' => '195977bfb7422cb5e68502c18e53e209372dc72e79090d4c2fa894f498bf44bc',
        'formulir-0524.draf.json' => 'a70160eadc772da923eb4b52753d9307c8ec2ce5e7a4bb4ea48360db06e1b5db',
        'formulir-0525.draf.json' => 'aa2029451477f9df35c606b585bd09b0c376e65af3a71b2892a65f940f16dc7e',
        'formulir-0526.draf.json' => '67b1b68007a7be6e8dfd1c7efcaad089586de0de9d49b539605c6665d13bd0a9',
        'formulir-0527.draf.json' => '9bdb0695824785a3512f8baec00eebae359f4101a1699ad1a68a49b0cee60aeb',
        'formulir-0528.draf.json' => '15b0455d9602e0cfd523687e055f4e410ca1c2c0f698ddff69ca366203d53e03',
        'formulir-0529.draf.json' => '9ecc26d654305b5581634c158477583f1a1ecb143791dfc9551f1de4dce66fdc',
        'formulir-0530.draf.json' => '0663ce76bde75714dcea6bcdb4df515aedf14b148337ac385cd7348555aaff26',
        'formulir-0531.draf.json' => '7ec00e26ac9ddcfaafb5f456edfb01b62994dd1975f7b649c0f5fa878465d2aa',
        'formulir-0532.draf.json' => '6acb1a005925ed70f3bbc7e4c7446ee190bc3a9951fe2e319b7ce0e165a30219',
        'formulir-0533.draf.json' => '434f534fbf3aeff7924d33a2464e904e0483db02221068c7a1704e50670f6c4c',
        'formulir-0535.draf.json' => '4a76bf3a8f78bd1b5564ab9570891e446e1e5db5c45ce0c994b0d1fc6172db59',
        'formulir-0536.draf.json' => '3ef7085edd75d3bc536f2e7b79a79d05740e1e729a3a9ecdec47b5bc176acbe6',
        'formulir-0537.draf.json' => '6490bc33b261db28f339e917dbd73e5b13ff9ad76b548fc6b13d99da22b2c003',
        'formulir-0538.draf.json' => 'd7508cc141dd6a08ff264a527dda81e4df4bea1002fd3c7e7705d05638124025',
        'formulir-0538A.draf.json' => 'fe10abc1959c19e33ac5f44169a0b00fa2d20b3d72eccb7792f91162659e54e3',
        'formulir-0538B.draf.json' => 'e60e8bbf1bb25f2f75b7d157381a5ec2676678e3b92f10468be12a96a510f27a',
        'formulir-0539.draf.json' => '8de7be9e568d468e9ad281b1c6305e92797ced233ebf471e1ef05ef7392e018d',
        'formulir-0541.draf.json' => 'feaa39f4791b8b16991a7c3bc7751f38b79ee70c5dfebff759b4d72bbc3eabe6',
        'ph_meter-0509.draf.json' => '067b5797bd322f45dd4b12e70f74bc6c9143468f8b618a139a929ac201b1d485',
    ];

    /**
     * Sidik jari tiap peta: `[kunci sel lembar profil untuk varian ini, sel
     * terpetakan, isian tahap 1, centang]`. Kolom pertama yang paling penting:
     * profil berubah bentuk (kunci bertambah/hilang) → merah → petanya ditinjau
     * ulang. Tanpa ini, kunci profil BARU yang tercetak di kertas tidak pernah
     * dibaca, dan tidak ada yang tahu — irisan `FormulirAsli` diam saja.
     *
     * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    private const SIDIK_JARI = [
        'anak_timbangan-0541.peta.json' => [120, 120, 0, 2],
        'autoclave-0539.peta.json' => [40, 25, 4, 5],
        'bath-0504.peta.json' => [55, 55, 2, 8],
        'buret-0514.peta.json' => [45, 30, 4, 3],
        'buret_digital-0528.peta.json' => [39, 13, 4, 5],
        'buret_digital-0529.peta.json' => [39, 33, 4, 5],
        'centrifuge-0515.peta.json' => [90, 15, 4, 4],
        'chlorine_meter-0531.peta.json' => [40, 40, 4, 4],
        'conductivity_meter-0510.peta.json' => [80, 60, 4, 9],
        'dial_indicator-0526.peta.json' => [100, 100, 2, 0],
        'differential_pressure-0507.peta.json' => [84, 66, 2, 4],
        'dispensett-0528.peta.json' => [39, 13, 4, 5],
        'dispensett-0529.peta.json' => [39, 33, 4, 5],
        'do_meter-0532.peta.json' => [20, 20, 3, 4],
        'flowmeter_flowrate-0538.peta.json' => [90, 33, 4, 0],
        'flowmeter_flowrate-0538A.peta.json' => [90, 54, 4, 0],
        'flowmeter_totalizer-0538.peta.json' => [63, 6, 4, 0],
        'flowmeter_totalizer-0538B.peta.json' => [63, 36, 4, 0],
        'furnace-0504.peta.json' => [55, 55, 2, 8],
        'gelas_ukur-0514.peta.json' => [45, 30, 4, 3],
        'hydrometer-0533.peta.json' => [33, 33, 4, 2],
        'inkubator-0504.peta.json' => [55, 55, 2, 8],
        'jangka_sorong-0527.peta.json' => [216, 170, 2, 0],
        'labu_ukur-0513.peta.json' => [12, 6, 4, 3],
        'load_cell-0520.peta.json' => [186, 180, 4, 7],
        'micrometer-0522A.peta.json' => [65, 65, 2, 0],
        'micrometer-0522B.peta.json' => [65, 65, 2, 0],
        'micrometer-0522C.peta.json' => [65, 65, 2, 0],
        'micrometer-0522D.peta.json' => [65, 65, 2, 0],
        'oven-0504.peta.json' => [55, 55, 2, 8],
        'ph_meter-0509.peta.json' => [60, 60, 4, 9],
        'picnometer-0513.peta.json' => [9, 6, 4, 4],
        'pipet_ukur-0514.peta.json' => [45, 30, 4, 3],
        'pipet_volume-0513.peta.json' => [12, 6, 4, 3],
        'piston_pipette-0528.peta.json' => [39, 13, 4, 5],
        'piston_pipette-0529.peta.json' => [39, 33, 4, 5],
        'pressure_gauge-0507.peta.json' => [84, 66, 2, 4],
        'proving_ring-0521.peta.json' => [96, 90, 4, 7],
        'refractometer-0523.peta.json' => [40, 40, 4, 4],
        'refrigerator-0504.peta.json' => [55, 55, 2, 8],
        'sieve-0536.peta.json' => [96, 96, 4, 2],
        'spectrophotometer-0511.peta.json' => [87, 69, 4, 7],
        'thermocouple-0535.peta.json' => [60, 60, 0, 8],
        'thermohygro-0525.peta.json' => [100, 100, 0, 0],
        'thermometer_glass-0537.peta.json' => [50, 50, 0, 0],
        'tids-0506.peta.json' => [70, 70, 2, 11],
        'timbangan-0508.peta.json' => [80, 70, 4, 10],
        'timer_stopwatch-0512.peta.json' => [240, 48, 4, 4],
        'timbangan-0508A.peta.json' => [80, 80, 4, 10],
        'tits-0505.peta.json' => [108, 54, 4, 11],
        'turbidimeter-0530.peta.json' => [60, 20, 4, 4],
        'tachometer-0515.peta.json' => [90, 15, 4, 4],
        'utm-0519.peta.json' => [174, 120, 4, 7],
        'viscometer-0524.peta.json' => [100, 60, 4, 16],
        'vacuum_gauge-0507.peta.json' => [84, 66, 2, 4],
    ];

    /**
     * Atribut alat bawaan yang COCOK dengan kertas, untuk profil yang kunci
     * selnya ikut atribut alat tapi nomor formulirnya tidak. Kertas 0512
     * mencetak kolom "0.01 S" = kunci `sentidetik`, yang hanya ada di template
     * alat beresolusi >= 0,01 s (`TimerStopwatchProfile::kolomPecahanDetik`).
     * Alat milidetik tidak cocok kertas ini; irisan FormulirAsli membuang
     * kolom itu untuknya.
     *
     * @var array<string, array<string, mixed>>
     */
    private const ALAT_SESUAI_KERTAS = [
        'timer_stopwatch' => ['resolusi' => 0.01],
    ];

    /**
     * Alat semu untuk profil yang NOMOR FORMULIRNYA ikut alat. Tanpa ini peta
     * varian selain bawaan (Timbangan substitusi, Micrometer 25–100 mm) tidak
     * punya konfigurasi pembanding. Tambah baris di sini kalau profil baru
     * memilih formulir dari atribut alat.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private const VARIAN_ALAT = [
        // `VarianMasterTimbangan::bawaanUntuk()`: > 200 kg = substitusi.
        'timbangan' => [['range_max' => 50, 'satuan' => 'kg'], ['range_max' => 500, 'satuan' => 'kg']],
        // `TabelStandarMicrometer::pitaCmc()`: 0522.A–D per kapasitas mm.
        'micrometer' => [['range_max' => 25], ['range_max' => 50], ['range_max' => 75], ['range_max' => 100]],
    ];

    /**
     * Formulir varian PER SESI yang belum disebut profilnya sendiri (tidak ada
     * di `kode_dokumen` maupun `kode_dokumen_varian` bentuk lembar). Piston
     * Volume: keluarga One Mark (FM-0528) dipilih teknisi lewat
     * `spesifikasi_alat.piston.keluarga`, tapi bentuk lembarnya cuma menyebut
     * 0529. Daftar ini UTANG profil — begitu profil menyebutnya di
     * `kode_dokumen_varian` (pola Flowmeter), barisnya dihapus.
     *
     * @var array<class-string, list<string>>
     */
    private const VARIAN_SESI_BELUM_DI_PROFIL = [
        PistonVolumeProfile::class => ['SIDIK-FM-CAL-0528_Rev.3'],
    ];

    /**
     * Profil yang belum menyebut nomor formulirnya untuk konfigurasi mana pun —
     * kode petanya cuma bisa diadu ke kertas (draf & nama PDF). Begitu profil
     * menyebutnya, test ini merah sampai barisnya dihapus.
     *
     * @var array<string, string>
     */
    private const PROFIL_BELUM_MENYEBUT_KODE = [];

    /**
     * Peta yang kuncinya diturunkan dari jumlah pengulangan KERTAS, bukan
     * bawaan profil. Anak Timbangan: PDF 0541 Rev.0 mencetak X1 X2 X3, profil
     * bawaan 1 (`AnakTimbanganMentah::PENGULANGAN_KERTAS`: kertas lapangan 5 Okt
     * cuma satu kotak; pertanyaan lab §23). Pindai tanpa `jumlah_pengulangan` 3
     * hanya membaca X1.
     *
     * @var array<string, int>
     */
    private const PENGULANGAN_KERTAS = [
        'anak_timbangan-0541.peta.json' => 3,
    ];

    /**
     * Nama berkas PDF yang revisinya beda dari yang TERCETAK di kertas.
     * Thermohygro: nama berkas Rev.3, kertasnya mencetak `Revise : 2` = profil.
     *
     * @var array<string, string>
     */
    private const PDF_REVISI_LAIN = [
        'thermohygro-0525.peta.json' => 'SIDIK-FM-CAL-0525_Rev.3',
    ];

    /**
     * Kotak centang TH-n yang `label_dekat`-nya di draf salah baca — dicatat
     * di peta lewat `label_draf`, draf tidak disunting. Refractometer 0523:
     * kotak ke-3 Insitu terbaca 'TH-6' padahal tercetak TH-7.
     *
     * @var array<string, array<string, string>>
     */
    private const LABEL_DRAF_KELIRU = [
        'refractometer-0523.peta.json' => ['TH-7' => 'TH-6'],
        'viscometer-0524.peta.json' => ['TH-7' => 'TH-6'],
    ];

    /**
     * Irisan yang memang bentuk kertasnya, bukan cacat skrip — PANDUAN §8:
     * 0508.A, satu kotak centang tercetak menonjol 1 pt dari selnya (irisan
     * satu-satunya di 41 formulir). Pasangan persis, bukan toleransi.
     *
     * @var array<string, list<string>>
     */
    private const IRISAN_BENTUK_KERTAS = [
        'formulir-0508A.draf.json' => ['c02|s007'],
    ];

    /**
     * Sel tabel yang kotaknya KOTAK BERLABEL di draf (`sumber_kotak: "isian"`):
     * label tercetaknya wajib sama dengan peran kunci itu. Timbangan Accuracy:
     * pembacaan ke-1..4 titik n = z{n}, m{n}, m{n}', z{n}'. Tertukar = angka m
     * terbaca sebagai z tanpa satu error pun.
     *
     * @var array<string, array<string, array<int, array{0: string, 1: string}>>>
     */
    private const LABEL_SEL_BERLABEL = [
        'timbangan' => ['akurasi' => [1 => ['z', ''], 2 => ['m', ''], 3 => ['m', "'"], 4 => ['z', "'"]]],
    ];

    /** Kapasitas Micrometer yang memilih varian kertas 0522.A–D. */
    private const KAPASITAS_MICROMETER = [
        'micrometer-0522A.peta.json' => 25.0,
        'micrometer-0522B.peta.json' => 50.0,
        'micrometer-0522C.peta.json' => 75.0,
        'micrometer-0522D.peta.json' => 100.0,
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $json = [];

    // ------------------------------------------------------------------
    // Seluruh folder
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function semuaPetaProvider(): array
    {
        $hasil = [];

        foreach (glob(dirname(__DIR__, 2).'/'.self::FOLDER_ASLI.'/*.peta.json') ?: [] as $berkas) {
            $hasil[basename($berkas)] = [basename($berkas)];
        }

        return $hasil;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function semuaDrafProvider(): array
    {
        $hasil = [];

        foreach (glob(dirname(__DIR__, 2).'/'.self::FOLDER_ASLI.'/*.draf.json') ?: [] as $berkas) {
            $hasil[basename($berkas)] = [basename($berkas)];
        }

        return $hasil;
    }

    /**
     * Peta yang memakai blok `tanpa_kotak`.
     *
     * @return array<string, array{0: string}>
     */
    public static function petaBertanpaKotakProvider(): array
    {
        return array_filter(self::semuaPetaProvider(), static function (array $baris): bool {
            $peta = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.self::FOLDER_ASLI.'/'.$baris[0]), true);

            return is_array($peta) && array_key_exists('tanpa_kotak', $peta);
        });
    }

    /**
     * Peta yang template-nya punya aturan arah baca kertas ([arahKertas]).
     *
     * @return array<string, array{0: string}>
     */
    public static function petaBerarahProvider(): array
    {
        return array_filter(self::semuaPetaProvider(), static function (array $baris): bool {
            $peta = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.self::FOLDER_ASLI.'/'.$baris[0]), true);

            return is_array($peta) && isset(self::arahKertas()[$peta['template_id'] ?? '']);
        });
    }

    /**
     * Draf tidak disunting tangan, tiap draf dipatok, dan tiap draf dipakai
     * minimal satu peta — draf yatim = formulir yang dikira sudah dipetakan.
     */
    public function test_draf_dipatok_apa_adanya_dan_dipakai(): void
    {
        $draf = array_keys(self::semuaDrafProvider());

        $this->assertEqualsCanonicalizing(array_keys(self::SHA_DRAF), $draf, 'Daftar draf berubah — patok hash draf baru (atau hapus yang sudah tidak ada) di SHA_DRAF.');

        foreach (self::SHA_DRAF as $nama => $sha) {
            $this->assertSame($sha, hash_file('sha256', $this->folder().'/'.$nama), "{$nama} berubah dari keluaran skrip — jangan disunting tangan; jalankan ulang skripnya.");
        }

        $dipakai = array_unique(array_map(fn (string $p): string => (string) $this->peta($p)['draf'], array_keys(self::semuaPetaProvider())));
        $this->assertSame([], array_values(array_diff($draf, $dipakai)), 'Draf tanpa peta.');
    }

    /**
     * Satu (template, kode dokumen) = satu peta. Dua peta untuk formulir yang
     * sama tidak dipilih salah satunya oleh server — lembarnya jadi tidak
     * bisa dipindai sama sekali.
     */
    public function test_satu_peta_per_template_dan_kode_dokumen(): void
    {
        $pasangan = [];

        foreach (array_keys(self::semuaPetaProvider()) as $nama) {
            $peta = $this->peta($nama);
            $kunci = $peta['template_id'].' '.$this->bakukan((string) $peta['kode_dokumen']);
            $this->assertArrayNotHasKey($kunci, $pasangan, "{$nama} dan ".($pasangan[$kunci] ?? '?').' memetakan formulir yang sama.');
            $pasangan[$kunci] = $nama;
        }
    }

    /** Pemuat jalur cetak membaca `{kode}-v{n}.json`; folder asli tidak boleh memuat pola itu. */
    public function test_folder_asli_tidak_memuat_berkas_berpola_v(): void
    {
        $this->assertSame([], glob($this->folder().'/*-v*.json') ?: []);
    }

    /**
     * Sidik jari dipatok untuk SETIAP peta, dan cocok. Pesan gagalnya memuat
     * baris PHP siap tempel — tapi menempelnya tanpa meninjau peta = membuang
     * penjaganya.
     */
    public function test_sidik_jari_tiap_peta_dipatok(): void
    {
        $beda = [];

        foreach (array_keys(self::semuaPetaProvider()) as $nama) {
            $peta = $this->peta($nama);
            $nyata = [
                count($this->templateVarian($nama)['sel']),
                count($peta['sel']),
                count(array_filter($peta['isian'], static fn (array $e): bool => ($e['tahap'] ?? null) === FormulirAsli::TAHAP_DIBUKA)),
                count($peta['centang']),
            ];

            if ((self::SIDIK_JARI[$nama] ?? null) !== $nyata) {
                $beda[] = "'{$nama}' => [".implode(', ', $nyata).'], // dipatok: '.json_encode(self::SIDIK_JARI[$nama] ?? null);
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys(self::SIDIK_JARI), array_keys(self::semuaPetaProvider()))), 'SIDIK_JARI memuat peta yang sudah tidak ada.');
        $this->assertSame([], $beda, "Sidik jari peta berubah — profil atau peta bergeser; tinjau petanya:\n".implode("\n", $beda));
    }

    /**
     * Tabel pengecualian tidak boleh basi — baris yang menunjuk peta/draf yang
     * sudah tidak ada adalah pengecualian yang tidak menjaga apa-apa.
     */
    public function test_tabel_pengecualian_menunjuk_berkas_yang_ada(): void
    {
        $peta = array_keys(self::semuaPetaProvider());

        foreach ([self::PENGULANGAN_KERTAS, self::PDF_REVISI_LAIN, self::LABEL_DRAF_KELIRU, self::KAPASITAS_MICROMETER] as $tabel) {
            $this->assertSame([], array_values(array_diff(array_keys($tabel), $peta)));
        }

        $this->assertSame([], array_values(array_diff(array_keys(self::IRISAN_BENTUK_KERTAS), array_keys(self::semuaDrafProvider()))));
    }

    // ------------------------------------------------------------------
    // Per peta — berkas, PDF, kode dokumen
    // ------------------------------------------------------------------

    #[DataProvider('semuaPetaProvider')]
    public function test_berkas_berpola(string $nama): void
    {
        $peta = $this->peta($nama);
        $id = (string) ($peta['template_id'] ?? '');

        $this->assertContains($id, app(TemplateLembarKerja::class)->kodeTersedia(), "template_id `{$id}` bukan profil terdaftar.");
        // Pemuat mencari `{template_id}-*.peta.json`; nama lain tidak pernah termuat.
        $this->assertStringStartsWith($id.'-', $nama, "nama berkas wajib diawali `{$id}-`.");
        $this->assertSame('ternormal_0_1', $peta['koordinat'] ?? null);
        $this->assertIsString($peta['kode_dokumen'] ?? null, 'kode_dokumen wajib.');
        $this->assertIsString($peta['sumber_sha256'] ?? null, 'sumber_sha256 wajib.');
        $this->assertIsString($peta['draf'] ?? null, 'draf wajib.');
        $this->assertSame(basename((string) $peta['draf']), $peta['draf'], 'draf cuma nama berkas, tanpa folder.');
        $this->assertFileExists($this->folder().'/'.$peta['draf']);
        $this->assertGreaterThan(40, mb_strlen(trim((string) ($peta['_catatan'] ?? ''))), 'Keputusan pemetaan wajib tertulis di `_catatan`.');

        foreach (['sel', 'isian', 'centang', 'abaikan'] as $kunci) {
            $this->assertIsList($peta[$kunci] ?? null, "`{$kunci}` wajib list (boleh kosong).");
        }
    }

    /**
     * PDF berubah → sha beda → merah → geometri diulang. Draf & peta wajib
     * menunjuk PDF yang sama, dan nama PDF-nya revisi formulir yang dipetakan.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_draf_dan_peta_menunjuk_pdf_yang_sama(string $nama): void
    {
        $peta = $this->peta($nama);
        $draf = $this->draf($peta);
        $pdf = base_path(self::FOLDER_PDF.'/'.basename((string) ($draf['sumber']['pdf'] ?? '')));

        $this->assertFileExists($pdf, 'PDF sumber tidak ada di '.self::FOLDER_PDF.'.');
        $this->assertSame(hash_file('sha256', $pdf), $draf['sumber']['sha256'] ?? null, 'Draf lahir dari PDF lain — jalankan ulang skripnya.');
        $this->assertSame($draf['sumber']['sha256'], $peta['sumber_sha256'], 'Peta ditulis untuk PDF lain.');
        // Nama berkas lab kadang menulis `-Rev.` (0538): yang diadu nomor & revisinya.
        $this->assertStringStartsWith(
            (self::PDF_REVISI_LAIN[$nama] ?? $peta['kode_dokumen']).' ',
            (string) preg_replace('/-Rev\./', '_Rev.', basename($pdf), 1),
            'PDF sumber bukan revisi formulir yang dipetakan.',
        );
    }

    /**
     * Kode dokumen peta = kode dokumen profil untuk salah satu varian, dan
     * sama dengan yang TERCETAK di draf. Server memilih peta lewat kode ini
     * (`FormulirAsli`), dan HP mencocokkan kode tercetak (`kode_dokumen`
     * draf) — kalau keduanya beda formulir, yang dipindai bukan yang dipetakan.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_kode_dokumen_peta_milik_profil_dan_tercetak_di_draf(string $nama): void
    {
        $peta = $this->peta($nama);
        $id = (string) $peta['template_id'];
        $kode = $this->bakukan((string) $peta['kode_dokumen']);
        $varian = $this->varianKodeDokumen($id);

        if (array_key_exists($id, self::PROFIL_BELUM_MENYEBUT_KODE)) {
            $this->assertSame([], $varian, "Profil {$id} kini menyebut kode dokumen — hapus barisnya dari PROFIL_BELUM_MENYEBUT_KODE.");
        } else {
            $this->assertContains($kode, array_keys($varian), "kode_dokumen `{$peta['kode_dokumen']}` bukan kode formulir profil `{$id}` (yang dikenal: ".implode(', ', array_keys($varian)).').');
        }

        $draf = $this->draf($peta);
        $tercetak = $this->bakukan((string) ($draf['kode_dokumen'] ?? ''));

        $this->assertNotSame('', $tercetak, 'draf tidak membaca kode dokumen tercetak.');
        $this->assertTrue(
            $kode === $tercetak || str_starts_with($kode, $tercetak.'_REV.'),
            "kode peta `{$peta['kode_dokumen']}` bukan formulir yang tercetak di draf (`{$draf['kode_dokumen']}`).",
        );

        // Revisi tercetak yang terbaca wajib sama dengan revisi di kode. Skrip
        // kadang tidak menangkap "Revise : n" tapi menangkap kotak `Rev` di
        // kaki halaman (Autoklaf) — yang itu juga diadu.
        $revisi = $draf['revisi_tercetak'] ?? null;

        foreach ($draf['isian_berlabel'] ?? [] as $f) {
            if (($revisi === null || $revisi === '') && str_starts_with((string) ($f['label'] ?? ''), 'Rev')) {
                $revisi = $f['tercetak_sesudah_titik_dua'] ?? null;
            }
        }

        if ($revisi !== null && $revisi !== '') {
            $this->assertSame($tercetak.'_REV.'.$this->bakukan((string) $revisi), $kode, 'revisi tercetak beda dari kode peta.');
        }
    }

    /**
     * §5 langkah 5 — belum ada formulir asli yang diadu ke >=20 foto nyata.
     * Begitu ada, test ini yang diubah bersama bukti fotonya, bukan flag-nya
     * diam-diam.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_belum_ada_yang_terverifikasi(string $nama): void
    {
        $peta = $this->peta($nama);

        $this->assertFalse($peta['terverifikasi'] ?? null, 'peta terverifikasi tanpa bukti §5 langkah 5.');
        $this->assertFalse($this->draf($peta)['terverifikasi'] ?? null, "draf {$peta['draf']} terverifikasi tanpa bukti §5 langkah 5.");
    }

    // ------------------------------------------------------------------
    // Per peta — titik & kotak draf
    // ------------------------------------------------------------------

    /**
     * Titik peta yang jatuh di NOL kotak = draf bergeser; di DUA kotak = kotak
     * kembar. Dua-duanya merah — yang dibetulkan skrip atau petanya.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_tiap_titik_peta_jatuh_di_tepat_satu_kotak_sejenis(string $nama): void
    {
        $peta = $this->peta($nama);
        $draf = $this->draf($peta);

        foreach ($this->titikPeta($peta) as [$jenis, $label, $x, $y]) {
            $this->assertTrue($x > 0 && $x < 1 && $y > 0 && $y < 1, "titik {$label} di luar halaman.");

            $kena = $this->kotakBerisiTitik($draf, $jenis, $x, $y);
            $this->assertCount(1, $kena, "{$label} ({$x}, {$y}) jatuh di ".count($kena)." kotak {$jenis} draf.");
        }
    }

    /**
     * Kebalikannya: tiap kotak isian draf (sel calon isian, isian berlabel,
     * kotak centang) dipetakan ATAU diabaikan beralasan — tepat sekali. Kotak
     * yang terlupa bukan kotak yang aman; dia kotak yang belum diperiksa. Dua
     * kode di satu kotak (mis. dua isian lingkungan) = satu angka dibaca dua
     * kali, ikut merah di sini.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_tiap_kotak_isian_draf_punya_nasib(string $nama): void
    {
        $peta = $this->peta($nama);
        $draf = $this->draf($peta);
        $dirujuk = [];

        foreach ($this->titikPeta($peta) as [$jenis, , $x, $y]) {
            foreach ($this->kotakBerisiTitik($draf, $jenis, $x, $y) as $kotak) {
                $dirujuk[$kotak['id']] = ($dirujuk[$kotak['id']] ?? 0) + 1;
            }
        }

        foreach (['sel', 'isian', 'centang'] as $jenis) {
            foreach ($this->kotakDraf($draf, $jenis) as $kotak) {
                $this->assertSame(1, $dirujuk[$kotak['id']] ?? 0, "kotak {$jenis} {$kotak['id']} tidak dipetakan dan tidak diabaikan (atau dirujuk dua kali).");
            }
        }
    }

    #[DataProvider('semuaPetaProvider')]
    public function test_abaikan_selalu_beralasan(string $nama): void
    {
        foreach ($this->peta($nama)['abaikan'] as $kelompok) {
            $this->assertGreaterThan(40, mb_strlen(trim((string) ($kelompok['alasan'] ?? ''))), 'alasan abaikan terlalu pendek untuk dipertanggungjawabkan.');
            $this->assertContains($kelompok['jenis'] ?? null, ['sel', 'isian', 'centang'], 'jenis abaikan tak dikenal.');
            $this->assertNotEmpty($kelompok['titik'] ?? [], 'abaikan tanpa titik.');
        }
    }

    /**
     * SEMUA kotak isian draf — bukan cuma yang dipetakan — dilarang saling
     * tumpang dan dilarang memotong sebagian sel tercetak; semua kotak di
     * dalam halaman. Per draf: satu draf bisa dipakai beberapa peta.
     */
    #[DataProvider('semuaDrafProvider')]
    public function test_kotak_draf_tidak_bertumpukan_dan_di_dalam_halaman(string $namaDraf): void
    {
        $draf = $this->bacaJson($namaDraf);
        $semua = $this->semuaKotakDraf($draf);
        $isian = array_values(array_filter($semua, static fn (array $k): bool => $k['jenis'] !== 'tercetak'));
        $pembungkus = array_values(array_filter($semua, static fn (array $k): bool => $k['jenis'] === 'tercetak'));

        foreach ($semua as $k) {
            [$x0, $y0, $x1, $y1] = $k['norm'];
            $this->assertTrue($x0 >= 0 && $y0 >= 0 && $x1 <= 1 && $y1 <= 1 && $x1 > $x0 && $y1 > $y0, "kotak {$k['jenis']} {$k['id']} di luar halaman.");
        }

        foreach ($isian as $i => $a) {
            foreach (array_slice($isian, $i + 1) as $b) {
                $this->assertFalse($this->beririsan($a['pt'], $b['pt']), "{$a['jenis']} {$a['id']} menumpuk {$b['jenis']} {$b['id']}.");
            }

            foreach ($pembungkus as $c) {
                if (in_array("{$a['id']}|{$c['id']}", self::IRISAN_BENTUK_KERTAS[$namaDraf] ?? [], true)) {
                    continue;
                }

                $this->assertFalse(
                    $this->beririsan($a['pt'], $c['pt']) && ! $this->membungkus($c['pt'], $a['pt']),
                    "{$a['jenis']} {$a['id']} memotong sebagian sel {$c['id']}.",
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Per peta — kunci, kode, dan lembar profil
    // ------------------------------------------------------------------

    /**
     * Kunci sel peta = kunci lembar profil untuk varian formulir itu. Kunci
     * yang tidak dikenal profil tidak pernah terbaca (server membuangnya dari
     * irisan) — jadi itu salah ketik atau profil bergeser, bukan sel cadangan.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_kunci_sel_ada_di_template_profil(string $nama): void
    {
        $peta = $this->peta($nama);
        $kunciPeta = array_map(static fn (array $e): string => (string) ($e['kunci'] ?? ''), $peta['sel']);
        $kunciProfil = array_keys($this->templateVarian($nama)['sel']);

        $this->assertNotEmpty($kunciPeta, 'tidak ada satu pun sel tabel yang dipetakan.');
        $this->assertSame([], $this->kembar($kunciPeta), 'satu kunci sel dipetakan ke lebih dari satu kotak.');
        $this->assertSame([], array_values(array_diff($kunciPeta, $kunciProfil)), 'kunci sel tidak ada di lembar profil — server membuangnya diam-diam dari irisan.');
    }

    /**
     * `tanpa_kotak` (kunci profil tanpa kotak di kertas) — kalau peta
     * memakainya, dia wajib LENGKAP: sel terpetakan + sel tanpa kotak = seluruh
     * kunci sel profil, tanpa irisan; tiap baris standar Usage Check & tiap
     * isian lingkungan punya nasib; semuanya beralasan dan dikenal profil.
     */
    #[DataProvider('petaBertanpaKotakProvider')]
    public function test_tanpa_kotak_menutup_sisa_lembar_profil(string $nama): void
    {
        $peta = $this->peta($nama);
        $bentuk = $this->bentukVarian($nama);
        $field = $this->fieldProfil($bentuk);
        $barisUsage = $this->barisUsage($bentuk);
        $tanpaSel = [];
        $tanpaIsian = [];
        $tanpaBaris = [];

        $this->assertIsList($peta['tanpa_kotak']);

        foreach ($peta['tanpa_kotak'] as $t) {
            $this->assertGreaterThan(20, mb_strlen(trim((string) ($t['alasan'] ?? ''))), 'tanpa_kotak wajib beralasan.');
            $this->assertContains($t['jenis'] ?? null, ['sel', 'isian', 'centang'], 'jenis tanpa_kotak tak dikenal.');

            match ($t['jenis']) {
                'sel' => $tanpaSel = [...$tanpaSel, ...(array) $t['kunci']],
                'isian' => $tanpaIsian = [...$tanpaIsian, ...(array) $t['kode']],
                'centang' => null,
            };

            if ($t['jenis'] !== 'centang') {
                continue;
            }

            $kode = (string) $t['kode'];

            if ($kode === 'standar_dicek.*.dipakai') {
                $this->assertNotSame([], $barisUsage, 'profil tidak punya blok Usage Check.');
                $tanpaBaris = [...$tanpaBaris, ...(array) $t['baris_ke']];

                continue;
            }

            $this->assertArrayHasKey($kode, $field, "tanpa_kotak centang `{$kode}` tidak ada di lembar profil.");

            foreach ((array) ($t['pilihan'] ?? []) as $pilihan) {
                $this->assertContains($pilihan, $this->nilaiPilihan($field[$kode]), "pilihan {$pilihan} bukan pilihan {$kode}.");
            }
        }

        $kunciPeta = array_column($peta['sel'], 'kunci');
        $this->assertSame([], $this->kembar($tanpaSel), 'satu kunci tanpa_kotak disebut dua kali.');
        $this->assertSame([], array_values(array_intersect($kunciPeta, $tanpaSel)), 'kunci ada di sel DAN tanpa_kotak.');
        $this->assertEqualsCanonicalizing(array_keys($this->templateVarian($nama)['sel']), [...$kunciPeta, ...$tanpaSel], 'kunci profil berubah bentuk atau peta tidak lengkap — petanya wajib ditinjau ulang.');

        $isianPeta = array_column($peta['isian'], 'kode');

        foreach ($tanpaIsian as $kode) {
            $this->assertArrayHasKey($kode, $field, "tanpa_kotak isian `{$kode}` tidak ada di lembar profil.");
            $this->assertNotContains($kode, $isianPeta, "isian `{$kode}` dipetakan DAN tanpa_kotak.");
        }

        // Empat angka lingkungan wajib punya nasib (kode dasar = segmen terakhir).
        $dasar = array_map(static fn (string $k): string => str_contains($k, '.') ? substr($k, (int) strrpos($k, '.') + 1) : $k, [...$isianPeta, ...$tanpaIsian]);

        foreach (self::LINGKUNGAN as $kode) {
            $this->assertContains($kode, $dasar, "{$kode} tidak dipetakan dan tidak dinyatakan tanpa kotak.");
        }

        $barisPeta = array_column(array_filter($peta['centang'], static fn (array $e): bool => $e['kode'] === 'standar_dicek.*.dipakai'), 'baris_ke');
        $this->assertSame([], array_values(array_intersect($barisPeta, $tanpaBaris)), 'baris standar dipetakan DAN tanpa_kotak.');
        $this->assertEqualsCanonicalizing(range(1, count($barisUsage)), [...$barisPeta, ...$tanpaBaris], 'baris standar profil tidak seluruhnya bernasib.');
    }

    #[DataProvider('semuaPetaProvider')]
    public function test_kode_isian_dan_centang_dikenal_profil(string $nama): void
    {
        $peta = $this->peta($nama);
        $bentuk = $this->bentukVarian($nama);
        $field = $this->fieldProfil($bentuk);
        $adaUsage = $this->barisUsage($bentuk) !== [];

        foreach (['isian', 'centang'] as $jenis) {
            foreach ($peta[$jenis] as $e) {
                $kode = (string) ($e['kode'] ?? '?');

                // `standar_dicek.*` kunci tingkat-request untuk SEMUA profil
                // (`CalibrationRequest`); sebagian profil cuma mendaftarkannya
                // sebagai `baris` blok Usage Check, bukan field.
                $dikenal = isset($field[$kode]) || ($jenis === 'centang' && $kode === 'standar_dicek.*.dipakai' && $adaUsage);
                $this->assertTrue($dikenal, "{$jenis} `{$kode}` tidak ada di lembar profil.");
                $this->assertContains($e['cara'] ?? null, self::CARA[$jenis], "cara isi `{$kode}` bukan kosakata §4.");
            }
        }

        foreach (['sel', 'isian', 'centang'] as $bagian) {
            foreach ($peta[$bagian] as $e) {
                $this->assertContains($e['sumber_kotak'] ?? self::SUMBER_KOTAK[$bagian][0], self::SUMBER_KOTAK[$bagian], "sumber_kotak {$bagian} `".($e['kunci'] ?? $e['kode']).'` tak dikenal.');
            }
        }

        $this->assertSame([], $this->kembar(array_column($peta['isian'], 'kode')), 'satu kode isian dipetakan dua kali.');
        $this->assertSame([], $this->kembar(array_map(
            static fn (array $c): string => $c['kode'].'|'.($c['pilihan'] ?? '').'|'.($c['baris_ke'] ?? ''),
            $peta['centang'],
        )), 'satu kotak centang (kode+pilihan+baris_ke) dipetakan dua kali.');

        // Pilihan centang pilihan-tunggal wajib salah satu pilihan profil
        // (TH-n diperiksa sendiri di bawah).
        foreach ($peta['centang'] as $e) {
            if (isset($e['pilihan']) && $e['kode'] !== 'thermohygro_standard_id') {
                $this->assertContains($e['pilihan'], $this->nilaiPilihan($field[$e['kode']] ?? []), "pilihan {$e['pilihan']} bukan pilihan {$e['kode']} di profil.");
            }
        }
    }

    /**
     * `tahap: 1` = isian yang dikirim ke HP untuk dibaca. Cuma kondisi
     * lingkungan dengan `cara: angka` — identitas & tanggal belum dibuka (K2),
     * dan kodenya harus muat di kolom & rute. Kebalikannya juga: suhu &
     * kelembaban awal/akhir yang dipetakan WAJIB dibuka — kecuali kodenya tidak
     * muat di `field_id` (Anak Timbangan `spesifikasi_alat.anak_timbangan.*`),
     * yang wajib TIDAK dibuka sampai penyimpanannya diputuskan.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_tahap_1_cuma_kondisi_lingkungan_yang_muat(string $nama): void
    {
        $dibuka = 0;

        foreach ($this->peta($nama)['isian'] as $e) {
            $kode = (string) $e['kode'];
            $dasar = str_contains($kode, '.') ? substr($kode, (int) strrpos($kode, '.') + 1) : $kode;
            $muat = mb_strlen($kode) <= self::PANJANG_FIELD_ID;

            if (in_array($dasar, self::LINGKUNGAN, true) && ($e['cara'] ?? null) === 'angka') {
                $this->assertSame($muat ? FormulirAsli::TAHAP_DIBUKA : null, $e['tahap'] ?? null, $muat
                    ? "isian lingkungan {$kode} wajib `tahap: 1`."
                    : "isian {$kode} lebih dari ".self::PANJANG_FIELD_ID.' karakter — tidak boleh dibuka (field_id).');
            }

            if (! array_key_exists('tahap', $e)) {
                continue;
            }

            $this->assertSame(FormulirAsli::TAHAP_DIBUKA, $e['tahap'], "`tahap` isian {$kode} wajib integer 1 (atau tidak ditulis).");
            $this->assertSame('angka', $e['cara'] ?? null, "isian tahap 1 {$kode} wajib `cara: angka`.");
            $this->assertNotNull(PemrosesScanLembarKerja::keluargaIsian($kode), "{$kode} bukan kondisi lingkungan (suhu_/kelembaban_/tekanan_).");
            $this->assertTrue($muat, "kode isian {$kode} lebih dari ".self::PANJANG_FIELD_ID.' karakter.');
            $this->assertMatchesRegularExpression(self::POLA_KODE_ISIAN, $kode, "kode isian {$kode} tidak lolos rute crop.");
            $dibuka++;
        }

        $this->assertLessThanOrEqual(WorksheetScanRequest::MAKS_ISIAN, $dibuka, 'isian tahap 1 melebihi langit kiriman.');
    }

    /**
     * Usage Check: `baris_ke` tak dobel, `label` = baris ke-n blok Usage Check
     * profil, dan — kalau peta mencatat `label_kertas` (wajib di semua baris
     * begitu satu baris mencatatnya) — kotaknya memang berdiri di baris
     * tercetak bertuliskan itu. Tertukar satu baris = standar yang salah
     * tercatat dipakai, tanpa satu error pun.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_centang_usage_check_sejajar_baris_profil_dan_kertas(string $nama): void
    {
        $peta = $this->peta($nama);
        $draf = $this->draf($peta);
        $baris = $this->barisUsage($this->bentukVarian($nama));
        $usage = array_values(array_filter($peta['centang'], static fn (array $e): bool => $e['kode'] === 'standar_dicek.*.dipakai'));
        $berlabelKertas = array_filter($usage, static fn (array $e): bool => array_key_exists('label_kertas', $e)) !== [];

        $this->assertSame([], $this->kembar(array_column($usage, 'baris_ke')), 'satu baris standar dipetakan dua kali.');

        foreach ($usage as $e) {
            $ke = (int) ($e['baris_ke'] ?? 0);

            $this->assertArrayHasKey($ke - 1, $baris, "baris_ke {$ke} tidak ada di Usage Check profil.");
            $this->assertSame($baris[$ke - 1], $e['label'] ?? null, "baris {$ke} peta berlabel lain dari baris profilnya.");

            if (! $berlabelKertas) {
                continue;
            }

            $kertas = trim((string) ($e['label_kertas'] ?? ''));
            $this->assertNotSame('', $kertas, "Usage Check baris {$ke} wajib mencatat label tercetaknya.");
            $this->assertTrue($this->berdiriDiBarisTercetak($draf, (float) $e['x'], (float) $e['y'], $kertas), "centang baris {$ke} tidak berdiri di baris tercetak '{$kertas}'.");
        }
    }

    /**
     * Pilihan TH-n: berpola, dikenal profil (`THERMOHYGRO_TERCETAK` bila ada),
     * dan kotaknya berlabel TH-n itu di draf (`label_draf` hanya untuk salah
     * baca yang dicatat di [LABEL_DRAF_KELIRU]). Tiap kotak TH-n yang tercetak
     * di draf wajib dipetakan sebagai pilihan — dan sebaliknya.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_centang_thermohygro_menunjuk_label_tercetak(string $nama): void
    {
        $peta = $this->peta($nama);
        $draf = $this->draf($peta);
        $profil = $this->profil((string) $peta['template_id']);
        $konstanta = $profil::class.'::THERMOHYGRO_TERCETAK';
        $dikenal = defined($konstanta) ? array_column(constant($konstanta), 'label') : [];
        $th = array_values(array_filter($peta['centang'], static fn (array $e): bool => $e['kode'] === 'thermohygro_standard_id'));
        $tercetak = [];

        foreach ($this->kotakDraf($draf, 'centang') as $k) {
            if (preg_match('/^TH-\d+$/', (string) $k['label_dekat']) === 1) {
                $tercetak[] = $k['label_dekat'];
            }
        }

        $harapLabelDraf = [];

        foreach ($th as $e) {
            $pilihan = (string) ($e['pilihan'] ?? '');
            $this->assertMatchesRegularExpression('/^TH-\d+$/', $pilihan);

            if ($dikenal !== []) {
                $this->assertContains($pilihan, $dikenal, "{$pilihan} bukan thermohygro tercetak profil.");
            }

            $labelDraf = $e['label_draf'] ?? $pilihan;
            $this->assertSame(self::LABEL_DRAF_KELIRU[$nama][$pilihan] ?? $pilihan, $labelDraf, "label_draf {$pilihan} tidak dicatat di LABEL_DRAF_KELIRU.");
            $this->assertSame($labelDraf, ($this->kotakBerisiTitik($draf, 'centang', (float) $e['x'], (float) $e['y'])[0] ?? [])['label_dekat'] ?? null, "kotak {$pilihan} berlabel lain di draf.");
            $harapLabelDraf[] = $labelDraf;
        }

        $this->assertEqualsCanonicalizing($tercetak, $harapLabelDraf, 'kotak TH-n tercetak di draf dan pilihan TH-n di peta tidak sama.');
    }

    /**
     * Yang dikirim HP untuk peta ini harus muat di langit `WorksheetScanRequest`
     * dan di kolom `worksheet_scan_cells` — kalau tidak, pindainya ditolak 422
     * atau (MySQL) gagal disimpan, padahal petanya hijau.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_peta_muat_di_langit_kiriman_dan_kolom(string $nama): void
    {
        $peta = $this->peta($nama);

        $this->assertLessThanOrEqual(WorksheetScanRequest::MAKS_SEL, count($peta['sel']), 'sel melebihi langit kiriman.');
        $this->assertLessThanOrEqual(WorksheetScanRequest::MAKS_CENTANG, count($peta['centang']), 'centang melebihi langit kiriman.');

        foreach ($peta['centang'] as $c) {
            $kode = (string) $c['kode'];
            $pilihan = $c['pilihan'] ?? null;
            $barisKe = $c['baris_ke'] ?? null;

            $this->assertLessThanOrEqual(self::PANJANG_FIELD_ID, mb_strlen($kode), "kode centang {$kode} lebih dari ".self::PANJANG_FIELD_ID.' karakter.');
            $this->assertFalse($pilihan !== null && $barisKe !== null, "centang {$kode} membawa `pilihan` DAN `baris_ke` — pilih salah satu.");

            if ($pilihan !== null) {
                $this->assertIsString($pilihan, "pilihan centang {$kode} wajib teks.");
                $this->assertLessThanOrEqual(20, mb_strlen($pilihan), "pilihan centang {$kode} lebih dari 20 karakter.");
            }

            if ($barisKe !== null) {
                $this->assertIsInt($barisKe, "baris_ke centang {$kode} wajib integer.");
                $this->assertTrue($barisKe >= 1 && $barisKe <= 200, "baris_ke centang {$kode} di luar 1..200.");
            }
        }

        $jangkar = count($this->draf($peta)['jangkar_teks'] ?? []);
        $this->assertGreaterThanOrEqual((int) config('ocr.geometri.jangkar_teks.jumlah_min', 8), $jangkar, 'draf punya terlalu sedikit jangkar teks.');
        $this->assertLessThanOrEqual(WorksheetScanRequest::MAKS_JANGKAR, $jangkar, 'jangkar teks draf melebihi langit kiriman.');
    }

    // ------------------------------------------------------------------
    // Per formulir — arah baca, label kotak, nominal
    // ------------------------------------------------------------------

    /**
     * Arah baca kertas: kunci yang urutannya bermakna (baris, ulangan, posisi,
     * peran) harus menjalar di kertas dengan arah yang sama. Tertukar = angka
     * mendarat di baris/kolom yang salah tanpa satu error pun.
     */
    #[DataProvider('petaBerarahProvider')]
    public function test_arah_kunci_di_kertas_sesuai_urutan_profil(string $nama): void
    {
        $peta = $this->peta($nama);
        $titik = [];

        foreach ($peta['sel'] as $e) {
            $titik[$e['kunci']] = ['x' => (float) $e['x'], 'y' => (float) $e['y']];
        }

        foreach (self::arahKertas()[$peta['template_id']] as [$a, $arah, $b]) {
            $this->assertArrayHasKey($a, $titik, "kunci {$a} tidak dipetakan.");
            $this->assertArrayHasKey($b, $titik, "kunci {$b} tidak dipetakan.");

            match ($arah) {
                'x<' => $this->assertLessThan($titik[$b]['x'], $titik[$a]['x'], "{$a} wajib di KIRI {$b}."),
                'y<' => $this->assertLessThan($titik[$b]['y'], $titik[$a]['y'], "{$a} wajib di ATAS {$b}."),
                'y=' => $this->assertEqualsWithDelta($titik[$b]['y'], $titik[$a]['y'], 0.001, "{$a} wajib sebaris dengan {$b}."),
            };
        }
    }

    /**
     * Sel berkotak berlabel (`sumber_kotak: "isian"`): label tercetak kotaknya
     * = peran kuncinya ([LABEL_SEL_BERLABEL]). Sel berlabel di template yang
     * tidak punya aturan label = merah — tidak ada yang memeriksanya.
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_sel_berkotak_berlabel_sesuai_peran_kunci(string $nama): void
    {
        $peta = $this->peta($nama);
        $draf = $this->draf($peta);
        $aturan = self::LABEL_SEL_BERLABEL[$peta['template_id']] ?? [];

        foreach ($peta['sel'] as $e) {
            [$tabel, $titik, $ke] = explode('|', (string) $e['kunci']);
            $berlabel = ($e['sumber_kotak'] ?? 'sel') === 'isian';

            if (! isset($aturan[$tabel])) {
                $this->assertFalse($berlabel, "sel {$e['kunci']} berkotak berlabel tanpa aturan label di LABEL_SEL_BERLABEL.");

                continue;
            }

            $this->assertTrue($berlabel, "sel {$e['kunci']} wajib berkotak berlabel (aturan LABEL_SEL_BERLABEL).");
            [$huruf, $aksen] = $aturan[$tabel][(int) $ke];
            $label = (string) (($this->kotakBerisiTitik($draf, 'isian', (float) $e['x'], (float) $e['y'])[0] ?? [])['label'] ?? '');

            $this->assertSame($huruf.$titik.$aksen, str_replace(['’', '′'], "'", $label), "kunci {$e['kunci']} menunjuk kotak berlabel {$label}.");
        }
    }

    /**
     * Nominal balok Micrometer TERCETAK per baris = `nominal_cetak_mm` varian
     * rentangnya, dan baris peta sejajar nominal tercetaknya. Baris kertas yang
     * bergeser satu = nominal tertukar di sertifikat.
     */
    public function test_nominal_micrometer_tercetak_sama_dengan_varian(): void
    {
        foreach (self::KAPASITAS_MICROMETER as $nama => $kapasitas) {
            $peta = $this->peta($nama);
            $varian = (new TabelStandarMicrometer)->pitaCmc($kapasitas);
            $tercetak = [];

            foreach ($this->draf($peta)['sel'] as $s) {
                $x = $s['x'] + $s['w'] / 2;

                if ($s['jenis'] === 'tercetak' && $x < 0.12 && preg_match('/^\d+\.\d$/', trim((string) $s['teks_tercetak'])) === 1) {
                    $tercetak[] = ['y' => $s['y'] + $s['h'] / 2, 'teks' => trim($s['teks_tercetak'])];
                }
            }

            usort($tercetak, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);

            $this->assertSame($varian['kode_dokumen'], $peta['kode_dokumen'], "{$nama}: varian kapasitas {$kapasitas} mm beda formulir.");
            $this->assertSame(
                array_map(static fn (array $t): string => number_format((float) $t['nominal_cetak_mm'], 1, '.', ''), $varian['titik']),
                array_column($tercetak, 'teks'),
                "{$nama}: nominal tercetak tidak sama dengan titik varian profil.",
            );

            foreach ($peta['sel'] as $e) {
                [$tabel, $baris] = explode('|', $e['kunci']);

                if ($tabel === 'mikro_pembacaan') {
                    $this->assertEqualsWithDelta($tercetak[(int) $baris - 1]['y'], $e['y'], 0.01, "{$nama}: baris {$baris} tidak sejajar nominal tercetaknya.");
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Per peta — ujung ke ujung lewat server
    // ------------------------------------------------------------------

    /**
     * HP yang membaca kode FM formulir ini dari kertas benar-benar disajikan
     * peta INI — sel = kunci peta, semua berkotak, isian = yang ber-`tahap` 1,
     * centang = semua centang peta, revisi terbaca. Tanpa kode yang dibaca,
     * peta ini tersaji untuk alat yang formulir bawaannya memang formulir ini
     * (varian per sesi tidak punya alat begitu).
     */
    #[DataProvider('semuaPetaProvider')]
    public function test_server_menyajikan_peta_untuk_formulir_yang_dibaca(string $nama): void
    {
        $peta = $this->peta($nama);
        $id = (string) $peta['template_id'];
        $draf = $this->draf($peta);
        $alat = $this->alatVarian($peta);
        $ulang = self::PENGULANGAN_KERTAS[$nama] ?? null;

        $template = app(FormulirAsli::class)->cari($id, $alat, $ulang, (string) $draf['kode_dokumen']);
        $this->assertIsArray($template, "server tidak menyajikan peta ini untuk kertas {$draf['kode_dokumen']}: ".(is_string($template) ? $template : 'null'));

        // Revisi wajib terbaca (dari draf, atau dari `_Rev.n` kode peta) —
        // tanpa itu pemroses menolak setiap pindai formulir ini.
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $template['revisi'], 'revisi formulir tidak terbaca.');

        if (($this->varianKodeDokumen($id)[$this->bakukan((string) $peta['kode_dokumen'])] ?? null) instanceof Equipment) {
            $bawaan = app(FormulirAsli::class)->cari($id, $alat, $ulang);
            $this->assertIsArray($bawaan, 'tanpa kode terbaca, alat variannya tidak disajikan peta ini.');
            $this->assertSame($template['sumber_sha256'], $bawaan['sumber_sha256'], 'tanpa kode terbaca tersaji formulir lain.');
        }

        $this->assertEqualsCanonicalizing(array_column($peta['sel'], 'kunci'), array_keys($template['sel']), 'sel tersaji beda dari kunci peta.');
        $this->assertNotContains(null, array_column($template['sel'], 'kotak'), 'ada sel tanpa kotak.');
        $this->assertNotContains(null, array_column($template['isian'], 'kotak'), 'ada isian tanpa kotak.');
        $this->assertNotContains(null, array_column($template['centang'], 'kotak'), 'ada centang tanpa kotak.');
        $this->assertEqualsCanonicalizing(
            array_column(array_filter($peta['isian'], static fn (array $e): bool => ($e['tahap'] ?? null) === FormulirAsli::TAHAP_DIBUKA), 'kode'),
            array_column($template['isian'], 'kode'),
            'isian tersaji bukan isian tahap 1 peta.',
        );
        $this->assertCount(count($peta['centang']), $template['centang'], 'centang tersaji beda dari peta.');
        $this->assertSame($draf['kode_dokumen'], $template['kode_dokumen']);
        $this->assertFalse($template['siap_pindai']);
        // K-50-11: peta belum terverifikasi tapi kamera dibuka lewat mode uji.
        $this->assertTrue($template['mode_uji'], 'formulir dipetakan tapi tidak dibuka mode uji.');
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    /**
     * Arah baca kertas per template: `[kunci a, arah, kunci b]` — `x<` a di
     * kiri b, `y<` a di atas b, `y=` sebaris. Dari pemeriksaan kelompok
     * Mekanik (bf3fd10).
     *
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private static function arahKertas(): array
    {
        $tekanan = [
            ['tekanan_up|1|1|pembacaan', 'x<', 'tekanan_up|1|2|pembacaan'],
            ['tekanan_up|1|2|pembacaan', 'x<', 'tekanan_up|1|3|pembacaan'],
            ['tekanan_up|1|3|pembacaan', 'x<', 'tekanan_down|1|1|pembacaan'],
            ['tekanan_down|1|2|pembacaan', 'x<', 'tekanan_down|1|3|pembacaan'],
            ['tekanan_up|1|1|pembacaan', 'y<', 'tekanan_up|11|1|pembacaan'],
            ['tekanan_up|5|1|pembacaan', 'y=', 'tekanan_down|5|3|pembacaan'],
        ];

        $gaya = [];
        $posisi = ['gaya_pos_0', 'gaya_pos_90', 'gaya_pos_180', 'gaya_pos_270'];

        foreach ($posisi as $i => $t) {
            $gaya[] = ["{$t}|1|1|pembacaan", 'x<', "{$t}|1|2|pembacaan"];
            $gaya[] = ["{$t}|1|2|pembacaan", 'x<', "{$t}|1|3|pembacaan"];
            $gaya[] = ["{$t}|1|1|pembacaan", 'y<', "{$t}|10|1|pembacaan"];
            $gaya[] = ["{$t}|7|1|pembacaan", 'y=', "{$t}|7|3|pembacaan"];

            if ($i > 0) {
                $gaya[] = ["{$posisi[$i - 1]}|1|3|pembacaan", 'x<', "{$t}|1|1|pembacaan"];
            }
        }

        $anak = [];

        foreach ([1, 5, 6, 10] as $k) {
            $anak[] = ["at_s1|{$k}|1|pembacaan", 'y<', "at_t1|{$k}|1|pembacaan"];
            $anak[] = ["at_t1|{$k}|1|pembacaan", 'y<', "at_t2|{$k}|1|pembacaan"];
            $anak[] = ["at_t2|{$k}|1|pembacaan", 'y<', "at_s2|{$k}|1|pembacaan"];
            $anak[] = ["at_s1|{$k}|1|pembacaan", 'x<', "at_s1|{$k}|2|pembacaan"];
            $anak[] = ["at_s1|{$k}|2|pembacaan", 'x<', "at_s1|{$k}|3|pembacaan"];
        }

        $anak[] = ['at_s2|1|1|pembacaan', 'y<', 'at_s1|2|1|pembacaan'];
        $anak[] = ['at_s1|5|3|pembacaan', 'x<', 'at_s1|6|1|pembacaan'];
        $anak[] = ['at_s1|1|1|pembacaan', 'y=', 'at_s1|6|1|pembacaan'];

        return [
            'timer_stopwatch' => [
                ['waktu_standar|1|1|jam', 'x<', 'waktu_standar|1|1|menit'],
                ['waktu_standar|1|1|detik', 'x<', 'waktu_standar|1|1|sentidetik'],
                ['waktu_standar|1|1|sentidetik', 'x<', 'waktu_uut|1|1|jam'],
                ['waktu_uut|1|1|detik', 'x<', 'waktu_uut|1|1|sentidetik'],
                ['waktu_standar|1|1|jam', 'y<', 'waktu_standar|1|2|jam'],
                ['waktu_standar|1|2|jam', 'y<', 'waktu_standar|1|3|jam'],
                ['waktu_standar|1|3|jam', 'y<', 'waktu_standar|2|1|jam'],
                ['waktu_standar|1|1|jam', 'y=', 'waktu_uut|1|1|jam'],
            ],
            'differential_pressure' => $tekanan,
            'pressure_gauge' => $tekanan,
            'vacuum_gauge' => $tekanan,
            'utm' => $gaya,
            'load_cell' => $gaya,
            'proving_ring' => [
                ['gaya_up|1|1|pembacaan', 'x<', 'gaya_up|1|2|pembacaan'],
                ['gaya_up|1|2|pembacaan', 'x<', 'gaya_up|1|3|pembacaan'],
                ['gaya_up|1|3|pembacaan', 'x<', 'gaya_down|1|1|pembacaan'],
                ['gaya_down|1|2|pembacaan', 'x<', 'gaya_down|1|3|pembacaan'],
                ['gaya_up|1|1|pembacaan', 'y<', 'gaya_up|14|1|pembacaan'],
                ['gaya_up|14|1|pembacaan', 'y<', 'gaya_up|15|1|pembacaan'],
            ],
            // Kertas ditranspos: kolom kertas = titik, baris kertas = ulangan.
            'hydrometer' => [
                ['hydro_massa|1|1|pembacaan', 'x<', 'hydro_massa|2|1|pembacaan'],
                ['hydro_massa|4|1|pembacaan', 'x<', 'hydro_massa|5|1|pembacaan'],
                ['hydro_massa|1|1|pembacaan', 'y<', 'hydro_massa|1|2|pembacaan'],
                ['hydro_massa|1|2|pembacaan', 'y<', 'hydro_massa|1|3|pembacaan'],
                ['hydro_massa|1|3|pembacaan', 'y<', 'hydro_suhu|1|1|pembacaan'],
                ['hydro_diameter|1|1|pembacaan', 'y<', 'hydro_massa|1|1|pembacaan'],
                ['hydro_diameter|1|1|pembacaan', 'x<', 'hydro_diameter|1|2|pembacaan'],
                ['hydro_diameter|1|2|pembacaan', 'x<', 'hydro_diameter|1|3|pembacaan'],
            ],
            'anak_timbangan' => $anak,
            'timbangan' => [
                ['keterulangan|1|1|zero', 'x<', 'keterulangan|1|1|pembacaan'],
                ['keterulangan|1|1|pembacaan', 'x<', 'keterulangan|2|1|zero'],
                ['keterulangan|2|1|zero', 'x<', 'keterulangan|2|1|pembacaan'],
                ['keterulangan|1|1|zero', 'y<', 'keterulangan|1|10|zero'],
                ['akurasi|1|3|pembacaan', 'y<', 'akurasi|2|1|pembacaan'],
            ],
        ];
    }

    private function folder(): string
    {
        return base_path(self::FOLDER_ASLI);
    }

    /**
     * @return array<string, mixed>
     */
    private function bacaJson(string $berkas): array
    {
        if (! isset(self::$json[$berkas])) {
            $isi = json_decode((string) file_get_contents($this->folder().'/'.basename($berkas)), true);
            $this->assertIsArray($isi, "{$berkas}: bukan JSON objek.");
            self::$json[$berkas] = $isi;
        }

        return self::$json[$berkas];
    }

    /**
     * @return array<string, mixed>
     */
    private function peta(string $nama): array
    {
        return $this->bacaJson($nama);
    }

    /**
     * @param  array<string, mixed>  $peta
     * @return array<string, mixed>
     */
    private function draf(array $peta): array
    {
        return $this->bacaJson((string) $peta['draf']);
    }

    private function profil(string $templateId): CalibrationProfile
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($templateId);
        $this->assertNotNull($profil, "Profil {$templateId} tidak terdaftar.");

        return $profil;
    }

    /**
     * Alat semu pembawa organisasi — konfigurasi bawaan yang dipakai rute
     * template tanpa `equipment_id`.
     *
     * @param  array<string, mixed>  $atribut
     */
    private function alatKonteks(array $atribut = []): Equipment
    {
        return new Equipment(['organization_id' => 1, ...$atribut]);
    }

    /**
     * Kode dokumen (dibakukan) yang dikenal profil => alat yang memilihnya,
     * atau `false` kalau kode itu cuma disebut di `kode_dokumen_varian`
     * (varian per sesi, belum bisa dipilih dari alat).
     *
     * @return array<string, Equipment|false>
     */
    private function varianKodeDokumen(string $templateId): array
    {
        $profil = $this->profil($templateId);
        $hasil = [];
        $konfigurasi = [$this->alatKonteks(self::ALAT_SESUAI_KERTAS[$templateId] ?? [])];

        foreach (self::VARIAN_ALAT[$templateId] ?? [] as $atribut) {
            $konfigurasi[] = $this->alatKonteks($atribut);
        }

        foreach ($konfigurasi as $alat) {
            $bentuk = $profil->bentukLembarKerja(false, $alat);

            if (is_string($bentuk['kode_dokumen'] ?? null)) {
                $hasil[$this->bakukan($bentuk['kode_dokumen'])] ??= $alat;
            }

            foreach ((array) ($bentuk['kode_dokumen_varian'] ?? []) as $kode) {
                if (is_string($kode)) {
                    $hasil[$this->bakukan($kode)] ??= false;
                }
            }
        }

        foreach (self::VARIAN_SESI_BELUM_DI_PROFIL as $kelas => $kodeSesi) {
            if ($profil instanceof $kelas) {
                foreach ($kodeSesi as $kode) {
                    $hasil[$this->bakukan($kode)] ??= false;
                }
            }
        }

        return $hasil;
    }

    /**
     * Alat yang memilih formulir peta ini — konfigurasi bawaan kalau kodenya
     * cuma dikenal sebagai varian per sesi.
     *
     * @param  array<string, mixed>  $peta
     */
    private function alatVarian(array $peta): Equipment
    {
        $alat = $this->varianKodeDokumen((string) $peta['template_id'])[$this->bakukan((string) $peta['kode_dokumen'])] ?? false;

        return $alat === false ? $this->alatKonteks() : $alat;
    }

    /**
     * Template lembar profil untuk varian formulir peta ini (alat varian +
     * jumlah pengulangan kertas).
     *
     * @return array<string, mixed>
     */
    private function templateVarian(string $nama): array
    {
        $peta = $this->peta($nama);

        return app(TemplateLembarKerja::class)->dariProfil(
            $this->profil((string) $peta['template_id']),
            $this->alatVarian($peta),
            self::PENGULANGAN_KERTAS[$nama] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukVarian(string $nama): array
    {
        $peta = $this->peta($nama);

        return $this->profil((string) $peta['template_id'])->bentukLembarKerja(false, $this->alatVarian($peta));
    }

    /**
     * Semua field lembar per kode, di kedalaman mana pun (`bagian[].field[]`,
     * dan field yang bersarang di dalamnya).
     *
     * @param  array<mixed>  $bentuk
     * @return array<string, array<string, mixed>>
     */
    private function fieldProfil(array $bentuk): array
    {
        $field = [];

        foreach ($bentuk as $kunci => $nilai) {
            if (! is_array($nilai)) {
                continue;
            }

            if ($kunci === 'field') {
                foreach ($nilai as $f) {
                    if (is_array($f) && is_string($f['kode'] ?? null)) {
                        $field[$f['kode']] ??= $f;
                    }
                }
            }

            $field += $this->fieldProfil($nilai);
        }

        return $field;
    }

    /**
     * Nilai sah field pilihan — daftar teks (`['Push', 'Pull']`) atau daftar
     * `{nilai, label}`.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function nilaiPilihan(array $field): array
    {
        return array_values(array_map(
            static fn (mixed $p): string => (string) (is_array($p) ? ($p['nilai'] ?? '') : $p),
            (array) ($field['pilihan'] ?? []),
        ));
    }

    /**
     * Label baris blok Usage Check profil, berurutan (baris ke-1 = indeks 0).
     *
     * @param  array<string, mixed>  $bentuk
     * @return list<string>
     */
    private function barisUsage(array $bentuk): array
    {
        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            if (($bagian['kode'] ?? null) === 'usage_check') {
                return array_values(array_map(static fn (array $b): string => (string) ($b['label'] ?? $b['nama'] ?? ''), $bagian['baris'] ?? []));
            }
        }

        return [];
    }

    /**
     * Kotak centang di (x, y) berdiri di baris tercetak bertuliskan `$label`:
     * sel tercetak sebaris yang teksnya memuat label itu, ATAU `label_dekat`
     * kotak centangnya berakhiran label itu.
     *
     * @param  array<string, mixed>  $draf
     */
    private function berdiriDiBarisTercetak(array $draf, float $x, float $y, string $label): bool
    {
        $label = mb_strtolower($label);
        $teks = '';

        foreach ($draf['sel'] ?? [] as $s) {
            if (($s['jenis'] ?? null) !== 'calon_isian' && $y >= $s['y'] - 0.012 && $y <= $s['y'] + $s['h'] + 0.012) {
                $teks .= ' '.($s['teks_tercetak'] ?? '');
            }
        }

        if (str_contains(mb_strtolower($teks), $label)) {
            return true;
        }

        foreach ($this->kotakBerisiTitik($draf, 'centang', $x, $y) as $k) {
            if (str_ends_with(mb_strtolower((string) $k['label_dekat']), $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Semua titik di peta: `[jenis kotak draf, label, x, y]`. `sumber_kotak`
     * menunjuk jenis kotak draf lain dari bawaan bagiannya.
     *
     * @param  array<string, mixed>  $peta
     * @return list<array{0: string, 1: string, 2: float, 3: float}>
     */
    private function titikPeta(array $peta): array
    {
        $titik = [];

        foreach (['sel', 'isian', 'centang'] as $bagian) {
            foreach ($peta[$bagian] as $e) {
                $label = $bagian.' '.($e['kunci'] ?? $e['kode']).(isset($e['pilihan']) ? ' '.$e['pilihan'] : '').(isset($e['baris_ke']) ? ' #'.$e['baris_ke'] : '');
                $titik[] = [(string) ($e['sumber_kotak'] ?? self::SUMBER_KOTAK[$bagian][0]), $label, (float) $e['x'], (float) $e['y']];
            }
        }

        foreach ($peta['abaikan'] as $kelompok) {
            foreach ($kelompok['titik'] ?? [] as $e) {
                $titik[] = [(string) $kelompok['jenis'], 'abaikan '.$kelompok['jenis'], (float) $e['x'], (float) $e['y']];
            }
        }

        return $titik;
    }

    /**
     * Semua kotak draf: sel `calon_isian` → `sel`; sel `tercetak`/`wadah` →
     * `tercetak` (pembungkus: boleh MEMBUNGKUS kotak isian, tidak boleh
     * memotongnya); `isian_berlabel` → `isian`; `kotak_centang` → `centang`.
     *
     * @param  array<string, mixed>  $draf
     * @return list<array{jenis: string, id: string, label: string|null, label_dekat: string|null, norm: array{0: float, 1: float, 2: float, 3: float}, pt: array{0: float, 1: float, 2: float, 3: float}}>
     */
    private function semuaKotakDraf(array $draf): array
    {
        $w = (float) $draf['sumber']['ukuran_pt']['w'];
        $h = (float) $draf['sumber']['ukuran_pt']['h'];
        $kotak = [];

        $tambah = static function (string $jenis, array $o) use (&$kotak, $w, $h): void {
            $norm = [(float) $o['x'], (float) $o['y'], (float) $o['x'] + (float) $o['w'], (float) $o['y'] + (float) $o['h']];
            $kotak[] = [
                'jenis' => $jenis,
                'id' => (string) $o['id'],
                'label' => isset($o['label']) ? (string) $o['label'] : null,
                'label_dekat' => isset($o['label_dekat']) ? (string) $o['label_dekat'] : null,
                'norm' => $norm,
                'pt' => [$norm[0] * $w, $norm[1] * $h, $norm[2] * $w, $norm[3] * $h],
            ];
        };

        foreach ($draf['sel'] ?? [] as $s) {
            $tambah(($s['jenis'] ?? null) === 'calon_isian' ? 'sel' : 'tercetak', $s);
        }

        foreach ($draf['isian_berlabel'] ?? [] as $s) {
            $tambah('isian', $s);
        }

        foreach ($draf['kotak_centang'] ?? [] as $s) {
            $tambah('centang', $s);
        }

        return $kotak;
    }

    /**
     * @param  array<string, mixed>  $draf
     * @return list<array<string, mixed>>
     */
    private function kotakDraf(array $draf, string $jenis): array
    {
        return array_values(array_filter($this->semuaKotakDraf($draf), static fn (array $k): bool => $k['jenis'] === $jenis));
    }

    /**
     * @param  array<string, mixed>  $draf
     * @return list<array<string, mixed>>
     */
    private function kotakBerisiTitik(array $draf, string $jenis, float $x, float $y): array
    {
        return array_values(array_filter(
            $this->kotakDraf($draf, $jenis),
            static fn (array $k): bool => $x >= $k['norm'][0] && $x <= $k['norm'][2] && $y >= $k['norm'][1] && $y <= $k['norm'][3],
        ));
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $a
     * @param  array{0: float, 1: float, 2: float, 3: float}  $b
     */
    private function beririsan(array $a, array $b): bool
    {
        return min($a[2], $b[2]) - max($a[0], $b[0]) > self::TOLERANSI_PT
            && min($a[3], $b[3]) - max($a[1], $b[1]) > self::TOLERANSI_PT;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $luar
     * @param  array{0: float, 1: float, 2: float, 3: float}  $dalam
     */
    private function membungkus(array $luar, array $dalam): bool
    {
        return $luar[0] <= $dalam[0] + self::TOLERANSI_PT
            && $luar[1] <= $dalam[1] + self::TOLERANSI_PT
            && $luar[2] >= $dalam[2] - self::TOLERANSI_PT
            && $luar[3] >= $dalam[3] - self::TOLERANSI_PT;
    }

    private function bakukan(string $kode): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $kode));
    }

    /**
     * @param  list<mixed>  $nilai
     * @return list<int|string>
     */
    private function kembar(array $nilai): array
    {
        return array_keys(array_filter(array_count_values(array_map('strval', $nilai)), static fn (int $n): bool => $n > 1));
    }
}
