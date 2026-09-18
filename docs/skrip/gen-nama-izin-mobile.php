<?php

use App\Services\MatriksIzin;

/**
 * Generate `test/fixtures/nama_izin.json` di repo `sidik-calibration-mobile`
 * dari `MatriksIzin::PETA` — SATU sumber kebenaran nama izin.
 *
 * ## Kenapa digenerate, bukan diketik di Dart
 *
 * Nama izin adalah kontrak antara dua repo, dan kontrak itu sudah pernah putus
 * tanpa satu pun gejala. `NamaIzin` di sisi Flutter lahir sebagai TEBAKAN dari
 * contoh di `docs/permintaan-endpoint-fase-2.md` — docblock-nya menulis sendiri
 * "ini tebakan sampai bentuk pastinya dikonfirmasi" — dan konfirmasinya tidak
 * pernah datang. Dari sebelas nama, LIMA tidak pernah ada di peta ini:
 * `master-data.ubah`, `akun.kelola`, `sertifikat.kirim`, `tanda-tangan.kelola`,
 * `folder.tulis`.
 *
 * Yang bikin itu mahal: `Izin.bolehkah()` sengaja jatuh ke cadangan aturan peran
 * hardcode buat nama yang tidak dikenal. Jadi salah-nama tidak memunculkan error
 * di sisi mana pun — tombolnya tetap jalan, cuma memakai `role.isAdmin` yang
 * justru `MatriksIzin` ada untuk menggantikannya. Matriks perannya mati separuh,
 * dan yang tersisa cuma nama kelasnya.
 *
 * Dijaga dua arah: `MeIzinTest::test_nama_izin_yang_ditanya_mobile_ada_semua` di
 * sini, dan `test/nama_izin_test.dart` di repo mobile yang membaca berkas ini.
 *
 * Jalankan dari akar repo API:
 *
 *   php artisan tinker --execute="require 'docs/skrip/gen-nama-izin-mobile.php';"
 *
 * Bawaannya jalur Windows tempat kedua repo hidup berdampingan di laptop lab,
 * bisa ditimpa lewat env `KELUARAN_NAMA_IZIN` supaya skrip ini juga jalan di CI
 * dan di mesin lain tanpa menyunting berkas.
 */
define('KELUARAN_IZIN', getenv('KELUARAN_NAMA_IZIN')
    ?: 'C:/Users/USER/sidik-calibration-mobile/test/fixtures/nama_izin.json');

$izin = array_keys(MatriksIzin::PETA);
sort($izin);

$isi = [
    '_sumber' => 'MatriksIzin::PETA di repo sidik-calibration-api. '
        .'DIGENERATE docs/skrip/gen-nama-izin-mobile.php — jangan disunting tangan.',
    '_dipakai' => 'test/nama_izin_test.dart — menuntut tiap nilai di `NamaIzin` ada di daftar ini. '
        .'Nama yang tidak dikenal server bikin Izin.bolehkah() diam-diam balik ke aturan peran hardcode.',
    'jumlah' => count($izin),
    'izin' => $izin,
];

file_put_contents(
    KELUARAN_IZIN,
    json_encode($isi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);

printf("Ditulis: %s (%d izin)\n", KELUARAN_IZIN, count($izin));
