<?php

namespace App\Support;

/**
 * Bentuk "End of Certificate" lembar `SERTIFIKAT` master yang berupa GRUP
 * garis-teks-garis (objek `grpSp` "Group 10" di DALAM print area — bukan sel
 * teks), per kode profil. G0-a, R1, K-50-15 (sertifikat persis master).
 *
 * Sumber: `_agents_workspace/audit-sertifikat-20261109/g0a/klasifikasi.md`
 * (dari `<lembar>.objek.json` 51 lembar master). Isi tiap baris =
 * `[teks, tebal, miring, ukuran pt, lebar]`:
 *
 * - teks: run \"End of Certificate\" kotak teks grup (persis, tanpa strip);
 * - tebal / miring / ukuran: `b` / `i` / `sz` run itu (hydrometer, centrifuge,
 *   tachometer: miring tanpa tebal; conductivity, viscometer, timer: 7 pt;
 *   spectrophotometer: 10 pt polos; selebihnya 8 pt tebal-miring);
 * - lebar: lebar grup / lebar print area (perkiraan dari `lebar_kolom`),
 *   rata-rata bila profil punya beberapa master, 2 desimal. Garis kiri &
 *   kanan masing-masing ±21 % grup, kotak teks ±58 % (diukur di 51 grup).
 *
 * Dibekukan ke `snapshot.tata_letak.akhir_sertifikat_garis` waktu terbit
 * (`CalibrationProfile::tataLetakSertifikat`) — sertifikat terbit tidak
 * berubah. Profil TANPA baris di sini tidak mencetak bentuk ini: sel teks
 * (13 profil, `tata_letak.akhir_sertifikat`). Tiap dari 48 profil punya tepat
 * satu bentuk. `differential_pressure`: master `Differential` `J56:S57`
 * (lebar 0,31); `tids`: dua master — Recorder Graptech `H58:N60` dan Yokogawa
 * K N `H57:N59` (lebar 0,28 rata-rata; keduanya Arial 8 tebal-miring, font di
 * PDF master `Arial-BoldItalicMT`).
 */
final class AkhirSertifikatMaster
{
    /** @var array<string, array{0: string, 1: bool, 2: bool, 3: int, 4: float}> */
    public const PER_PROFIL = [
        'centrifuge' => ['End of Certificate', false, true, 8, 0.33],
        'chlorine_meter' => ['End of Certificate', true, true, 8, 0.32],
        'conductivity_meter' => ['End of Certificate', true, true, 7, 0.35],
        'dial_indicator' => ['End of Certificate', true, true, 8, 0.25],
        'do_meter' => ['End of Certificate', true, true, 8, 0.34],
        'flowmeter_flowrate' => ['End of Certificate', true, true, 8, 0.29],
        'flowmeter_totalizer' => ['End of Certificate', true, true, 8, 0.31],
        'gas_detector' => ['End of Certificate', true, true, 8, 0.34],
        'height_gauge' => ['End of Certificate', true, true, 8, 0.28],
        'hydrometer' => ['End of Certificate', false, true, 8, 0.29],
        'jangka_sorong' => ['End of Certificate', true, true, 8, 0.28],
        'load_cell' => ['End of Certificate', true, true, 8, 0.30],
        'proving_ring' => ['End of Certificate', true, true, 8, 0.28],
        'utm' => ['End of Certificate', true, true, 8, 0.30],
        'micrometer' => ['End of Certificate', true, true, 8, 0.27],
        'ph_meter' => ['End of Certificate', true, true, 8, 0.32],
        'refractometer' => ['End of Certificate', true, true, 8, 0.32],
        'sieve' => ['End of Certificate', true, true, 8, 0.27],
        'spectrophotometer' => ['End of Certificate', false, false, 10, 0.31],
        'tachometer' => ['End of Certificate', false, true, 8, 0.33],
        'timer_stopwatch' => ['End of Certificate', true, true, 7, 0.28],
        'thermocouple' => ['End of Certificate', true, true, 8, 0.29],
        'thermohygro' => ['End of Certificate', true, true, 8, 0.23],
        'thermometer_glass' => ['End of Certificate', true, true, 8, 0.30],
        'turbidimeter' => ['End of Certificate', true, true, 8, 0.34],
        'viscometer' => ['End of Certificate', true, true, 7, 0.31],
        'inkubator' => ['End of Certificate', true, true, 8, 0.29],
        'oven' => ['End of Certificate', true, true, 8, 0.29],
        'furnace' => ['End of Certificate', true, true, 8, 0.29],
        'refrigerator' => ['End of Certificate', true, true, 8, 0.29],
        'bath' => ['End of Certificate', true, true, 8, 0.29],
        'pressure_gauge' => ['End of Certificate', true, true, 8, 0.33],
        'vacuum_gauge' => ['End of Certificate', true, true, 8, 0.33],
        'differential_pressure' => ['End of Certificate', true, true, 8, 0.31],
        'tids' => ['End of Certificate', true, true, 8, 0.28],
    ];

    /**
     * @return array{teks: string, tebal: bool, miring: bool, ukuran: int, lebar: float}|null
     */
    public static function untukProfil(string $kode): ?array
    {
        $baris = self::PER_PROFIL[$kode] ?? null;

        if ($baris === null) {
            return null;
        }

        return ['teks' => $baris[0], 'tebal' => $baris[1], 'miring' => $baris[2], 'ukuran' => $baris[3], 'lebar' => $baris[4]];
    }
}
