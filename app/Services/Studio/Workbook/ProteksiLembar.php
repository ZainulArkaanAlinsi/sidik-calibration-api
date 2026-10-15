<?php

namespace App\Services\Studio\Workbook;

use App\Models\User;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Proteksi lembar Excel di Studio (K-49-15, 15 Okt 2026): lembar yang
 * dilindungi di `.xlsm` asli → sel TERKUNCI-nya hanya boleh disunting
 * `super_admin` di simulasi `/hitung`; sel yang tidak terkunci tetap boleh
 * disunting siapa pun yang punya izin `workbook.hitung`.
 *
 * ## Bentuk simpan (format kanonik 5, di `tata_letak`)
 *
 *  - `dilindungi` bool — `<sheetProtection sheet="1">` (PhpSpreadsheet
 *    `getProtection()->getSheet()`). Baris lama tanpa kunci = tidak dilindungi.
 *  - `sel_tidak_terkunci` list rentang A1 (`"B5:C6"`, `"F2"`,
 *    `"E9:E1048576"`) — HIMPUNAN sel yang tidak terkunci, hanya diisi bila
 *    `dilindungi`. Rentang boleh tumpang tindih; sel terkunci ⇔ lembar
 *    dilindungi DAN tidak ada rentang yang memuatnya. Bukan penanda per sel:
 *    satu kolom input utuh cukup satu rentang.
 *
 * ## Aturan Excel yang ditiru
 *
 * Sel terkunci kecuali gayanya `protection locked="0"` (bawaan Excel =
 * terkunci, juga `inherit`). Gaya sel diambil seperti Excel: sel yang ada di
 * berkas → xf sel itu; sel yang tidak ada → gaya baris (`<row s>`) bila ada,
 * lalu gaya kolom (`<col style>`), lalu xf 0. Jadi kolom input yang dibuka
 * lewat gaya kolom utuh tetap terbuka sampai baris 1.048.576.
 *
 * Batas yang diketahui: "Allow Edit Ranges" (`<protectedRanges>`) TIDAK
 * dibaca — PhpSpreadsheet hanya membaca sandi format lama, jadi rentang
 * bersandi modern tampak tanpa sandi. Sel terkunci di rentang seperti itu
 * diperlakukan terkunci (lebih ketat dari Excel; super_admin tetap bisa).
 */
final class ProteksiLembar
{
    public const MAKS_KOLOM = 16384;

    public const MAKS_BARIS = 1048576;

    /** @var array<int, bool> xf → tidak terkunci */
    private array $cacheXf = [];

    public function __construct(private readonly Spreadsheet $ss) {}

    /**
     * Satu-satunya aturan "boleh menyunting sel terkunci" — dipakai `/hitung`
     * dan detail workbook (`boleh_edit_sel_terkunci`), supaya klien tidak
     * menebak dari peran.
     */
    public static function bolehMelewati(?User $user): bool
    {
        return $user?->isSuperAdmin() === true;
    }

    /**
     * @return array{dilindungi: bool, sel_tidak_terkunci: list<string>}
     */
    public function dariSheet(Worksheet $sheet): array
    {
        if ($sheet->getProtection()->getSheet() !== true) {
            return ['dilindungi' => false, 'sel_tidak_terkunci' => []];
        }

        return ['dilindungi' => true, 'sel_tidak_terkunci' => $this->rentangTidakTerkunci($sheet)];
    }

    /**
     * Rentang dari `tata_letak` → batas numerik, atau null bila lembar tidak
     * dilindungi (baris lama tanpa kunci juga null).
     *
     * @param  array<string, mixed>  $tata
     * @return list<array{0: int, 1: int, 2: int, 3: int}>|null [kolom1, baris1, kolom2, baris2]
     */
    public static function dariTataLetak(array $tata): ?array
    {
        if (($tata['dilindungi'] ?? false) !== true) {
            return null;
        }

        $hasil = [];

        foreach ((array) ($tata['sel_tidak_terkunci'] ?? []) as $rentang) {
            if (! is_string($rentang) || $rentang === '') {
                continue;
            }

            [[$k1, $b1], [$k2, $b2]] = Coordinate::rangeBoundaries($rentang);
            $hasil[] = [(int) $k1, (int) $b1, (int) $k2, (int) $b2];
        }

        return $hasil;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>|null  $rentang  keluaran `dariTataLetak()`
     */
    public static function terkunci(?array $rentang, int $kolom, int $baris): bool
    {
        if ($rentang === null) {
            return false;
        }

        foreach ($rentang as [$k1, $b1, $k2, $b2]) {
            if ($kolom >= $k1 && $kolom <= $k2 && $baris >= $b1 && $baris <= $b2) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function rentangTidakTerkunci(Worksheet $sheet): array
    {
        $bukaBawaan = $this->buka(0);

        // Gaya kolom: kolom tanpa dimensi = xf 0.
        $kolom = [];

        foreach ($sheet->getColumnDimensions() as $huruf => $dim) {
            $xf = $dim->getXfIndex();

            if ($xf !== null) {
                $kolom[Coordinate::columnIndexFromString((string) $huruf)] = $this->buka($xf);
            }
        }

        ksort($kolom);
        $umum = self::intervalKolom($kolom, $bukaBawaan);

        // Gaya baris (menang atas gaya kolom untuk sel yang tidak ada di berkas).
        $gayaBaris = [];

        foreach ($sheet->getRowDimensions() as $nomor => $dim) {
            $xf = $dim->getXfIndex();

            if ($xf !== null) {
                $gayaBaris[(int) $nomor] = $this->buka($xf);
            }
        }

        // Sel yang ada di berkas (termasuk sel kosong bergaya): xf sel itu sendiri.
        $sel = [];
        $koleksi = $sheet->getCellCollection();

        foreach ($koleksi->getCoordinates() as $alamat) {
            $cell = $koleksi->get($alamat);

            if ($cell === null) {
                continue;
            }

            [$k, $b] = Coordinate::indexesFromString($alamat);
            $sel[(int) $b][(int) $k] = $this->buka($cell->getXfIndex());
        }

        $khusus = array_unique([...array_keys($sel), ...array_keys($gayaBaris)]);
        sort($khusus);

        // Segmen baris berurutan: pita baris tanpa sel/gaya (= `$umum`) dan baris khusus.
        $segmen = [];
        $mulai = 1;

        foreach ($khusus as $b) {
            if ($b > $mulai) {
                $segmen[] = [$mulai, $b - 1, $umum];
            }

            $dasar = array_key_exists($b, $gayaBaris)
                ? ($gayaBaris[$b] ? [[1, self::MAKS_KOLOM]] : [])
                : $umum;
            $segmen[] = [$b, $b, self::timpa($dasar, $sel[$b] ?? [])];
            $mulai = $b + 1;
        }

        if ($mulai <= self::MAKS_BARIS) {
            $segmen[] = [$mulai, self::MAKS_BARIS, $umum];
        }

        return self::rentang($segmen);
    }

    /**
     * Gabungkan segmen baris jadi persegi panjang: interval kolom yang tetap
     * tercakup di segmen berikutnya diteruskan ke bawah (boleh tumpang tindih
     * dengan interval yang lebih lebar — keanggotaan = gabungan).
     *
     * @param  list<array{0: int, 1: int, 2: list<array{0: int, 1: int}>}>  $segmen
     * @return list<string>
     */
    private static function rentang(array $segmen): array
    {
        /** @var array<string, array{0: int, 1: int, 2: int}> $terbuka kolom1, kolom2, baris mulai */
        $terbuka = [];
        $keluar = [];

        foreach ($segmen as [$b1, , $interval]) {
            $lanjut = [];

            foreach ($terbuka as $kunci => [$k1, $k2, $mulai]) {
                if (self::tercakup($interval, $k1, $k2)) {
                    $lanjut[$kunci] = [$k1, $k2, $mulai];
                } else {
                    $keluar[] = [$k1, $mulai, $k2, $b1 - 1];
                }
            }

            foreach ($interval as [$a, $z]) {
                $lanjut[$a.':'.$z] ??= [$a, $z, $b1];
            }

            $terbuka = $lanjut;
        }

        foreach ($terbuka as [$k1, $k2, $mulai]) {
            $keluar[] = [$k1, $mulai, $k2, self::MAKS_BARIS];
        }

        usort($keluar, static fn (array $x, array $y): int => [$x[1], $x[0], $x[3], $x[2]] <=> [$y[1], $y[0], $y[3], $y[2]]);

        return array_map(static function (array $r): string {
            $awal = Coordinate::stringFromColumnIndex($r[0]).$r[1];

            return $r[0] === $r[2] && $r[1] === $r[3] ? $awal : $awal.':'.Coordinate::stringFromColumnIndex($r[2]).$r[3];
        }, $keluar);
    }

    /** @param  list<array{0: int, 1: int}>  $interval */
    private static function tercakup(array $interval, int $k1, int $k2): bool
    {
        foreach ($interval as [$a, $z]) {
            if ($a <= $k1 && $k2 <= $z) {
                return true;
            }

            if ($a > $k1) {
                return false;
            }
        }

        return false;
    }

    /**
     * Interval kolom tidak terkunci untuk baris tanpa sel/gaya baris.
     *
     * @param  array<int, bool>  $kolom  kolom bergaya (urut) → tidak terkunci
     * @return list<array{0: int, 1: int}>
     */
    private static function intervalKolom(array $kolom, bool $bukaBawaan): array
    {
        $hasil = [];
        $c = 1;

        foreach ($kolom as $k => $buka) {
            if ($k > $c && $bukaBawaan) {
                self::tambah($hasil, $c, $k - 1);
            }

            if ($buka) {
                self::tambah($hasil, $k, $k);
            }

            $c = $k + 1;
        }

        if ($c <= self::MAKS_KOLOM && $bukaBawaan) {
            self::tambah($hasil, $c, self::MAKS_KOLOM);
        }

        return $hasil;
    }

    /**
     * Interval dasar baris ditimpa status sel yang ada di berkas.
     *
     * @param  list<array{0: int, 1: int}>  $dasar  urut, tidak tumpang tindih
     * @param  array<int, bool>  $titik  kolom → tidak terkunci
     * @return list<array{0: int, 1: int}>
     */
    private static function timpa(array $dasar, array $titik): array
    {
        if ($titik === []) {
            return $dasar;
        }

        ksort($titik);
        $kunci = array_keys(array_filter($titik, static fn (bool $buka): bool => ! $buka));
        $buka = array_keys(array_filter($titik));
        $potong = [];
        $i = 0;
        $n = count($kunci);

        foreach ($dasar as [$a, $z]) {
            $mulai = $a;

            while ($i < $n && $kunci[$i] < $a) {
                $i++;
            }

            while ($i < $n && $kunci[$i] <= $z) {
                if ($kunci[$i] > $mulai) {
                    $potong[] = [$mulai, $kunci[$i] - 1];
                }

                $mulai = $kunci[$i] + 1;
                $i++;
            }

            if ($mulai <= $z) {
                $potong[] = [$mulai, $z];
            }
        }

        foreach ($buka as $k) {
            $potong[] = [$k, $k];
        }

        usort($potong, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $hasil = [];

        foreach ($potong as [$a, $z]) {
            self::tambah($hasil, $a, $z);
        }

        return $hasil;
    }

    /** @param  list<array{0: int, 1: int}>  $hasil  urut menurut awal */
    private static function tambah(array &$hasil, int $a, int $z): void
    {
        $akhir = count($hasil) - 1;

        if ($akhir >= 0 && $a <= $hasil[$akhir][1] + 1) {
            $hasil[$akhir][1] = max($hasil[$akhir][1], $z);

            return;
        }

        $hasil[] = [$a, $z];
    }

    /** Gaya xf ini TIDAK terkunci (Excel: terkunci kecuali `locked="0"`). */
    private function buka(int $xf): bool
    {
        if (! array_key_exists($xf, $this->cacheXf)) {
            $style = $this->ss->getCellXfByIndexOrNull($xf) ?? $this->ss->getDefaultStyle();
            $this->cacheXf[$xf] = $style->getProtection()->getLocked() === Protection::PROTECTION_UNPROTECTED;
        }

        return $this->cacheXf[$xf];
    }
}
