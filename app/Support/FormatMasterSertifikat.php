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
 * tak terbukti): flowmeter_totalizer/flowrate, ph_meter, timbangan, conductivity — dan TURBIDIMETER, yang DITAHAN
 * (keputusan koordinator): master lama `+1` berlawanan dengan job Jun 2026 `+4` dan bukti kertas
 * lama bertentangan dengan PDF master, jadi tanggal terbit tetap tanggal terbit sistem sampai
 * pemilik memastikan.
 *
 * S-4 (12 Nov 2026): enclosure (bath, inkubator, oven, furnace, refrigerator) dan tids TIDAK lagi
 * `null` di sini — N ditentukan PER VARIAN master oleh profilnya (`tata_letak.tanggal_master.tambah_hari`):
 * Enclosure Constant Yokogawa `INPUT DATA!E168` = `P16+1`, Enclosure Recorder `E100` = `P16+4`;
 * TIDS Recorder (Graptech) `E67` = `P16+4`, TIDS Yokogawa K N `E67` = `P16+2` (`P16` = Calibration
 * Date). Kolom ke-4 di bawah tetap `null` karena satu profil punya dua workbook dengan N berbeda.
 * `vacuum_gauge` +4: master vakum satu-satunya (lembar tersembunyi `Druck (kPa) vacum` di Druck07G)
 * mengikuti SERTIFIKAT Druck07G yang `S11+4`.
 *
 * Issuance Date yang dibekukan = `min(tanggal kalibrasi + N, hari penerbitan)`
 * (`CertificateSnapshotBuilder::tanggalTerbitMaster`): sertifikat tidak pernah mengaku terbit
 * SESUDAH hari ia benar-benar diterbitkan.
 *
 * Profil TANPA baris di sini (buret, buret_digital, picnometer): tidak
 * ada master di folder ekstraksi (P7) — tidak menyatakan apa pun. BATH punya master (S-4: memakai
 * lembar SERTIFIKAT Enclosure Constant Yokogawa / Recorder lewat `EnclosureProfileBase`), jadi ada barisnya.
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
        'bath' => [2.34, 'en', 'en', null],
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
     * Ukuran huruf MEDIAN lembar `SERTIFIKAT` master setelah diskala ke A4 (pt),
     * per kode profil — dasar `tata_letak.font_master_pt` (A3, audit 11 Nov 2026).
     *
     * Cara ukur (sama dengan audit): median ukuran huruf semua karakter bukan-spasi
     * di `<workbook>/sertifikat*.terang.pdf` x (595,3 / lebar halaman PDF master).
     * Hanya profil yang di audit 11 Nov menyimpang dari 0,95-1,05 x master DAN yang
     * ukurannya bisa dicapai tanpa membuat huruf < 6 pt (`SkalaUkuranSertifikat`):
     *
     *     oven               7,44  (enclosure-recorder)
     *     timbangan          6,84  (timbangan-kg; gram 6,72, substitusi 6,96)
     *     tits               8,04  (fungsi-measure; fungsi-source 8,28)
     *     conductivity_meter 7,92  (style 1 = style 2)
     *     pressure_gauge     7,32  (Druck 07G; 13G 7,20)
     *     vacuum_gauge       7,32  (tak ada master vakum sendiri: pembanding terdekat 07G)
     *     timer_stopwatch    7,20
     *     gas_detector       7,9   (B1: Capacity 4 baris mendorong tangga lama ke
     *                              0,90 x; audit 06 `gas-detector-uli-skin-std-rigaz`)
     *     viscometer         8,8   (B1: Capacity + Graduation 2 baris; audit 06
     *                              `5-viscometer-86068360-terbaru`)
     *
     * Thermohygro SENGAJA tidak ada: lembar master dicetak skala 50 % (4,8 pt),
     * menyamakannya berarti huruf < 5 pt yang tak terbaca.
     *
     * @var array<string, float>
     */
    public const FONT_MEDIAN_PT = [
        'oven' => 7.44,
        'timbangan' => 6.84,
        'tits' => 8.04,
        'conductivity_meter' => 7.92,
        'pressure_gauge' => 7.32,
        'vacuum_gauge' => 7.32,
        'timer_stopwatch' => 7.2,
        'gas_detector' => 7.9,
        'viscometer' => 8.8,
    ];

    /** Ukuran huruf median master (pt) untuk profil ini, atau `null` bila tak dinyatakan. */
    public static function fontMasterPt(string $kode): ?float
    {
        return self::FONT_MEDIAN_PT[$kode] ?? null;
    }

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
