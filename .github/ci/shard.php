<?php

declare(strict_types=1);

/*
 * Pembagi shard suite test untuk CI (`.github/workflows/tes.yml`).
 *
 * Kenapa ada: suite penuh makan 45 menit di SQLite dan 70 menit di MySQL. Tiap
 * job CI punya checkout dan database sendiri, jadi suite boleh dipecah ke
 * beberapa job paralel tanpa menyentuh jebakan `Storage::fake` di AGENTS.md
 * (yang cuma berlaku untuk dua proses di SATU checkout).
 *
 * Yang dibagi adalah BERKAS test, bukan test per test. Tiap shard menjalankan
 * `php artisan test -c phpunit.shard.xml`, yaitu salinan persis phpunit.xml /
 * phpunit.mysql.xml yang hanya `<testsuites>`-nya diganti daftar `<file>`
 * milik shard itu. `<php>` (env, memory_limit), `<source>`, dan nama suite
 * (Unit, Feature) tidak diubah. Berkas yang dimuat lewat `<file>` melewati
 * jalur PHPUnit yang sama dengan hasil pindai `<directory>`.
 *
 * Pembagiannya deterministik: berkas diurutkan dari yang terberat (bobot di
 * shard-data.json, detik hasil ukur CI) lalu tiap berkas masuk ke shard yang
 * sedang paling ringan (LPT). Input sama, keluaran sama, di setiap job.
 * Berkas baru yang belum punya bobot dihitung sebesar median; pembagiannya
 * tetap lengkap, hanya kurang rata sampai bobotnya disegarkan.
 *
 * Perintah (dijalankan dari mana saja; berkas relatif dibaca dari akar repo):
 *
 *   php .github/ci/shard.php verify  --profil=sqlite|mysql --of=N
 *       Gagal (exit 1) kalau ada berkas test yang tidak kebagian shard mana pun,
 *       kebagian dua kali, shard kosong, atau ada *Test.php di bawah tests/ yang
 *       tidak ikut suite dan tidak tercatat sengaja di shard-data.json.
 *   php .github/ci/shard.php plan    --profil=... --of=N
 *       Tabel jumlah berkas dan perkiraan detik per shard.
 *   php .github/ci/shard.php daftar  --profil=... --of=N --shard=K
 *       Berkas milik shard K, satu per baris.
 *   php .github/ci/shard.php config  --profil=... --of=N --shard=K [--out=phpunit.shard.xml]
 *       Menjalankan verify, lalu menulis konfigurasi PHPUnit shard K.
 *   php .github/ci/shard.php bobot   <log-run.txt>
 *       Menyegarkan bobot dari log `gh run view <id> --log` (jumlah durasi
 *       per berkas, per profil). Tulis ke shard-data.json.
 *
 * Profil: sqlite memakai phpunit.xml, mysql memakai phpunit.mysql.xml.
 */

const BERKAS_DATA = __DIR__.'/shard-data.json';

const KONFIGURASI = [
    'sqlite' => 'phpunit.xml',
    'mysql' => 'phpunit.mysql.xml',
];

exit(main($argv));

function main(array $argv): int
{
    $perintah = $argv[1] ?? '';
    [$opsi, $posisi] = bacaOpsi(array_slice($argv, 2));

    try {
        return match ($perintah) {
            'verify' => perintahVerify($opsi),
            'plan' => perintahPlan($opsi),
            'daftar' => perintahDaftar($opsi),
            'config' => perintahConfig($opsi),
            'bobot' => perintahBobot($posisi),
            default => gagalPakai(),
        };
    } catch (RuntimeException $e) {
        galat($e->getMessage());

        return 1;
    }
}

function gagalPakai(): int
{
    fwrite(STDERR, "Pakai: php .github/ci/shard.php verify|plan|daftar|config|bobot ... (lihat kepala berkas)\n");

    return 2;
}

/** @return array{0: array<string, string>, 1: list<string>} */
function bacaOpsi(array $args): array
{
    $opsi = [];
    $posisi = [];

    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) === 1) {
            $opsi[$m[1]] = $m[2];
        } else {
            $posisi[] = $arg;
        }
    }

    return [$opsi, $posisi];
}

function galat(string $pesan): void
{
    echo getenv('GITHUB_ACTIONS') === 'true' ? "::error::{$pesan}\n" : "GALAT: {$pesan}\n";
}

function catatan(string $pesan): void
{
    echo getenv('GITHUB_ACTIONS') === 'true' ? "::notice::{$pesan}\n" : "catatan: {$pesan}\n";
}

function akar(): string
{
    $akar = realpath(__DIR__.'/../..');

    if ($akar === false) {
        throw new RuntimeException('Akar repo tidak ditemukan dari '.__DIR__);
    }

    return str_replace('\\', '/', $akar);
}

/** @return array{profil: string, jumlah: int, konfigurasi: string} */
function parameterBersama(array $opsi): array
{
    $profil = $opsi['profil'] ?? '';

    if (! isset(KONFIGURASI[$profil])) {
        throw new RuntimeException('--profil harus sqlite atau mysql.');
    }

    $jumlah = $opsi['of'] ?? '';

    if (preg_match('/^[1-9][0-9]*$/', $jumlah) !== 1) {
        throw new RuntimeException('--of harus bilangan bulat positif (jumlah shard).');
    }

    return ['profil' => $profil, 'jumlah' => (int) $jumlah, 'konfigurasi' => akar().'/'.KONFIGURASI[$profil]];
}

/** @return array<string, mixed> */
function bacaData(): array
{
    $isi = file_get_contents(BERKAS_DATA);

    if ($isi === false) {
        throw new RuntimeException('Tidak bisa membaca '.BERKAS_DATA);
    }

    $data = json_decode($isi, true, 16, JSON_THROW_ON_ERROR);

    return is_array($data) ? $data : [];
}

/**
 * Berkas test per suite, persis cara PHPUnit memindai `<directory>` / `<file>`
 * di konfigurasi: akhiran `Test.php` bawaan, rekursif, urut `sort()`.
 * Atribut atau elemen yang tidak dipahami membuat skrip berhenti, bukan
 * menebak, supaya pembagian tidak diam-diam menyimpang dari konfigurasinya.
 *
 * @return array<string, list<string>> nama suite => berkas relatif dari akar repo
 */
function bacaSuite(string $konfigurasi): array
{
    $dom = new DOMDocument;

    if (! $dom->load($konfigurasi)) {
        throw new RuntimeException("Tidak bisa membaca $konfigurasi");
    }

    $akar = akar();
    $hasil = [];
    $sudah = [];

    foreach ($dom->getElementsByTagName('testsuite') as $suite) {
        $nama = $suite->getAttribute('name');
        $berkas = [];
        $kecuali = [];

        foreach ($suite->childNodes as $anak) {
            if ($anak instanceof DOMElement && $anak->tagName === 'exclude') {
                $kecuali[] = $akar.'/'.trim($anak->textContent);
            }
        }

        foreach ($suite->childNodes as $anak) {
            if (! $anak instanceof DOMElement || $anak->tagName === 'exclude') {
                continue;
            }

            foreach ($anak->attributes as $atribut) {
                if (! ($anak->tagName === 'directory' && in_array($atribut->name, ['suffix', 'prefix'], true))) {
                    throw new RuntimeException("Atribut {$atribut->name} pada <{$anak->tagName}> belum didukung pembagi shard.");
                }
            }

            $path = $akar.'/'.trim($anak->textContent);

            if ($anak->tagName === 'file') {
                $berkas[] = $path;
            } elseif ($anak->tagName === 'directory') {
                $akhiran = $anak->hasAttribute('suffix') ? $anak->getAttribute('suffix') : 'Test.php';
                $awalan = $anak->getAttribute('prefix');

                foreach (pindai($path, $akhiran, $awalan) as $f) {
                    $berkas[] = $f;
                }
            } else {
                throw new RuntimeException("Elemen <{$anak->tagName}> di <testsuite> belum didukung pembagi shard.");
            }
        }

        $berkas = array_values(array_unique(array_filter(
            $berkas,
            static function (string $f) use ($kecuali): bool {
                foreach ($kecuali as $k) {
                    if ($f === $k || str_starts_with($f, rtrim($k, '/').'/')) {
                        return false;
                    }
                }

                return true;
            }
        )));
        sort($berkas);

        $relatif = [];

        foreach ($berkas as $f) {
            $r = ltrim(substr($f, strlen($akar)), '/');

            if (isset($sudah[$r])) {
                throw new RuntimeException("$r muncul di dua suite ({$sudah[$r]} dan $nama).");
            }

            $sudah[$r] = $nama;
            $relatif[] = $r;
        }

        $hasil[$nama] = $relatif;
    }

    if ($hasil === [] || array_sum(array_map('count', $hasil)) === 0) {
        throw new RuntimeException("Tidak ada berkas test yang ditemukan lewat $konfigurasi");
    }

    return $hasil;
}

/** @return list<string> path absolut */
function pindai(string $direktori, string $akhiran, string $awalan): array
{
    if (! is_dir($direktori)) {
        throw new RuntimeException("Direktori test tidak ada: $direktori");
    }

    $hasil = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($direktori, FilesystemIterator::SKIP_DOTS));

    foreach ($iter as $info) {
        if (! $info->isFile()) {
            continue;
        }

        $nama = $info->getFilename();

        if (str_ends_with($nama, $akhiran) && ($awalan === '' || str_starts_with($nama, $awalan))) {
            $hasil[] = str_replace('\\', '/', $info->getPathname());
        }
    }

    return $hasil;
}

/**
 * Bobot dalam sentidetik (bilangan bulat, supaya hasil bagi tidak bergantung
 * pada pembulatan float). Berkas tanpa bobot dihitung sebesar median.
 *
 * @param  list<string>  $berkas
 * @return array{0: array<string, int>, 1: list<string>} bobot, daftar berkas tanpa bobot terukur
 */
function bobotBerkas(array $berkas, string $profil): array
{
    $terukur = bacaData()[$profil] ?? [];
    $bobot = [];
    $tanpa = [];
    $dikenal = [];

    foreach ($berkas as $f) {
        if (isset($terukur[$f]) && is_numeric($terukur[$f])) {
            $bobot[$f] = max(1, (int) round(((float) $terukur[$f]) * 100));
            $dikenal[] = $bobot[$f];
        } else {
            $tanpa[] = $f;
        }
    }

    sort($dikenal);
    $median = $dikenal === [] ? 100 : $dikenal[intdiv(count($dikenal), 2)];

    foreach ($tanpa as $f) {
        $bobot[$f] = $median;
    }

    return [$bobot, $tanpa];
}

/**
 * LPT: dari yang terberat, tiap berkas ke shard yang sedang paling ringan
 * (seri: nomor shard terkecil). Urutan berkas dalam shard dikembalikan ke
 * urutan alfabet supaya mengikuti urutan bawaan PHPUnit.
 *
 * @param  array<string, int>  $bobot
 * @return list<list<string>> indeks 0 = shard 1
 */
function bagi(array $bobot, int $jumlah): array
{
    $urut = array_keys($bobot);
    usort($urut, static fn (string $a, string $b): int => [$bobot[$b], $a] <=> [$bobot[$a], $b]);

    $beban = array_fill(0, $jumlah, 0);
    $shard = array_fill(0, $jumlah, []);

    foreach ($urut as $f) {
        $tujuan = 0;

        for ($i = 1; $i < $jumlah; $i++) {
            if ($beban[$i] < $beban[$tujuan]) {
                $tujuan = $i;
            }
        }

        $beban[$tujuan] += $bobot[$f];
        $shard[$tujuan][] = $f;
    }

    foreach ($shard as &$daftar) {
        sort($daftar);
    }

    return $shard;
}

/**
 * @param  array<string, list<string>>  $suite
 * @param  list<string>  $milikShard
 * @return array<string, list<string>>
 */
function suiteUntukShard(array $suite, array $milikShard): array
{
    $punya = array_flip($milikShard);
    $hasil = [];

    foreach ($suite as $nama => $berkas) {
        $dipilih = array_values(array_filter($berkas, static fn (string $f): bool => isset($punya[$f])));

        if ($dipilih !== []) {
            $hasil[$nama] = $dipilih;
        }
    }

    return $hasil;
}

/** @param array<string, list<string>> $suiteShard */
function tulisKonfigurasi(string $konfigurasi, array $suiteShard, string $keterangan, ?string $tujuan): string
{
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    $dom->load($konfigurasi);

    $induk = $dom->getElementsByTagName('testsuites')->item(0);

    if (! $induk instanceof DOMElement) {
        throw new RuntimeException("<testsuites> tidak ada di $konfigurasi");
    }

    while ($induk->firstChild !== null) {
        $induk->removeChild($induk->firstChild);
    }

    $induk->appendChild($dom->createTextNode("\n"));

    foreach ($suiteShard as $nama => $berkas) {
        $suite = $dom->createElement('testsuite');
        $suite->setAttribute('name', $nama);
        $suite->appendChild($dom->createTextNode("\n"));

        foreach ($berkas as $f) {
            $suite->appendChild($dom->createTextNode('            '));
            $el = $dom->createElement('file');
            $el->appendChild($dom->createTextNode($f));
            $suite->appendChild($el);
            $suite->appendChild($dom->createTextNode("\n"));
        }

        $suite->appendChild($dom->createTextNode('        '));
        $induk->appendChild($dom->createTextNode('        '));
        $induk->appendChild($suite);
        $induk->appendChild($dom->createTextNode("\n"));
    }

    $induk->appendChild($dom->createTextNode('    '));

    $dom->documentElement->insertBefore(
        $dom->createComment("\n        DIBUAT OTOMATIS oleh .github/ci/shard.php dari ".basename($konfigurasi).". $keterangan\n        Hanya <testsuites> yang diganti; <php> dan <source> sama persis. Jangan di-commit.\n    "),
        $induk
    );
    $dom->documentElement->insertBefore($dom->createTextNode("\n    "), $induk);

    $xml = $dom->saveXML();

    if ($xml === false) {
        throw new RuntimeException('Gagal menyusun XML shard.');
    }

    if ($tujuan !== null) {
        file_put_contents($tujuan, $xml);
    }

    return $xml;
}

/** @return list<string> berkas yang terbaca kembali dari XML shard */
function berkasDariXml(string $xml): array
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $hasil = [];

    foreach ($dom->getElementsByTagName('file') as $el) {
        $hasil[] = trim($el->textContent);
    }

    return $hasil;
}

/**
 * Semua pemeriksaan jaminan. Mengembalikan daftar masalah (kosong = sehat).
 *
 * @param  array{profil: string, jumlah: int, konfigurasi: string}  $p
 * @return array{0: list<string>, 1: array<string, list<string>>, 2: list<list<string>>, 3: array<string, int>, 4: list<string>}
 */
function periksa(array $p): array
{
    $masalah = [];
    $suite = bacaSuite($p['konfigurasi']);
    $semua = array_merge(...array_values($suite));
    [$bobot, $tanpaBobot] = bobotBerkas($semua, $p['profil']);
    $shard = bagi($bobot, $p['jumlah']);

    // 1. Tiap berkas tepat satu kali di seluruh shard.
    $hitung = [];

    foreach ($shard as $daftar) {
        foreach ($daftar as $f) {
            $hitung[$f] = ($hitung[$f] ?? 0) + 1;
        }
    }

    foreach ($semua as $f) {
        if (($hitung[$f] ?? 0) === 0) {
            $masalah[] = "TIDAK KEBAGIAN shard mana pun: $f";
        } elseif ($hitung[$f] > 1) {
            $masalah[] = "KEBAGIAN {$hitung[$f]} kali: $f";
        }
    }

    foreach (array_keys($hitung) as $f) {
        if (! in_array($f, $semua, true)) {
            $masalah[] = "Ada di shard tapi bukan bagian suite: $f";
        }
    }

    // 2. Tidak ada shard kosong (job CI yang tidak menjalankan apa-apa itu hijau palsu).
    foreach ($shard as $i => $daftar) {
        if ($daftar === []) {
            $masalah[] = 'Shard '.($i + 1).' kosong; kurangi jumlah shard.';
        }
    }

    // 3. XML tiap shard, dibaca kembali, harus memuat tepat berkas milik shard itu.
    $terbaca = [];

    foreach ($shard as $i => $daftar) {
        $xml = tulisKonfigurasi($p['konfigurasi'], suiteUntukShard($suite, $daftar), 'Shard '.($i + 1).'/'.$p['jumlah'].'.', null);
        $dariXml = berkasDariXml($xml);
        $a = $dariXml;
        $b = $daftar;
        sort($a);
        sort($b);

        if ($a !== $b) {
            $masalah[] = 'XML shard '.($i + 1).' tidak sama dengan daftar berkasnya.';
        }

        $terbaca = array_merge($terbaca, $dariXml);
    }

    $u = array_unique($terbaca);
    sort($u);
    $s = $semua;
    sort($s);

    if (count($terbaca) !== count($u) || $u !== $s) {
        $masalah[] = 'Gabungan XML semua shard tidak sama dengan seluruh berkas suite.';
    }

    // 4. Berkas harus ada di disk.
    foreach ($semua as $f) {
        if (! is_file(akar().'/'.$f)) {
            $masalah[] = "Berkas tidak ada di disk: $f";
        }
    }

    // 5. *Test.php di bawah tests/ yang tidak ikut suite harus tercatat sengaja.
    $sengaja = bacaData()['di_luar_suite_sengaja'] ?? [];
    $ikut = array_flip($semua);

    foreach (pindai(akar().'/tests', 'Test.php', '') as $mutlak) {
        $r = ltrim(substr($mutlak, strlen(akar())), '/');

        if (! isset($ikut[$r]) && ! isset($sengaja[$r])) {
            $masalah[] = "Berkas test di luar suite dan tidak tercatat sengaja (shard-data.json > di_luar_suite_sengaja): $r";
        }
    }

    return [$masalah, $suite, $shard, $bobot, $tanpaBobot];
}

function perintahVerify(array $opsi): int
{
    $p = parameterBersama($opsi);
    [$masalah, , $shard, $bobot, $tanpaBobot] = periksa($p);

    ringkas($p, $shard, $bobot);

    if ($tanpaBobot !== []) {
        catatan(count($tanpaBobot).' berkas belum punya bobot terukur (dihitung median): '.implode(', ', array_slice($tanpaBobot, 0, 8)).(count($tanpaBobot) > 8 ? ', ...' : '').'. Segarkan dengan `shard.php bobot`.');
    }

    if ($masalah !== []) {
        foreach ($masalah as $m) {
            galat($m);
        }

        return 1;
    }

    $total = array_sum(array_map('count', $shard));
    echo "OK: $total berkas terbagi ke {$p['jumlah']} shard (profil {$p['profil']}), tanpa duplikat dan tanpa yang hilang.\n";

    return 0;
}

/**
 * @param  array{profil: string, jumlah: int, konfigurasi: string}  $p
 * @param  list<list<string>>  $shard
 * @param  array<string, int>  $bobot
 */
function ringkas(array $p, array $shard, array $bobot): void
{
    echo "Shard profil {$p['profil']} ({$p['jumlah']} shard), perkiraan dari bobot terukur:\n";

    foreach ($shard as $i => $daftar) {
        $detik = array_sum(array_map(static fn (string $f): int => $bobot[$f], $daftar)) / 100;
        printf("  shard %d: %3d berkas, ~%.0f detik\n", $i + 1, count($daftar), $detik);
    }
}

function perintahPlan(array $opsi): int
{
    $p = parameterBersama($opsi);
    [, , $shard, $bobot] = periksa($p);
    ringkas($p, $shard, $bobot);

    return 0;
}

function perintahDaftar(array $opsi): int
{
    $p = parameterBersama($opsi);
    $k = (int) ($opsi['shard'] ?? 0);

    if ($k < 1 || $k > $p['jumlah']) {
        throw new RuntimeException('--shard harus 1..'.$p['jumlah']);
    }

    [, , $shard] = periksa($p);
    echo implode("\n", $shard[$k - 1])."\n";

    return 0;
}

function perintahConfig(array $opsi): int
{
    $p = parameterBersama($opsi);
    $k = (int) ($opsi['shard'] ?? 0);

    if ($k < 1 || $k > $p['jumlah']) {
        throw new RuntimeException('--shard harus 1..'.$p['jumlah']);
    }

    [$masalah, $suite, $shard, $bobot] = periksa($p);

    if ($masalah !== []) {
        foreach ($masalah as $m) {
            galat($m);
        }

        return 1;
    }

    $tujuan = $opsi['out'] ?? 'phpunit.shard.xml';

    if (! str_starts_with($tujuan, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $tujuan) !== 1) {
        $tujuan = akar().'/'.$tujuan;
    }

    // Harus sedirektori dengan konfigurasi asal: `bootstrap` dan lokasi xsd relatif terhadapnya.
    if (dirname($tujuan) !== dirname($p['konfigurasi'])) {
        throw new RuntimeException('--out harus berada di akar repo, sedirektori dengan '.basename($p['konfigurasi']).'.');
    }

    tulisKonfigurasi($p['konfigurasi'], suiteUntukShard($suite, $shard[$k - 1]), "Shard $k/{$p['jumlah']}, profil {$p['profil']}.", $tujuan);

    $detik = array_sum(array_map(static fn (string $f): int => $bobot[$f], $shard[$k - 1])) / 100;
    printf("Shard %d/%d (%s): %d berkas, ~%.0f detik -> %s\n", $k, $p['jumlah'], $p['profil'], count($shard[$k - 1]), $detik, basename($tujuan));

    return 0;
}

/**
 * Segarkan bobot dari log `gh run view <id> --log`. Log berisi keluaran
 * `php artisan test` (Collision): baris judul `PASS Tests\Feature\XTest` diikuti
 * baris per test dengan durasi di ujung ("0.03s" atau "12ms"). Nama job yang
 * memuat "mysql" masuk profil mysql, sisanya sqlite. Job tak bernama test
 * (agregator, deploy) tidak punya baris test jadi tidak berpengaruh.
 */
function perintahBobot(array $posisi): int
{
    $log = $posisi[0] ?? '';

    if ($log === '' || ! is_file($log)) {
        throw new RuntimeException('Pakai: php .github/ci/shard.php bobot <log-run.txt>');
    }

    $akar = akar();
    $jumlah = ['sqlite' => [], 'mysql' => []];
    $kelasAktif = [];
    $handle = fopen($log, 'rb');

    while (($baris = fgets($handle)) !== false) {
        if (preg_match('/^([^\t]+)\t[^\t]*\t\S+Z ?(.*)$/s', $baris, $m) !== 1) {
            continue;
        }

        $job = $m[1];
        $isi = preg_replace('/\x1b\[[0-9;]*m/', '', rtrim($m[2], "\r\n"));
        $profil = stripos($job, 'mysql') !== false ? 'mysql' : 'sqlite';

        if (preg_match('/^\s*(?:PASS|FAIL|WARN|DEPR|RISKY|SKIPPED|INFO|NOTICE)\s+(Tests\\\\\S+)/', $isi, $h) === 1) {
            $kelasAktif[$job] = $h[1];
            $berkas = 'tests/'.str_replace('\\', '/', substr($h[1], strlen('Tests\\'))).'.php';
            $jumlah[$profil][$berkas] ??= 0.0;

            continue;
        }

        if (isset($kelasAktif[$job]) && preg_match('/^\s*\S\s+.*?\s(\d+(?:\.\d+)?)(ms|s)\s*$/u', $isi, $t) === 1) {
            $berkas = 'tests/'.str_replace('\\', '/', substr($kelasAktif[$job], strlen('Tests\\'))).'.php';
            $jumlah[$profil][$berkas] += (float) $t[1] / ($t[2] === 'ms' ? 1000.0 : 1.0);
        }
    }

    fclose($handle);

    $data = bacaData();

    foreach ($jumlah as $profil => $peta) {
        $peta = array_filter($peta, static fn (string $f): bool => is_file($akar.'/'.$f), ARRAY_FILTER_USE_KEY);

        if ($peta === []) {
            catatan("Log tidak memuat test untuk profil $profil; bobotnya dibiarkan.");

            continue;
        }

        ksort($peta);
        $data[$profil] = array_map(static fn (float $d): float => round($d, 2), $peta);
        echo sprintf("%s: %d berkas, total %.0f detik\n", $profil, count($peta), array_sum($data[$profil]));
    }

    file_put_contents(BERKAS_DATA, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");

    return 0;
}
