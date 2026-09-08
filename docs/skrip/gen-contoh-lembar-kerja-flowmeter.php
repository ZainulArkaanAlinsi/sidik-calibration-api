<?php

use App\Services\Calibration\Profiles\FlowmeterFlowrateProfile;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use Illuminate\Contracts\Console\Kernel;

/**
 * Generate `lib/services/contoh_lembar_kerja_aliran.dart` di repo
 * `sidik-calibration-mobile` dari bentuk lembar kerja yang BENERAN dikirim
 * server.
 *
 * ## Kenapa digenerate, bukan diketik
 *
 * Berkas contoh di repo mobile itu salinan APA ADANYA respons
 * `GET /api/calibrations/lembar-kerja`. Disusun ulang tangan, dia menyimpang
 * diam-diam begitu backendnya direvisi — dan test mode mock yang jalan di atas
 * bentuk basi memberi rasa aman yang salah. Persoalan yang sama sudah ditulis
 * di kepala `contoh_lembar_kerja_panjang.dart`.
 *
 * Dropdown bersumber master (`master_alat`, `master_ruangan`,
 * `master_thermohygro`, `standar_dicek`) keluar KOSONG di sini karena skrip ini
 * tidak menyentuh database — dan itu memang bentuk yang benar untuk mode mock,
 * sama seperti berkas contoh alat lain.
 *
 * Jalankan dari akar repo API:
 *     php docs/skrip/gen-contoh-lembar-kerja-flowmeter.php
 */
require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const KELUARAN = 'C:/Users/USER/sidik-calibration-mobile/lib/services/contoh_lembar_kerja_aliran.dart';

/** Ubah nilai PHP jadi literal Dart yang bisa dibaca. */
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
        // Float bulat ditulis dengan `.0` supaya tipenya tetap `double` di Dart —
        // `1` dan `1.0` beda tipe di sana, dan yang pertama bikin
        // `whereType<double>()` melewatkannya.
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

$profil = [
    'Totalizer' => new FlowmeterTotalizerProfile,
    'Flowrate' => new FlowmeterFlowrateProfile,
];

$fungsi = [];

foreach ($profil as $nama => $p) {
    $bentuk = $p->bentukLembarKerja();

    $fungsi[] = sprintf(
        <<<'DART'
        /// Bentuk lembar kerja contoh **Flow Meter Cairan (%s)**.
        ///
        /// Kode profil `%s`, satuan `%s`, kertas `%s`.
        ///
        /// DIGENERATE `docs/skrip/gen-contoh-lembar-kerja-flowmeter.php` di repo API —
        /// jangan disunting tangan.
        Map<String, dynamic> contohBentukLembarKerjaFlowmeter%s({
          bool untukAdmin = false,
        }) {
          return %s;
        }
        DART,
        $nama,
        $p->kode(),
        $bentuk['satuan'],
        $bentuk['kode_dokumen'],
        $nama,
        dart($bentuk, 2),
    );
}

$kepala = <<<'DART'
/// Bentuk lembar kerja contoh **Flowmeter Ultrasonic** (kelompok Aliran, alat
/// ke-27 & ke-28).
///
/// ## Kenapa berkas ini ada, dan kenapa isinya nggak diketik tangan
///
/// Isinya salinan APA ADANYA dari respons
/// `GET /api/calibrations/lembar-kerja?equipment_id=…` untuk alat contoh
/// ter-seed `FM-TOT-DEMO-01` dan `FM-FLW-DEMO-01`. Digenerate dari bentuk yang
/// beneran dikirim server (`docs/skrip/gen-contoh-lembar-kerja-flowmeter.php`
/// di repo API), bukan disusun ulang di sini: bentuk yang diketik tangan bakal
/// menyimpang diam-diam begitu backendnya direvisi, dan test yang jalan di atas
/// bentuk basi memberi rasa aman yang salah.
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

file_put_contents(KELUARAN, $kepala."\n".implode("\n\n", $fungsi)."\n");

printf("Ditulis: %s (%d baris)\n", KELUARAN, substr_count((string) file_get_contents(KELUARAN), "\n"));
