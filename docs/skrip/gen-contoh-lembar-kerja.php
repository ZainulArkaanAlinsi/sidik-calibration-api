<?php

use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Services\Calibration\Profiles\AutoclaveProfile;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\DialIndicatorProfile;
use App\Services\Calibration\Profiles\JangkaSorongProfile;
use App\Services\Calibration\Profiles\SieveProfile;
use App\Services\Calibration\Profiles\Enclosure\BathProfile;
use App\Services\Calibration\Profiles\Enclosure\FurnaceProfile;
use App\Services\Calibration\Profiles\Enclosure\InkubatorProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use App\Services\Calibration\Profiles\Enclosure\RefrigeratorProfile;
use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use App\Services\Calibration\Profiles\HydrometerProfile;
use Illuminate\Contracts\Console\Kernel;

/**
 * Generate berkas bentuk lembar kerja contoh (`contoh_lembar_kerja_*.dart`) di
 * repo `sidik-calibration-mobile`, dari bentuk yang BENERAN dikirim server.
 *
 * ## Kenapa digenerate, bukan diketik
 *
 * Berkas contoh di repo mobile itu salinan APA ADANYA respons
 * `GET /api/calibrations/lembar-kerja`. Disusun ulang tangan, dia menyimpang
 * diam-diam begitu backendnya direvisi — dan test mode mock yang jalan di atas
 * bentuk basi memberi rasa aman yang salah.
 *
 * Yang bikin itu mahal bukan mode mock-nya sendiri (dia cuma hidup di build
 * `USE_MOCK=true`), melainkan ini: bug yang lolos ke `main` sudah TIGA kali
 * lolos justru karena bentuk mock-nya tidak ada, jadi tidak ada satu pun test
 * yang pernah menyuapkan bentuk aslinya ke parser — TIDS, Timbangan (lima cacat
 * sekaligus), dan Micrometer (tiga cacat server yang lolos 3.128 test backend).
 *
 * ## SATU skrip untuk semua kelompok
 *
 * Emitter Dart-nya ([dart]) satu-satunya bagian yang rumit di sini, dan
 * menggandakannya per kelompok persis pola yang §18 `permintaan-user-7.md`
 * cabut (37 salinan helper profil jadi 6). Kelompok baru cukup menambah satu
 * entri di `KELOMPOK`.
 *
 * Jalankan dari akar repo API:
 *     php docs/skrip/gen-contoh-lembar-kerja.php
 */
require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Folder tujuan di repo mobile.
 *
 * Bawaannya jalur Windows tempat kedua repo hidup berdampingan di laptop lab.
 * Bisa ditimpa lewat env `AKAR_MOBILE` supaya skrip ini juga jalan di CI dan di
 * mesin lain tanpa menyunting berkas — skrip yang cuma jalan di satu komputer
 * berhenti dijalankan, dan berkas contoh yang berhenti digenerate diam-diam
 * menyimpang dari server.
 *
 *     AKAR_MOBILE=../sidik-calibration-mobile/lib/services php docs/skrip/gen-contoh-lembar-kerja.php
 */
define('AKAR_MOBILE', rtrim(
    getenv('AKAR_MOBILE') ?: 'C:/Users/USER/sidik-calibration-mobile/lib/services',
    '/\\',
).'/');

/**
 * Ubah nilai PHP jadi literal Dart yang bisa dibaca.
 *
 * Float bulat ditulis dengan `.0` supaya tipenya tetap `double` di Dart — `1`
 * dan `1.0` beda tipe di sana, dan yang pertama bikin `whereType<double>()`
 * melewatkannya. Bentuk kegagalan itu sudah pernah menggigit repo mobile:
 * daftar `pengulangan` yang berisi objek alih-alih angka lolos tanpa error tapi
 * menghasilkan NOL kolom pembacaan — lembar kerja yang terbuka rapi dan tidak
 * bisa diisi.
 */
function dart(mixed $nilai, int $lekuk = 1): string
{
    $spasi = str_repeat('  ', $lekuk);
    $tutup = str_repeat('  ', $lekuk - 1);

    if ($nilai === null) {
        return 'null';
    }

    if (is_bool($nilai)) {
        return $nilai ? 'true' : 'false';
    }

    if (is_int($nilai)) {
        return (string) $nilai;
    }

    if (is_float($nilai)) {
        return floor($nilai) === $nilai && abs($nilai) < 1e15
            ? number_format($nilai, 1, '.', '')
            : (string) $nilai;
    }

    if (is_string($nilai)) {
        return "'".str_replace(['\\', "'", "\n", '$'], ['\\\\', "\\'", '\\n', '\\$'], $nilai)."'";
    }

    if (! is_array($nilai)) {
        return 'null';
    }

    if ($nilai === []) {
        return '<dynamic>[]';
    }

    $isi = [];

    if (array_is_list($nilai)) {
        foreach ($nilai as $v) {
            $isi[] = $spasi.dart($v, $lekuk + 1).',';
        }

        return "[\n".implode("\n", $isi)."\n".$tutup.']';
    }

    foreach ($nilai as $k => $v) {
        $isi[] = $spasi.dart((string) $k, $lekuk + 1).': '.dart($v, $lekuk + 1).',';
    }

    return "{\n".implode("\n", $isi)."\n".$tutup.'}';
}

$kepalaAliran = <<<'DART'
/// Bentuk lembar kerja contoh **Flowmeter Ultrasonic** (kelompok Aliran, alat
/// ke-27 & ke-28).
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan. Isinya salinan APA ADANYA respons
/// `GET /api/calibrations/lembar-kerja?equipment_id=…` untuk alat contoh
/// ter-seed `FM-TOT-DEMO-01` dan `FM-FLW-DEMO-01`.
///
/// ## Yang cuma ada di lembar ini
///
///  1. **Satu titik, DUA deret berdampingan.** `flow_uut_pembacaan` dan
///     `flow_std_pembacaan` tabelnya terpisah, dan deviasinya lahir dari
///     selisih BERPASANGAN keduanya. Tertukar atau tertimpa, yang terbit bukan
///     error melainkan deviasi NOL di setiap titik.
///  2. **Deret UUT yang BERSARANG** pada varian Flowrate: `pengulangan` itu
///     ulangan, `kolom` (`durasi_1..3`) itu durasi 20"/40"/60". Simpangan
///     bakunya dihitung atas ketiga durasi ulangan ITU — diratakan, komponen
///     budget ke-3 keluar jauh lebih besar dan tetap terlihat masuk akal.
///  3. **LIMA tabel ber-`tahap` sama** di bagian `hasil`, plus dua di bagian
///     `pipa`. Kunci barisnya dipisah `offset_kunci` (0 / 1000 / 2000 / 3000 /
///     4000 / 5000 / 5100); tanpa itu angka yang diketik di satu kotak muncul
///     di kotak lain, tanpa satu pun error.
///  4. **Geometri pipa yang MENENTUKAN ANGKA.** Diameter & ketebalan kosong
///     bikin `u_A` nol dan DUA komponen budget lenyap sekaligus.
///
/// Dropdown bersumber master (`master_alat`, `master_ruangan`,
/// `master_thermohygro`) sengaja kosong di sini — sama seperti berkas contoh
/// alat lain, mode mock memang nggak punya masternya.
library;
DART;

$kepalaMassa = <<<'DART'
/// Bentuk lembar kerja contoh **Anak Timbangan** (kelompok Massa, alat ke-29).
///
/// Berkas SENDIRI, bukan menumpang `contoh_lembar_kerja_massa.dart` yang memuat
/// Timbangan (alat ke-21). Bentuk Timbangan lahir dari alat contoh `TB-100`
/// (kapasitas 100 kg, resolusi 0,02 kg) — dia butuh Equipment, dan generator ini
/// memanggil `bentukLembarKerja()` tanpa alat. Digabung, fixture Timbangan
/// tertimpa bentuk yang bukan miliknya.
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan. Isinya salinan APA ADANYA respons
/// `GET /api/calibrations/lembar-kerja?equipment_id=…`.
///
/// ## Yang cuma ada di lembar ini
///
///  1. **EMPAT tabel ber-`tahap` sama, satu per peran ABBA.** `at_s1`, `at_t1`,
///     `at_t2`, `at_s2` — dan urutannya MENGIKAT, karena
///     `de = (T1 − S1 − S2 + T2)/2` memberi tanda yang berbeda ke tiap suku.
///     Baris yang mendarat di peran yang salah membalikkan ARAH koreksi
///     kepingnya, tanpa satu pun error.
///  2. **Kunci barisnya dipisah `offset_kunci`** (0 / 1000 / 2000 / 3000).
///     Tanpa itu angka yang diketik di satu tabel muncul di tabel lain.
///  3. **Keempatnya menyatakan `simpan_ke`** (`measurements[].at_*`). Sampai
///     itu ada, payload dari HP berangkat tanpa satu pun kunci peran dan
///     SELURUH titik pulang "belum dihitung" — lembar penuh di layar, nol titik
///     terbit. Lihat `AlurPenuhAnakTimbanganTest` di repo API.
///  4. **Tiga kotak yang MENGGERAKKAN ANGKA** di `identitas_alat`:
///     `kelas_uut` dan `kelas_standar` memilih kolom tabel densitas OIML R111
///     dan baris tabel MPE; `timbangan` memasok dua dari enam komponen budget.
///     Ketiganya prasyarat tingkat-SESI — satu pun kosong, seluruh sesi ditolak.
///  5. **Tekanan udara tetap diminta** walau kertas Rev.0 tidak punya kolomnya.
///     Tanpa tekanan, densitas udara tidak bisa dihitung dan koreksi apung
///     seluruh keping hilang.
///
/// Dropdown bersumber master (`master_alat`, `master_ruangan`,
/// `master_thermohygro`) sengaja kosong di sini — sama seperti berkas contoh
/// alat lain, mode mock memang nggak punya masternya.
library;
DART;

$kepalaEnclosure = <<<'DART'
/// Bentuk lembar kerja contoh **Enclosure** — Oven, Furnace, Bath, Inkubator,
/// dan Refrigerator.
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan.
///
/// ## Kenapa kelimanya baru punya bentuk mock sekarang
///
/// Sampai 9 September 2026 kelimanya **jatuh ke bentuk pH** di mode mock, dan
/// nggak ada satu pun yang sadar: yang kegambar lembar pH tiga titik buffer,
/// tanpa error di mana pun. Yang menemukannya
/// `bentuk_mock_semua_profil_test.dart` — sapuan yang daftar profilnya diambil
/// dari registry server, bukan diketik tangan.
///
/// Kelima Enclosure yang paling berbahaya di daftar itu, dan alasannya bentuk
/// lembarnya: dia **GRID** (`grid_sensor` di tingkat atas, 9 termokopel x set
/// point), sementara bentuk pH nggak punya satu pun kotak yang cocok. Bukan
/// "sebagian kotaknya salah" — nggak ada yang cocok sama sekali.
///
/// ## Kelimanya SATU kertas dan SATU mesin hitung
///
/// `SIDIK-FM-CAL-0504_Rev.3` dipakai kelima profil, dan `EnclosureCalculator`
/// melayani kelimanya. Yang beda cuma judul & labelnya — itu sebabnya kelima
/// fungsi di bawah bentuknya nyaris identik, dan itu memang benar, bukan
/// salin-tempel yang kelupaan dirapikan.
///
/// `SemuaProfilLembarKerjaTest` di server MENGIZINKAN kelimanya berbagi satu
/// nomor formulir; pengecualian itu di-hardcode di sana berikut alasannya.
library;
DART;

$kepalaAnalitik = <<<'DART'
/// Bentuk lembar kerja contoh **instrumen analitik** — untuk sekarang baru
/// Conductivitymeter.
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan.
///
/// ## Kenapa berkas ini baru ada sekarang, dan kenapa cuma berisi satu
///
/// Kedelapan contoh instrumen analitik lain (pH, Turbidimeter, Chlorine,
/// Refractometer, Spectro, Visco, DO, Gas Detector) masih tinggal DI DALAM
/// `lembar_kerja_service.dart` — bentuk lama, sebelum contoh dipisah per
/// kelompok pengukuran. Conductivity penghuni pertama berkas ini; sisanya
/// menyusul kalau ada yang memindahkan, dan tempatnya sudah disiapkan.
///
/// Yang bikin Conductivity dikerjakan duluan: dia satu-satunya profil analitik
/// yang **nggak punya bentuk mock sama sekali** sampai 9 September 2026, jadi
/// di mode mock dia diam-diam memajang lembar pH — tanpa error di mana pun.
/// Yang menemukannya `bentuk_mock_semua_profil_test.dart`, sapuan yang daftar
/// profilnya diambil dari registry server.
///
/// ## Bukan alat yang divonis PASS/FAIL
///
/// Catatan utang yang pertama ditulis sempat menyebut Conductivity "DIVONIS
/// PASS/FAIL"; itu KELIRU. `ConductivityProfile::punyaToleransi()` memulangkan
/// `false`, dan `docs/kontrak-api.md` maupun tabel vonis mock sama-sama sudah
/// menempatkannya di kelompok yang berhenti di `U95%`. Dicatat di sini supaya
/// keliru itu nggak dipungut ulang dari riwayat.
library;
DART;

$kepalaAutoclave = <<<'DART'
/// Bentuk lembar kerja contoh **Autoklaf**.
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan.
///
/// ## Kenapa Autoklaf punya berkasnya sendiri
///
/// Besarannya SUHU dan TEKANAN sekaligus, jadi dia nggak duduk di
/// `contoh_lembar_kerja_suhu.dart` bareng ketiga alat suhu. Lembarnya juga
/// membawa kunci tingkat-atas yang nggak dipunyai lembar mana pun:
/// `jumlah_disk`, `jumlah_titik_waktu`, `jumlah_pembacaan_tekanan`,
/// `satuan_tekanan_pilihan`, dan `display_tekanan_pilihan`.
///
/// ## Utang terakhir yang dilunasi
///
/// Sampai 9 September 2026 Autoklaf jatuh ke bentuk pH di mode mock — utang
/// TERAKHIR dari tujuh yang ditemukan `bentuk_mock_semua_profil_test.dart`.
/// Yang bikin dia ditinggal paling belakang: sertifikatnya TIGA bagian yang
/// nggak sebangun (Sebaran Suhu, Kinerja, Tekanan), dan bentuk pH nggak punya
/// satu pun di antaranya — bukan "sebagian kolomnya salah", tapi tiga blok yang
/// hilang seluruhnya.
///
/// Perlu dicatat supaya nggak salah baca: jalur mock Autoklaf di HP SUDAH
/// teruji lewat `autoclave_matriks_lembar_generik_test.dart` dan
/// `autoclave_payload_matriks_test.dart`. Yang belum ada cuma bentuk lembarnya
/// sendiri — dan itu justru yang bikin ketiga test itu jalan di atas bentuk
/// yang bukan miliknya.
library;
DART;

$kepalaDimensi = <<<'DART'
/// Bentuk lembar kerja contoh **Dial Indicator, Jangka Sorong, Sieve Mesh**
/// (kelompok Panjang, alat ke-30..32).
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan. Micrometer & Height Gauge tetap di
/// `contoh_lembar_kerja_panjang.dart`; berkas ini terpisah supaya berkas lama
/// yang tidak digenerate tidak ikut tertimpa.
///
/// ## Yang cuma ada di lembar-lembar ini
///
///  1. **Dial Indicator** — nominal tiap baris TUMPUKAN balok ukur (`kolom_baris`
///     `nominal` bertipe `daftar_angka`, mis. `2,5+1,3+1,2`), enam penunjukan
///     berlabel UP/DOWN (`pengulangan_arah`), dan field tingkat-bagian
///     `balok_pra_evaluasi` yang juga `daftar_angka`. Koma di dalamnya koma
///     DESIMAL, bukan pemisah.
///  2. **Jangka Sorong** — TIGA tabel titik (`simpan_ke`
///     `measurements[].js_outside|js_inside|js_depth`) yang digabung per POSISI
///     baris; server memecahnya ke `titik_ke` 1.. / 101.. / 201...
///  3. **Sieve Mesh** — opening TIDAK lewat `measurements[]`: tabelnya
///     `simpan_ke: spesifikasi_alat.sieve.opening` dengan tiga kolom
///     (warp/weft/kawat), baris = nomor opening (sampai 100).
library;
DART;

$kepalaVolumetrik = <<<'DART'
/// Bentuk lembar kerja contoh **Hydrometer** (kelompok Volumetrik, alat ke-33).
///
/// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja.php` di repo API — jangan
/// disunting tangan.
///
/// ## Kenapa lembar ini tidak sebangun dengan satu pun lembar lain
///
/// Tiga puluh dua lembar sebelumnya berbentuk "standar lawan pembacaan".
/// Hydrometer tidak: yang dipungut kertas `SIDIK-FM-CAL-0533_Rev.2` adalah
/// **massa hasil timbang (gram)** dan **suhu air (°C)**, masing-masing tiga
/// ulangan per titik skala — dan densitas yang dicetak sertifikat tidak pernah
/// diketik siapa pun, dia hasil metode Cuckow di server.
///
///  1. **Dua tabel yang harus SINKRON** (`simpan_ke`
///     `measurements[].hydro_massa` dan `measurements[].hydro_suhu`) — kolom
///     ke-n keduanya merujuk titik skala yang sama, dan server MENOLAK titik
///     yang cuma punya salah satunya. Digabung per POSISI baris, sama seperti
///     tiga tabel Jangka Sorong dan kelima tabel Flowmeter; `titik_ukur` tiap
///     `measurements[i]` datang dari tabel massa, yang disebut duluan.
///  2. **`offset_kunci` berbeda di ketiga tabelnya** (1000 massa, 2000 diameter
///     stem, 3000 suhu). Tanpa itu ketiganya berbagi satu `Map<double,
///     TitikState>` — `tahap`-nya sama dan `titik_ukur` bawaannya 0,0 — jadi
///     angka yang diketik di satu tabel muncul di tabel lain.
///  3. **Varian beban tambahan** (`spesifikasi_alat.hydrometer.pakai_beban_tambahan`)
///     menentukan RUMUS MANA yang dipakai, dan kotak `Sl` di bawahnya cuma
///     muncul kalau dipilih `ya` (`tampil_kalau`). Itu yang membuat "tidak
///     perlu sinker" tidak tertukar dengan "lupa mengisi sinker".
///
///     Dropdown `pilihan`, BUKAN saklar boolean: `TipeField.fromApi` cuma
///     mengenal tujuh tipe, dan tipe tak dikenal jatuh ke `TipeField.teks`
///     tanpa satu pun error — teknisi bakal melihat kotak ketikan bebas untuk
///     pertanyaan yang menentukan rumus, dan apa pun yang diketik dibaca server
///     sebagai "tidak".
///  4. **Kotak tekanan udara (hPa)** di blok identitas — tidak dimiliki lembar
///     mana pun selain Gas Detector, dan di sini WAJIB: densitas udara lahir
///     dari situ.
///  5. **Kedua tabel `titik_bisa_diubah: true`** — beda dari Micrometer & Dial
///     Indicator yang nominalnya terkunci kertas. Keduanya, bukan salah
///     satunya: titik yang ditambah teknisi hidup di satu daftar milik seluruh
///     lembar, jadi dua tabel yang sama-sama `true` tumbuh berbarengan. Batas
///     LIMA titik ditegakkan server (`CalibrationController::susunBlokHydrometer`),
///     bukan lewat kunci bentuk lembar — kontrak lembar kerja HP tidak punya
///     batas jumlah titik, dan kunci yang tidak dibaca klien bikin batasnya
///     cuma ada di atas kertas.
///  6. **Desimal per BARIS** (`desimal`: 4 massa, 1 suhu, 3 diameter stem) —
///     satu-satunya tempat kontrak ini menyatakan ketelitian kotak isian.
library;
DART;

$kelompok = [
    'dimensi' => [
        'berkas' => 'contoh_lembar_kerja_dimensi.dart',
        'kepala' => $kepalaDimensi,
        'profil' => [
            'DialIndicator' => DialIndicatorProfile::class,
            'JangkaSorong' => JangkaSorongProfile::class,
            'Sieve' => SieveProfile::class,
        ],
    ],
    'autoclave' => [
        'berkas' => 'contoh_lembar_kerja_autoclave.dart',
        'kepala' => $kepalaAutoclave,
        'profil' => [
            'Autoklaf' => AutoclaveProfile::class,
        ],
    ],
    'analitik' => [
        'berkas' => 'contoh_lembar_kerja_analitik.dart',
        'kepala' => $kepalaAnalitik,
        'profil' => [
            'Conductivity' => ConductivityProfile::class,
        ],
    ],
    'aliran' => [
        'berkas' => 'contoh_lembar_kerja_aliran.dart',
        'kepala' => $kepalaAliran,
        'profil' => [
            'FlowmeterTotalizer' => FlowmeterTotalizerProfile::class,
            'FlowmeterFlowrate' => FlowmeterFlowrateProfile::class,
        ],
    ],
    'anak_timbangan' => [
        'berkas' => 'contoh_lembar_kerja_anak_timbangan.dart',
        'kepala' => $kepalaMassa,
        'profil' => [
            'AnakTimbangan' => AnakTimbanganProfile::class,
        ],
    ],
    'volumetrik' => [
        'berkas' => 'contoh_lembar_kerja_volumetrik.dart',
        'kepala' => $kepalaVolumetrik,
        'profil' => [
            'Hydrometer' => HydrometerProfile::class,
        ],
    ],
    'enclosure' => [
        'berkas' => 'contoh_lembar_kerja_enclosure.dart',
        'kepala' => $kepalaEnclosure,
        'profil' => [
            'Oven' => OvenProfile::class,
            'Furnace' => FurnaceProfile::class,
            'Bath' => BathProfile::class,
            'Inkubator' => InkubatorProfile::class,
            'Refrigerator' => RefrigeratorProfile::class,
        ],
    ],
];

foreach ($kelompok as $k) {
    $fungsi = [];

    foreach ($k['profil'] as $suffix => $kelas) {
        $p = new $kelas;
        $bentuk = $p->bentukLembarKerja();

        $fungsi[] = sprintf(
            <<<'DART'
            /// Bentuk lembar kerja contoh **%s**.
            ///
            /// Kode profil `%s`, satuan `%s`, kertas `%s`.
            Map<String, dynamic> contohBentukLembarKerja%s({
              bool untukAdmin = false,
            }) {
              return %s;
            }
            DART,
            $p->namaAlatKemampuan(),
            $p->kode(),
            $bentuk['satuan'] ?? '-',
            $bentuk['kode_dokumen'] ?? '-',
            $suffix,
            dart($bentuk, 2),
        );
    }

    $keluaran = AKAR_MOBILE.$k['berkas'];
    file_put_contents($keluaran, $k['kepala']."\n\n".implode("\n\n", $fungsi)."\n");

    printf(
        "Ditulis: %s (%d profil, %d baris)\n",
        $k['berkas'],
        count($k['profil']),
        substr_count((string) file_get_contents($keluaran), "\n"),
    );
}
