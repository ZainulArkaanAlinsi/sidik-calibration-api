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
 * Belum terbukti dari master (tidak ada di tabel): tids, timbangan
 * (baris standarnya mengikuti data sesi, bukan sel tetap), hydrometer
 * (menaut lewat titik), thermohygro (sengaja tak tercetak).
 */
final class StandarCetakMaster
{
    /** Yokogawa/CA 150 Handy Cal + sensor, S/N 23P1005 (LK-285-IDN). */
    public const TERMOMETER_SENSOR = ['Termometer & Sensor Std.', '23P1005'];

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
    ];

    /** @return list<list<string>> */
    public static function untukProfil(string $kode): array
    {
        return self::PER_PROFIL[$kode] ?? [];
    }
}
