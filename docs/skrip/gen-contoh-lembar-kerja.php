<?php

use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\Enclosure\BathProfile;
use App\Services\Calibration\Profiles\Enclosure\FurnaceProfile;
use App\Services\Calibration\Profiles\Enclosure\InkubatorProfile;
use App\Services\Calibration\Profiles\Enclosure\OvenProfile;
use App\Services\Calibration\Profiles\Enclosure\RefrigeratorProfile;
use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
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

const AKAR_MOBILE = 'C:/Users/USER/sidik-calibration-mobile/lib/services/';

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

$kelompok = [
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
