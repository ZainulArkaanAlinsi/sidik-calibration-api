<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Adu SETIAP sel `database/data/tabel-kalibrator-enclosure.json` ke CSV master
 * — bukan sampel, seluruh 2.672 nilai daun yang ada di berkasnya.
 *
 * Sumber (dua berkas saja, lihat alasan di §1):
 *
 *   * `.../Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa/STANDAR KALIBRATOR.csv`
 *     — `meter.constant`, `meter.yokogawa`, `sensor.yoko` (koreksi/U95%/drift).
 *   * `.../Master_Olah_Data_Suhu_Enclosure_Recorder/Standar_Kalibrator.csv`
 *     — `meter.recorder`, `sensor.recorder` (koreksi/U95%/drift).
 *
 * Rekonstruksi lengkapnya (baris/kolom persis sama dengan test ini) ada di
 * `docs/skrip/gen-tabel-kalibrator-enclosure.py` — generator itu dijalankan
 * lebih dulu untuk MEMBUKTIKAN pemetaan baris/kolomnya benar (dibandingkan ke
 * `tabel-kalibrator-enclosure.json` sel demi sel sebelum test ini ditulis),
 * baru dituliskan ulang di PHP di sini supaya test tidak bergantung pada
 * python di CI.
 *
 * ## §1. Kenapa BUKAN `STANDAR-CONSTANT.csv` / `STANDAR-YOKOGAWA.csv` /
 * `TERMOCOUPLE TYPE K.csv` / `TERMOCOUPLE TYPE N.csv`
 *
 * Nilainya sama, tapi `TERMOCOUPLE TYPE K.csv` dan `TERMOCOUPLE TYPE N.csv`
 * (DI KEDUA direktori) kolom "Titik Kalibrasi (oC)"-nya SALAH BARIS relatif
 * kolom Correction pasangannya — baris oC `-20` di situ menyimpan koreksi
 * yang sebetulnya milik oC `0`, dst. Dibuktikan: `STANDAR KALIBRATOR.csv`
 * baris oC=25 (blok "TABEL KOREKSI SENSOR") memberi TCK-01..16 = -0,175 —
 * PERSIS di tengah antara -0,27 (oC=0) dan -0,08 (oC=50) — sementara angka
 * -0,175 itu TIDAK PERNAH muncul sama sekali di `TERMOCOUPLE TYPE K.csv`.
 * `STANDAR KALIBRATOR.csv` / `Standar_Kalibrator.csv` sudah memuat semua
 * (kalibrator + sensor + drift) dalam satu tabel yang kolom oC-nya lurus,
 * jadi itu yang dipakai, bukan pecahan sheetnya.
 *
 * ## §2. Pembulatan: 10 ANGKA PENTING, bukan 10 desimal
 *
 * Dibuktikan lewat pencocokan sel demi sel (bukan tebakan): `-0,15002501250627304`
 * (meter.constant Type N @300) mendarat di JSON sebagai `-0,1500250125` (cocok
 * 10 desimal MAUPUN 10 angka penting — kebetulan sama karena angka pertamanya
 * bukan nol), tapi `-0,09995002498752886` (meter.constant Type N @500) mendarat
 * sebagai `-0,09995002499` — itu 10 ANGKA PENTING (nol di depan tidak dihitung),
 * BUKAN `round(x, 10)` (yang akan memberi `-0,099950025`, beda di digit ke-11).
 * `sprintf('%.10G', $x)` di PHP menghasilkan pembulatan yang sama.
 *
 * ## §3. Noise titik-mengambang (~1e-15 s.d. 1e-18) dibekukan ke NOL
 *
 * Sel seperti `2,8449465006019636e-15` (meter.constant Type K @50) atau
 * `-6,938893903907228e-18` (meter.recorder Type K CH15 @0) adalah residu
 * `A-A` Excel yang mestinya nol pas. Ambang `1e-9` dipakai karena nilai ASLI
 * terkecil yang pernah terlihat di tabel-tabel ini yang BUKAN noise (~1e-5,
 * sensor PRT PT100 per-titik) masih 10.000× lebih besar dari ambang ini — dan
 * PRT PT100 per-titik toh tidak ikut ke JSON (§4).
 *
 * ## §4. Yang TIDAK bisa ditelusur / SENGAJA tidak diuji
 *
 * - **Sensor PRT PT100 per-titik** (kolom "PRT PT100" di "TABEL KOREKSI/U95%
 *   SENSOR"). Skema JSON `sensor.{yoko,recorder}.{koreksi,u95}` cuma memuat
 *   `Type K`/`Type N` — dibuktikan dari struktur berkas yang ada (tidak punya
 *   kunci `sensor.yoko.koreksi.PT100`). Hanya *drift* PT100 (satu angka, panel
 *   "Sensor TC") yang ikut, dan itu DIUJI di bawah. Kolom PT100 per-titik
 *   sendiri juga punya dua sel `#REF!` (oC 300, 400) di master.
 * - **`Type J`** di `meter.constant`/`meter.yokogawa` — kolomnya ada di CSV
 *   tapi seluruh selnya kosong di kedua kalibrator, jadi tidak pernah masuk
 *   skema JSON (tidak ada `meter.constant.koreksi.{"Type J"}`).
 *
 * ## §5. TEMUAN — master punya titik yang JSON tidak punya (dilaporkan, TIDAK
 * diperbaiki diam-diam di sini; lihat `test_titik_minus_20_recorder_...`)
 *
 * Enam puluh sel: master (`Standar_Kalibrator.csv`) mengisi baris oC=-20 di
 * "TABEL NILAI U95% TEMPERATURE RECORDER" (Type N=0,83, Type K=0,67 — kedua
 * jenis, seluruh 20 kanal = 40 sel) DAN di "TABEL NILAI KOREKSI/U95% SENSOR
 * TERMOKOPEL" untuk Type N (koreksi=-0,15, u95=0,76 — 10 kanal TCN3-12 × 2
 * tabel = 20 sel), tapi `meter.recorder.u95` dan `sensor.recorder.{koreksi,u95}`
 * di JSON TIDAK punya kunci `-20` sama sekali untuk sel-sel itu. Bukan beda
 * nilai — kuncinya memang tidak ada. §5 test di bawah mengunci temuan ini
 * supaya tidak diam-diam berubah tanpa disadari.
 */
class TabelKalibratorEnclosureCocokMasterTest extends TestCase
{
    private const AMBANG_NOISE = 1e-9;

    private const TOL = 1e-9;

    private static ?array $json = null;

    private static ?array $csvCy = null;

    private static ?array $csvRec = null;

    private static ?array $master = null;

    private static function jsonData(): array
    {
        return self::$json ??= json_decode(
            (string) file_get_contents(database_path('data/tabel-kalibrator-enclosure.json')),
            true,
        );
    }

    /**
     * Dua CSV master dibaca dari `tests/Fixtures/`, BUKAN dari
     * `Project-PT-Sidik/alat-alat-Pt-Sidik/`.
     *
     * Direktori master itu ditahan `.gitignore:93` (`alat-alat-Pt-Sidik/*`),
     * jadi hasil clone bersih tidak punya satu pun berkasnya — test yang
     * membacanya langsung bakal hijau di mesin ini dan MERAH di CI, dan job
     * deploy Render `needs: phpunit`. Salinannya di sini byte-per-byte sama
     * dengan masternya; nol data pelanggan, dan identitas kalibratornya
     * (S/N 99875850, 23P1005, C305B1470, LK-202-IDN) sudah lebih dulu
     * ter-track di belasan berkas lain.
     *
     * Kalau masternya direvisi, salin ULANG ke sini — jangan sunting
     * salinannya, nanti fixture-nya menyimpang diam-diam dari sumbernya.
     */
    private static function dirCy(): string
    {
        return base_path(
            'tests/Fixtures/enclosure-kalibrator-master/Constant_Yokogawa',
        );
    }

    private static function dirRec(): string
    {
        return base_path(
            'tests/Fixtures/enclosure-kalibrator-master/Recorder',
        );
    }

    /** @return list<list<string>> */
    private static function bacaCsv(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Tidak bisa membuka {$path}");
        }
        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        // BOM UTF-8 nemplok di sel pertama baris pertama.
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', (string) $rows[0][0]) ?? $rows[0][0];
        }

        return $rows;
    }

    private static function sel(array $row, int $col): string
    {
        return isset($row[$col]) ? trim((string) $row[$col]) : '';
    }

    /** §2 + §3: 10 angka penting, lalu bekukan noise ke nol. */
    private static function bulat(float $x): float
    {
        if (abs($x) < self::AMBANG_NOISE) {
            return 0.0;
        }

        return (float) sprintf('%.10G', $x);
    }

    private static function nilai(array $row, int $col): ?float
    {
        $v = self::sel($row, $col);
        if ($v === '' || str_contains($v, '#REF') || str_contains($v, '#')) {
            return null;
        }

        return self::bulat((float) $v);
    }

    private static function kunciSuhu(string $s): int|string
    {
        $f = (float) $s;

        return ((float) (int) $f === $f) ? (int) $f : $s;
    }

    /**
     * {label: {suhu: nilai}} — label yang seluruh selnya kosong di rentang
     * baris ini dibuang (bukan array kosong), sama seperti generator python.
     *
     * @param  array<int, int>  $barisRange
     * @param  array<string, int>  $labelKeKolom
     * @return array<string, array<int|string, float>>
     */
    private static function parseGrid(array $rows, array $barisRange, int $kolSuhu, array $labelKeKolom): array
    {
        $out = [];
        foreach (array_keys($labelKeKolom) as $label) {
            $out[$label] = [];
        }
        foreach ($barisRange as $r) {
            $row = $rows[$r];
            $suhuTeks = self::sel($row, $kolSuhu);
            if ($suhuTeks === '') {
                continue;
            }
            $suhu = self::kunciSuhu($suhuTeks);
            foreach ($labelKeKolom as $label => $kol) {
                $v = self::nilai($row, $kol);
                if ($v === null) {
                    continue;
                }
                $out[$label][$suhu] = $v;
            }
        }

        return array_filter($out, static fn (array $isi): bool => $isi !== []);
    }

    /**
     * Panel drift satu-kolom: baris berlabel + nilai, dinormalkan ke
     * {'PT100'|'Type N'|'Type K': nilai}.
     *
     * @param  array<int, int>  $barisIdx
     * @return array<string, float>
     */
    private static function parseDrift(array $rows, array $barisIdx, int $kolLabel, int $kolNilai): array
    {
        $out = [];
        foreach ($barisIdx as $r) {
            $row = $rows[$r];
            $label = self::sel($row, $kolLabel);
            $nilaiTeks = self::sel($row, $kolNilai);
            if ($label === '' || $nilaiTeks === '') {
                continue;
            }
            if (str_contains($label, 'PT100')) {
                $kunci = 'PT100';
            } elseif (str_contains($label, 'Type N')) {
                $kunci = 'Type N';
            } elseif (str_contains($label, 'Type K')) {
                $kunci = 'Type K';
            } else {
                continue;
            }
            $out[$kunci] = self::bulat((float) $nilaiTeks);
        }

        return $out;
    }

    /** union suhu unik, urutan kemunculan pertama, untuk grid {label:{suhu:v}}. */
    private static function urutanSuhu(array ...$grids): array
    {
        $out = [];
        $seen = [];
        foreach ($grids as $grid) {
            foreach ($grid as $suhuDict) {
                foreach (array_keys($suhuDict) as $suhu) {
                    if (! isset($seen[$suhu])) {
                        $seen[$suhu] = true;
                        $out[] = (string) $suhu;
                    }
                }
            }
        }

        return $out;
    }

    /** sama, untuk grid tiga lapis {label:{channel:{suhu:v}}} (meter.recorder). */
    private static function urutanSuhuChannel(array ...$grids): array
    {
        $out = [];
        $seen = [];
        foreach ($grids as $grid) {
            foreach ($grid as $channelDict) {
                foreach ($channelDict as $suhuDict) {
                    foreach (array_keys($suhuDict) as $suhu) {
                        if (! isset($seen[$suhu])) {
                            $seen[$suhu] = true;
                            $out[] = (string) $suhu;
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Bangun seluruh struktur "master" yang seharusnya cocok dengan JSON,
     * sekali saja untuk seluruh test class (baca CSV itu bukan gratis).
     */
    private static function master(): array
    {
        if (self::$master !== null) {
            return self::$master;
        }

        $cy = self::$csvCy ??= self::bacaCsv(self::dirCy().DIRECTORY_SEPARATOR.'STANDAR KALIBRATOR.csv');
        $rec = self::$csvRec ??= self::bacaCsv(self::dirRec().DIRECTORY_SEPARATOR.'Standar_Kalibrator.csv');

        // ==================================================== meter.constant / meter.yokogawa
        // Baris 9..26 (0-based) = suhu -100..1700. Constant kolom 2-8 (7
        // jenis, Type J dibuang — §4), Yokogawa kolom 13-19 (offset +11).
        $jenisMeter = ['PT100', 'Type K', 'Type N', 'Type B', 'Type T', 'Type R', 'Type S'];
        $barisMeter = range(9, 26);
        $barisMeterU95 = range(31, 48);

        $kolomConstant = [];
        $kolomYokogawa = [];
        foreach ($jenisMeter as $i => $j) {
            $kolomConstant[$j] = 2 + $i;
            $kolomYokogawa[$j] = 13 + $i;
        }

        $meterConstantKoreksi = self::parseGrid($cy, $barisMeter, 1, $kolomConstant);
        $meterYokogawaKoreksi = self::parseGrid($cy, $barisMeter, 12, $kolomYokogawa);
        $meterConstantU95 = self::parseGrid($cy, $barisMeterU95, 1, $kolomConstant);
        $meterYokogawaU95 = self::parseGrid($cy, $barisMeterU95, 12, $kolomYokogawa);

        // Drift: panel "TABEL RECORD DRIFT" kanan-atas, baris 5-7 (0-based),
        // kolom 23/24 (Constant), 25/26 (Yokogawa).
        $meterConstantDrift = self::parseDrift($cy, range(5, 7), 23, 24);
        $meterYokogawaDrift = self::parseDrift($cy, range(5, 7), 25, 26);

        // ==================================================== sensor.yoko
        // "TABEL KOREKSI SENSOR" / "TABEL U95% SENSOR": suhu kolom 1, PT100
        // kolom 2 (TIDAK ikut — §4), TCK-01..16 kolom 3..18, TCN3..12 kolom
        // 19..28.
        $kolomTck = [];
        for ($i = 1; $i <= 16; $i++) {
            $kolomTck[(string) $i] = 2 + $i;
        }
        $kolomTcn = [];
        for ($i = 3; $i <= 12; $i++) {
            $kolomTcn[(string) $i] = 16 + $i;
        }

        $barisSensorYokoKoreksi = range(63, 77);
        $barisSensorYokoU95 = range(84, 98);

        $sensorYokoKoreksi = [
            'Type K' => self::parseGrid($cy, $barisSensorYokoKoreksi, 1, $kolomTck),
            'Type N' => self::parseGrid($cy, $barisSensorYokoKoreksi, 1, $kolomTcn),
        ];
        $sensorYokoU95 = [
            'Type K' => self::parseGrid($cy, $barisSensorYokoU95, 1, $kolomTck),
            'Type N' => self::parseGrid($cy, $barisSensorYokoU95, 1, $kolomTcn),
        ];

        // Drift sensor: panel "Sensor TC", baris 11-13 (0-based), kolom 23/24.
        $sensorYokoDrift = self::parseDrift($cy, range(11, 13), 23, 24);

        // ==================================================== meter.recorder
        // "TABEL NILAI KOREKSI/U95% TEMPERATURE RECORDER": suhu kolom 1,
        // Type N CH1-20 kolom 3-22, Type K CH1-20 kolom 23-42.
        $kolomChTypeN = [];
        $kolomChTypeK = [];
        for ($i = 1; $i <= 20; $i++) {
            $kolomChTypeN[(string) $i] = 2 + $i;
            $kolomChTypeK[(string) $i] = 22 + $i;
        }

        $barisRecorderKoreksi = range(8, 23);
        $barisRecorderU95 = range(29, 44);

        $meterRecorderKoreksi = [
            'Type N' => self::parseGrid($rec, $barisRecorderKoreksi, 1, $kolomChTypeN),
            'Type K' => self::parseGrid($rec, $barisRecorderKoreksi, 1, $kolomChTypeK),
        ];
        $meterRecorderU95 = [
            'Type N' => self::parseGrid($rec, $barisRecorderU95, 1, $kolomChTypeN),
            'Type K' => self::parseGrid($rec, $barisRecorderU95, 1, $kolomChTypeK),
        ];

        // Drift: panel "Recorder" kanan-atas, baris 7-8 (0-based), kolom 45/46.
        $meterRecorderDrift = self::parseDrift($rec, range(7, 8), 45, 46);

        // ==================================================== sensor.recorder
        // "TABEL NILAI KOREKSI/U95% SENSOR TERMOKOPEL": suhu kolom 1, TCN3-12
        // kolom 3-12 (kolom == nomor kanal), TCK-01..16 kolom 23-38.
        $kolomTcnRec = [];
        for ($i = 3; $i <= 12; $i++) {
            $kolomTcnRec[(string) $i] = $i;
        }
        $kolomTckRec = [];
        for ($i = 1; $i <= 16; $i++) {
            $kolomTckRec[(string) $i] = 22 + $i;
        }

        $barisSensorRecKoreksi = range(52, 66);
        $barisSensorRecU95 = range(71, 85);

        $sensorRecorderKoreksi = [
            'Type K' => self::parseGrid($rec, $barisSensorRecKoreksi, 1, $kolomTckRec),
            'Type N' => self::parseGrid($rec, $barisSensorRecKoreksi, 1, $kolomTcnRec),
        ];
        $sensorRecorderU95 = [
            'Type K' => self::parseGrid($rec, $barisSensorRecU95, 1, $kolomTckRec),
            'Type N' => self::parseGrid($rec, $barisSensorRecU95, 1, $kolomTcnRec),
        ];

        // Drift sensor: panel "Sensor TC", baris 14-15 (0-based), kolom 45/46.
        $sensorRecorderDrift = self::parseDrift($rec, range(14, 15), 45, 46);

        // ==================================================== index_temps
        $indexTempsYoko = self::urutanSuhu($meterConstantKoreksi, $meterConstantU95);
        $indexTempsRecorder = self::urutanSuhuChannel($meterRecorderKoreksi, $meterRecorderU95);

        return self::$master = [
            'meter' => [
                'constant' => [
                    'koreksi' => $meterConstantKoreksi,
                    'u95' => $meterConstantU95,
                    'drift' => $meterConstantDrift,
                ],
                'yokogawa' => [
                    'koreksi' => $meterYokogawaKoreksi,
                    'u95' => $meterYokogawaU95,
                    'drift' => $meterYokogawaDrift,
                ],
                'recorder' => [
                    'koreksi' => $meterRecorderKoreksi,
                    'u95' => $meterRecorderU95,
                    'drift' => $meterRecorderDrift,
                ],
            ],
            'sensor' => [
                'yoko' => [
                    'koreksi' => $sensorYokoKoreksi,
                    'u95' => $sensorYokoU95,
                    'drift' => $sensorYokoDrift,
                ],
                'recorder' => [
                    'koreksi' => $sensorRecorderKoreksi,
                    'u95' => $sensorRecorderU95,
                    'drift' => $sensorRecorderDrift,
                ],
            ],
            'index_temps' => [
                'yoko' => $indexTempsYoko,
                'recorder' => $indexTempsRecorder,
            ],
        ];
    }

    /**
     * Jalan turun paralel di struktur JSON (aktual) dan master (harapan),
     * satu assertion per SEL DAUN. Berjalan dari sisi JSON supaya §5 (master
     * punya lebih banyak titik dari JSON) tidak melahirkan kegagalan palsu —
     * temuan itu diuji terpisah & eksplisit di `test_titik_minus_20_recorder_...`.
     */
    private function assertPohonCocokMaster(mixed $expected, mixed $actual, string $path): void
    {
        if (is_array($actual)) {
            $this->assertIsArray(
                $expected,
                "Sel {$path} berbentuk array di JSON tapi cuma satu nilai di master — kedalaman "
                    .'strukturnya tidak sama, cek pemetaan baris/kolom.',
            );
            foreach ($actual as $key => $value) {
                $p = $path === '' ? (string) $key : "{$path}.{$key}";
                $this->assertArrayHasKey(
                    $key,
                    $expected,
                    "Sel {$p} ada di JSON tapi tidak bisa diturunkan dari master — cek pemetaan baris/kolom.",
                );
                $this->assertPohonCocokMaster($expected[$key], $value, $p);
            }

            return;
        }

        $this->assertEqualsWithDelta(
            (float) $expected,
            (float) $actual,
            self::TOL,
            "Sel {$path} meleset dari master (JSON={$actual}, master={$expected}).",
        );
    }

    public function test_meter_constant_cocok_master(): void
    {
        $m = self::master();
        $j = self::jsonData();

        $this->assertPohonCocokMaster($m['meter']['constant'], $j['meter']['constant'], 'meter.constant');
    }

    public function test_meter_yokogawa_cocok_master(): void
    {
        $m = self::master();
        $j = self::jsonData();

        $this->assertPohonCocokMaster($m['meter']['yokogawa'], $j['meter']['yokogawa'], 'meter.yokogawa');
    }

    public function test_meter_recorder_cocok_master(): void
    {
        $m = self::master();
        $j = self::jsonData();

        $this->assertPohonCocokMaster($m['meter']['recorder'], $j['meter']['recorder'], 'meter.recorder');
    }

    public function test_sensor_yoko_cocok_master(): void
    {
        $m = self::master();
        $j = self::jsonData();

        $this->assertPohonCocokMaster($m['sensor']['yoko'], $j['sensor']['yoko'], 'sensor.yoko');
    }

    public function test_sensor_recorder_cocok_master(): void
    {
        $m = self::master();
        $j = self::jsonData();

        $this->assertPohonCocokMaster($m['sensor']['recorder'], $j['sensor']['recorder'], 'sensor.recorder');
    }

    public function test_index_temps_cocok_master(): void
    {
        $m = self::master();
        $j = self::jsonData();

        $this->assertSame($m['index_temps']['yoko'], $j['index_temps']['yoko'], 'index_temps.yoko meleset dari master.');
        $this->assertSame(
            $m['index_temps']['recorder'],
            $j['index_temps']['recorder'],
            'index_temps.recorder meleset dari master.',
        );
    }

    /**
     * §5 dikunci di sini: master (baris oC=-20) punya titik yang JSON tidak
     * punya. Ini BUKAN beda nilai — kuncinya memang tidak ada di JSON. Test
     * ini akan MERAH (bukan berarti ada yang salah) begitu salah satu dari
     * dua hal terjadi, dan keduanya perlu ditinjau manusia:
     *
     *   - JSON diperbarui untuk memuat titik -20 itu (tandanya: perbaiki
     *     assertion di bawah jadi assertArrayHasKey, dan hapus catatan §5).
     *   - Master berubah / baris -20 hilang (tandanya: `assertArrayHasKey`
     *     yang pertama gagal, bukan yang kedua).
     */
    public function test_titik_minus_20_recorder_yang_hilang_dari_json_terdokumentasi(): void
    {
        $m = self::master();
        $j = self::jsonData();

        // meter.recorder.u95: master punya -20 di KEDUA jenis, seluruh 20 kanal.
        for ($ch = 1; $ch <= 20; $ch++) {
            $kanal = (string) $ch;
            $this->assertArrayHasKey(
                -20,
                $m['meter']['recorder']['u95']['Type N'][$kanal],
                "Master seharusnya masih punya titik -20 di meter.recorder.u95.Type N kanal {$kanal}.",
            );
            $this->assertArrayHasKey(
                -20,
                $m['meter']['recorder']['u95']['Type K'][$kanal],
                "Master seharusnya masih punya titik -20 di meter.recorder.u95.Type K kanal {$kanal}.",
            );
            $this->assertArrayNotHasKey(
                -20,
                $j['meter']['recorder']['u95']['Type N'][$kanal],
                "meter.recorder.u95.Type N kanal {$kanal} sekarang punya titik -20 — perbarui docblock §5 "
                    .'dan longgarkan assertion ini kalau itu memang disengaja.',
            );
            $this->assertArrayNotHasKey(
                -20,
                $j['meter']['recorder']['u95']['Type K'][$kanal],
                "meter.recorder.u95.Type K kanal {$kanal} sekarang punya titik -20 — perbarui docblock §5.",
            );
        }
        $this->assertEqualsWithDelta(0.83, $m['meter']['recorder']['u95']['Type N']['1'][-20], self::TOL);
        $this->assertEqualsWithDelta(0.67, $m['meter']['recorder']['u95']['Type K']['1'][-20], self::TOL);

        // sensor.recorder.{koreksi,u95}.Type N: master punya -20 di kanal 3-12.
        for ($ch = 3; $ch <= 12; $ch++) {
            $kanal = (string) $ch;
            $this->assertArrayHasKey(-20, $m['sensor']['recorder']['koreksi']['Type N'][$kanal]);
            $this->assertArrayHasKey(-20, $m['sensor']['recorder']['u95']['Type N'][$kanal]);
            $this->assertArrayNotHasKey(
                -20,
                $j['sensor']['recorder']['koreksi']['Type N'][$kanal],
                "sensor.recorder.koreksi.Type N kanal {$kanal} sekarang punya titik -20 — perbarui docblock §5.",
            );
            $this->assertArrayNotHasKey(
                -20,
                $j['sensor']['recorder']['u95']['Type N'][$kanal],
                "sensor.recorder.u95.Type N kanal {$kanal} sekarang punya titik -20 — perbarui docblock §5.",
            );
        }
        $this->assertEqualsWithDelta(-0.15, $m['sensor']['recorder']['koreksi']['Type N']['3'][-20], self::TOL);
        $this->assertEqualsWithDelta(0.76, $m['sensor']['recorder']['u95']['Type N']['3'][-20], self::TOL);
    }

    /** Sanity check jumlah sel — kalau ini bergeser, cakupan test di atas ikut bergeser. */
    public function test_jumlah_sel_json_sama_dengan_yang_diadu(): void
    {
        $j = self::jsonData();

        $hitung = static function (mixed $d) use (&$hitung): int {
            if (! is_array($d)) {
                return 1;
            }
            $total = 0;
            foreach ($d as $v) {
                $total += $hitung($v);
            }

            return $total;
        };

        // 2.672 dikonfirmasi manual sebelum test ini ditulis (lihat laporan
        // tugas) — angka ini pengaman supaya penambahan/penghapusan diam-diam
        // di JSON kelihatan, bukan ambang yang berarti.
        $this->assertSame(2672, $hitung($j), 'Jumlah sel daun total JSON berubah — tinjau cakupan test ini.');
    }
}
