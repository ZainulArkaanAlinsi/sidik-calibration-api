<?php

namespace App\Support;

use App\Models\AuditLog;

/**
 * Bandingkan isian ASLI teknisi dengan keadaan lembar sekarang, per sel (K-50-01).
 *
 * Murni fungsi: bahannya (asli, sekarang, baris audit) disuplai pemanggil.
 * "Asli" = `calibration_sessions.input_asli` (dibekukan sekali saat kirim
 * pertama, tidak pernah ditimpa). "Siapa/kapan/alasan" per sel diputar ulang
 * dari `audit_logs` — baris koreksi menyimpan nilai lama DAN baru.
 */
final class BandingkanLembar
{
    /**
     * @param  array<string, mixed>|null  $asli  isi `input_asli`
     * @param  array<string, mixed>  $sekarang  bentuk sama dengan `input_asli`
     * @param  iterable<AuditLog>  $jejak  urut waktu naik
     * @return array{ada_asli: bool, jenis: string, total_sel: int, jumlah_berubah: int, sel: list<array<string, mixed>>}
     */
    public static function susun(?array $asli, array $sekarang, iterable $jejak): array
    {
        $jenis = (string) ($sekarang['jenis'] ?? 'pembacaan');
        $nilaiAsli = $asli === null ? [] : self::ratakan($asli);
        $nilaiSekarang = self::ratakan($sekarang);

        $siapa = [];
        foreach ($jejak as $b) {
            $lama = is_array($b->old_data) ? $b->old_data : [];
            $baru = is_array($b->new_data) ? $b->new_data : [];
            $kunciBagian = array_key_exists('lembar', $baru) ? 'lembar' : (array_key_exists('pembacaan', $baru) ? 'pembacaan' : null);

            if ($kunciBagian === null) {
                continue;
            }

            $jenisBaris = $kunciBagian === 'lembar' ? 'autoclave' : 'pembacaan';
            $sebelum = self::ratakan(['jenis' => $jenisBaris, $kunciBagian => $lama[$kunciBagian] ?? []]);
            $sesudah = self::ratakan(['jenis' => $jenisBaris, $kunciBagian => $baru[$kunciBagian] ?? []]);

            foreach ($sesudah as $kunci => $nilai) {
                if (($sebelum[$kunci] ?? null) !== $nilai) {
                    $siapa[$kunci] = [
                        'diubah_oleh' => $b->pelaku === null ? null : ['id' => (int) $b->pelaku->id, 'nama' => (string) $b->pelaku->name],
                        'diubah_pada' => $b->created_at?->toIso8601String(),
                        'alasan' => $b->note,
                    ];
                }
            }
        }

        $sel = [];
        $berubah = 0;

        foreach (array_unique([...array_keys($nilaiAsli), ...array_keys($nilaiSekarang)]) as $kunci) {
            $a = $nilaiAsli[$kunci] ?? null;
            $s = $nilaiSekarang[$kunci] ?? null;
            $beda = $asli !== null && $a !== $s;
            $berubah += $beda ? 1 : 0;

            $sel[] = [
                'kunci' => $kunci,
                'asli' => $a,
                'sekarang' => $s,
                'berubah' => $beda,
                ...($beda ? ($siapa[$kunci] ?? ['diubah_oleh' => null, 'diubah_pada' => null, 'alasan' => null]) : []),
            ];
        }

        return [
            'ada_asli' => $asli !== null,
            'jenis' => $jenis,
            'total_sel' => count($sel),
            'jumlah_berubah' => $berubah,
            'sel' => $sel,
        ];
    }

    /**
     * Ratakan jadi kunci → nilai skalar.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, int|float|string|bool|null>
     */
    public static function ratakan(array $bentuk): array
    {
        $hasil = [];

        if (($bentuk['jenis'] ?? null) === 'autoclave') {
            self::turun((array) ($bentuk['lembar'] ?? []), 'lembar', $hasil);

            return $hasil;
        }

        foreach ((array) ($bentuk['pembacaan'] ?? []) as $b) {
            $kunci = sprintf(
                'titik_%s.%s.%s.sensor_%s.pembacaan_%s',
                $b['titik_ke'] ?? 0, $b['peran_sensor'] ?? '-', $b['tahap'] ?? '-', $b['sensor_ke'] ?? '-', $b['pembacaan_ke'] ?? 0,
            );
            $hasil[$kunci] = $b['pembacaan'] ?? null;
        }

        return $hasil;
    }

    /** @param  array<string, mixed>  $hasil */
    private static function turun(mixed $nilai, string $awalan, array &$hasil): void
    {
        if (is_array($nilai)) {
            foreach ($nilai as $k => $v) {
                self::turun($v, $awalan.'.'.$k, $hasil);
            }

            return;
        }

        $hasil[$awalan] = is_numeric($nilai) ? (float) $nilai : $nilai;
    }
}
