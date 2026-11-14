<?php

namespace Tests\Feature;

use App\Exceptions\SertifikatLebihSatuHalamanException;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Services\SertifikatSatuHalaman;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EnclosureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\Concerns\GerbangHurufSertifikat;
use Tests\Concerns\PengukurHurufCanvas;
use Tests\TestCase;

/**
 * Setiap sertifikat, semua alat, dirender BENERAN — dan dua hal dijaga sekaligus.
 *
 * ## Kenapa test ini ada, padahal `SertifikatSatuHalamanTest` sudah ada
 *
 * Yang itu menguji ATURAN PEMILIHANNYA dengan dompdf dipalsukan: kapan
 * memadatkan ulang, kapan menyerah, kapan mencatat error. Cepat, dan memang
 * begitu seharusnya.
 *
 * Yang nggak bisa dijawab test itu: apakah lembarnya BENERAN muat. Itu
 * pertanyaan tentang tinggi kop, ukuran huruf, jumlah baris, dan tinggi ruang
 * tanda tangan — semuanya cuma kelihatan kalau dompdf-nya beneran jalan.
 *
 * ## Yang dijaga #1 — semuanya satu halaman
 *
 * Header sertifikat mencetak `Page : 1 of 1` dari angka yang ditulis mati di
 * snapshot. Lembar yang meluap tetap mencetak "1 of 1" di KEDUA halamannya:
 * dokumen terkendali yang menyatakan hal yang tidak benar tentang dirinya
 * sendiri.
 *
 * ## Yang dijaga #2 — dan ini yang sebenarnya gampang jebol
 *
 * Versi pertama test ini cuma menjaga #1, dan itu **tetap hijau waktu tinggi
 * kotak TTD gw naikin ke 130px buat nguji** — karena `SertifikatSatuHalaman`
 * menyelamatkannya dengan memaksa mode padat. Mode padat punya ruang sisa
 * banyak, jadi #1 nyaris nggak pernah merah.
 *
 * Tapi "diselamatkan mode padat" itu BUKAN netral: hurufnya mengecil, jarak
 * menyempit, dan sertifikat alat itu jadi beda bentuk dari sertifikat alat
 * lain di lab yang sama. Perubahan tata letak yang mendemosikan satu alat ke
 * mode padat nggak menghasilkan error apa pun — persis kelas kegagalan yang
 * bikin repo ini nulis aturan "yang nggak error itu yang paling mahal".
 *
 * Jadi yang dipatok di sini DAFTARNYA: alat mana saja yang butuh mode padat.
 * Nambah satu nama = ada perubahan tata letak yang mengecilkan huruf satu alat.
 *
 * ## Yang dijaga #3 — huruf tidak mengecil diam-diam (13 Nov 2026)
 *
 * #1 dan #2 tidak menangkap Sieve Mesh: baris "Minimum Apertures" (B2) membuat
 * hurufnya jatuh dari 0,93 ke 0,83 x master (7,9 -> 7,1 pt, tingkat `longgar -1`
 * ke `-2`) dan lembarnya TETAP satu halaman. BUTUH_PADAT cuma membedakan normal
 * lawan padat, jadi tidak melihat tingkat tangga yang dipilih produksi.
 *
 * Sesudah sapuan di atas, tiap sertifikat dirender ulang lewat
 * `SertifikatSatuHalaman` (tangga pemadatan produksi) dan median huruf PDF-nya
 * diukur (`PengukurHurufCanvas`) untuk SEMUA profil terdaftar: Bath/Furnace/
 * Refrigerator lewat sesi sintetis, tiga profil tekanan yang ditahan lewat
 * snapshot tanpa terbit. Rasio terhadap median huruf master dikunci per profil
 * DAN per sesi dengan baseline dua arah — `tests/Fixtures/sertifikat-huruf/baseline-huruf-master.json`,
 * aturannya di `Tests\Concerns\GerbangHurufSertifikat`, diuji cepat oleh
 * `GerbangHurufSertifikatTest`. Turun = merah; naik tanpa baseline = merah.
 * Membekukan baseline: `SIDIK_TULIS_BASELINE=1 php artisan test --filter=SertifikatSemuaAlatSatuHalamanTest`.
 *
 * Render ulang itu memakai nama dan alamat pelanggan SEPANJANG DATA NYATA
 * (`PEMILIK_UJI`/`ALAMAT_UJI`), bukan data seeder. Data seeder pendek (alamat 32
 * karakter) menyisakan ruang yang tidak ada di sertifikat sungguhan: dengan data
 * seeder Sieve Mesh tetap 7,9 pt walau tanpa perbaikan, hanya dengan alamat dua
 * baris ia jatuh ke 7,1 pt. Gerbang yang diukur dengan data seeder lolos tepat di
 * regresi yang dibuatnya.
 *
 * ## Ambang yang berlaku sekarang
 *
 * Kotak normal: yang paling mepet **Conductivity Meter** — masih normal di
 * 86px, kena demosi ke padat di 88px. Nilai yang dipakai 80px.
 *
 * Kotak padat: **Visible Spectrofotometer** meluap ke halaman dua begitu lewat
 * 30px, jadi 24px dipertahankan. Dia lembar terpadat di sistem (24 titik
 * ketidakpastian), dan di bawah mode padat nggak ada jaring pengaman lagi.
 *
 * Ambang kedua itu cuma ketahuan sesudah sapuannya berhenti melewat diam-diam —
 * lihat `terbitkan()`.
 */
class SertifikatSemuaAlatSatuHalamanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dua sumber goyah yang dicabut dari render sapuan ini (30 Sep 2026).
     *
     * Akar flake "meluap / lebih mepet" yang muncul SEKALI di suite penuh
     * (MySQL: `22506.01.A`; SQLite: `DEMO-FM-TOT-001`) tidak berhasil
     * direproduksi: HTML lembar diadu byte-per-byte antara jalan sendirian dan
     * sesudah suite penuh, identik; margin diukur ulang, sama dengan baseline.
     * Jadi isi lembar bukan pelakunya. Yang terbukti bersama dan bisa dicabut:
     *
     *   1. Jam dinding. Sapuan ini butuh 10-20 menit; tanggal terbit tercetak
     *      dari `now()` per sertifikat, dan tanggal jatuh tempo standar dihitung
     *      dari `now()` waktu seeder. Suite yang melintasi tengah malam, atau
     *      sekadar berjalan berjam-jam, merender lembar yang isinya bergantung
     *      pada kapan tiap baris dieksekusi. `freezeTime()` menjadikannya satu
     *      instan.
     *   2. Direktori sementara dompdf. `temp_dir` bawaannya `sys_get_temp_dir()`,
     *      dipakai bersama SEMUA proses di mesin. Dua proses test bersamaan
     *      diuji 30 Sep 2026: salah satunya mati dengan
     *      `unlink(...cpdXXXX.tmp.png): Permission denied` — berkas gambar
     *      sementaranya bertabrakan. Tiap test sekarang punya direktori sendiri.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->tempDompdf = storage_path('framework/testing/dompdf-'.getmypid().'-'.bin2hex(random_bytes(4)));
        @mkdir($this->tempDompdf, 0777, true);
        config(['dompdf.options.temp_dir' => $this->tempDompdf]);

        // Gerbang huruf: ukuran huruf tiap render dicatat, tanpa render tambahan.
        PengukurHurufCanvas::pasang();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDompdf.'/*') ?: [] as $berkas) {
            @unlink($berkas);
        }
        @rmdir($this->tempDompdf);

        parent::tearDown();
    }

    private string $tempDompdf = '';

    /**
     * Semua sesi bawaan yang harus keterbit, `nomor_sesi (nama alat)`.
     *
     * Dipatok, bukan dihitung. Sapuan yang daftarnya datang dari database punya
     * satu cara gagal yang nggak bersuara: sesi yang berhenti bisa disetujui
     * hilang dari daftar, dan test-nya tetap hijau — cuma memeriksa lebih
     * sedikit. Nama yang HILANG dari daftar ini berarti sertifikat alat itu
     * berhenti terbit, dan itu jauh lebih besar dari urusan tata letak.
     *
     * @var list<string>
     */
    private const DIPERIKSA = [
        // Alat ke-31 (15 Sep 2026). Tiga tabel titik (Outside/Inside/Depth) di
        // satu sertifikat — yang paling panjang dari ketiga alat baru.
        '001-CAL-126 (Jangka Sorong)',
        '001-UBLK-05.26 (Height Gauge)',
        '002-UB.P-11-20 (Outside Micrometer)',
        '003-UB.P-11-20 (Digital Outside Micrometer)',
        '0106-CAL-1023 (Micrometer Digital)',
        '011-CAL-525 (Timbangan)',
        '0133-CAL-324 (Centrifuge)',
        '0135-CAL-125 (Thermometer Glass)',
        '0136-CAL-123 (Timbangan Elektronik)',
        '0140-CAL-424 (Digital Tachometer)',
        '015-CAL-424 (Stopwatch)',
        '019-CAL-425 (Moisture Analyzer)',
        '0312-CAL-624 (Thermohygrometer)',
        // Alat ke-30 (15 Sep 2026), sepuluh titik tumpukan balok ukur.
        '0392-CAL-324 (Dial Consolidation)',
        '0513-CAL-1124 (Thermocouple Thermometer)',
        // Alat ke-32 (15 Sep 2026), tiga baris: warp, weft, Ø kawat.
        '0736-CAL-526 (Sieve Mesh)',
        '2211.11.R (Refractometer)',
        '22506.01.A (Temperature Calibrator)',
        '2405.03.AV (Incubator)',
        '2405.13.A (pH Meter)',
        '2405.32.A.NK (Conductivity Meter)',
        '2406.25.AI (Oven)',
        '2406.32.A (Turbidimeter)',
        '2406.32.C (Chlorine Meter)',
        '2406.50.S (DO Meter)',
        '2406.51.S (Autoclave)',
        '2602.03.A (Multi Gas Detector)',
        '2606.08.C (Temperature Recorder Controller)',
        '2607.59.W (Viscometer)',
        // Alat ke-29, sesi contoh 14 keping. Di luar lampiran akreditasi,
        // jadi sertifikatnya terbit tanpa kop KAN.
        'DEMO-AT-001 (Anak Timbangan)',
        // Alat ke-46..48, piston volume (28 Sep 2026). Terbit TANPA vonis
        // PASS/FAIL (aturan keputusan menunggu V-6); tidak memicu G-2/G-7/G-8,
        // jadi tidak ditahan.
        'DEMO-BD-GRD-001 (Buret Digital)',
        'DEMO-COND-MSCM (Conductivity Meter)',
        'DEMO-DS-FIX-001 (Dispensett)',
        // Alat ke-27 & ke-28 — sertifikatnya membawa satu blok tambahan
        // (`PIPE SPECIFICATION & SENSOR MOUNTING`) yang alat lain tidak punya,
        // jadi justru keduanya yang paling perlu diperiksa muat satu halaman.
        'DEMO-FM-FLW-001 (Flow Meter Cairan (Flowrate))',
        // Alat yang SAMA dengan baris di bawah, metode yang lain: varian
        // gravimetri (ISO 4185). Tiga titik terbit, titik keempat diblokir
        // karena di luar pita CMC — sertifikatnya tetap satu halaman.
        'DEMO-FM-GRAV-TOT-001 (Flow Meter Cairan (Totalizer))',
        'DEMO-FM-TOT-001 (Flow Meter Cairan (Totalizer))',
        'DEMO-HYD-001 (Hydrometer)',
        // Alat ke-41, gaya kedua (24 Sep 2026). Sepuluh titik — lima lebih
        // banyak dari UTM — dan dua desimal, bukan satu. Pasangan itu yang
        // bikin dia pantas disapu: tabelnya paling tinggi di kelompok gaya.
        'DEMO-LC-001 (Load Cell)',
        'DEMO-PP-FIX-001 (Micropipette)',
        // Alat ke-42, gaya ketiga (25 Sep 2026). Sepuluh titik, dan kolom
        // ketiganya FAKTOR kalibrasi berdesimal lima — paling lebar di
        // kelompoknya.
        'DEMO-PR-001 (Proving Ring)',
        'DEMO-SPECTRO-NIAGA (Visible Spectrofotometer)',
        // Alat ke-12. Sesi contohnya menyusul 11 Sep 2026 — sampai itu
        // TIDS satu-satunya alat yang sertifikatnya tidak pernah dirender
        // sapuan ini.
        'DEMO-TIDS-001 (Temperature Recorder)',
        // Alat ke-40, dan yang PERTAMA di kelompok Gaya (24 Sep 2026).
        // Sertifikatnya mencetak empat kolom yang sama dengan alat lain
        // (Standard | UUT | Correction | RRPE), tapi angkanya dikonversi balik
        // dari kN ke satuan mesin lewat `cetakDalamSatuanAlat()` — tanpa itu
        // titik 200 kgf tercetak `2,0`.
        'DEMO-UTM-001 (Universal Testing Machine)',
        // Alat ke-34..39, Volumetric Glassware (22 Sep 2026). Graduated
        // mencetak sampai lima baris dengan SATU U95 di bawah tabel.
        'DEMO-VOL-001 (Pipet Volume)',
        'DEMO-VOL-002 (Labu Ukur)',
        'DEMO-VOL-003 (Picnometer)',
        'DEMO-VOL-004 (Gelas Ukur)',
        'DEMO-VOL-005 (Buret)',
        'DEMO-VOL-006 (Pipet Ukur)',
    ];

    /**
     * Sesi bawaan yang SENGAJA tidak bisa terbit: ditahan menunggu keputusan
     * metode Technical Manager (T-1/T-2/T-11 tekanan, keputusan 28 Sep 2026 di
     * `database/data/log-metode-tekanan-piston.json`). Dipatok juga — sesi yang
     * diam-diam lepas dari penahanan sama berbahayanya dengan sesi yang diam-diam
     * berhenti terbit. Begitu TM menjawab dan log mencatatnya, sesi ini pindah
     * ke `DIPERIKSA`.
     *
     * @var list<string>
     */
    private const DITAHAN = [
        'DEMO-DP-ADT-001',
        'DEMO-PG-13G-001',
        'DEMO-VG-07G-001',
    ];

    /**
     * Nama dan alamat pelanggan penguji huruf (panjang yang sama dengan penanda
     * sintetis audit 13 Nov 2026: 21 dan 50 karakter) — sepanjang data pelanggan
     * nyata, bukan sependek data seeder. Hanya mengganti `header.owner`/`address`
     * di snapshot dalam memori; tidak ada nama pelanggan sungguhan.
     */
    private const PEMILIK_UJI = 'PELANGGAN SINTETIS 26';

    private const ALAMAT_UJI = 'Alamat Sintetis 26, Jl. Contoh No. 26, Kota Contoh';

    /**
     * Bath/Furnace/Refrigerator — nama, kode profil, rentang bawah, rentang atas
     * (= kapasitas yang diketik). Hanya varian Yokogawa (master Enclosure Constant).
     *
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    private const ENCLOSURE_SINTETIS = [
        'Bath' => ['bath', 0, 100],
        'Furnace' => ['furnace', 0, 1000],
        'Refrigerator' => ['refrigerator', 0, 100],
    ];

    /**
     * Alat yang lembarnya memang nggak muat di mode normal, dan sudah begitu
     * SEBELUM kotak TTD digedein — dipastikan dengan menyapu ulang di 46px.
     *
     * @var list<string>
     */
    private const BUTUH_PADAT = [
        // Height Gauge (alat ke-26) KELUAR dari daftar ini 20 Okt 2026 (K-50-15,
        // pemilik 18 Okt: sertifikat persis lembar SERTIFIKAT master): master
        // `184` menyembunyikan baris 25:28 & 30:32, jadi yang tercetak tinggal
        // tiga titik (25 / 300 / 600 mm) — lembarnya muat di mode normal
        // walau blok `Note : Result of Paralelism` ikut tercetak.
        //
        // KETIGA sesi Micrometer masuk daftar ini, dan sebabnya jumlah baris:
        // sertifikat masternya mencetak SEBELAS titik ukur, kedua terbanyak
        // sesudah Thermohygrometer. Bukan gejala tata letak yang menyusut —
        // lembarnya memang panjang sejak sesi contohnya lahir.
        '002-UB.P-11-20 (Outside Micrometer)',
        '003-UB.P-11-20 (Digital Outside Micrometer)',
        '0106-CAL-1023 (Micrometer Digital)',
        // Thermometer Glass masuk daftar ini 12 Nov 2026 (B1/R2, K-50-15): baris sensor
        // acuan `PRT PT100` (`SERTIFIKAT!B43`) kini SELALU tercetak seperti master, jadi
        // Standard Used bertambah satu baris dan sesi contoh lima titik tidak lagi muat di
        // mode normal (sebab yang sama dengan Viscometer di bawah). Tetap SATU halaman,
        // huruf 7,9 pt = 1,00 x master (rapat-1).
        '0135-CAL-125 (Thermometer Glass)',
        '0312-CAL-624 (Thermohygrometer)',
        // Dial Indicator (alat ke-30, 15 Sep 2026) — sebab yang sama dengan
        // Height Gauge & Micrometer di atas: sesi contoh masternya SEPULUH titik
        // tumpukan balok ukur. Lembarnya panjang sejak lahir, bukan tata letak
        // yang menyusut.
        '0392-CAL-324 (Dial Consolidation)',
        // Sieve Mesh (alat ke-32) — bukan karena barisnya banyak (cuma tiga),
        // tapi karena tiap baris KELOMPOK sendiri: Wrap, Weft, dan Wire Diameter
        // punya budget, U95, dan `k` masing-masing, jadi sertifikatnya mencetak
        // tiga judul + tiga kalimat `Uncertainty U95% = ±`. Master pun
        // melaporkan U95 per parameter.
        '0736-CAL-526 (Sieve Mesh)',
        '22506.01.A (Temperature Calibrator)',
        // Kedua Enclosure masuk sini 15 Okt 2026, dan sebabnya isi, bukan tata
        // letak yang menyusut: sertifikatnya dulu SALAH mencetak satu baris
        // `Standard | UUT | Correction` per set point (Correction = Keseragaman).
        // Sekarang mencetak dua bagian master seperti Autoklaf — A) Sebaran
        // Suhu (dua baris × 9 posisi per set point) dan B) Kinerja Enklosur —
        // jadi 4 set point Inkubator = 8 baris + 4 baris + dua kepala tabel.
        '2405.03.AV (Incubator)',
        // Conductivity masuk sini 20 Okt 2026 (K-50-15, pemilik 18 Okt:
        // sertifikat persis lembar SERTIFIKAT master). Master `157` mencetak
        // tabel SENDIRI per satuan (`C33:T36` µS/cm, `C38:T42` mS/cm), masing-
        // masing dengan U95 & kalimat `k`-nya; dulu baris mS/cm salah tercetak
        // di bawah kepala `(µS/cm)` satu tabel. Dua tabel + baris satuan =
        // isinya bertambah, bukan tata letak yang menyusut — bentuk lama
        // (snapshot tanpa `tata_letak`) pun sudah meluap di mode normal.
        '2405.32.A.NK (Conductivity Meter)',
        '2406.25.AI (Oven)',
        // Chlorine KELUAR dari daftar ini 21 Okt 2026 (K-50-15, pemilik 18 Okt:
        // sertifikat persis lembar SERTIFIKAT master): master `156` mencetak
        // SATU tabel dengan kolom U95 & Remark (`C16:W19`) dan satu kalimat
        // `k`, bukan dua tabel per remark dengan judul & baris U95 masing-
        // masing. Lembarnya memendek, jadi muat di mode normal.
        '2406.51.S (Autoclave)',
        '2602.03.A (Multi Gas Detector)',
        '2606.08.C (Temperature Recorder Controller)',
        // Viscometer masuk daftar ini 10 Nov 2026 (G1/R2, K-50-15): baris
        // `Termometer & Sensor Std.` (`SERTIFIKAT!B35`) kini SELALU tercetak
        // seperti master, jadi Standard Used bertambah satu baris dan sesi
        // contoh lima baris standar tidak lagi muat di mode normal. Tetap SATU
        // halaman di mode padat.
        //
        // Daftar ini diukur dengan sakelar `sertifikat.kop_pracetak` MATI (bawaan,
        // kop/judul/kode dokumen tetap tercetak). Dengan sakelar menyala (G0-b P1)
        // Sieve Mesh, UTM, Gas Detector, dan Viscometer muat di mode normal
        // (Viscometer hanya sisa 6 px, mepet `SertifikatPunyaMarginTest`) — ukur
        // ulang daftar ini dan tes margin SEBELUM menyalakannya.
        '2607.59.W (Viscometer)',
        // Load Cell (alat ke-41, 24 Sep 2026) — sebab yang sama dengan Height
        // Gauge & Micrometer: jumlah baris. Sertifikat masternya mencetak
        // SEPULUH titik, dan tiap angkanya dua desimal (resolusi 0,01 kN),
        // jadi kolomnya juga lebih lebar dari UTM yang satu desimal. UTM
        // sendiri cuma enam titik dan tetap muat di mode normal — jadi ini
        // bukan gejala tata letak gaya yang menyusut, melainkan sesi contoh
        // yang memang paling panjang di kelompoknya.
        'DEMO-COND-MSCM (Conductivity Meter)',
        'DEMO-LC-001 (Load Cell)',
        // Proving Ring (alat ke-42, 25 Sep 2026) — sepuluh titik, DAN kolom
        // ketiganya berdesimal lima (faktor kalibrasi 0,12632 kgf/Div) sementara
        // dua kolom gaya di sebelahnya dua desimal. Tabel paling lebar sekaligus
        // paling tinggi di kelompok gaya.
        //
        // (Sampai 20 Okt 2026 — lihat entri UTM di bawah.) Yang justru TIDAK
        // masuk sini: UTM. Kolom sebaran (RRPE) yang baru
        // ditambahkan 25 Sep 2026 tidak menggesernya ke mode padat — enam titik
        // masih muat berempat kolom. Itu yang membedakan "tata letak menyusut"
        // dari "lembarnya memang panjang".
        'DEMO-PR-001 (Proving Ring)',
        // UTM masuk sini 20 Okt 2026 (K-50-15, pemilik 18 Okt: sertifikat
        // persis lembar SERTIFIKAT master). Master `163` mencetak tiga blok
        // yang dulu tidak ada: `Type of Force : Pull` (`E18`), legenda `RRPE`
        // (`E37`) dan `Units Used: kgf : Kilogram-force` (`E39:I40`). Isinya
        // bertambah, bukan tata letak yang menyusut; titik nol (`E21`,
        // disembunyikan master) malah keluar dari tabel. Tetap satu halaman.
        'DEMO-UTM-001 (Universal Testing Machine)',
    ];

    /**
     * Tiga hal sekaligus, dan namanya cuma menyebut yang pertama:
     *
     *   1. Tiap sesi bawaan BISA diterbitkan — daftarnya dipatok di `DIPERIKSA`.
     *   2. Nggak ada lembar yang meluap, bahkan sesudah mode padat dipaksa.
     *   3. Daftar alat yang BUTUH mode padat nggak berubah.
     *
     * Yang ketiga yang paling gampang jebol tanpa suara, dan alasannya ada di
     * docblock kelas.
     */
    public function test_semua_sertifikat_bawaan_muat_satu_halaman(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $tampilan = app(DataTampilanSertifikat::class);
        $meluap = [];
        $padat = [];
        $diperiksa = [];
        $ditahan = [];
        $validator = app(CalibrationValidator::class);
        // Gerbang huruf (#3): sertifikat tiap sesi, dirender ulang di fase kedua dengan header panjang.
        $untukHuruf = [];

        foreach (CalibrationSession::query()->get() as $sesi) {
            $menungguTm = collect($validator->periksa($sesi)['temuan'])
                ->contains('kode', CalibrationValidator::MENUNGGU_KEPUTUSAN_TM);

            if ($menungguTm) {
                // Konfirmasi admin pun tidak boleh menembusnya.
                $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
                    ->assertStatus(422);
                $ditahan[] = $sesi->nomor_sesi;

                // Tak bisa terbit, tapi hurufnya tetap diukur: snapshot dibangun di
                // jalur yang sama dengan penerbitan, tanpa menerbitkan apa pun.
                $untukHuruf[] = [$sesi, $this->bangunTanpaTerbit($sesi)];

                continue;
            }

            $sertifikat = $this->terbitkan($sesi);
            $untukHuruf[] = [$sesi, $sertifikat];

            $diperiksa[] = $this->sebut($sesi, $sertifikat);
            $bahan = $tampilan->untuk($sertifikat);
            $alat = $this->sebut($sesi, $sertifikat);

            // Urutan yang sama persis dengan SertifikatSatuHalaman. Disalin —
            // bukan dipanggil — karena yang dicari di sini justru CABANG MANA
            // yang kepakai, dan layanannya cuma mengembalikan isi PDF-nya.
            $halaman = $this->halaman($bahan, false);

            if ($halaman > 1) {
                $padat[] = $alat;
                $halaman = $this->halaman($bahan, true);
            }

            if ($halaman > 1) {
                $meluap[] = $alat;
            }
        }

        sort($diperiksa);
        sort($ditahan);

        $this->assertSame(
            self::DITAHAN,
            $ditahan,
            'Daftar sesi bawaan yang DITAHAN menunggu keputusan TM berubah. Nama HILANG = penahanannya lepas '
            .'(sudah dicatat di log metode?); nama BARU = cacat master baru ikut menahan.',
        );

        $this->assertSame(
            self::DIPERIKSA,
            $diperiksa,
            "Daftar sertifikat yang diperiksa berubah.\n"
            .'Nama yang HILANG = sesi itu berhenti bisa diterbitkan, dan sapuan ini jadi diam-diam '
            ."memeriksa lebih sedikit — persis cara test sapuan gagal tanpa bersuara.\n"
            .'Nama BARU = seeder nambah sesi; perbarui DIPERIKSA.',
        );

        $this->assertSame(
            [],
            $meluap,
            'Meluap ke halaman dua walau mode padat dipaksa: '.implode(', ', $meluap),
        );

        sort($padat);

        $this->assertSame(
            self::BUTUH_PADAT,
            $padat,
            "Daftar alat yang butuh mode padat berubah.\n"
            .'Nama BARU di daftar = perubahan tata letak baru saja mengecilkan huruf sertifikat alat itu, '
            ."tanpa error apa pun. Tersangka pertama: tinggi `.ttd .ruang-ttd` atau ukuran huruf.\n"
            .'Kalau perubahannya memang disengaja, perbarui BUTUH_PADAT berikut alasannya.',
        );

        // ---- #3 Huruf mengikuti master, SEMUA profil terdaftar ------------------------------

        // Bath/Furnace/Refrigerator tidak punya sesi bawaan seeder: sesi sintetis dari grid
        // master Enclosure Constant Yokogawa (pola `SertifikatS4BandingUlangTest`).
        foreach (self::ENCLOSURE_SINTETIS as $nama => [$kode, $min, $maks]) {
            $sesi = $this->sesiEnclosureSintetis($nama, $kode, $min, $maks);
            $untukHuruf[] = [$sesi, $this->bangunTanpaTerbit($sesi)];
        }

        // Dirender ulang lewat `SertifikatSatuHalaman` (tangga pemadatan produksi) dengan nama &
        // alamat pelanggan sepanjang data nyata. Data seeder pendek (alamat 32 karakter) menyisakan
        // ruang yang tidak ada di sertifikat sungguhan: dengan data seeder, Sieve Mesh tetap 7,9 pt
        // padahal dengan alamat dua baris jatuh ke 7,1 pt — regresi 13 Nov lolos justru karena itu.
        $hurufPerSesi = [];
        $tidakSatuHalaman = [];

        foreach ($untukHuruf as [$sesi, $sertifikat]) {
            $snapshot = $sertifikat->snapshot;
            $snapshot['header']['owner'] = self::PEMILIK_UJI;
            $snapshot['header']['address'] = self::ALAMAT_UJI;
            $sertifikat->setAttribute('snapshot', $snapshot);

            $this->catatHuruf($hurufPerSesi, $tidakSatuHalaman, $sesi, $sertifikat);
        }

        $this->assertSame(
            [],
            $tidakSatuHalaman,
            'Sertifikat tidak muat SATU halaman dengan header panjang walau tangga pemadatan habis: '.implode('; ', $tidakSatuHalaman),
        );

        $terdaftar = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $terdaftar[$profil->kode()] = true; // registry memuat TidsProfile dua kali
        }

        $terdaftar = array_keys($terdaftar);
        $terukur = GerbangHurufSertifikat::terburuk($hurufPerSesi);
        $baseline = GerbangHurufSertifikat::baseline();

        if (getenv('SIDIK_TULIS_BASELINE') === '1') {
            $tolak = GerbangHurufSertifikat::tulis($terukur, $terdaftar, $baseline, null, $hurufPerSesi);

            $this->assertSame([], $tolak, "Baseline huruf TIDAK ditulis:\n".implode("\n", $tolak));

            return;
        }

        $masalah = [
            ...GerbangHurufSertifikat::periksa($terukur, $terdaftar, $baseline, $hurufPerSesi),
            ...GerbangHurufSertifikat::selarasDenganFontMedian($baseline),
            ...GerbangHurufSertifikat::periksaMasterPt($baseline, GerbangHurufSertifikat::masterPt()),
        ];

        $this->assertSame(
            [],
            $masalah,
            "Gerbang huruf sertifikat (rasio huruf app / master per profil) tidak lolos:\n- ".implode("\n- ", $masalah),
        );
    }

    /**
     * Render sertifikat lewat `SertifikatSatuHalaman` dan catat median huruf PDF-nya.
     * Hasil render wajib keluaran canvas pengukur — kalau tidak, gerbangnya mengukur
     * sesuatu yang bukan PDF itu.
     *
     * @param  array<string, list<array{app_pt: float, sesi: string}>>  $perSesi
     * @param  list<string>  $tidakSatuHalaman
     */
    private function catatHuruf(array &$perSesi, array &$tidakSatuHalaman, CalibrationSession $sesi, Certificate $sertifikat): void
    {
        $kode = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)->kode();

        try {
            $isiPdf = app(SertifikatSatuHalaman::class)->isi($sertifikat);
        } catch (SertifikatLebihSatuHalamanException) {
            $tidakSatuHalaman[] = "{$sesi->nomor_sesi} ({$kode})";

            return;
        }

        $catatan = PengukurHurufCanvas::catatan($isiPdf);

        $this->assertNotNull(
            $catatan,
            "{$sesi->nomor_sesi} ({$kode}): PDF-nya tidak punya catatan ukuran huruf — canvas pengukur tidak terpasang, "
            .'atau PDF ini bukan hasil render sapuan ini.',
        );

        if ($catatan['halaman'] !== 1) {
            $tidakSatuHalaman[] = "{$sesi->nomor_sesi} ({$kode}) {$catatan['halaman']} halaman";
        }

        $perSesi[$kode][] = ['app_pt' => PengukurHurufCanvas::median($catatan['ukuran']), 'sesi' => (string) $sesi->nomor_sesi];
    }

    /** Sesi Enclosure sintetis (Yokogawa Type N) lewat seeder Enclosure, hanya di DB uji. */
    private function sesiEnclosureSintetis(string $nama, string $kode, int $min, int $maks): CalibrationSession
    {
        $seeder = new EnclosureSeeder;
        $grid = (new ReflectionClassConstant(EnclosureSeeder::class, 'GRID_YOKO'))->getValue();
        $standar = Standard::where('nama', 'Temperature Calibrator Yokogawa CA 150 Handy Cal')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->orderBy('id')->firstOrFail();
        $nomor = "HURUF.{$kode}.1";

        (new ReflectionMethod($seeder, 'seedSesi'))->invoke(
            $seeder,
            ['nama' => "PT Sintetis Huruf {$kode}", 'alamat' => 'Alamat Sintetis Huruf'],
            ['nama_alat' => $nama, 'nama_alat_kemampuan' => $nama, 'merk' => 'MerkSintetis', 'model' => 'Model-1', 'serial_number' => 'HURUF-'.strtoupper($kode), 'range_min' => $min, 'range_max' => $maks, 'kapasitas' => (string) $maks],
            ['nomor_sesi' => $nomor, 'nomor_order' => $nomor, 'tanggal' => '2024-05-02', 'tipe_sensor' => 'Type N'],
            $grid,
            $standar,
            $teknisi,
        );

        return CalibrationSession::query()->where('nomor_sesi', $nomor)->firstOrFail();
    }

    /**
     * Baris sertifikat + snapshot untuk sesi yang TIDAK diterbitkan (ditahan keputusan TM,
     * atau sintetis): dibangun lewat `CertificateSnapshotBuilder` (jalur yang sama dengan
     * penerbitan) supaya `SertifikatSatuHalaman` bisa merendernya. Bukan sertifikat terbit.
     */
    private function bangunTanpaTerbit(CalibrationSession $sesi): Certificate
    {
        $sertifikat = new Certificate;
        $sertifikat->forceFill([
            'organization_id' => $sesi->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'HURUF-UJI/'.$sesi->id,
            'qr_token' => 'huruf-uji-'.$sesi->id,
            'qr_payload' => 'https://contoh.invalid/verify/huruf-uji-'.$sesi->id,
            'diterbitkan_pada' => now(),
            'berlaku_sampai' => now()->addYear(),
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
        ])->save();

        $lengkap = CalibrationSession::with([
            'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard', 'thermohygro',
            'standarDicek', 'calibrationMethod', 'room', 'rawMeasurements', 'uncertaintyCalculations.standard',
        ])->findOrFail($sesi->id);

        $sertifikat->update([
            'validasi' => app(CalibrationValidator::class)->periksa($lengkap),
            'snapshot' => app(CertificateSnapshotBuilder::class)->bangun($lengkap, $sertifikat),
        ]);

        return $sertifikat->fresh();
    }

    /**
     * Jumlah halaman hasil render — angka dari dompdf, bukan perkiraan.
     *
     * @param  array<string, mixed>  $bahan
     */
    private function halaman(array $bahan, bool $paksaPadat): int
    {
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);

        // `output()` dipanggil DULU: jumlah halaman baru ada sesudah dompdf
        // benar-benar merender, dan `getCanvas()` sebelum itu balik nol.
        $pdf->output();

        return (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /** `nomor_sesi (nama alat)` — unik, dan kebaca waktu test-nya merah. */
    private function sebut(CalibrationSession $sesi, Certificate $sertifikat): string
    {
        $alat = $sertifikat->snapshot['header']['equipment_name'] ?? '(alat?)';

        return "{$sesi->nomor_sesi} ({$alat})";
    }

    /**
     * Terbitkan sertifikatnya, dan GAGALKAN test kalau nggak bisa.
     *
     * Versi pertama fungsi ini membungkus `postJson()` dengan try/catch lalu
     * mengembalikan null. Itu dua kesalahan sekaligus, dan CodeRabbit yang
     * menangkapnya: `postJson()` **nggak melempar** pada 422, jadi catch-nya
     * praktis mati — dan yang gagal disetujui dilewat diam-diam, sementara
     * ambang lama (`> 10`) bikin test tetap hijau walau tinggal 11 sertifikat
     * yang benar-benar diperiksa.
     *
     * ## Kenapa `abaikan_peringatan: true`
     *
     * Tujuh sesi bawaan (Viscometer, Spectro, Thermohygrometer, Digital
     * Tachometer, Centrifuge, Temperature Recorder, dan Conductivity kedua)
     * balik 422 `butuh_konfirmasi` — BUKAN karena hitung ulangnya beda, tapi
     * karena punya peringatan domain seperti `pembacaan_di_luar_rentang` atau
     * `centrifuge_di_luar_akreditasi`. Di alur aslinya admin menyetujuinya
     * dengan konfirmasi eksplisit, dan itu yang ditiru di sini.
     *
     * Yang dibeli: sapuannya naik dari 17 jadi 24 sertifikat — tujuh alat yang
     * tadinya nggak pernah dirender sekali pun sekarang ikut dijaga.
     *
     * `boleh_terbit: false` tetap bikin merah, dan memang harus: itu penolakan
     * keras, bukan peringatan yang boleh dikonfirmasi.
     */
    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = $sesi->fresh()->certificate()->first();

        $this->assertInstanceOf(
            Certificate::class,
            $sertifikat,
            "Sesi {$sesi->nomor_sesi} disetujui tapi sertifikatnya nggak kebentuk.",
        );

        return $sertifikat;
    }
}
