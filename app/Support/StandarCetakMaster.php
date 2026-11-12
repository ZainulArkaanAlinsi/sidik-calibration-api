<?php

namespace App\Support;

/**
 * Baris tabel Standard Used lembar `SERTIFIKAT` master yang SELALU tercetak,
 * walau teknisi tidak mencentangnya di Usage Check, per kode profil. G1, R2,
 * K-50-15 (sertifikat persis master).
 *
 * Master mengisi baris ini dari sel tetap (`DATABASE!V16/V21/V22`,
 * `'INPUT DATA'!X22/Z23/AA21/AA26`), bukan dari pilihan per sesi. App dulu
 * mencetak hanya standar yang tertaut ke titik / dicentang, jadi baris
 * "Termometer & Sensor Std." (Yokogawa/CA 150 Handy Cal, 23P1005,
 * LK-285-IDN) hilang di 14+ profil. Cuma labu ukur & pipet volume Rev.7
 * punya jalurnya sendiri (`FixedVolumetricGlasswareRev7Profile::
 * standarTambahanSesi`) — mereka TIDAK ada di tabel ini.
 *
 * Tiap entri = daftar pencocok baris master `standards` (NAMA dulu, serial
 * belakangan — `CalibrationProfile::cocokkanStandar`). Baris yang tidak ada di
 * master standar lab dilewati; sertifikatnya tidak gagal terbit.
 *
 * Bukti per profil (sel `SERTIFIKAT` master → sumber baris termometer):
 *
 * - chlorine_meter: `B33` = `'INPUT DATA'!X22`
 * - conductivity_meter: `B52` = `'INPUT DATA'!$Z$23` (STYLE 1)
 * - ph_meter: `B36` = `'INPUT DATA'!X22` (IMTE WQ-129 dan "for trial")
 * - turbidimeter: `B36` = `'INPUT DATA'!AA26`
 * - viscometer: `B35` = `'INPUT DATA'!AA21` (master terbaru)
 * - do_meter: `B34` = `'INPUT DATA'!X22`
 * - refractometer: `B32` = `'INPUT DATA'!AA20`
 * - buret, gelas_ukur, pipet_ukur, buret_digital*: Graduated `B34` =
 *   `DATABASE!V21` (*Graduated Piston Volume `B32` = `DATABASE!V16`)
 * - dispensett, piston_pipette: Fixed Piston Volume `B30` = `DATABASE!V16`
 * - picnometer: tanpa workbook sendiri; mengikuti Fixed (`DATABASE!V22`)
 *
 * - hydrometer (B1 / R2): `SERTIFIKAT!B29:B31` = `DATABASE!V13` (neraca, menaut
 *   lewat titik), `V14` Termometer & Sensor Std. dan `V16` Caliper — dua
 *   baris terakhir tetap, urutan master
 *
 * Baris SENSOR acuan (B1 / R2) tidak tetap: master memilihnya per sesi lewat
 * `'INPUT DATA'!P24` (`VLOOKUP … Standar_Sensor`), di app = kolom sesi
 * `tipe_sensor`. Tabel [SENSOR_PER_TIPE] + hook
 * `CalibrationProfile::tipeSensorStandarTercetak()` (oven/inkubator = enclosure
 * `SERTIFIKAT!B54`, tids `B42`, thermocouple `B42`, thermometer glass `B43`).
 *
 * Belum terbukti dari master (tidak ada di tabel): timbangan (baris standarnya
 * mengikuti data sesi, bukan sel tetap), thermohygro (sengaja tak tercetak).
 */
final class StandarCetakMaster
{
    /** Yokogawa/CA 150 Handy Cal + sensor, S/N 23P1005 (LK-285-IDN). */
    public const TERMOMETER_SENSOR = ['Termometer & Sensor Std.', '23P1005'];

    /** Digital Caliper Tesa Cal-IP67, S/N LPI-0368 (hydrometer `DATABASE!V16`). */
    public const CALIPER = ['Digital Caliper', 'Cal-IP67', 'LPI-0368'];

    /**
     * Sensor acuan menurut tipe sesi (`tipe_sensor`): `Thermocouple Type K`
     * (`-/Type K`, TC-01,02, LK-064-IDN), `Thermocouple Type N` (TCN-06,11),
     * `PRT PT100` (SH1/20). Pencocok NAMA dulu, serial belakangan.
     *
     * @var array<string, list<string>>
     */
    public const SENSOR_PER_TIPE = [
        'Type K' => ['Thermocouple Type K', 'TC-01,02'],
        'Type N' => ['Thermocouple Type N', 'TCN-06,11'],
        'RTD' => ['PRT Pt-100', 'PRT PT100', 'SH1/20'],
    ];

    /** @var array<string, list<list<string>>> */
    public const PER_PROFIL = [
        'chlorine_meter' => [self::TERMOMETER_SENSOR],
        'conductivity_meter' => [self::TERMOMETER_SENSOR],
        'ph_meter' => [self::TERMOMETER_SENSOR],
        'turbidimeter' => [self::TERMOMETER_SENSOR],
        'viscometer' => [self::TERMOMETER_SENSOR],
        'do_meter' => [self::TERMOMETER_SENSOR],
        'refractometer' => [self::TERMOMETER_SENSOR],
        'buret' => [self::TERMOMETER_SENSOR],
        'gelas_ukur' => [self::TERMOMETER_SENSOR],
        'pipet_ukur' => [self::TERMOMETER_SENSOR],
        'picnometer' => [self::TERMOMETER_SENSOR],
        'buret_digital' => [self::TERMOMETER_SENSOR],
        'dispensett' => [self::TERMOMETER_SENSOR],
        'piston_pipette' => [self::TERMOMETER_SENSOR],
        'hydrometer' => [self::TERMOMETER_SENSOR, self::CALIPER],
    ];

    /**
     * Pencocok baris sensor acuan untuk satu tipe sensor sesi (`Type K`,
     * `Type N`, `RTD`); `null` bila tipe tidak dikenal (baris tidak dicetak,
     * tidak ditebak).
     *
     * @return list<string>|null
     */
    public static function sensorUntukTipe(?string $tipe): ?array
    {
        return $tipe === null ? null : (self::SENSOR_PER_TIPE[$tipe] ?? null);
    }

    /** @return list<list<string>> */
    public static function untukProfil(string $kode): array
    {
        return self::PER_PROFIL[$kode] ?? [];
    }
}
