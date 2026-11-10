<?php

namespace App\Services\Studio\Draf;

use App\Models\WorkbookMaster;
use App\Services\Studio\PetaSel\Alamat;
use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\WorkbookScalarEditPreparer;

/**
 * Pemetaan TEPERCAYA untuk [WorkbookScalarEditPreparer::prepare()]: subset
 * `ekstrak` sebuah [PetaSel] yang boleh disunting Studio di draf (A1), sudah
 * diselesaikan untuk varian workbook-nya. Pemetaan ini SATU-SATUNYA sumber
 * kebenaran "sel mana ↔ jalur mana": klien hanya mengirim alamat sel dan nilai,
 * tidak pernah jalur.
 *
 * ## Bentuk keluaran
 *
 * `list<{sel, jalur, tipe: 'angka', bulat: 10}>` — persis empat kunci, urutan
 * kunci tetap, urutan entri mengikuti peta. `jalur` sudah bebas placeholder
 * (`{varian}` diganti). Daftar kosong = tidak ada sel yang boleh disunting.
 *
 * ## Desain: apa yang masuk (fail-closed)
 *
 * Entri `ekstrak` masuk HANYA bila SEMUA ini benar (selain itu DIABAIKAN diam-diam,
 * artinya selnya tidak bisa disunting dan klien menerima `sel_tidak_didukung`;
 * peta yang rusak sudah ditolak lebih awal oleh [PetaSel::untuk]):
 *
 *  1. berlaku untuk varian workbook ini — disaring oleh [PetaSel::untukVarian]
 *     (fungsi yang sama dengan [PemeriksaMaster::periksa]). Kunci `varian` di
 *     entri adalah penyaring itu, bukan makna angka, jadi ia satu-satunya kunci
 *     selain `sel/jalur/tipe/bulat` yang diizinkan, dan dibuang dari keluaran;
 *  2. kunci tepat `sel`, `jalur`, `tipe`, `bulat` (+ `varian` opsional). Kunci
 *     lain MENGUBAH makna nilai sel (`bagi`, `angka_penting`, `nol_di_bawah`,
 *     `buang_akhiran`, `ulang`/`langkah_baris`, ...) — sunting langsung ke daun
 *     akan menyimpang dari yang ekstraksi hasilkan, jadi ditolak;
 *  3. `tipe === 'angka'` dan `bulat === 10` (identik ketat; `10.0` atau `"10"`
 *     tidak). Teks, tanggal, bilangan_bulat, peta_angka, daftar_angka,
 *     daftar_objek, daftar_kunci, angka_atau_teks, bendera_akhiran tidak masuk;
 *  4. `sel` satu sel sah (bukan rentang), `jalur` sah setelah `{varian}`
 *     diselesaikan lewat [PetaSel::isiJalur]; `{varian}` yang tak bisa
 *     diselesaikan (peta tanpa varian) atau `{i}` mengeluarkan entri; panjang
 *     alamat/jalur di bawah batas preparer, jadi pemetaan hasil kami tidak
 *     pernah membuat preparer melempar untuk seluruh gelombang;
 *  5. tidak ada butir LAIN (yang berlaku untuk varian ini) yang menyentuh sel
 *     yang sama. "Butir lain" = `ekstrak` lain, `jangkar[].sel`, `cek[].sel`
 *     (termasuk geseran `ulang`), dan `paket_lain[].ekstrak[]`. Satu sel dibaca
 *     lagi oleh `sel`, `rentang`, `kunci`/`nilai` (peta_angka), atau kolom
 *     `daftar_objek` (termasuk geseran `ulang`), mis. sel yang juga dibagi 1000
 *     ke jalur lain atau sel yang juga jangkar teks: menyunting satu daun
 *     membuat daun turunannya tidak ikut / master tidak lagi cocok dengan
 *     draf = draf yang tidak bisa direproduksi dari master mana pun. Alamat
 *     dibandingkan TERURAI (lembar sama tanpa beda huruf besar-kecil/kutip,
 *     sel termasuk dalam rentang), bukan sebagai teks; alamat yang tak bisa
 *     diurai pada butir lain mengeluarkan semua kandidat (tak terbukti aman);
 *  6. tidak ada butir lain yang menulis/membandingkan jalur yang BERTUMPANG
 *     TINDIH: satu jalur adalah awalan SEGMEN jalur lain (`a` vs `a.b`,
 *     `a[0]` vs `a`), atau segmen berkunci sama memakai `[kode=X]` di satu sisi
 *     dan `[N]` di sisi lain (alias; tidak ditebak dari data -> bentrok).
 *     Termasuk jalur `cek[].banding` (cek membandingkan daun itu dengan sel
 *     cek). Jalur `paket_lain` milik paket lain, tidak ikut dibandingkan.
 *
 * Varian: peta ber-blok `varian` BUTUH nama workbook yang cocok tepat satu
 * pola ([PetaSel::varianDariPola], sama dengan impor). Nama tak dikenali
 * (impor menolaknya, atau melewati peta bila `lainnya = lewati`) = daftar
 * KOSONG, bukan "butir umum saja": workbook yang tidak lolos impor tidak
 * boleh menjadi pintu sunting.
 *
 * Yang TIDAK diperiksa di sini (kewajiban pemanggil/preparer): daun sudah ada
 * di basis dan berupa angka hingga (preparer), alias jalur yang baru terlihat
 * setelah dibaca dari data (preparer menolak dua pemetaan ke daun yang sama),
 * dan rumus workbook yang menurunkan sel lain dari sel ini (belum dimodelkan;
 * fase rumus Studio).
 */
final class PemetaanSuntingan
{
    private const KUNCI_WAJIB = ['sel', 'jalur', 'tipe', 'bulat'];

    /** Batas alamat/jalur = konstanta preparer itu sendiri (bukan salinan); tes menguji batas persis kelas ini. */
    private const MAKS_ALAMAT = WorkbookScalarEditPreparer::MAX_ADDRESS_BYTES;

    private const MAKS_JALUR = WorkbookScalarEditPreparer::MAX_PATH_BYTES;

    /**
     * Batas ulangan yang masih dijabarkan satu per satu. Di atasnya geseran
     * alamat/jalur TIDAK dipotong diam-diam (pemotongan membuat sel di luar
     * batas lolos sebagai "aman"): butir itu dianggap tak terbukti aman dan
     * semua kandidat keluar (fail-closed). Nilai nyata di 25 peta: maks 96.
     */
    private const MAKS_ULANG = 10000;

    private function __construct() {}

    /**
     * Kode varian dari nama workbook memakai pola peta (fungsi impor). Null bila
     * peta tanpa blok `varian` ATAU nama tidak cocok tepat satu pola.
     */
    public static function varian(PetaSel $peta, string $namaWorkbook): ?string
    {
        return self::varianDariIsiPeta($peta->isi, $namaWorkbook);
    }

    /** Pemetaan untuk workbook master ini (varian dari `nama`, seperti jalur impor). */
    public static function untukMaster(PetaSel $peta, WorkbookMaster $workbook): array
    {
        return self::untukNama($peta, (string) $workbook->nama);
    }

    /**
     * @return list<array{sel: string, jalur: string, tipe: string, bulat: int}>
     */
    public static function untukNama(PetaSel $peta, string $namaWorkbook): array
    {
        return self::dariIsiPeta($peta->isi, $namaWorkbook);
    }

    /**
     * Inti logika di atas isi peta mentah (`PetaSel->isi`). Publik supaya tes
     * bisa memakai peta sintetis; kode produksi memakai [untukMaster]/[untukNama].
     *
     * @param  array<string, mixed>  $isiPeta
     * @return list<array{sel: string, jalur: string, tipe: string, bulat: int}>
     */
    public static function dariIsiPeta(array $isiPeta, string $namaWorkbook): array
    {
        $ekstrak = $isiPeta['ekstrak'] ?? null;

        if (! is_array($ekstrak) || ! array_is_list($ekstrak)) {
            return [];
        }

        $varian = null;

        if (isset($isiPeta['varian'])) {
            $varian = self::varianDariIsiPeta($isiPeta, $namaWorkbook);

            if ($varian === null) {
                return [];
            }
        }

        // Hanya butir array; PetaSel::untukVarian bertipe `array` per butir.
        $berlaku = PetaSel::untukVarian(array_values(array_filter($ekstrak, 'is_array')), $varian);

        $luar = self::penguasaLuarEkstrak($isiPeta, $varian);
        $hasil = [];

        foreach ($berlaku as $indeks => $butir) {
            $kandidat = self::kandidat($butir, $varian);

            if ($kandidat === null || self::bentrok($kandidat, $indeks, $berlaku, $varian, $luar)) {
                continue;
            }

            $hasil[] = $kandidat;
        }

        return $hasil;
    }

    /** @param  array<string, mixed>  $isiPeta */
    private static function varianDariIsiPeta(array $isiPeta, string $nama): ?string
    {
        $pola = $isiPeta['varian']['pola'] ?? null;

        if (! is_array($pola) || $pola === []) {
            return null;
        }

        return PetaSel::varianDariPola(array_map('strval', $pola), $nama);
    }

    /**
     * @param  array<string, mixed>  $butir
     * @return array{sel: string, jalur: string, tipe: string, bulat: int}|null
     */
    private static function kandidat(array $butir, ?string $varian): ?array
    {
        $kunci = array_keys($butir);

        if (array_diff(self::KUNCI_WAJIB, $kunci) !== [] || array_diff($kunci, [...self::KUNCI_WAJIB, 'varian']) !== []) {
            return null;
        }

        if ($butir['tipe'] !== 'angka' || $butir['bulat'] !== 10) {
            return null;
        }

        $sel = $butir['sel'];
        $jalur = $butir['jalur'];

        if (! is_string($sel) || ! is_string($jalur) || strlen($sel) > self::MAKS_ALAMAT) {
            return null;
        }

        $alamat = Alamat::urai($sel);

        if ($alamat === null || $alamat['rentang']) {
            return null;
        }

        if (str_contains($jalur, '{i}') || (str_contains($jalur, '{varian}') && $varian === null)) {
            return null;
        }

        $jalurFinal = PetaSel::isiJalur($jalur, $varian, null);

        if (str_contains($jalurFinal, '{') || str_contains($jalurFinal, '}')
            || strlen($jalurFinal) > self::MAKS_JALUR || JalurIsi::urai($jalurFinal) === null) {
            return null;
        }

        return ['sel' => $sel, 'jalur' => $jalurFinal, 'tipe' => 'angka', 'bulat' => 10];
    }

    /**
     * Apakah butir LAIN (ekstrak lain, jangkar, cek, paket_lain) menyentuh sel
     * kandidat atau menulis jalur yang bertumpang tindih dengannya.
     *
     * @param  array{sel: string, jalur: string, tipe: string, bulat: int}  $kandidat
     * @param  array<int, array<string, mixed>>  $berlaku
     * @param  array{alamat: list<array{lembar: string, k1: int, b1: int, k2: int, b2: int}>, jalur: list<string>, rusak: bool}  $luar
     */
    private static function bentrok(array $kandidat, int $indeks, array $berlaku, ?string $varian, array $luar): bool
    {
        $sel = self::uraiLonggar($kandidat['sel']);

        // Alamat butir lain yang tak bisa diurai = tak terbukti aman: fail-closed.
        if ($luar['rusak'] || $sel === null) {
            return true;
        }

        $alamat = $luar['alamat'];
        $jalur = $luar['jalur'];

        foreach ($berlaku as $j => $lain) {
            if ($j === $indeks) {
                continue;
            }

            $dari = self::alamatButir($lain);

            if ($dari['rusak']) {
                return true;
            }

            array_push($alamat, ...$dari['alamat']);
            array_push($jalur, ...self::jalurButir($lain, $varian, 'jalur'));
        }

        foreach ($alamat as $a) {
            if ($a['lembar'] === $sel['lembar'] && $sel['k1'] >= $a['k1'] && $sel['k1'] <= $a['k2']
                && $sel['b1'] >= $a['b1'] && $sel['b1'] <= $a['b2']) {
                return true;
            }
        }

        foreach ($jalur as $j) {
            if (self::jalurBertumpang($kandidat['jalur'], $j)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alamat dan jalur yang dikuasai butir DI LUAR `ekstrak` (untuk varian ini):
     * `jangkar[].sel`, `cek[].sel` (+ geseran `ulang`) dan jalur `cek[].banding`,
     * serta alamat `paket_lain[].ekstrak[]`. Jalur paket_lain milik paket lain,
     * jadi tidak diikutkan.
     *
     * @param  array<string, mixed>  $isiPeta
     * @return array{alamat: list<array{lembar: string, k1: int, b1: int, k2: int, b2: int}>, jalur: list<string>, rusak: bool}
     */
    private static function penguasaLuarEkstrak(array $isiPeta, ?string $varian): array
    {
        $butirAlamat = self::butirBerlaku($isiPeta['jangkar'] ?? null, $varian);
        $cek = self::butirBerlaku($isiPeta['cek'] ?? null, $varian);
        $paketLain = $isiPeta['paket_lain'] ?? null;

        foreach (is_array($paketLain) ? $paketLain : [] as $pl) {
            if (is_array($pl)) {
                $butirAlamat = [...$butirAlamat, ...self::butirBerlaku($pl['ekstrak'] ?? null, $varian)];
            }
        }

        $hasil = ['alamat' => [], 'jalur' => [], 'rusak' => false];

        foreach ([...$butirAlamat, ...$cek] as $butir) {
            $dari = self::alamatButir($butir);
            $hasil['alamat'] = [...$hasil['alamat'], ...$dari['alamat']];
            $hasil['rusak'] = $hasil['rusak'] || $dari['rusak'];
        }

        foreach ($cek as $butir) {
            $hasil['jalur'] = [...$hasil['jalur'], ...self::jalurButir($butir, $varian, 'banding')];
        }

        return $hasil;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function butirBerlaku(mixed $daftar, ?string $varian): array
    {
        if (! is_array($daftar)) {
            return [];
        }

        return PetaSel::untukVarian(array_values(array_filter($daftar, 'is_array')), $varian);
    }

    /**
     * Semua alamat yang dibaca satu butir, geseran `ulang` dihitung. `rusak` =
     * ada kolom alamat bertipe teks yang tak bisa diurai (bukan "tidak ada").
     *
     * @param  array<string, mixed>  $butir
     * @return array{alamat: list<array{lembar: string, k1: int, b1: int, k2: int, b2: int}>, rusak: bool}
     */
    private static function alamatButir(array $butir): array
    {
        $mentah = [];

        // `kunci` hanyalah alamat pada peta_angka; pada daftar_objek ia nama kolom.
        $kolomAlamat = ($butir['tipe'] ?? null) === 'peta_angka' ? ['sel', 'kunci', 'nilai'] : ['sel', 'nilai'];

        foreach ($kolomAlamat as $k) {
            if (is_string($butir[$k] ?? null)) {
                $mentah[] = $butir[$k];
            }
        }

        $rentang = $butir['rentang'] ?? null;

        foreach (is_array($rentang) ? $rentang : [$rentang] as $r) {
            if (is_string($r)) {
                $mentah[] = $r;
            }
        }

        if (is_array($butir['kolom'] ?? null)) {
            foreach ($butir['kolom'] as $spek) {
                $r = is_array($spek) ? ($spek['rentang'] ?? null) : $spek;

                if (is_string($r)) {
                    $mentah[] = $r;
                }
            }
        }

        $geser = [0];
        $terlaluBanyak = is_int($butir['ulang'] ?? null) && $butir['ulang'] > self::MAKS_ULANG;

        if (! $terlaluBanyak && is_int($butir['ulang'] ?? null) && is_int($butir['langkah_baris'] ?? null) && $butir['ulang'] >= 1) {
            $geser = array_map(static fn (int $i): int => $i * $butir['langkah_baris'], range(0, $butir['ulang'] - 1));
        }

        // Ulangan terlalu banyak = alamatnya tak bisa dijabarkan utuh: tandai rusak.
        $hasil = ['alamat' => [], 'rusak' => $terlaluBanyak];

        foreach ($mentah as $teks) {
            $a = self::uraiLonggar($teks);

            if ($a === null) {
                $hasil['rusak'] = true;

                continue;
            }

            foreach ($geser as $g) {
                $hasil['alamat'][] = ['lembar' => $a['lembar'], 'k1' => $a['k1'], 'b1' => $a['b1'] + $g, 'k2' => $a['k2'], 'b2' => $a['b2'] + $g];
            }
        }

        return $hasil;
    }

    /**
     * [Alamat::urai] untuk PEMBANDINGAN: lebih longgar dari parser ketat supaya
     * `$A$1`, `a1`, `'Lembar'!A1`, dan `lembar!A1` tetap dikenali sebagai sel
     * yang sama (nama lembar Excel tidak membedakan huruf besar-kecil). Kandidat
     * sendiri tetap harus lolos [Alamat::urai] ketat di [kandidat].
     *
     * @return array{lembar: string, rentang: bool, k1: int, b1: int, k2: int, b2: int}|null
     */
    private static function uraiLonggar(string $alamat): ?array
    {
        $pos = strrpos($alamat, '!');

        if ($pos === false || $pos === 0) {
            return null;
        }

        $lembar = trim(substr($alamat, 0, $pos));

        if (strlen($lembar) >= 2 && $lembar[0] === "'" && str_ends_with($lembar, "'")) {
            $lembar = str_replace("''", "'", substr($lembar, 1, -1));
        }

        $a = Alamat::urai('L!'.strtoupper(str_replace('$', '', trim(substr($alamat, $pos + 1)))));

        if ($a === null || $lembar === '') {
            return null;
        }

        $a['lembar'] = mb_strtolower($lembar);

        return $a;
    }

    /**
     * Jalur yang ditulis/dibandingkan satu butir pada kolom `$kolom` (`jalur`
     * atau `banding`), setelah `{varian}`/`{i}` diselesaikan.
     *
     * @param  array<string, mixed>  $butir
     * @return list<string>
     */
    private static function jalurButir(array $butir, ?string $varian, string $kolom): array
    {
        $jalur = $butir[$kolom] ?? null;

        if (! is_string($jalur)) {
            return [];
        }

        if (str_contains($jalur, '{i}') && is_int($butir['ulang'] ?? null) && $butir['ulang'] >= 1) {
            // Terlalu banyak untuk dijabarkan: jalur penanda yang TAK TERURAI membuat
            // [jalurBertumpang] menjawab bentrok untuk semua kandidat (fail-closed).
            if ($butir['ulang'] > self::MAKS_ULANG) {
                return ['{ulang-terlalu-besar}'];
            }

            return array_map(
                static fn (int $i): string => PetaSel::isiJalur($jalur, $varian, $i),
                range(0, $butir['ulang'] - 1),
            );
        }

        return [PetaSel::isiJalur($jalur, $varian, null)];
    }

    /**
     * Dua jalur bertumpang tindih bila salah satu adalah awalan SEGMEN yang lain
     * (juga sama persis), mis. `a` vs `a.b`, `a[0]` vs `a`. Pada segmen
     * berkunci sama: pemilih `[kode=X]` lawan `[N]` = mungkin elemen yang sama
     * (alias; tidak ditebak dari data) -> bentrok; `[kode=A]` lawan `[kode=B]`
     * atau `[0]` lawan `[1]` = elemen berbeda -> tidak. Jalur yang tak bisa
     * diurai = tak terbukti aman -> bentrok.
     */
    private static function jalurBertumpang(string $a, string $b): bool
    {
        $sa = JalurIsi::urai($a);
        $sb = JalurIsi::urai($b);

        if ($sa === null || $sb === null) {
            return true;
        }

        for ($i = 0, $n = min(count($sa), count($sb)); $i < $n; $i++) {
            [$x, $y] = [$sa[$i], $sb[$i]];

            if ($x['kunci'] !== $y['kunci']) {
                return false;
            }

            $xPilih = $x['kode'] !== null || $x['indeks'] !== null;
            $yPilih = $y['kode'] !== null || $y['indeks'] !== null;

            if ($xPilih !== $yPilih) {
                return true; // satu sisi menulis seluruh `a`, sisi lain `a[...]`
            }

            if (! $xPilih) {
                continue;
            }

            if (($x['kode'] !== null) !== ($y['kode'] !== null)) {
                return true; // [kode=X] lawan [N]
            }

            if ($x['kode'] !== $y['kode'] || $x['indeks'] !== $y['indeks']) {
                return false; // elemen berbeda
            }
        }

        return true; // salah satu awalan segmen yang lain
    }
}
