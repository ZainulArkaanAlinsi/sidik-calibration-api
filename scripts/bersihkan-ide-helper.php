<?php

/**
 * Beresin dua tabrakan deklarasi di `_ide_helper.php` hasil `ide-helper:generate`.
 *
 * Kenapa perlu: paket `barryvdh/laravel-dompdf` daftarin DUA alias ke kelas yang
 * sama — `Pdf` dan `PDF` (composer.json paketnya, extra.laravel.aliases). Nama
 * kelas di PHP itu case-insensitive, jadi ide-helper nulis dua deklarasi yang
 * bentrok di file yang sama. Analyzer bener waktu ngeluh; yang salah keluaran
 * generatornya.
 *
 * Dijalanin OTOMATIS lewat `composer ide-helper` — jangan diandelin dipanggil
 * manual sesudah regenerate, gampang kelupaan.
 *
 * Aman diulang (idempoten) dan aman kalau nggak ada yang perlu dibersihin.
 * `_ide_helper.php` itu file generated & di-gitignore, nggak pernah ke-load pas
 * runtime — jadi ini murni ngerapiin bahan baca editor, nol risiko ke produksi.
 */
$berkas = dirname(__DIR__).'/_ide_helper.php';

if (! is_file($berkas)) {
    fwrite(STDERR, "_ide_helper.php nggak ada — jalanin `php artisan ide-helper:generate` dulu.\n");
    exit(1);
}

$baris = file($berkas);
$jumlahAwal = count($baris);

/**
 * Batas blok namespace diambil dari baris `namespace ... {` BERIKUTNYA, bukan
 * dari nyari kurung tutup yang cocok. Indentasi keluaran ide-helper nggak
 * konsisten, jadi ngitung kurung gampang meleset; batas antar-namespace nggak.
 *
 * @return array{0: int, 1: int}|null [indeks awal, indeks akhir] inklusif
 */
$blokNamespace = function (array $baris, string $nama): ?array {
    $awal = null;

    foreach ($baris as $i => $isi) {
        if (rtrim($isi) === "namespace {$nama} {") {
            $awal = $i;

            continue;
        }

        if ($awal !== null && str_starts_with($isi, 'namespace ')) {
            return [$awal, $i - 1];
        }
    }

    return $awal === null ? null : [$awal, count($baris) - 1];
};

$buang = [];

// 1. `namespace Barryvdh\DomPDF\Facade { class Pdf { ... } }` — deklarasi ULANG
//    kelas asli dari vendor. Dibuang, bukan dibiarin: kode di repo ini `use
//    Barryvdh\DomPDF\Facade\Pdf` langsung (LaporanController, GenerateCertificate,
//    BangunUlangSnapshotSertifikat), jadi yang mau dibaca editor justru kelas
//    vendornya — docblock-nya udah lengkap di sana.
if ($blok = $blokNamespace($baris, 'Barryvdh\DomPDF\Facade')) {
    $buang += array_fill_keys(range($blok[0], $blok[1]), true);
}

// 2. Alias di namespace akar yang cuma beda huruf besar-kecil (`PDF` vs `Pdf`).
//    Yang kesebut duluan dipertahankan, sisanya dibuang.
$aliasKelihatan = [];

foreach ($baris as $i => $isi) {
    if (isset($buang[$i])) {
        continue;
    }

    if (! preg_match('/^\s*class\s+(\w+)\s+extends\s+[\\\\\w]+\s*\{\s*\}\s*$/', $isi, $cocok)) {
        continue;
    }

    $kunci = strtolower($cocok[1]);

    if (isset($aliasKelihatan[$kunci])) {
        $buang[$i] = true;

        continue;
    }

    $aliasKelihatan[$kunci] = true;
}

if ($buang === []) {
    echo "_ide_helper.php udah bersih — nggak ada yang dibuang.\n";
    exit(0);
}

$hasil = [];

foreach ($baris as $i => $isi) {
    if (! isset($buang[$i])) {
        $hasil[] = $isi;
    }
}

file_put_contents($berkas, implode('', $hasil));

printf(
    "_ide_helper.php dibersihin: %d baris dibuang (%d -> %d).\n",
    count($buang),
    $jumlahAwal,
    count($hasil),
);
