<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Functions;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;

/**
 * Fungsi lembar yang HARUS sama dengan Excel tetapi berbeda di PhpSpreadsheet
 * 5.10 (Studio tahap C, bukti `C-anak-timbangan/excel-audit.md`):
 *
 *  - STDEV / STDEV.S: bawaan PhpSpreadsheet satu lintasan (Σx² − (Σx)²/n) —
 *    vektor uji Excel COM: identik 77/400, meleset sampai 9·10¹¹ ULP pada data
 *    terkondisi buruk. Di sini dua lintasan (rata-rata Σx/n, lalu Σ(x−m)²
 *    berurutan, /(n−1), akar) = `SIDIK.STDEV` spike S0a (87f3967): identik
 *    360/400 — 300/300 untuk data bertanda sama (bacaan lab), ±1..4 ULP pada
 *    data bertanda campur bersebaran besar (algoritma internal Excel tidak
 *    terbuka; 12 varian lain diuji, tidak ada yang lebih baik).
 *  - TINV / T.INV.2T: bawaan PhpSpreadsheet Newton-Raphson bertoleransi —
 *    identik 42/1233. Di sini tabel nilai Excel (`TabelTinvExcel`, df bulat
 *    1..5000 untuk p = 0,05 → identik menurut konstruksi), di luar tabel
 *    kuantil presisi ganda dengan tahap akhir sama seperti Excel
 *    (t = √(df·(1/x − 1)), x = BETA.INV(p, df/2, ½); Excel COM: identik
 *    514/516 terhadap TINV-nya sendiri).
 *  - NOW / TODAY: dibekukan ke waktu acuan (serial Excel) supaya hasil hitung
 *    bisa diulang dan diadu.
 *
 * `addFunction()` PhpSpreadsheet tidak bisa menimpa fungsi bawaan, jadi entri
 * tabel fungsinya (`TabelFungsiPhpSpreadsheet`) ditukar sementara lewat `pasang()`
 * dan SELALU dikembalikan `lepas()` (try/finally di `MesinHitung`) — tabel itu
 * statis seproses, jangan sampai bocor ke ekspor Excel lain di request yang
 * sama. Penukaran yang sama memasang pagar argumen skalar `BatasFungsiExcel`
 * (tinjau C-2 #1); entri aslinya dibaca lewat `asli()`.
 */
final class FungsiExcel
{
    /** Serial Excel untuk NOW(); TODAY() = floor. Null = belum dipasang. */
    private static ?float $waktuAcuan = null;

    /** @var array<string, array<string, mixed>>|null entri asli selama terpasang */
    private static ?array $asli = null;

    private const GANTI = [
        'STDEV' => 'stdev',
        'STDEV.S' => 'stdev',
        'TINV' => 'tinv',
        'T.INV.2T' => 'tinv',
        'NOW' => 'now',
        'TODAY' => 'today',
        'POWER' => 'pangkat',
    ];

    private function __construct() {}

    /** @param  float  $tenggat  `microtime(true)` batas akhir hitung, diperiksa pagar `BatasFungsiExcel` */
    public static function pasang(float $waktuAcuanSerial, float $tenggat = INF): void
    {
        BatasFungsiExcel::setelTenggat($tenggat);

        if (self::$asli !== null) {
            self::$waktuAcuan = $waktuAcuanSerial;

            return;
        }

        self::$asli = [];
        $tabel = &TabelFungsiPhpSpreadsheet::tabel();

        foreach ([self::class => self::GANTI, BatasFungsiExcel::class => BatasFungsiExcel::GANTI] as $kelas => $peta) {
            foreach ($peta as $nama => $metode) {
                self::$asli[$nama] = $tabel[$nama];
                $tabel[$nama]['functionCall'] = [$kelas, $metode];
            }
        }

        // Operator `^` yang ditulis ulang `PenulisUlangPangkat`.
        Calculation::addFunction(PenulisUlangPangkat::FUNGSI, [
            'category' => 'Sidik', 'functionCall' => [self::class, 'pangkat'], 'argumentCount' => '2',
        ]);

        self::$waktuAcuan = $waktuAcuanSerial;
    }

    public static function lepas(): void
    {
        if (self::$asli === null) {
            return;
        }

        $tabel = &TabelFungsiPhpSpreadsheet::tabel();

        foreach (self::$asli as $nama => $entri) {
            $tabel[$nama] = $entri;
        }

        Calculation::removeFunction(PenulisUlangPangkat::FUNGSI);
        self::$asli = null;
        self::$waktuAcuan = null;
        BatasFungsiExcel::setelTenggat(INF);
    }

    /**
     * Pemanggil ASLI PhpSpreadsheet untuk fungsi `$nama` — entri yang disimpan
     * selama terpasang, atau entri tabel bila belum dipasang.
     */
    public static function asli(string $nama): callable
    {
        $entri = self::$asli[$nama] ?? TabelFungsiPhpSpreadsheet::tabel()[$nama];
        /** @var callable $panggil */
        $panggil = $entri['functionCall'];

        return $panggil;
    }

    /**
     * STDEV dua lintasan dengan aturan argumen Excel: angka di RUJUKAN
     * (datang sebagai larik) dihitung, teks/boolean/kosong di rujukan
     * diabaikan; argumen langsung boolean/teks-angka ikut dihitung, teks lain
     * → #VALUE!; galat di mana pun → galat itu.
     */
    public static function stdev(mixed ...$args): float|string
    {
        $x = [];

        foreach ($args as $arg) {
            if (is_array($arg)) {
                foreach (Functions::flattenArray($arg) as $v) {
                    if (is_string($v) && in_array($v, ExcelError::ERROR_CODES, true)) {
                        return $v;
                    }

                    if (is_int($v) || is_float($v)) {
                        $x[] = (float) $v;
                    }
                }

                continue;
            }

            if ($arg === null) {
                $x[] = 0.0;
            } elseif (is_bool($arg)) {
                $x[] = $arg ? 1.0 : 0.0;
            } elseif (is_int($arg) || is_float($arg)) {
                $x[] = (float) $arg;
            } elseif (is_string($arg) && in_array($arg, ExcelError::ERROR_CODES, true)) {
                return $arg;
            } elseif (is_string($arg) && is_numeric(trim($arg))) {
                $x[] = (float) trim($arg);
            } else {
                return ExcelError::VALUE();
            }
        }

        return self::stdevDuaLintasan($x);
    }

    /** @param  list<float>  $x */
    public static function stdevDuaLintasan(array $x): float|string
    {
        $n = count($x);

        if ($n < 2) {
            return ExcelError::DIV0();
        }

        $jumlah = 0.0;

        foreach ($x as $v) {
            $jumlah += $v;
        }

        $rata = $jumlah / $n;
        $kuadrat = 0.0;

        foreach ($x as $v) {
            $d = $v - $rata;
            $kuadrat += $d * $d;
        }

        return sqrt($kuadrat / ($n - 1));
    }

    /** TINV(p, df) = T.INV.2T(p, df), df dipotong ke bilangan bulat. */
    public static function tinv(mixed $p, mixed $df): float|string
    {
        $p = self::angka($p);
        $df = self::angka($df);

        if (is_string($p)) {
            return $p;
        }

        if (is_string($df)) {
            return $df;
        }

        $dfBulat = floor($df);

        if ($p <= 0.0 || $p > 1.0 || $dfBulat < 1.0) {
            return ExcelError::NAN();
        }

        if ($dfBulat <= PHP_INT_MAX) {
            $tabel = TabelTinvExcel::nilai($p, (int) $dfBulat);

            if ($tabel !== null) {
                return $tabel;
            }
        }

        return self::tinvCadangan($p, $dfBulat);
    }

    /**
     * `x^y` / POWER(x, y) seperti Excel (bukti COM, lihat `PenulisUlangPangkat`):
     * y bulat → kuadrat-berulang; y = ½ → √x; y < 0 → 1/(x^|y|); selain itu
     * exp(y·ln x). Galat: 0^0 #NUM!, 0^negatif #DIV/0!, negatif^pecahan #NUM!.
     * Larik (rumus larik) diproses per unsur.
     */
    public static function pangkat(mixed $x, mixed $y): mixed
    {
        $x = self::satuAtauLarik($x);
        $y = self::satuAtauLarik($y);

        if (is_array($x) || is_array($y)) {
            return self::perUnsur($x, $y);
        }

        $x = self::angkaPangkat($x);
        $y = self::angkaPangkat($y);

        if (is_string($x)) {
            return $x;
        }

        if (is_string($y)) {
            return $y;
        }

        return self::pangkatAngka($x, $y);
    }

    public static function pangkatAngka(float $x, float $y): float|string
    {
        if ($x === 0.0) {
            return match (true) {
                $y === 0.0 => ExcelError::NAN(),
                $y < 0.0 => ExcelError::DIV0(),
                default => 0.0,
            };
        }

        if ($y < 0.0) {
            $positif = self::pangkatAngka($x, -$y);

            return is_string($positif) ? $positif : 1.0 / $positif;
        }

        if (floor($y) === $y && $y <= 2147483647.0) {
            $n = (int) $y;
            $hasil = 1.0;
            $basis = $x;

            while ($n > 0) {
                if (($n & 1) === 1) {
                    $hasil *= $basis;
                }

                $basis *= $basis;
                $n >>= 1;
            }

            return is_finite($hasil) ? $hasil : ExcelError::NAN();
        }

        if ($x < 0.0) {
            return ExcelError::NAN();
        }

        $hasil = $y === 0.5 ? sqrt($x) : exp($y * log($x));

        return is_finite($hasil) ? $hasil : ExcelError::NAN();
    }

    private static function satuAtauLarik(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }

        $datar = Functions::flattenArray($v);

        return count($datar) === 1 ? reset($datar) : $v;
    }

    private static function angkaPangkat(mixed $v): float|string
    {
        return match (true) {
            $v === null => 0.0,
            is_bool($v) => $v ? 1.0 : 0.0,
            is_int($v), is_float($v) => (float) $v,
            is_string($v) && in_array($v, ExcelError::ERROR_CODES, true) => $v,
            is_string($v) && is_numeric(trim($v)) => (float) trim($v),
            default => ExcelError::VALUE(),
        };
    }

    /** @return array<int|string, mixed> */
    private static function perUnsur(mixed $x, mixed $y): array
    {
        $bentuk = is_array($x) ? $x : $y;
        $hasil = [];

        foreach ($bentuk as $b => $baris) {
            foreach ((array) $baris as $k => $_) {
                $xv = is_array($x) ? ($x[$b][$k] ?? null) : $x;
                $yv = is_array($y) ? ($y[$b][$k] ?? null) : $y;
                $hasil[$b][$k] = self::pangkat($xv, $yv);
            }
        }

        return $hasil;
    }

    public static function now(): float|string
    {
        return self::$waktuAcuan ?? ExcelError::VALUE();
    }

    public static function today(): float|string
    {
        return self::$waktuAcuan === null ? ExcelError::VALUE() : floor(self::$waktuAcuan);
    }

    /** Argumen skalar Excel → float, atau kode galat. */
    private static function angka(mixed $v): float|string
    {
        $v = Functions::flattenSingleValue($v);

        return match (true) {
            $v === null => 0.0,
            is_int($v), is_float($v) => (float) $v,
            is_string($v) && in_array($v, ExcelError::ERROR_CODES, true) => $v,
            is_string($v) && is_numeric(trim($v)) => (float) trim($v),
            default => ExcelError::VALUE(),
        };
    }

    /**
     * Kuantil dua-sisi di luar tabel Excel: x = I⁻¹(p; df/2, ½) presisi ganda
     * (Newton + biseksi berpengaman), lalu tahap akhir yang sama dengan Excel
     * t = √(df·(1/x − 1)). Tidak dijamin bit-identik — harness paritas
     * melaporkannya sebagai kelas tersendiri.
     */
    public static function tinvCadangan(float $p, float $df): float
    {
        if ($p >= 1.0) {
            return 0.0;
        }

        $a = $df / 2.0;
        $b = 0.5;
        $lnBeta = self::lnBeta($a, $b);
        $lo = 0.0;
        $hi = 1.0;

        // Tebakan awal dari kuantil normal dua-sisi + koreksi Cornish–Fisher orde 1.
        $z = self::kuantilNormal(1.0 - $p / 2.0);
        $t0 = $z + ($z ** 3 + $z) / (4.0 * $df);
        $x = $df / ($df + $t0 * $t0);

        if (! ($x > 0.0 && $x < 1.0)) {
            $x = 0.5;
        }

        for ($i = 0; $i < 200; $i++) {
            $f = self::betaTakLengkap($a, $b, $x, $lnBeta) - $p;

            if ($f === 0.0) {
                break;
            }

            if ($f > 0.0) {
                $hi = $x;
            } else {
                $lo = $x;
            }

            $turunan = exp(($a - 1.0) * log($x) + ($b - 1.0) * log1p(-$x) - $lnBeta);
            $baru = $turunan > 0.0 && is_finite($turunan) ? $x - $f / $turunan : ($lo + $hi) / 2.0;

            if (! ($baru > $lo && $baru < $hi)) {
                $baru = ($lo + $hi) / 2.0;
            }

            if (abs($baru - $x) <= 2.0 * PHP_FLOAT_EPSILON * $x) {
                $x = $baru;
                break;
            }

            $x = $baru;
        }

        return sqrt($df * (1.0 / $x - 1.0));
    }

    /** I_x(a, b) teregularisasi, pecahan berlanjut Lentz (ε 1e-16). */
    private static function betaTakLengkap(float $a, float $b, float $x, float $lnBeta): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }

        if ($x >= 1.0) {
            return 1.0;
        }

        $depan = exp($a * log($x) + $b * log1p(-$x) - $lnBeta);

        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $depan * self::pecahanBeta($a, $b, $x) / $a;
        }

        return 1.0 - $depan * self::pecahanBeta($b, $a, 1.0 - $x) / $b;
    }

    private static function pecahanBeta(float $a, float $b, float $x): float
    {
        $kecil = 1e-300;
        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        $d = abs($d) < $kecil ? $kecil : $d;
        $d = 1.0 / $d;
        $h = $d;

        for ($m = 1; $m <= 5000; $m++) {
            $m2 = 2 * $m;
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            $d = abs($d) < $kecil ? $kecil : $d;
            $c = 1.0 + $aa / $c;
            $c = abs($c) < $kecil ? $kecil : $c;
            $d = 1.0 / $d;
            $h *= $d * $c;
            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            $d = abs($d) < $kecil ? $kecil : $d;
            $c = 1.0 + $aa / $c;
            $c = abs($c) < $kecil ? $kecil : $c;
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;

            if (abs($del - 1.0) < 1e-16) {
                break;
            }
        }

        return $h;
    }

    /**
     * ln B(a, ½) = ln √π − ln R(a), R(a) = Γ(a+½)/Γ(a). Tanpa selisih dua
     * lnΓ besar (pembatalan): a ≤ 100 kelipatan ½ (df bulat → selalu) lewat
     * rekursi R(a+1) = R(a)·(a+½)/a dalam log1p; a > 100 lewat beda Stirling.
     */
    private static function lnBeta(float $a, float $b): float
    {
        if ($b !== 0.5) {
            return self::lnGamma($a) + self::lnGamma($b) - self::lnGamma($a + $b);
        }

        $lnAkarPi = 0.5 * log(M_PI);

        if ($a > 100.0) {
            $lnR = $a * log1p(0.5 / $a) - 0.5 + 0.5 * log($a)
                + 1.0 / (12.0 * ($a + 0.5)) - 1.0 / (12.0 * $a)
                - 1.0 / (360.0 * ($a + 0.5) ** 3) + 1.0 / (360.0 * $a ** 3)
                + 1.0 / (1260.0 * ($a + 0.5) ** 5) - 1.0 / (1260.0 * $a ** 5);

            return $lnAkarPi - $lnR;
        }

        if (fmod(2.0 * $a, 1.0) === 0.0) {
            // R(1) = √π/2, R(½) = 1/√π.
            [$k, $lnR] = fmod($a, 1.0) === 0.0 ? [1.0, $lnAkarPi - M_LN2] : [0.5, -$lnAkarPi];

            for (; $k < $a; $k += 1.0) {
                $lnR += log1p(0.5 / $k);
            }

            return $lnAkarPi - $lnR;
        }

        return self::lnGamma($a) + self::lnGamma($b) - self::lnGamma($a + $b);
    }

    /** Lanczos (g = 7, n = 9). */
    private static function lnGamma(float $x): float
    {
        if ($x < 0.5) {
            return log(M_PI / abs(sin(M_PI * $x))) - self::lnGamma(1.0 - $x);
        }

        $koef = [0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313,
            -176.61502916214059, 12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7];
        $x -= 1.0;
        $s = $koef[0];

        for ($i = 1; $i < 9; $i++) {
            $s += $koef[$i] / ($x + $i);
        }

        $t = $x + 7.5;

        return 0.5 * log(2.0 * M_PI) + ($x + 0.5) * log($t) - $t + log($s);
    }

    /** Kuantil normal baku (Acklam) — hanya tebakan awal Newton. */
    private static function kuantilNormal(float $p): float
    {
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];

        if ($p < 0.02425) {
            $q = sqrt(-2.0 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }

        if ($p > 1.0 - 0.02425) {
            $q = sqrt(-2.0 * log(1.0 - $p));

            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }

        $q = $p - 0.5;
        $r = $q * $q;

        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
    }
}
