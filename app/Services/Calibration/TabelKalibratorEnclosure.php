<?php

namespace App\Services\Calibration;

/**
 * Tabel koreksi, U95, & drift kalibrator suhu untuk kalibrasi ENCLOSURE
 * (Oven/Furnace/Bath/Inkubator/Refrigerator), dibaca dari
 * `database/data/tabel-kalibrator-enclosure.json`.
 *
 * ## Kenapa berkas data, bukan konstanta
 *
 * Sama alasan dengan [TabelKalibratorSuhu]: isinya ratusan angka yang lahir dari
 * SERTIFIKAT kalibrator & sensor standar dan punya tanggal kedaluwarsa. Yang
 * memperbaruinya admin lab lewat berkas, bukan programmer lewat `git`.
 *
 * ## Tiga meter kalibrator, dua bentuk tabel
 *
 * Master enclosure ada DUA workbook. Yang pertama melayani **Constant** &
 * **Yokogawa** (koreksi kalibrator dilihat per tipe sensor & titik suhu). Yang
 * kedua **Recorder** (Graphtech GL840) — koreksinya per KANAL (CH1..CH20),
 * karena tiap kanal recorder punya sertifikat sendiri. Karena itu:
 *
 *   merk                 koreksi/u95 meter dilihat per
 *   constant / yokogawa  [tipe sensor][titik]
 *   recorder             [tipe sensor][kanal][titik]
 *
 * ## Tabel sensor terpisah dari tabel meter
 *
 * Termokopel yang ditaruh di dalam enclosure punya koreksi & U95 sendiri, per
 * NOMOR sensor (TCN3..TCN12 untuk Type N, TCK-01..TCK-16 untuk Type K) dan per
 * titik. Dua workbook punya tabel sensor yang isinya beda (sertifikat sensor
 * beda), jadi dikunci `yoko` (dipakai Constant & Yokogawa) vs `recorder`.
 *
 * ## Pencocokan titik: TERDEKAT, bukan interpolasi
 *
 * Master memakai `INDEX(Index_Kalibrator, MATCH(MIN(ABS(Index_Kalibrator −
 * rata_rata)), …))` — titik tabel yang paling dekat ke rata-rata pembacaan
 * sensor, tanpa interpolasi. `index()` mengembalikan titik tabel itu; koreksi,
 * U95, dst. dibaca di titik tersebut.
 *
 * @see EnclosureCalculator
 * @see docs/pertanyaan-lab-enclosure.md
 */
class TabelKalibratorEnclosure
{
    /** Merk kalibrator yang punya tabel. Kunci huruf kecil. */
    public const MERK = ['constant', 'yokogawa', 'recorder'];

    /** @var array<string, string> */
    public const MERK_TERCETAK = [
        'constant' => 'Constant',
        'yokogawa' => 'Yokogawa',
        'recorder' => 'Temperature Recorder',
    ];

    /**
     * Merk `recorder` memakai tabel koreksi/U95 meter yang dikunci per KANAL,
     * beda bentuk dari Constant/Yokogawa. Dipisah supaya pemanggil nggak perlu
     * hafal kuirk-nya.
     */
    public const MERK_BERKANAL = 'recorder';

    /**
     * Workbook mana yang tabel SENSOR-nya dipakai per merk kalibrator. Constant &
     * Yokogawa satu workbook (`yoko`), Recorder workbook sendiri.
     *
     * @var array<string, string>
     */
    private const WORKBOOK_SENSOR = [
        'constant' => 'yoko',
        'yokogawa' => 'yoko',
        'recorder' => 'recorder',
    ];

    /** Tipe sensor yang dipakai enclosure (lampiran akreditasi + master). */
    public const TIPE_SENSOR = ['Type N', 'Type K'];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** Workbook (kelompok tabel sensor + index) untuk merk ini. */
    public function workbook(string $merk): string
    {
        return self::WORKBOOK_SENSOR[$merk] ?? 'yoko';
    }

    /**
     * Titik tabel kalibrator TERDEKAT ke `$rataRata` — "index" master
     * (`M## = MATCH(MIN(ABS(Index_Kalibrator − J##)))`).
     *
     * Balik `null` kalau daftar index untuk workbook itu kosong.
     */
    public function index(string $merk, float $rataRata): ?float
    {
        $daftar = array_map('floatval', self::muat()['index_temps'][$this->workbook($merk)] ?? []);

        return $daftar === [] ? null : $this->titikTerdekat($daftar, $rataRata);
    }

    /**
     * Koreksi kalibrator (STD Meter) di titik `$index`.
     *
     * Untuk `recorder` WAJIB kasih `$kanal` (koreksi per kanal); untuk
     * Constant/Yokogawa `$kanal` diabaikan.
     */
    public function koreksiMeter(string $merk, string $tipeSensor, float $index, ?int $kanal = null): ?float
    {
        return $this->nilaiMeter($merk, 'koreksi', $tipeSensor, $index, $kanal);
    }

    /** U95 sertifikat kalibrator (STD Meter) di titik `$index`. Negatif ditolak. */
    public function u95Meter(string $merk, string $tipeSensor, float $index, ?int $kanal = null): ?float
    {
        $nilai = $this->nilaiMeter($merk, 'u95', $tipeSensor, $index, $kanal);

        return $nilai !== null && $nilai >= 0.0 ? $nilai : null;
    }

    /** Ketidakpastian drift kalibrator meter untuk tipe sensor ini. */
    public function driftMeter(string $merk, string $tipeSensor): ?float
    {
        $nilai = self::muat()['meter'][$merk]['drift'][$tipeSensor] ?? null;

        return $nilai === null ? null : (float) $nilai;
    }

    /** Koreksi sensor/termokopel nomor `$sensorNo` di titik `$index`. */
    public function koreksiSensor(string $merk, string $tipeSensor, int $sensorNo, float $index): ?float
    {
        return $this->nilaiSensor($merk, 'koreksi', $tipeSensor, $sensorNo, $index);
    }

    /** U95 sensor/termokopel nomor `$sensorNo` di titik `$index`. Negatif ditolak. */
    public function u95Sensor(string $merk, string $tipeSensor, int $sensorNo, float $index): ?float
    {
        $nilai = $this->nilaiSensor($merk, 'u95', $tipeSensor, $sensorNo, $index);

        return $nilai !== null && $nilai >= 0.0 ? $nilai : null;
    }

    /** Ketidakpastian drift sensor/termokopel untuk tipe sensor ini. */
    public function driftSensor(string $merk, string $tipeSensor): ?float
    {
        $wb = $this->workbook($merk);
        $nilai = self::muat()['sensor'][$wb]['drift'][$tipeSensor] ?? null;

        return $nilai === null ? null : (float) $nilai;
    }

    /**
     * Nilai kolom `$kolom` (koreksi/u95) tabel METER, cabang berkanal vs tidak.
     */
    private function nilaiMeter(string $merk, string $kolom, string $tipeSensor, float $index, ?int $kanal): ?float
    {
        $tabel = self::muat()['meter'][$merk][$kolom][$tipeSensor] ?? null;

        if (! is_array($tabel)) {
            return null;
        }

        if ($merk === self::MERK_BERKANAL) {
            if ($kanal === null) {
                return null;
            }

            $tabel = $tabel[(string) $kanal] ?? null;

            if (! is_array($tabel)) {
                return null;
            }
        }

        return $this->nilaiTitikTerdekat($tabel, $index);
    }

    /** Nilai kolom `$kolom` tabel SENSOR untuk nomor sensor `$sensorNo`. */
    private function nilaiSensor(string $merk, string $kolom, string $tipeSensor, int $sensorNo, float $index): ?float
    {
        $wb = $this->workbook($merk);
        $tabel = self::muat()['sensor'][$wb][$kolom][$tipeSensor][(string) $sensorNo] ?? null;

        return is_array($tabel) ? $this->nilaiTitikTerdekat($tabel, $index) : null;
    }

    /**
     * Nilai di titik TERDEKAT dalam peta `titik(string) => nilai`.
     *
     * @param  array<string, float|null>  $peta
     */
    private function nilaiTitikTerdekat(array $peta, float $target): ?float
    {
        $titik = [];

        foreach ($peta as $t => $v) {
            if ($v !== null) {
                $titik[] = (float) $t;
            }
        }

        if ($titik === []) {
            return null;
        }

        $menang = $this->titikTerdekat($titik, $target);

        return (float) $peta[$this->kunci($menang)];
    }

    /**
     * Titik tabel terdekat ke `$target`. Pada seri (jarak sama) yang menang titik
     * yang LEBIH RENDAH — beda dari TITS.
     *
     * Master enclosure memakai `INDEX(…, MATCH(MIN(ABS(Index − rata)), …, 0))`.
     * `MATCH(…, 0)` mengembalikan yang PERTAMA ketemu, dan `Index_Kalibrator`
     * urut menaik (…, 50, 100, …), jadi titik 75 yang tepat di tengah 50 & 100
     * mengambil 50 (yang di atas dalam daftar). Ketahuan di sesi Yokogawa SP3
     * (75 °C): koreksinya beda 0,0575 °C antara index 50 dan 100.
     *
     * @param  list<float>  $titik
     */
    private function titikTerdekat(array $titik, float $target): float
    {
        $menang = null;

        foreach ($titik as $t) {
            $jarak = abs($t - $target);

            if ($menang === null
                || $jarak < $menang['jarak']
                || ($jarak === $menang['jarak'] && $t < $menang['titik'])) {
                $menang = ['jarak' => $jarak, 'titik' => $t];
            }
        }

        return $menang['titik'];
    }

    /** Kunci JSON untuk sebuah titik: bilangan bulat tanpa `.0`. */
    private function kunci(float $titik): string
    {
        return $titik == (int) $titik ? (string) (int) $titik : (string) $titik;
    }

    /**
     * Isi berkas data, dibaca sekali per proses (konstanta lab, bukan status).
     *
     * @return array<string, mixed>
     */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = database_path('data/tabel-kalibrator-enclosure.json');

        if (! is_file($path)) {
            throw new \RuntimeException(
                "Tabel kalibrator enclosure nggak ketemu di {$path} — tanpa itu koreksi & U95 standar "
                .'nggak bisa dibaca dan sesi enclosure nggak boleh dihitung.',
            );
        }

        $isi = json_decode((string) file_get_contents($path), true);

        if (! is_array($isi) || ! isset($isi['meter'], $isi['sensor'])) {
            throw new \RuntimeException("Tabel kalibrator enclosure di {$path} rusak — kunci `meter`/`sensor` nggak ada.");
        }

        return self::$data = $isi;
    }
}
