<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Timbangan beban rumus EDIT `/hitung` sebelum dievaluasi (tinjau C #1: DoS
 * lewat rumus edit). Dipakai `HitungWorkbook` untuk menolak 422
 * `rumus_terlalu_berat`; rumus asli workbook tidak ditimbang di sini (pagar
 * mereka `KalkulasiBerpagar` saat evaluasi).
 *
 * Dua timbangan, karena biaya mesin punya dua bentuk:
 *  - `sel` = jumlah sel yang dibaca (biaya LINEAR — 27 VLOOKUP skalar ke
 *    tabel bernama di rumus AT terberat = 2.010 sel, murah);
 *  - `pasangan` = hasil kali dua rentang terbesar (biaya KUADRATIK — fungsi
 *    larik/pencarian yang memadukan dua rentang, `MATCH(A1:A2000,B1:B2000,0)`,
 *    atau siaran `A1:A2000*TRANSPOSE(B1:B2000)`; ukuran hasil siaran
 *    max(baris)·max(kolom) selalu ≤ hasil kali ukuran kedua rentang). Tidak
 *    bisa diputus di tengah oleh batas waktu, jadi dicegah sebelum jalan.
 *
 * Ukuran tiap rujukan:
 *  - rentang `A1:B5` = baris × kolom, apa adanya (PhpSpreadsheet merinci
 *    setiap alamat, berisi atau tidak);
 *  - kolom penuh (`A:A`, `$A:$C`) dihitung SEPERTI PhpSpreadsheet
 *    mengevaluasinya: ujungnya dipotong ke baris terakhir berisi di lembar
 *    itu SESUDAH edit diterapkan. Nilainya tetap sama dengan Excel
 *    (`SUM(A:A)` = jumlah sel berisi; sel di luar area terpakai kosong) —
 *    dan edit di `A1048576` ikut memperluas areanya, jadi tidak bisa dipakai
 *    menyelundupkan rentang sejuta baris. Baris penuh (hanya lolos lewat nama
 *    terdefinisi) dipotong ke kolom terakhir berisi di lembar — konservatif;
 *  - sel tunggal = 1; nama terdefinisi = isi rujukannya (rekursif, ≤ 5 lapis);
 *  - rujukan workbook luar / lembar yang tidak ada = 0 (nilai cache / #REF!).
 *
 * Baris penuh (`1:1`) TIDAK dipakai di rumus edit (`adaBarisPenuh`, 422 di
 * validasi): PhpSpreadsheet 5.10 memotong `1:2` ke kolom terakhir berisi di
 * baris AKHIR saja, jadi `SUM(1:2)` dengan Z1=100, A2=1 memberi 1, Excel 101
 * (diuji 15 Okt 2026). Kolom penuh dipotong ke baris terakhir berisi di
 * SELURUH lembar — sama dengan Excel.
 *
 * Timbangan ketiga, `teks × larik` (tinjau C-3 #1): operator `&` dan fungsi
 * teks per unsur (`TEKS_PER_UNSUR`) membangun string BARU untuk tiap unsur
 * larik — `REPT("a",32767)&ROW(A1:A10000)` = 10.000 × 32 KB = 366 MB dalam
 * SATU operasi PhpSpreadsheet, di loop privat `Calculation::processTokenStack`
 * yang tidak bisa diputus `PagarMemori`. Jadi dicegah di sini:
 * `teks` = jumlah operasi teks per unsur; `larik` = ukuran larik terbesar yang
 * bisa terbentuk (rentang, termasuk lewat nama; dua larik atau lebih → siaran
 * maks(baris) × maks(kolom), dengan TRANSPOSE → hasil kali dua sisi terpanjang).
 * Tiap unsur dianggap bisa 32.767 huruf (batas teks sel).
 *
 * Rujukan yang ukurannya baru diketahui saat evaluasi — INDIRECT, OFFSET,
 * rentang yang ujungnya hasil fungsi (`A1:INDEX(…)`), rentang berantai, nama
 * yang tidak terurai — ditandai `dinamis` (definisi yang sama dengan
 * `GrafDependensi`).
 */
final class PagarRumus
{
    private const KEDALAMAN_MAKS = 5;

    private const JENIS_RUJUKAN = ['Cell Reference', 'Column Reference', 'Row Reference'];

    /**
     * Fungsi daftar putih yang membangun teks BARU per unsur larik (selain
     * operator `&`). CONCAT/TEXTJOIN tidak termasuk: hasilnya satu teks
     * (≤ 32.767 huruf), bukan larik teks.
     */
    public const TEKS_PER_UNSUR = [
        'LEFT', 'RIGHT', 'MID', 'TRIM', 'UPPER', 'LOWER', 'PROPER', 'CLEAN', 'CONCATENATE',
        'REPLACE', 'SUBSTITUTE', 'REPT', 'TEXT', 'FIXED', 'DOLLAR', 'T', 'CHAR', 'UNICHAR', 'ADDRESS',
    ];

    private function __construct() {}

    /** Rumus memuat rujukan baris penuh (`1:1`, `$2:$5`)? Rumus tak terurai → false (ditolak di tempat lain). */
    public static function adaBarisPenuh(string $rumus): bool
    {
        foreach (DaftarPutihFungsi::token($rumus) ?? [] as $t) {
            if (($t['type'] ?? null) === 'Row Reference') {
                return true;
            }
        }

        return false;
    }

    /**
     * Baris & kolom terakhir berisi per lembar, sesudah edit (edit kosong
     * tidak menyusutkan — konservatif).
     *
     * @param  list<array{judul: string, alamat: string, isi: IsiSel}>  $edit
     * @return array<string, array{0: int, 1: int}> JUDUL BESAR → [baris, kolom]
     */
    public static function luas(ModelHitung $model, array $edit): array
    {
        $luas = [];

        foreach ($model->lembar as $l) {
            $b = 1;
            $k = 1;

            foreach (array_keys($l['sel']) as $alamat) {
                [$kk, $bb] = Coordinate::indexesFromString((string) $alamat);
                $b = max($b, $bb);
                $k = max($k, $kk);
            }

            $luas[strtoupper($l['judul'])] = [$b, $k];
        }

        foreach ($edit as $e) {
            $judul = strtoupper($e['judul']);

            if ($e['isi']->tipe === IsiSel::KOSONG || ! isset($luas[$judul])) {
                continue;
            }

            [$kk, $bb] = Coordinate::indexesFromString($e['alamat']);
            $luas[$judul] = [max($luas[$judul][0], $bb), max($luas[$judul][1], $kk)];
        }

        return $luas;
    }

    /**
     * Rumus asli (nilai `f` model) di sel itu, atau null bila sel aslinya bukan rumus.
     */
    public static function rumusAsli(ModelHitung $model, string $judul, string $alamat): ?string
    {
        foreach ($model->lembar as $l) {
            if ($l['judul'] === $judul) {
                $sel = $l['sel'][$alamat] ?? null;

                return is_array($sel) && $sel[0] === DataType::TYPE_FORMULA ? (string) $sel[1] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $luas  dari `luas()`
     * @return array{sel: int, pasangan: int, rentang: array{0: int, 1: int}, dinamis: bool, teks: int, larik: int}
     */
    public static function timbang(string $rumus, string $judul, ModelHitung $model, array $luas): array
    {
        $namaPeta = [];

        foreach ($model->nama as $n) {
            $namaPeta[strtoupper($n['nama'])][] = $n;
        }

        $hasil = self::timbangDalam($rumus, $judul, $namaPeta, $luas, 0);
        $ukuran = $hasil['rentang'];
        rsort($ukuran);
        $terbesar = [$ukuran[0] ?? 0, $ukuran[1] ?? 0];

        return [
            'sel' => $hasil['sel'],
            'pasangan' => $terbesar[0] * $terbesar[1],
            'rentang' => $terbesar,
            'dinamis' => $hasil['dinamis'],
            'teks' => $hasil['teks'],
            'larik' => self::larikTerbesar($hasil['dimensi'], $hasil['transpose']),
        ];
    }

    /**
     * Ukuran larik terbesar yang bisa terbentuk dari larik-larik di rumus.
     * Satu larik = ukurannya sendiri. Dua atau lebih: siaran Excel memberi
     * maks(baris) × maks(kolom); bila ada TRANSPOSE, sisi mana pun bisa jadi
     * baris atau kolom → hasil kali dua sisi terpanjang (dari larik berbeda).
     *
     * @param  list<array{0: int, 1: int}>  $dimensi  [baris, kolom] tiap larik
     */
    private static function larikTerbesar(array $dimensi, bool $transpose): int
    {
        if ($dimensi === []) {
            return 1;
        }

        if (count($dimensi) === 1) {
            return $dimensi[0][0] * $dimensi[0][1];
        }

        $larik = max(array_column($dimensi, 0)) * max(array_column($dimensi, 1));

        if ($transpose) {
            $sisi = array_map(static fn (array $d): int => max($d[0], $d[1]), $dimensi);
            rsort($sisi);
            $larik = max($larik, $sisi[0] * $sisi[1]);
        }

        return $larik;
    }

    /**
     * @param  array<string, list<array{nama: string, lembar: string|null, nilai: string, lokal: bool, cakupan: string|null}>>  $namaPeta
     * @param  array<string, array{0: int, 1: int}>  $luas
     * @return array{sel: int, rentang: list<int>, dinamis: bool, teks: int, dimensi: list<array{0: int, 1: int}>, transpose: bool}
     */
    private static function timbangDalam(string $rumus, string $judul, array $namaPeta, array $luas, int $kedalaman): array
    {
        $hasil = self::kosong(false);
        $token = DaftarPutihFungsi::token(str_starts_with($rumus, '=') ? $rumus : '='.$rumus);

        if ($token === null) {
            return self::kosong(true);
        }

        $terpakai = [];
        $n = count($token);

        for ($i = 0; $i < $n; $i++) {
            $t = $token[$i];
            $jenis = $t['type'] ?? '';
            $nilai = is_scalar($t['value'] ?? null) ? (string) $t['value'] : '';

            if ($jenis === 'Function') {
                $fungsi = DaftarPutihFungsi::namaDariToken($nilai);

                if (in_array($fungsi, DaftarPutihFungsi::DINAMIS, true)) {
                    $hasil['dinamis'] = true;
                }

                if (in_array($fungsi, self::TEKS_PER_UNSUR, true)) {
                    $hasil['teks']++;
                }

                // Konstanta larik `{1,2;3,4}` (MKMATRIX) tidak dihitung di sini: MKMATRIX
                // di luar daftar putih, jadi rumus edit berkonstanta larik sudah 422
                // di validasi. Bila kelak diizinkan, dimensinya wajib ikut `dimensi`.
                if ($fungsi === 'TRANSPOSE') {
                    $hasil['transpose'] = true;
                }

                continue;
            }

            if ($jenis === 'Binary Operator' && $nilai === '&') {
                $hasil['teks']++;

                continue;
            }

            if ($jenis === 'Binary Operator' && $nilai === ':') {
                $a = $token[$i - 2] ?? null;
                $b = $token[$i - 1] ?? null;

                if ($a !== null && $b !== null && in_array($a['type'] ?? '', self::JENIS_RUJUKAN, true) && in_array($b['type'] ?? '', self::JENIS_RUJUKAN, true)) {
                    $terpakai[$i - 2] = $terpakai[$i - 1] = true;
                    [$baris, $kolom] = self::dimensiRentang($a, $b, $judul, $luas);
                    $hasil['sel'] += $baris * $kolom;
                    $hasil['rentang'][] = $baris * $kolom;

                    if ($baris * $kolom > 1) {
                        $hasil['dimensi'][] = [$baris, $kolom];
                    }
                } else {
                    $hasil['dinamis'] = true;
                }

                continue;
            }

            if ($jenis === 'Defined Name') {
                $baca = self::nama($nilai, $judul, $namaPeta, $luas, $kedalaman);
                $hasil['sel'] += $baca['sel'];
                array_push($hasil['rentang'], ...$baca['rentang']);
                array_push($hasil['dimensi'], ...$baca['dimensi']);
                $hasil['dinamis'] = $hasil['dinamis'] || $baca['dinamis'];
                $hasil['teks'] += $baca['teks'];
                $hasil['transpose'] = $hasil['transpose'] || $baca['transpose'];
            }
        }

        foreach ($token as $i => $t) {
            if (isset($terpakai[$i]) || ! in_array($t['type'] ?? '', self::JENIS_RUJUKAN, true)) {
                continue;
            }

            if (($t['type'] ?? '') === 'Cell Reference') {
                $hasil['sel']++;
            } else {
                // Rujukan kolom/baris tanpa pasangan `:` — tidak terbaca statis.
                $hasil['dinamis'] = true;
            }
        }

        return $hasil;
    }

    /**
     * @return array{sel: int, rentang: list<int>, dinamis: bool, teks: int, dimensi: list<array{0: int, 1: int}>, transpose: bool}
     */
    private static function kosong(bool $dinamis): array
    {
        return ['sel' => 0, 'rentang' => [], 'dinamis' => $dinamis, 'teks' => 0, 'dimensi' => [], 'transpose' => false];
    }

    /**
     * [baris, kolom] rentang; `[0, 0]` = tidak dibaca dari lembar.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, array{0: int, 1: int}>  $luas
     * @return array{0: int, 1: int}
     */
    private static function dimensiRentang(array $a, array $b, string $judul, array $luas): array
    {
        $ra = self::sel((string) $a['value'], $judul, $luas);

        if ($ra === null) {
            return [0, 0];
        }

        $rb = self::sel((string) $b['value'], $ra[0], $luas);

        if ($rb === null) {
            return [0, 0];
        }

        $b1 = min($ra[1], $rb[1]);
        $b2 = max($ra[1], $rb[1]);
        $k1 = min($ra[2], $rb[2]);
        $k2 = max($ra[2], $rb[2]);
        [$barisTerakhir, $kolomTerakhir] = $luas[$ra[0]] ?? [1, 1];

        if (($a['type'] ?? '') === 'Column Reference' || ($b['type'] ?? '') === 'Column Reference') {
            $b2 = min($b2, max($b1, $barisTerakhir));
        }

        if (($a['type'] ?? '') === 'Row Reference' || ($b['type'] ?? '') === 'Row Reference') {
            $k2 = min($k2, max($k1, $kolomTerakhir));
        }

        return [$b2 - $b1 + 1, $k2 - $k1 + 1];
    }

    /**
     * Token rujukan → [JUDUL BESAR, baris, kolom]; null = workbook luar,
     * lembar tak dikenal, atau alamat tak terbaca (tidak dibaca dari lembar).
     *
     * @param  array<string, array{0: int, 1: int}>  $luas
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function sel(string $ref, string $judul, array $luas): ?array
    {
        $ref = trim($ref);
        $lembar = $judul;
        $alamat = $ref;

        if (str_contains($ref, '!')) {
            [$lembar, $alamat] = Worksheet::extractSheetTitle($ref, true, true);
            $lembar = (string) $lembar;

            if (str_starts_with($lembar, '[')) {
                return null;
            }
        }

        $lembar = strtoupper($lembar);

        if (! isset($luas[$lembar])) {
            return null;
        }

        try {
            [$kolom, $baris] = Coordinate::indexesFromString(str_replace('$', '', strtoupper((string) $alamat)));
        } catch (Throwable) {
            return null;
        }

        return [$lembar, $baris, $kolom];
    }

    /**
     * @param  array<string, list<array{nama: string, lembar: string|null, nilai: string, lokal: bool, cakupan: string|null}>>  $namaPeta
     * @param  array<string, array{0: int, 1: int}>  $luas
     * @return array{sel: int, rentang: list<int>, dinamis: bool, teks: int, dimensi: list<array{0: int, 1: int}>, transpose: bool}
     */
    private static function nama(string $token, string $judul, array $namaPeta, array $luas, int $kedalaman): array
    {
        if ($kedalaman >= self::KEDALAMAN_MAKS) {
            return self::kosong(true);
        }

        $lembarNama = $judul;
        $nama = $token;

        if (str_contains($token, '!')) {
            [$lembarNama, $nama] = Worksheet::extractSheetTitle($token, true, true);
            $lembarNama = (string) $lembarNama;
        }

        $calon = $namaPeta[strtoupper((string) $nama)] ?? [];
        $pilih = null;

        foreach ($calon as $c) {
            if ($c['lokal'] && strcasecmp((string) $c['cakupan'], $lembarNama) === 0) {
                $pilih = $c;
                break;
            }
        }

        if ($pilih === null) {
            foreach ($calon as $c) {
                if (! $c['lokal']) {
                    $pilih = $c;
                    break;
                }
            }
        }

        $nilai = $pilih === null ? '' : ltrim($pilih['nilai'], '=');

        // Bukan nama terdaftar (#NAME?), nama rusak, atau tautan workbook luar: tidak membaca lembar.
        if ($nilai === '' || str_contains($nilai, '#REF!') || str_contains($nilai, '[')) {
            return self::kosong(false);
        }

        return self::timbangDalam('='.$nilai, $pilih['lembar'] ?? $judul, $namaPeta, $luas, $kedalaman + 1);
    }
}
