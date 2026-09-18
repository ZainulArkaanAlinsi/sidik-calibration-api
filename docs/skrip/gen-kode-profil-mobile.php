<?php

use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Contracts\Console\Kernel;

/**
 * Generate `test/fixtures/kode_profil.json` di repo `sidik-calibration-mobile`
 * dari `CalibrationProfileRegistry` — SATU sumber kebenaran daftar profil.
 *
 * ## Kenapa digenerate, bukan diketik di Dart
 *
 * Sisi server punya `SemuaProfilLembarKerjaTest` yang menyapu registry, jadi
 * profil ke-29 ikut teruji tanpa ada yang perlu ingat. Sisi mobile tidak punya
 * padanannya, dan akibatnya sudah terukur: **tujuh** profil (`autoclave`,
 * `conductivity_meter`, dan kelima Enclosure) diam-diam memajang lembar pH tiga
 * titik buffer di mode mock — tanpa satu pun error.
 *
 * Itu bukan bug produksi. Yang bikin dia mahal: dokumen proyek mencatat TIGA
 * kali bahwa bug yang lolos ke `main` lolos justru karena bentuk mock-nya tidak
 * ada, jadi tidak ada satu pun test yang pernah menyuapkan bentuk aslinya ke
 * parser — TIDS (`titik_ukur: null` bikin baris jadi `[]`), Timbangan (lima
 * cacat sekaligus), Micrometer (tiga cacat server yang lolos 3.128 test
 * backend).
 *
 * Daftar yang diketik tangan di Dart bakal ketinggalan, dan repo ini sudah
 * mencatat lima kejadiannya (template OCR 7→17, `EquipmentFactory`, komentar
 * "lembar tanpa vonis", tabel vonis mock, `CetakLembarKerjaOcrTest`). Karena itu
 * daftarnya lahir dari registry, bukan dari ingatan.
 *
 * Jalankan dari akar repo API:
 *     php docs/skrip/gen-kode-profil-mobile.php
 */
require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Berkas tujuan di repo mobile.
 *
 * Bawaannya jalur Windows tempat kedua repo hidup berdampingan di laptop lab.
 * Bisa ditimpa lewat env `KELUARAN_KODE_PROFIL` supaya skrip ini juga jalan di
 * CI dan di mesin lain tanpa menyunting berkas — skrip yang cuma jalan di satu
 * komputer berhenti dijalankan, dan daftar yang berhenti digenerate diam-diam
 * ketinggalan dari registry. Itu persis kegagalan yang skrip ini ada untuk
 * mencegahnya.
 */
define('KELUARAN', getenv('KELUARAN_KODE_PROFIL')
    ?: 'C:/Users/USER/sidik-calibration-mobile/test/fixtures/kode_profil.json');

$registry = app(CalibrationProfileRegistry::class);
$daftar = new ReflectionMethod($registry, 'daftarProfil');
$daftar->setAccessible(true);

$profil = [];

foreach ($daftar->invoke($registry) as $p) {
    $profil[] = [
        'kode' => $p->kode(),
        'nama_alat_kemampuan' => $p->namaAlatKemampuan(),
    ];
}

usort($profil, static fn (array $a, array $b): int => $a['kode'] <=> $b['kode']);

$isi = [
    '_sumber' => 'CalibrationProfileRegistry::daftarProfil() di repo sidik-calibration-api. '
        .'DIGENERATE docs/skrip/gen-kode-profil-mobile.php — jangan disunting tangan.',
    '_dipakai' => 'test/bentuk_mock_semua_profil_test.dart — menuntut tiap kode punya bentuk '
        .'mock-nya sendiri, atau terdaftar di TANPA_BENTUK_MOCK berikut alasannya.',
    'jumlah' => count($profil),
    'profil' => $profil,
];

file_put_contents(
    KELUARAN,
    json_encode($isi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);

printf("Ditulis: %s (%d profil)\n", KELUARAN, count($profil));
