<?php

namespace App\Services\Studio\Hitung;

use PhpOffice\PhpSpreadsheet\Calculation\ArrayEnabled;
use PhpOffice\PhpSpreadsheet\Calculation\DateTimeExcel\Helpers as TanggalHelpers;
use PhpOffice\PhpSpreadsheet\Calculation\Exception as GalatKalkulasi;
use PhpOffice\PhpSpreadsheet\Calculation\Functions;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ErrorValue;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Helpers as MatHelpers;
use PhpOffice\PhpSpreadsheet\Calculation\Statistical\Distributions\DistributionValidations;
use PhpOffice\PhpSpreadsheet\Calculation\Statistical\Distributions\Gamma;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Helpers as TeksHelpers;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use Throwable;

/**
 * Pagar argumen SKALAR fungsi lembar (tinjau C-2 #1): batas Excel ditiru di
 * runtime fungsi, bukan hanya di validasi rumus edit — argumen bisa datang dari
 * sel yang disunting dan masuk ke rumus ASLI workbook (`=REPT("-",B3)`, B3 =
 * 1e9). Pola penukaran entri tabel fungsi sama dengan `FungsiExcel`
 * (dipasang/dilepas bersamanya).
 *
 * PhpSpreadsheet 5.10 tidak menegakkan batas ini dan meledak (bukti probe di
 * `_agents_workspace/studio-data-acuan/03-bangun-satu-master-dan-dos.md`):
 * FIXED/DOLLAR(…, 1e9) PHP Fatal memori, REPT membangun string 1 GB sebelum
 * memeriksa panjangnya, FACT/COMBIN/LCM/TDIST mengulang sebanyak argumennya
 * (> 40 s), SUBSTITUTE dan TEXT `@` berlipat kuadrat. Setiap batas di bawah
 * diadu ke Excel 365 lewat COM (angka acuan di `StudioBatasFungsiExcelTest`):
 *
 *  - FIXED/DOLLAR: desimal ≥ 128 → #VALUE!; hasil > 255 karakter → #VALUE!.
 *  - REPT: hasil > 32.767 karakter, atau hitungan ≥ 2³¹ → #VALUE!.
 *  - TEXT: format > 255 karakter atau hasil > 255 karakter → #VALUE!; format
 *    tanggal/waktu untuk serial < 0 atau melewati 31-12-9999 → #VALUE!.
 *  - SUBSTITUTE (semua kemunculan): panjang hasil dihitung DULU, > 32.767 →
 *    #VALUE! tanpa membangun string-nya.
 *  - FACT: n > 170 → #NUM!.
 *  - COMBIN: n ≥ 2³¹−1 → #NUM!; n > 170 dihitung tanpa faktorial (rasio
 *    berurutan, maks 515 langkah) — luapan → #NUM!. n ≤ 170 tetap jalur
 *    PhpSpreadsheet (nilai lama tidak berubah).
 *  - LCM: algoritme Euclid (PhpSpreadsheet memfaktorkan prima sampai √n);
 *    argumen (15 digit bermakna, seperti Excel) atau hasil > 2⁵³ → #NUM!.
 *    GCD: argumen > 2⁵³ → #NUM!.
 *  - DATE/EDATE/EOMONTH/WORKDAY/NETWORKDAYS: tanggal masukan atau hasil di
 *    luar 1900(1904)…9999 → #NUM!.
 *  - TDIST/T.DIST.2T/T.DIST.RT/CHISQ.INV/CHIINV/CHISQ.INV.RT: df > 10⁶ →
 *    #NUM!. Ini batas MESIN, bukan Excel (Excel sampai 10¹⁰): biaya
 *    PhpSpreadsheet tumbuh linear (TDIST, 10⁸ = 3 s) atau √df (fungsi gamma),
 *    dan nilainya di sana sudah menyimpang dari Excel. df ≤ 10⁶ tetap jalur
 *    PhpSpreadsheet (kecuali dua perbaikan paritas di bawah).
 *
 * Dua perbaikan PARITAS (tinjau C-3 #5, diadu ke Excel 365 lewat COM):
 *
 *  - T.DIST.RT(x < 0, df) = 1 − T.DIST.RT(−x, df) — Excel memberi nilai
 *    (T.DIST.RT(−1,5) = 0,818391266175439), PhpSpreadsheet #NUM!. TDIST dan
 *    T.DIST.2T dengan x < 0 tetap #NUM! — Excel juga #NUM!.
 *  - CHIINV / CHISQ.INV.RT: PhpSpreadsheet mencari akar dengan Newton-
 *    Raphson yang kurungnya berbatas atas 100 — akar > 100 (mis. df ≥ 150
 *    pada p = 0,5) memulangkan 100 DIAM-DIAM, dan p dekat 1 / df = 1 meleset
 *    sampai 10¹¹ ULP. Diganti bagi-dua sampai dua double bertetangga atas
 *    gamma tak lengkap PhpSpreadsheet: P(df/2, x/2) = 1 − p untuk p ≥ ½
 *    (1 − p eksak), Q(df/2, x/2) = p untuk p < ½ — tanpa pengurangan yang
 *    menghapus angka penting.
 *
 * Pembungkus teks (FIXED, DOLLAR, REPT, TEXT, SUBSTITUTE) juga memeriksa
 * `PagarMemori` per unsur larik (tinjau C-3 #1) — galat REQUEST 422
 * `hitung_terlalu_berat`, bukan galat sel.
 *
 * Argumen yang tidak valid untuk alasan lain diteruskan ke fungsi asli supaya
 * galatnya (dan urutan galatnya) tetap milik PhpSpreadsheet. Argumen larik
 * diproses per unsur lewat mekanisme larik PhpSpreadsheet yang sama
 * (`ArrayEnabled`) dengan pembungkus ini sebagai pemanggil per unsur. Tanda
 * tangan setiap pembungkus SAMA dengan fungsi aslinya: PhpSpreadsheet mengisi
 * argumen kosong dari nilai bawaan lewat refleksi.
 */
final class BatasFungsiExcel
{
    use ArrayEnabled;

    /** Panjang teks maksimum satu sel Excel. */
    public const MAKS_TEKS = 32767;

    /** Panjang maksimum format & hasil TEXT/FIXED/DOLLAR di Excel. */
    public const MAKS_TEKS_FORMAT = 255;

    public const MAKS_DESIMAL = 127;

    public const MAKS_FAKTORIAL = 170;

    /** COMBIN(2147483647, k) = #NUM! di Excel; 2147483646 masih boleh. */
    public const MAKS_N_COMBIN = 2147483646.0;

    /** REPT("", 2147483648) = #VALUE! di Excel. */
    public const MAKS_HITUNGAN_REPT = 2147483647.0;

    /** 2⁵³ — batas bilangan bulat GCD/LCM Excel. */
    public const MAKS_BULAT = 9007199254740992.0;

    /** Batas mesin derajat bebas TDIST/T.DIST.2T/T.DIST.RT/CHISQ.INV (bukan batas Excel). */
    public const MAKS_DF_MESIN = 1000000.0;

    /** Fungsi Excel → metode pembungkus. */
    public const GANTI = [
        'FIXED' => 'fixed',
        'DOLLAR' => 'dollar',
        'REPT' => 'rept',
        'TEXT' => 'text',
        'SUBSTITUTE' => 'substitute',
        'FACT' => 'fact',
        'COMBIN' => 'combin',
        'LCM' => 'lcm',
        'GCD' => 'gcd',
        'TDIST' => 'tdist',
        'T.DIST.2T' => 'tdist2t',
        'T.DIST.RT' => 'tdistRt',
        'CHISQ.INV' => 'chisqInv',
        'CHIINV' => 'chiInvKanan',
        'CHISQ.INV.RT' => 'chiInvKanan',
        'DATE' => 'date',
        'EDATE' => 'edate',
        'EOMONTH' => 'eomonth',
        'WORKDAY' => 'workday',
        'NETWORKDAYS' => 'networkdays',
    ];

    /** `microtime(true)` batas akhir hitung (dipasang `FungsiExcel::pasang`). */
    private static float $tenggat = INF;

    /** @var array<int, float> kalender → serial 31-12-9999 */
    private static array $serialMaks = [];

    private function __construct() {}

    public static function setelTenggat(float $tenggat): void
    {
        self::$tenggat = $tenggat;
    }

    // ---- teks -------------------------------------------------------------

    public static function fixed(mixed $value, mixed $decimals = 2, mixed $noCommas = false): array|string
    {
        if (is_array($value) || is_array($decimals) || is_array($noCommas)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $value, $decimals, $noCommas);
        }

        PagarMemori::periksa();

        // Desimal di atas batas diganti penanda tak-numerik: fungsi asli tetap
        // memeriksa `value` lebih dulu (FIXED(NA(),128) = #N/A seperti Excel),
        // lalu menolak desimal dengan #VALUE! — tanpa membangun string raksasa.
        if (self::desimalLewatBatas($decimals)) {
            $decimals = '#';
        }

        return self::teksPendek(self::asli('FIXED')($value, $decimals, $noCommas));
    }

    public static function dollar(mixed $value = 0, mixed $decimals = 2): mixed
    {
        if (is_array($value) || is_array($decimals)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $value, $decimals);
        }

        PagarMemori::periksa();

        if (self::desimalLewatBatas($decimals)) {
            $decimals = '#';
        }

        return self::teksPendek(self::asli('DOLLAR')($value, $decimals));
    }

    public static function rept(mixed $stringValue, mixed $repeatCount): array|string
    {
        if (is_array($stringValue) || is_array($repeatCount)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $stringValue, $repeatCount);
        }

        // Per unsur larik (tinjau C-3 #1): REPT(…, 32767+0*ROW(A1:A10000)) tumbuh 32 KB per unsur.
        PagarMemori::periksa();

        // Galat teks dan hitungan tak valid/negatif: jalur asli (tidak membangun apa pun).
        if (is_numeric($repeatCount) && (float) $repeatCount >= 0.0 && ! self::galat($stringValue)) {
            $hitungan = floor((float) $repeatCount);
            $panjang = StringHelper::countCharacters(TeksHelpers::extractString($stringValue));

            if ($hitungan > self::MAKS_HITUNGAN_REPT || $panjang * $hitungan > self::MAKS_TEKS) {
                return ExcelError::VALUE();
            }
        }

        return self::asli('REPT')($stringValue, $repeatCount);
    }

    public static function text(mixed $value, mixed $format): array|string
    {
        if (is_array($value) || is_array($format)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $value, $format);
        }

        PagarMemori::periksa();

        if (! self::galat($value) && ! self::galat($format)) {
            $kode = TeksHelpers::extractString($format);

            if (StringHelper::countCharacters($kode) > self::MAKS_TEKS_FORMAT) {
                return ExcelError::VALUE();
            }

            // Serial di luar jangkauan tanggal Excel dengan format tanggal/waktu.
            if ((is_int($value) || is_float($value))
                && ($value < 0 || $value >= self::serialMaks() + 1.0)
                && Date::isDateTimeFormatCode($kode)) {
                return ExcelError::VALUE();
            }
        }

        $hasil = self::asli('TEXT')($value, $format);

        return is_string($hasil) ? self::teksPendek($hasil) : $hasil;
    }

    public static function substitute(mixed $text = '', mixed $fromText = '', mixed $toText = '', mixed $instance = null): array|string
    {
        if (is_array($text) || is_array($fromText) || is_array($toText) || is_array($instance)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $text, $fromText, $toText, $instance);
        }

        PagarMemori::periksa();

        // Dengan `instance` hanya satu kemunculan yang diganti — hasilnya
        // terbatas dan fungsi asli sudah memeriksa panjangnya.
        if ($instance === null && ! self::galat($text) && ! self::galat($fromText) && ! self::galat($toText)) {
            $teks = TeksHelpers::extractString($text);
            $dari = TeksHelpers::extractString($fromText);
            $ke = TeksHelpers::extractString($toText);

            if ($dari !== '') {
                // `str_replace` mengganti kemunculan tak bertumpuk, kiri ke kanan — sama dengan substr_count.
                $panjang = StringHelper::countCharacters($teks)
                    + substr_count($teks, $dari) * (StringHelper::countCharacters($ke) - StringHelper::countCharacters($dari));

                if ($panjang > self::MAKS_TEKS) {
                    return ExcelError::VALUE();
                }
            }
        }

        return self::asli('SUBSTITUTE')($text, $fromText, $toText, $instance);
    }

    // ---- matematika -------------------------------------------------------

    public static function fact(mixed $factVal): array|string|int|float
    {
        if (is_array($factVal)) {
            return self::evaluateSingleArgumentArray([self::class, __FUNCTION__], $factVal);
        }

        $n = self::angkaMat($factVal);

        if ($n !== null && $n >= 0.0 && floor($n) > self::MAKS_FAKTORIAL) {
            return ExcelError::NAN();
        }

        return self::asli('FACT')($factVal);
    }

    public static function combin(mixed $numObjs, mixed $numInSet): array|string|float
    {
        if (is_array($numObjs) || is_array($numInSet)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $numObjs, $numInSet);
        }

        $n = Functions::flattenSingleValue($numObjs);
        $k = Functions::flattenSingleValue($numInSet);

        // Galat & validasi (k < 0, n < k) tetap jalur asli: dilempar SEBELUM faktorial.
        if (! is_numeric($n) || ! is_numeric($k)) {
            return self::asli('COMBIN')($numObjs, $numInSet);
        }

        $n = (float) $n;
        $k = (float) $k;

        if ($k < 0.0 || $n - $k < 0.0 || floor($n) <= self::MAKS_FAKTORIAL) {
            return self::asli('COMBIN')($numObjs, $numInSet);
        }

        self::periksaTenggat();
        $n = floor($n);

        if ($n > self::MAKS_N_COMBIN) {
            return ExcelError::NAN();
        }

        $k = min(floor($k), $n - floor($k));

        // C(n, k) ≥ C(2k, k) > 1,8·10³⁰⁸ untuk k ≥ 516 (C(1030, 515) sudah meluap di Excel).
        if ($k >= 516.0) {
            return ExcelError::NAN();
        }

        $hasil = 1.0;

        for ($i = 1.0; $i <= $k; $i++) {
            $hasil = (($n - $k + $i) / $i) * $hasil;
        }

        return is_finite($hasil) ? round($hasil) : ExcelError::NAN();
    }

    public static function lcm(mixed ...$args): int|string
    {
        // Validasi sama dengan Lcm::evaluate PhpSpreadsheet 5.10 (urutan galat ikut).
        $nilai = [];
        $adaNol = false;
        $adaIsi = false;

        try {
            foreach (Functions::flattenArray($args) as $v) {
                $adaIsi = $adaIsi || $v !== null;
                $x = MatHelpers::validateNumericNullSubstitution($v, 1);
                MatHelpers::validateNotNegative($x);
                $nilai[] = $x;
                $adaNol = $adaNol || ! ((bool) $x);
            }

            if (! $adaIsi) {
                throw new GalatKalkulasi(ExcelError::VALUE());
            }
        } catch (GalatKalkulasi $e) {
            return $e->getMessage();
        }

        self::periksaTenggat();

        // Excel: argumen > 2⁵³ → #NUM!, bahkan bila ada nol (LCM(0, 1E300)).
        foreach ($nilai as $x) {
            if (self::lewatBulat($x)) {
                return ExcelError::NAN();
            }
        }

        if ($adaNol) {
            return 0;
        }

        $hasil = 1;

        foreach ($nilai as $x) {
            $b = (int) $x;

            if ($hasil === 0 || $b === 0) {
                $hasil = 0;

                continue;
            }

            $sisa = intdiv($hasil, self::fpb($hasil, $b));

            // Hasil kali dua bilangan ≤ 2⁵³ bisa melewati int64: saring dengan float dulu.
            if ((float) $sisa * (float) $b > 2.0 * self::MAKS_BULAT) {
                return ExcelError::NAN();
            }

            $hasil = $sisa * $b;

            if ($hasil > (int) self::MAKS_BULAT) {
                return ExcelError::NAN();
            }
        }

        return $hasil;
    }

    public static function gcd(mixed ...$args): mixed
    {
        // Euclid PhpSpreadsheet cepat; batas Excel diperiksa sesudah validasinya lolos.
        $hasil = self::asli('GCD')(...$args);

        if (is_string($hasil)) {
            return $hasil;
        }

        foreach (Functions::flattenArray($args) as $v) {
            if ($v !== null && is_numeric($v) && self::lewatBulat($v)) {
                return ExcelError::NAN();
            }
        }

        return $hasil;
    }

    // ---- statistik --------------------------------------------------------

    public static function tdist(mixed $value, mixed $degrees, mixed $tails): mixed
    {
        if (is_array($value) || is_array($degrees) || is_array($tails)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $value, $degrees, $tails);
        }

        self::periksaTenggat();

        // Semua numerik → galat asli mana pun juga #NUM!, jadi df di atas batas = #NUM!.
        if (is_numeric($value) && is_numeric($degrees) && is_numeric($tails) && floor((float) $degrees) > self::MAKS_DF_MESIN) {
            return ExcelError::NAN();
        }

        return self::asli('TDIST')($value, $degrees, $tails);
    }

    public static function tdist2t(mixed $value, mixed $degrees): mixed
    {
        if (is_array($value) || is_array($degrees)) {
            return self::evaluateArrayArguments([self::class, 'tdist'], $value, $degrees, 2);
        }

        return self::tdist($value, $degrees, 2);
    }

    public static function tdistRt(mixed $value, mixed $degrees): mixed
    {
        if (is_array($value) || is_array($degrees)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $value, $degrees);
        }

        // Excel: ekor kanan untuk x < 0 = 1 − ekor kanan(−x) (PhpSpreadsheet: #NUM!).
        // Galat df (df < 1, df > batas mesin, bukan angka) tetap dari jalur yang sama.
        $x = Functions::flattenSingleValue($value);

        if ((is_int($x) || is_float($x) || (is_string($x) && is_numeric($x))) && (float) $x < 0.0) {
            $kanan = self::tdist(-(float) $x, $degrees, 1);

            return is_int($kanan) || is_float($kanan) ? 1.0 - $kanan : $kanan;
        }

        return self::tdist($value, $degrees, 1);
    }

    public static function chisqInv(mixed $probability, mixed $degrees): array|string|float
    {
        if (is_array($probability) || is_array($degrees)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $probability, $degrees);
        }

        self::periksaTenggat();

        if (is_numeric($probability) && is_numeric($degrees) && floor((float) $degrees) > self::MAKS_DF_MESIN) {
            return ExcelError::NAN();
        }

        return self::asli('CHISQ.INV')($probability, $degrees);
    }

    /**
     * CHIINV / CHISQ.INV.RT — invers ekor kanan khi-kuadrat (lihat kepala
     * kelas). Validasi & urutan galat sama dengan PhpSpreadsheet
     * (`ChiSquared::inverseRightTail`): bukan angka → #VALUE!, p di luar
     * [0, 1] atau df < 1 → #NUM!. Tambahan sesuai Excel: p = 0 → #NUM!,
     * p = 1 → 0. df > 10⁶ → #NUM! (batas mesin).
     */
    public static function chiInvKanan(mixed $probability, mixed $degrees): array|string|float
    {
        if (is_array($probability) || is_array($degrees)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $probability, $degrees);
        }

        try {
            $p = DistributionValidations::validateProbability($probability);
            $df = DistributionValidations::validateInt($degrees);
        } catch (GalatKalkulasi $e) {
            return $e->getMessage();
        }

        if ($df < 1 || $p <= 0.0 || $df > self::MAKS_DF_MESIN) {
            return ExcelError::NAN();
        }

        return $p >= 1.0 ? 0.0 : self::akarKhiKuadratKanan($p, $df);
    }

    /**
     * x ≥ 0 dengan Q(df/2, x/2) = p, bagi-dua sampai dua double bertetangga.
     * Batas tenggat diperiksa berkala (fungsi gamma df besar ±7 rb suku).
     */
    private static function akarKhiKuadratKanan(float $p, int $df): float|string
    {
        $a = $df / 2.0;
        $bawah = $p >= 0.5;
        $sasaran = $bawah ? 1.0 - $p : $p;
        // x di kanan akar ⇔ P(a, x/2) ≥ 1 − p ⇔ Q(a, x/2) ≤ p.
        $kanan = static fn (float $x): bool => $bawah
            ? Gamma::regularizedGammaP($a, $x / 2.0) >= $sasaran
            : Gamma::regularizedGammaQ($a, $x / 2.0) <= $sasaran;

        $lo = 0.0;
        $hi = max(2.0, (float) $df);

        while (! $kanan($hi)) {
            self::periksaTenggat();
            $lo = $hi;
            $hi *= 2.0;

            if (! is_finite($hi)) {
                return ExcelError::NAN();
            }
        }

        for ($i = 0; $i < 2200; $i++) {
            $tengah = 0.5 * ($lo + $hi);

            if ($tengah <= $lo || $tengah >= $hi) {
                break;
            }

            if (($i & 15) === 0) {
                self::periksaTenggat();
            }

            if ($kanan($tengah)) {
                $hi = $tengah;
            } else {
                $lo = $tengah;
            }
        }

        return $hi;
    }

    // ---- tanggal ----------------------------------------------------------

    public static function date(mixed $year, mixed $month, mixed $day): mixed
    {
        if (is_array($year) || is_array($month) || is_array($day)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $year, $month, $day);
        }

        return self::serialSah(self::asli('DATE')($year, $month, $day));
    }

    public static function edate(mixed $dateValue, mixed $adjustmentMonths): mixed
    {
        if (is_array($dateValue) || is_array($adjustmentMonths)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $dateValue, $adjustmentMonths);
        }

        return self::geserBulan('EDATE', $dateValue, $adjustmentMonths);
    }

    public static function eomonth(mixed $dateValue, mixed $adjustmentMonths): mixed
    {
        if (is_array($dateValue) || is_array($adjustmentMonths)) {
            return self::evaluateArrayArguments([self::class, __FUNCTION__], $dateValue, $adjustmentMonths);
        }

        return self::geserBulan('EOMONTH', $dateValue, $adjustmentMonths);
    }

    public static function workday(mixed $startDate, mixed $endDays, mixed ...$dateArgs): mixed
    {
        if (is_array($startDate) || is_array($endDays)) {
            return self::evaluateArrayArgumentsSubset([self::class, __FUNCTION__], 2, $startDate, $endDays, ...$dateArgs);
        }

        $hasil = self::asli('WORKDAY')($startDate, $endDays, ...$dateArgs);

        if (! is_int($hasil) && ! is_float($hasil)) {
            return $hasil;
        }

        return self::tanggalLewatBatas($startDate) ? ExcelError::NAN() : self::serialSah($hasil);
    }

    public static function networkdays(mixed $startDate, mixed $endDate, mixed ...$dateArgs): mixed
    {
        if (is_array($startDate) || is_array($endDate)) {
            return self::evaluateArrayArgumentsSubset([self::class, __FUNCTION__], 2, $startDate, $endDate, ...$dateArgs);
        }

        $hasil = self::asli('NETWORKDAYS')($startDate, $endDate, ...$dateArgs);

        if (! is_int($hasil) && ! is_float($hasil)) {
            return $hasil;
        }

        return self::tanggalLewatBatas($startDate) || self::tanggalLewatBatas($endDate) ? ExcelError::NAN() : $hasil;
    }

    // ---- bantu ------------------------------------------------------------

    /** Entri asli PhpSpreadsheet (tersimpan saat `FungsiExcel::pasang`). */
    private static function asli(string $nama): callable
    {
        return FungsiExcel::asli($nama);
    }

    private static function periksaTenggat(): void
    {
        if (microtime(true) > self::$tenggat) {
            throw GagalHitung::terlaluLama();
        }
    }

    private static function galat(mixed $v): bool
    {
        return is_string($v) && ErrorValue::isError($v, true) === true;
    }

    /** Desimal FIXED/DOLLAR yang numerik dan ≥ 128 sesudah dipotong (Excel memotong 127,9 → 127). */
    private static function desimalLewatBatas(mixed $decimals): bool
    {
        $d = Functions::flattenSingleValue($decimals);

        return is_numeric($d) && (float) $d >= self::MAKS_DESIMAL + 1.0;
    }

    /** Teks hasil > 255 karakter → #VALUE! (Excel: FIXED, DOLLAR, TEXT). */
    private static function teksPendek(mixed $hasil): mixed
    {
        if (is_string($hasil) && ! self::galat($hasil) && StringHelper::countCharacters($hasil) > self::MAKS_TEKS_FORMAT) {
            return ExcelError::VALUE();
        }

        return $hasil;
    }

    /** Nilai MathTrig (`validateNumericNullBool`): null 0, bool 0/1, numerik; selain itu null (jalur asli). */
    private static function angkaMat(mixed $v): ?float
    {
        $v = Functions::flattenSingleValue($v);

        return match (true) {
            $v === null => 0.0,
            is_bool($v) => (float) $v,
            is_numeric($v) => (float) $v,
            default => null,
        };
    }

    /**
     * Argumen GCD/LCM di atas 2⁵³. Excel membacanya dengan 15 digit bermakna
     * (GCD(9007199254740994) lolos, GCD(9007199254741000) #NUM! — COM), jadi
     * batasnya diadu pada nilai yang dibulatkan ke 15 digit.
     */
    private static function lewatBulat(mixed $x): bool
    {
        return (float) sprintf('%.14e', (float) $x) > self::MAKS_BULAT;
    }

    private static function fpb(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    /** Serial tanggal 31-12-9999 untuk kalender yang sedang dipakai (1900: 2958465). */
    private static function serialMaks(): float
    {
        $kalender = Date::getExcelCalendar();

        return self::$serialMaks[$kalender] ??= (float) Date::formattedPHPToExcel(9999, 12, 31);
    }

    /** Hasil tanggal numerik di luar 0..31-12-9999 → #NUM!. */
    private static function serialSah(mixed $hasil): mixed
    {
        if ((is_int($hasil) || is_float($hasil)) && ($hasil < 0 || $hasil > self::serialMaks())) {
            return ExcelError::NAN();
        }

        return $hasil;
    }

    /** Tanggal masukan yang lolos validasi asli tetapi melewati 31-12-9999. */
    private static function tanggalLewatBatas(mixed $tanggal): bool
    {
        try {
            return floor(TanggalHelpers::getDateValue($tanggal)) > self::serialMaks();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * EDATE/EOMONTH: hasil fungsi asli, lalu batas Excel — tanggal masukan
     * melewati 9999, bulan tujuan sesudah Desember 9999, bulan tujuan sebelum
     * Januari tahun dasar kalender (Excel EDATE(31,-1) = #NUM!; PhpSpreadsheet
     * 1), atau hasil di luar jangkauan. Batas bawah tidak berlaku untuk serial
     * 0 (keanehan Excel "0 Januari 1900", di luar pagar ini).
     */
    private static function geserBulan(string $nama, mixed $tanggal, mixed $bulan): mixed
    {
        $hasil = self::asli($nama)($tanggal, $bulan);

        if (! is_int($hasil) && ! is_float($hasil)) {
            return $hasil;
        }

        try {
            $awal = floor(TanggalHelpers::getDateValue($tanggal, false));
            $geser = floor(TanggalHelpers::validateNumericNull($bulan));
        } catch (Throwable) {
            return $hasil;
        }

        if ($awal > self::serialMaks()) {
            return ExcelError::NAN();
        }

        // Bulan tujuan dihitung sendiri: PhpSpreadsheet melipat tahun raksasa
        // kembali ke jangkauan (EDATE(1, 1E9) = 2349727, Excel #NUM!).
        $d = Date::excelToDateTimeObject($awal);
        $tujuan = (float) $d->format('Y') * 12.0 + (float) $d->format('n') - 1.0 + $geser;

        if ($tujuan > 9999.0 * 12.0 + 11.0 || ($awal >= 1.0 && $tujuan < Date::getExcelCalendar() * 12.0)) {
            return ExcelError::NAN();
        }

        return self::serialSah($hasil);
    }
}
