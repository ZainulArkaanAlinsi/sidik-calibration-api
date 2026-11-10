<?php

namespace App\Support;

/**
 * Format TAMPILAN lembar `SERTIFIKAT` master yang bergantung pada kertas dan
 * tanggal, per kode profil (G0-b, P1/P2, keputusan pemilik 10 Nov 2026: semua
 * keputusan format ikut PERSIS master).
 *
 * Sumber: `_agents_workspace/audit-sertifikat-20261109/master/<workbook>/
 * sertifikat*.json` (`halaman.margin.atas`, sel & `number_format`) dan
 * `_ringkas.md` (rumus sel Issuance Date). Tiap baris:
 *
 *     kode => [margin atas (inci), bahasa tanggal kepala, bahasa tanggal terbit,
 *              tambah hari]
 *
 * - margin atas: master TIDAK mencetak gambar kop, logo KAN, judul `CALIBRATION
 *   CERTIFICATE`, maupun kode dokumen di print area; margin atas yang lebar itu
 *   adalah kertas berkop pracetak. Sertifikat BARU menyisakan ruang kosong
 *   setinggi margin itu (dikurangi margin halaman 0,85 cm) — lihat
 *   `tata_letak.kop_pracetak`.
 * - bahasa kepala: `[$-421]` = `id`, `[$-809]` = `en` pada Received/Calibration
 *   Date master. `en` tercetak `8 May 2025` (format `d mmmm yyyy`), `id`
 *   `08 Mei 2025` (`dd mmmm yyyy`).
 * - bahasa terbit: format sel Issuance Date; bisa beda dari kepala (load cell,
 *   UTM, proving ring: kepala Inggris, terbit Indonesia).
 * - tambah hari: rumus Issuance Date master `=<sel tanggal kalibrasi>+N`.
 *   `null` = master TIDAK memakai rumus +N (diketik atau rumus lain) ATAU
 *   workbook profil itu >1 dan nilainya berbeda: tanggal terbit tetap tanggal
 *   terbit sistem (bentuk lama). Jangan ditebak — lihat laporan G0-b.
 *
 * Terbukti master (penjaga-angka, 10 Nov 2026): thermocouple +1 dan thermometer_glass +1
 * (`INPUT DATA!E68`/`E67` = `P16+1`), gas_detector/jangka_sorong/height_gauge +0, piston_pipette
 * dan dispensett +4 (Fixed Piston `D62` = `Q16+4`). Tetap `null` (nilai bentrok antar workbook atau
 * tak terbukti): flowmeter_totalizer/flowrate, ph_meter, timbangan, conductivity, tids, enclosure
 * (inkubator/oven/furnace/refrigerator), vacuum_gauge — dan TURBIDIMETER, yang DITAHAN
 * (keputusan koordinator): master lama `+1` berlawanan dengan job Jun 2026 `+4` dan bukti kertas
 * lama bertentangan dengan PDF master, jadi tanggal terbit tetap tanggal terbit sistem sampai
 * pemilik memastikan.
 *
 * Issuance Date yang dibekukan = `min(tanggal kalibrasi + N, hari penerbitan)`
 * (`CertificateSnapshotBuilder::tanggalTerbitMaster`): sertifikat tidak pernah mengaku terbit
 * SESUDAH hari ia benar-benar diterbitkan.
 *
 * Profil TANPA baris di sini (bath, buret, buret_digital, picnometer): tidak
 * ada master di folder ekstraksi (P7) — tidak menyatakan apa pun.
 *
 * Pilihan master baku bila >1 workbook (P6): lihat `docs/sertifikat-g0b-format-master.md`.
 */
final class FormatMasterSertifikat
{
    /** Margin halaman PDF (`@page`, cm) yang sudah ada sebelum ruang pracetak. */
    public const MARGIN_HALAMAN_CM = 0.85;

    /**
     * Batas atas ruang kosong (cm). Kop gambar + judul yang dihapus tingginya
     * ±4,5 cm di kertas produksi; ruang yang LEBIH tinggi dari itu membuat
     * sertifikat tumbuh dibanding sebelum G0-b, dan aturan satu halaman membalasnya
     * dengan mengecilkan huruf (terbukti: Viscometer, margin master 2,66 inci =
     * 5,9 cm, masuk daftar `BUTUH_PADAT` di `SertifikatSemuaAlatSatuHalamanTest`).
     * Master yang margin atasnya lebih lebar dari ini (centrifuge, thermohygro,
     * viscometer, load cell, anak timbangan, tachometer, ...) tertulis di laporan
     * sebagai selisih sisa.
     */
    public const RUANG_PRACETAK_MAKS_CM = 4.5;

    /** @var array<string, array{0: float, 1: string, 2: string, 3: int|null}> */
    public const PER_PROFIL = [
        'anak_timbangan' => [2.64, 'id', 'id', 4],
        'autoclave' => [2.53, 'en', 'en', 4],
        'centrifuge' => [2.91, 'en', 'en', 4],
        'chlorine_meter' => [0.94, 'id', 'id', 2],
        'conductivity_meter' => [2.0, 'id', 'id', null],
        'dial_indicator' => [2.46, 'id', 'id', 4],
        'do_meter' => [0.96, 'id', 'id', 1],
        'flowmeter_flowrate' => [1.53, 'en', 'en', null],
        'flowmeter_totalizer' => [1.53, 'en', 'en', null],
        'gas_detector' => [1.65, 'id', 'id', 0],
        'height_gauge' => [2.19, 'id', 'id', 0],
        'hydrometer' => [2.3, 'id', 'id', 0],
        'jangka_sorong' => [1.97, 'id', 'id', 0],
        'load_cell' => [2.75, 'en', 'id', 4],
        'proving_ring' => [1.92, 'en', 'id', 4],
        'utm' => [0.63, 'en', 'id', 4],
        'micrometer' => [2.23, 'id', 'id', 4],
        'ph_meter' => [1.65, 'id', 'id', null],
        'refractometer' => [2.33, 'id', 'id', 4],
        'sieve' => [2.23, 'id', 'id', 4],
        'spectrophotometer' => [2.39, 'id', 'id', 1],
        'tachometer' => [2.61, 'en', 'en', 1],
        'timer_stopwatch' => [1.1, 'id', 'id', 1],
        'thermocouple' => [1.53, 'en', 'en', 1],
        'thermohygro' => [2.88, 'id', 'id', 2],
        'thermometer_glass' => [1.53, 'en', 'en', 1],
        // DITAHAN: tambah_hari null (tanggal terbit sistem) — lihat docblock kelas.
        'turbidimeter' => [1.65, 'id', 'id', null],
        'viscometer' => [2.66, 'id', 'id', 4],
        'inkubator' => [2.34, 'en', 'en', null],
        'oven' => [2.34, 'en', 'en', null],
        'furnace' => [2.34, 'en', 'en', null],
        'refrigerator' => [2.34, 'en', 'en', null],
        'pressure_gauge' => [1.54, 'id', 'id', 4],
        'vacuum_gauge' => [1.54, 'id', 'id', 4],
        'differential_pressure' => [1.54, 'id', 'id', 4],
        'tids' => [1.53, 'en', 'en', null],
        'tits' => [2.28, 'en', 'en', 4],
        'labu_ukur' => [2.1, 'id', 'id', 4],
        'pipet_volume' => [2.1, 'id', 'id', 4],
        'gelas_ukur' => [2.1, 'id', 'id', 4],
        'pipet_ukur' => [2.1, 'id', 'id', 4],
        'piston_pipette' => [2.1, 'id', 'id', 4],
        'dispensett' => [2.1, 'id', 'id', 4],
        'timbangan' => [2.2, 'id', 'id', null],
    ];

    /**
     * Tinggi ruang kosong pengganti kop (cm), atau `null` bila profil tak ada
     * di tabel.
     */
    public static function ruangPracetakCm(string $kode): ?float
    {
        $baris = self::PER_PROFIL[$kode] ?? null;

        return $baris === null ? null : round(min(self::RUANG_PRACETAK_MAKS_CM, max(0.0, $baris[0] * 2.54 - self::MARGIN_HALAMAN_CM)), 2);
    }

    /**
     * @return array{kepala: string, terbit: string, tambah_hari: int|null}|null
     */
    public static function tanggalUntukProfil(string $kode): ?array
    {
        $baris = self::PER_PROFIL[$kode] ?? null;

        return $baris === null ? null : ['kepala' => $baris[1], 'terbit' => $baris[2], 'tambah_hari' => $baris[3]];
    }
}
