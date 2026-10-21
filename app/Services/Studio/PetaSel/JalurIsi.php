<?php

namespace App\Services\Studio\PetaSel;

use RuntimeException;

/**
 * Jalur ke daun isi data acuan: `standar.nama`, `cmc[kode=A].u95_um`,
 * `cmc[kode=B].titik[3].tumpukan_mm`.
 *
 * Menulis hanya ke struktur yang SUDAH ada di dasar untuk list (elemen
 * `[kode=X]`/`[N]` wajib ada — peta tidak boleh menambah pita atau titik
 * diam-diam); kunci objek boleh lahir.
 */
final class JalurIsi
{
    /**
     * @return list<array{kunci: string, kode: ?string, indeks: ?int}>|null
     */
    public static function urai(string $jalur): ?array
    {
        $segmen = [];

        foreach (explode('.', $jalur) as $s) {
            if (preg_match('/^([a-z0-9_]+)(?:\[(?:kode=([A-Za-z0-9_\-]+)|(\d+))\])?$/', $s, $m) !== 1) {
                return null;
            }

            $segmen[] = [
                'kunci' => $m[1],
                'kode' => isset($m[2]) && $m[2] !== '' ? $m[2] : null,
                'indeks' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
            ];
        }

        return $segmen;
    }

    /**
     * @param  array<array-key, mixed>  $isi
     *
     * @throws RuntimeException jalur tidak ada di dasar
     */
    public static function ambil(array $isi, string $jalur): mixed
    {
        $ref = $isi;

        foreach (self::uraiPasti($jalur) as $s) {
            $ref = self::langkah($ref, $s, $jalur);
        }

        return $ref;
    }

    /**
     * @param  array<array-key, mixed>  $isi
     *
     * @throws RuntimeException jalur list tidak ada di dasar
     */
    public static function tulis(array &$isi, string $jalur, mixed $nilai): void
    {
        $segmen = self::uraiPasti($jalur);
        $ref = &$isi;
        $akhir = count($segmen) - 1;

        foreach ($segmen as $n => $s) {
            if (! is_array($ref)) {
                throw new RuntimeException("jalur '{$jalur}': '{$s['kunci']}' bukan objek di dasar");
            }

            $daun = $n === $akhir && $s['kode'] === null && $s['indeks'] === null;

            if ($daun) {
                $ref[$s['kunci']] = $nilai;

                return;
            }

            if (! array_key_exists($s['kunci'], $ref)) {
                if ($s['kode'] !== null || $s['indeks'] !== null) {
                    throw new RuntimeException("jalur '{$jalur}': list '{$s['kunci']}' tidak ada di dasar");
                }

                $ref[$s['kunci']] = [];
            }

            $ref = &$ref[$s['kunci']];

            if ($s['kode'] !== null || $s['indeks'] !== null) {
                $i = self::indeks($ref, $s, $jalur);

                if ($n === $akhir) {
                    $ref[$i] = $nilai;

                    return;
                }

                $ref = &$ref[$i];
            }
        }
    }

    /** @return list<array{kunci: string, kode: ?string, indeks: ?int}> */
    private static function uraiPasti(string $jalur): array
    {
        return self::urai($jalur) ?? throw new RuntimeException("jalur '{$jalur}' tidak sah");
    }

    /** @param  array{kunci: string, kode: ?string, indeks: ?int}  $s */
    private static function langkah(mixed $ref, array $s, string $jalur): mixed
    {
        if (! is_array($ref) || ! array_key_exists($s['kunci'], $ref)) {
            throw new RuntimeException("jalur '{$jalur}': '{$s['kunci']}' tidak ada di dasar");
        }

        $ref = $ref[$s['kunci']];

        if ($s['kode'] === null && $s['indeks'] === null) {
            return $ref;
        }

        return $ref[self::indeks($ref, $s, $jalur)];
    }

    /** @param  array{kunci: string, kode: ?string, indeks: ?int}  $s */
    private static function indeks(mixed $list, array $s, string $jalur): int
    {
        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException("jalur '{$jalur}': '{$s['kunci']}' bukan list di dasar");
        }

        if ($s['indeks'] !== null) {
            if (! array_key_exists($s['indeks'], $list)) {
                throw new RuntimeException("jalur '{$jalur}': {$s['kunci']}[{$s['indeks']}] tidak ada di dasar");
            }

            return $s['indeks'];
        }

        foreach ($list as $i => $el) {
            if (is_array($el) && (string) ($el['kode'] ?? '') === $s['kode']) {
                return $i;
            }
        }

        throw new RuntimeException("jalur '{$jalur}': {$s['kunci']} dengan kode {$s['kode']} tidak ada di dasar");
    }
}
