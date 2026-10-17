<?php

namespace App\Services\Studio\Workbook;

use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Conditional formatting Excel → gaya `g` sel (Studio tahap C). Server yang
 * menerapkan, saat impor (nilai cache) dan pada hasil `/hitung` (nilai baru);
 * klien cukup menggambar `g` seperti biasa.
 *
 * Aturan disimpan di `tata_letak.cf` lembar (bentuk di `aturan()`), gaya dasar
 * sel di rentang CF (sebelum CF) di `tata_letak.cf_gaya_dasar` — supaya
 * `/hitung` bisa menerapkan ulang dari awal, bukan menumpuk di atas hasil CF
 * impor.
 *
 * Didukung (efek ke `g.isi`, `g.teks`, `g.tebal`, `g.miring`): `cellIs`
 * (equal, notEqual, greaterThan(OrEqual), lessThan(OrEqual), between,
 * notBetween) dengan nilai pembanding literal (angka, "teks", TRUE/FALSE),
 * `containsText`/`notContainsText`/`beginsWith`/`endsWith`,
 * `containsBlanks`/`notContainsBlanks`, `containsErrors`/`notContainsErrors`.
 * Semantik Excel: perbandingan teks tanpa peka huruf; urutan tipe angka < teks
 * < logika; sel kosong = 0 / "" / FALSE mengikuti pembanding; sel galat tidak
 * pernah cocok; aturan ber-prioritas lebih tinggi menang untuk properti yang
 * sama; `stopIfTrue` menghentikan aturan berikutnya.
 *
 * TIDAK didukung (disimpan `didukung: false`, tidak mengubah `g`): iconSet,
 * dataBar, colorScale (tidak ada kunci `g` untuknya — kontrak klien tetap),
 * expression (rumus CF), top10/duplicate/unique/aboveAverage/timePeriod, dan
 * pembanding berupa rujukan sel/rumus.
 */
final class FormatBersyarat
{
    private const DIDUKUNG = [
        Conditional::CONDITION_CELLIS, Conditional::CONDITION_CONTAINSTEXT, Conditional::CONDITION_NOTCONTAINSTEXT,
        Conditional::CONDITION_BEGINSWITH, Conditional::CONDITION_ENDSWITH, Conditional::CONDITION_CONTAINSBLANKS,
        Conditional::CONDITION_NOTCONTAINSBLANKS, Conditional::CONDITION_CONTAINSERRORS, Conditional::CONDITION_NOTCONTAINSERRORS,
    ];

    private function __construct() {}

    /**
     * Aturan CF satu lembar, urut prioritas.
     *
     * @return list<array<string, mixed>>
     */
    public static function aturan(Worksheet $ws): array
    {
        $hasil = [];
        $urut = 0;

        foreach ($ws->getConditionalStylesCollection() as $teksRentang => $daftar) {
            $rentang = self::rentang((string) $teksRentang);

            foreach ($daftar as $c) {
                $urut++;
                $nilai = [];
                $didukung = in_array($c->getConditionType(), self::DIDUKUNG, true) && $rentang !== [];

                if ($c->getConditionType() === Conditional::CONDITION_CELLIS) {
                    foreach ($c->getConditions() as $kondisi) {
                        $literal = self::literal((string) $kondisi);

                        if ($literal === false) {
                            $didukung = false;
                        }

                        $nilai[] = $literal === false ? null : $literal;
                    }

                    if ($nilai === []) {
                        $didukung = false;
                    }
                }

                $hasil[] = [
                    'rentang' => (string) $teksRentang,
                    'batas' => $rentang,
                    'jenis' => $c->getConditionType(),
                    'operator' => $c->getOperatorType(),
                    'nilai' => $nilai,
                    'teks' => $c->getText(),
                    'prioritas' => $c->getPriority() > 0 ? $c->getPriority() : 100000 + $urut,
                    'berhenti' => $c->getStopIfTrue(),
                    'gaya' => self::gaya($c),
                    'didukung' => $didukung,
                ];
            }
        }

        usort($hasil, static fn (array $a, array $b): int => $a['prioritas'] <=> $b['prioritas']);

        return $hasil;
    }

    /**
     * Aturan dari `tata_letak.cf` (tanpa `batas`) → siap `terapkan()`.
     *
     * @param  list<array<string, mixed>>  $cf
     * @return list<array<string, mixed>>
     */
    public static function dariTataLetak(array $cf): array
    {
        return array_values(array_map(static fn (array $a): array => $a + ['batas' => self::rentang((string) ($a['rentang'] ?? ''))], array_filter($cf, 'is_array')));
    }

    /** Sel `$alamat` ada di rentang salah satu aturan? */
    public static function dalamRentang(array $aturan, int $baris, int $kolom): bool
    {
        foreach ($aturan as $a) {
            foreach ($a['batas'] as [$b1, $k1, $b2, $k2]) {
                if ($baris >= $b1 && $baris <= $b2 && $kolom >= $k1 && $kolom <= $k2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gaya akhir sel: gaya dasar + properti dari aturan yang cocok.
     *
     * @param  list<array<string, mixed>>  $aturan
     * @param  array<string, mixed>|null  $gDasar
     * @return array<string, mixed>|null
     */
    public static function terapkan(array $aturan, int $baris, int $kolom, mixed $nilai, ?array $gDasar): ?array
    {
        $tambahan = [];

        foreach ($aturan as $a) {
            if (! $a['didukung'] || ! self::kena($a, $baris, $kolom)) {
                continue;
            }

            if (! self::cocok($a, $nilai)) {
                continue;
            }

            foreach ($a['gaya'] as $k => $v) {
                $tambahan[$k] ??= $v;
            }

            if ($a['berhenti']) {
                break;
            }
        }

        if ($tambahan === []) {
            return $gDasar;
        }

        $g = array_replace($gDasar ?? [], $tambahan);
        ksort($g);

        return $g;
    }

    private static function kena(array $a, int $baris, int $kolom): bool
    {
        foreach ($a['batas'] as [$b1, $k1, $b2, $k2]) {
            if ($baris >= $b1 && $baris <= $b2 && $kolom >= $k1 && $kolom <= $k2) {
                return true;
            }
        }

        return false;
    }

    private static function cocok(array $a, mixed $v): bool
    {
        $galat = is_string($v) && in_array($v, ExcelError::ERROR_CODES, true);
        $kosong = $v === null || $v === '';

        return match ($a['jenis']) {
            Conditional::CONDITION_CONTAINSERRORS => $galat,
            Conditional::CONDITION_NOTCONTAINSERRORS => ! $galat,
            Conditional::CONDITION_CONTAINSBLANKS => ! $galat && ($v === null || (is_string($v) && trim($v) === '')),
            Conditional::CONDITION_NOTCONTAINSBLANKS => ! ($v === null || (is_string($v) && trim($v) === '')),
            Conditional::CONDITION_CONTAINSTEXT => ! $galat && $a['teks'] !== '' && mb_stripos(self::teks($v), $a['teks']) !== false,
            Conditional::CONDITION_NOTCONTAINSTEXT => ! $galat && ($a['teks'] === '' || mb_stripos(self::teks($v), $a['teks']) === false),
            Conditional::CONDITION_BEGINSWITH => ! $galat && $a['teks'] !== '' && mb_stripos(self::teks($v), $a['teks']) === 0,
            Conditional::CONDITION_ENDSWITH => ! $galat && $a['teks'] !== '' && str_ends_with(mb_strtolower(self::teks($v)), mb_strtolower($a['teks'])),
            Conditional::CONDITION_CELLIS => ! $galat && self::cocokCellIs($a['operator'], $a['nilai'], $v, $kosong),
            default => false,
        };
    }

    /** @param  list<mixed>  $pembanding */
    private static function cocokCellIs(string $operator, array $pembanding, mixed $v, bool $kosong): bool
    {
        $c1 = $pembanding[0] ?? null;
        $c2 = $pembanding[1] ?? $c1;
        $banding = static fn (mixed $c): int => self::banding($kosong ? self::kosongSeperti($c) : $v, $c);

        return match ($operator) {
            Conditional::OPERATOR_EQUAL => $banding($c1) === 0,
            Conditional::OPERATOR_NOTEQUAL => $banding($c1) !== 0,
            Conditional::OPERATOR_GREATERTHAN => $banding($c1) > 0,
            Conditional::OPERATOR_GREATERTHANOREQUAL => $banding($c1) >= 0,
            Conditional::OPERATOR_LESSTHAN => $banding($c1) < 0,
            Conditional::OPERATOR_LESSTHANOREQUAL => $banding($c1) <= 0,
            Conditional::OPERATOR_BETWEEN => self::antara($banding, $c1, $c2),
            Conditional::OPERATOR_NOTBETWEEN => ! self::antara($banding, $c1, $c2),
            default => false,
        };
    }

    private static function antara(callable $banding, mixed $c1, mixed $c2): bool
    {
        [$bawah, $atas] = self::banding($c1, $c2) <= 0 ? [$c1, $c2] : [$c2, $c1];

        return $banding($bawah) >= 0 && $banding($atas) <= 0;
    }

    private static function kosongSeperti(mixed $c): mixed
    {
        return match (true) {
            is_string($c) => '',
            is_bool($c) => false,
            default => 0.0,
        };
    }

    /** Urutan Excel: angka < teks < logika; teks tanpa peka huruf. */
    private static function banding(mixed $a, mixed $b): int
    {
        $peringkat = static fn (mixed $x): int => match (true) {
            is_bool($x) => 2,
            is_string($x) => 1,
            default => 0,
        };
        $pa = $peringkat($a);
        $pb = $peringkat($b);

        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return match ($pa) {
            2 => (int) $a <=> (int) $b,
            1 => strcmp(mb_strtolower((string) $a), mb_strtolower((string) $b)) <=> 0,
            default => (float) $a <=> (float) $b,
        };
    }

    private static function teks(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            is_float($v) => (string) $v,
            default => (string) $v,
        };
    }

    /** Pembanding literal CF → nilai; `false` = bukan literal (rujukan/rumus). */
    private static function literal(string $kondisi): mixed
    {
        $k = trim($kondisi);

        if (preg_match('/^"((?:[^"]|"")*)"$/', $k, $m) === 1) {
            return str_replace('""', '"', $m[1]);
        }

        if (is_numeric($k)) {
            return (float) $k;
        }

        if (strcasecmp($k, 'TRUE') === 0) {
            return true;
        }

        if (strcasecmp($k, 'FALSE') === 0) {
            return false;
        }

        return false;
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int}> [b1, k1, b2, k2] */
    private static function rentang(string $teks): array
    {
        $hasil = [];

        foreach (preg_split('/[\s,]+/', trim($teks)) ?: [] as $r) {
            if ($r === '') {
                continue;
            }

            try {
                [[$k1, $b1], [$k2, $b2]] = Coordinate::rangeBoundaries(str_replace('$', '', $r));
                $hasil[] = [(int) $b1, (int) $k1, (int) $b2, (int) $k2];
            } catch (Throwable) {
                continue;
            }
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private static function gaya(Conditional $c): array
    {
        $g = [];
        $st = $c->getStyle();
        // dxf: warna isi pola solid ada di bgColor (→ endColor PhpSpreadsheet);
        // cadangan fgColor (startColor).
        $isi = self::rgb($st->getFill()->getEndColor()->getARGB()) ?? self::rgb($st->getFill()->getStartColor()->getARGB());

        if ($isi !== null) {
            $g['isi'] = $isi;
        }

        $teks = self::rgb($st->getFont()->getColor()->getARGB());

        // Hitam = warna bawaan objek Font PhpSpreadsheet (dxf tulisan PhpSpreadsheet
        // selalu memuatnya); diperlakukan sama dengan gaya dasar: tidak dikirim.
        if ($teks !== null && $teks !== '#000000') {
            $g['teks'] = $teks;
        }

        if ($st->getFont()->getBold() === true) {
            $g['tebal'] = true;
        }

        if ($st->getFont()->getItalic() === true) {
            $g['miring'] = true;
        }

        return $g;
    }

    private static function rgb(?string $argb): ?string
    {
        return $argb === null || strlen($argb) < 6 ? null : '#'.strtoupper(substr($argb, -6));
    }
}
