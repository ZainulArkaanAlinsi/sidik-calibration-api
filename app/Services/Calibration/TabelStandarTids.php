<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel koreksi, U95, & drift standar untuk lembar **TIDS** (Temperatur
 * Indikator dengan Sensor), dibaca dari `database/data/tabel-standar-tids.json`.
 *
 * Isinya gabungan DUA workbook master TIDS yang turun dari lab 28 Agt 2026 —
 * dua-duanya ber-password, dua-duanya berkop `KALIBRASI TEMPERATURE INDIKATOR
 * DENGAN SENSOR (TIDS)` dan bernomor lingkup `LK-285-IDN`:
 *
 *   `… TIDS - Recorder Graptech.xlsm`  → sheet `Standar_Recorder`
 *   `… TIDS - Yokogawa K,N.xlsm`       → sheet `STANDAR KALIBRATOR`
 *
 * Yang membedakan keduanya bukan alat yang dikalibrasi, melainkan **standar
 * yang dipakai mengalibrasinya**. Itu sebabnya dua workbook ini jadi SATU
 * profil dengan tiga keluarga standar, bukan dua alat baru — persis pola yang
 * sudah dipakai TITS (dua workbook: fungsi Measure & Source) dan Enclosure (dua
 * workbook: Recorder & Constant/Yokogawa). Nama alat di lampiran akreditasi
 * cuma satu baris, dan `CalibrationProfileRegistry` memang melempar
 * `LogicException` kalau dua profil mengaku ejaan nama yang sama.
 *
 * ## Bentuk tabelnya beda antar keluarga
 *
 *   keluarga             koreksi & U95 meter dilihat per
 *   constant / yokogawa  [tipe sensor][titik]
 *   recorder             [tipe sensor][KANAL][titik]
 *
 * Recorder Graptech GL840 punya 20 kanal dan tiap kanal punya barisnya sendiri
 * di sertifikat — sama bentuk dengan `TabelKalibratorEnclosure`, yang melayani
 * recorder fisik yang sama untuk lembar Enclosure. Dipisah dari berkas itu
 * karena angkanya bukan angka yang sama: sertifikat recorder di master TIDS
 * bertanggal 8 Mei 2025, tabel enclosure dari workbook & tanggal lain. Menumpang
 * ke berkas tetangga berarti satu pembaruan sertifikat diam-diam menggeser
 * U95 dua jenis alat sekaligus.
 *
 * ## Nomor sensor → kanal recorder: aritmetika master, ditulis terang
 *
 * Master memilih kolom tabel recorder lewat 27 tingkat `IF` bersarang
 * (`PERHITUNGAN FC!Q23`): Type N nomor 3 → kolom 3 (= CH1), nomor 4 → CH2, …;
 * Type K nomor 1 → kolom 23 (= CH1), nomor 2 → CH2, …. Diterjemahkan jadi
 * [kanalRecorder]. Penomoran Type N memang MULAI DARI 3 — catatan di lembar
 * kerjanya sendiri menuliskannya: *"If using Thermocouple Type N, No.
 * Thermocouple START FROM 3. If using PRT PT100 (RTD), No. Thermocouple ALL
 * 17."*
 *
 * ## RTD + recorder: kombinasi yang TIDAK punya tabel
 *
 * Cabang terakhir kedua rumus master jatuh ke `VLOOKUP(…, 100, 0)` — kolom
 * ke-100 di tabel yang cuma 42 kolom. Errornya dibungkus `IFNA(…,"")`, jadi
 * hasilnya KOSONG, dan kosong ikut dijumlah `J+Q+R` sebagai nol. Artinya sesi
 * TIDS dengan PRT PT100 di recorder terbit dengan **koreksi meter dan koreksi
 * sensor dua-duanya hilang** tanpa satu pun error di sepanjang jalurnya.
 * Di sini kombinasi itu memulangkan `null` dan pemanggilnya WAJIB memblokir
 * titiknya — lihat [TidsCalculator].
 *
 * ## Pencocokan titik: TERDEKAT, bukan interpolasi
 *
 * `PERHITUNGAN FC!P23` memakai `INDEX(…, MATCH(MIN(ABS(index − rata²)), …))` —
 * titik tabel terdekat ke RATA-RATA pembacaan standar, bukan ke set point.
 * Sama seperti [TabelKalibratorSuhu3Alat]; alasan lengkapnya di docblock sana.
 *
 * @see TidsCalculator
 * @see App\Services\Calibration\Profiles\TidsProfile
 * @see docs/pertanyaan-lab-tids-workbook.md
 */
class TabelStandarTids
{
    /** Keluarga standar yang punya tabel. Kunci huruf kecil. */
    public const KELUARGA = ['recorder', 'constant', 'yokogawa'];

    /** @var array<string, string> */
    public const KELUARGA_TERCETAK = [
        'recorder' => 'Temperature Recorder',
        'constant' => 'Constant',
        'yokogawa' => 'Yokogawa',
    ];

    /**
     * Merk/tipe seperti tercetak di blok `Standard used:` sertifikatnya —
     * `DATABASE!Q13:U16` kedua workbook.
     *
     * @var array<string, array{merk_tipe: string, serial: string, tertelusur: string}>
     */
    public const KELUARGA_SERTIFIKAT = [
        'recorder' => ['merk_tipe' => 'Graptech/GL840', 'serial' => 'C305B1470', 'tertelusur' => 'LK-285-IDN'],
        'constant' => ['merk_tipe' => 'Constant/40T', 'serial' => '99875850', 'tertelusur' => 'LK-285-IDN'],
        'yokogawa' => ['merk_tipe' => 'Yokogawa/CA 150 Handy Cal', 'serial' => '23P1005', 'tertelusur' => 'LK-241-IDN'],
    ];

    /**
     * Keluarga `recorder` memakai tabel meter yang dikunci per KANAL — beda
     * bentuk dari Constant/Yokogawa. Dipisah supaya pemanggil nggak perlu hafal
     * kuirk-nya.
     */
    public const KELUARGA_BERKANAL = 'recorder';

    /**
     * Tipe sensor STANDAR yang benar-benar dimiliki lab.
     *
     * `DATABASE!Q19:V25` kedua workbook mendaftar delapan baris (sampai Type J),
     * tapi cuma tiga teratas yang punya S/N & ketertelusuran. Lima sisanya baris
     * kosong yang menunggu alat dibeli.
     *
     * @var list<string>
     */
    public const TIPE_SENSOR = ['RTD', 'Type K', 'Type N'];

    /**
     * Ejaan master vs kosakata repo. Master menulis `PRT PT100`; berkas data &
     * seluruh repo memakai `RTD` (lihat [TabelKalibratorSuhu::TIPE_SENSOR]).
     *
     * @var array<string, string>
     */
    public const EJAAN_MASTER = [
        'RTD' => 'PRT PT100',
        'Type K' => 'Thermocouple Type K',
        'Type N' => 'Thermocouple Type N',
    ];

    /** Nomor sensor RTD — satu-satunya, dan nomornya 17 (catatan lembar kerja). */
    public const NOMOR_RTD = 17;

    /** Faktor cakupan sertifikat kalibrator & sensor — kolom `Divisor` `Q24`/`Q25` = 2. */
    public const K_SERTIFIKAT = 2.0;

    /** Tabel sensor mana yang dipakai keluarga ini — recorder punya sertifikat sensornya sendiri. */
    private const MEJA_SENSOR = [
        'recorder' => 'recorder',
        'constant' => 'kalibrator',
        'yokogawa' => 'kalibrator',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Kanal recorder untuk sebuah (tipe sensor, No. Termokopel).
     *
     * `null` = kombinasi itu nggak menunjuk kanal mana pun — lihat blok
     * "RTD + recorder" di docblock kelas.
     */
    public function kanalRecorder(string $tipeSensor, int $noSensor): ?int
    {
        $kanal = match ($tipeSensor) {
            'Type N' => $noSensor - 2,
            'Type K' => $noSensor,
            default => null,
        };

        return $kanal !== null && $kanal >= 1 && $kanal <= 20 ? $kanal : null;
    }

    /**
     * Titik tabel TERDEKAT ke `$nilai` untuk keluarga ini.
     *
     * Seri sama dekat dimenangkan yang PERTAMA di daftar, sama seperti
     * `MATCH(…, 0)` Excel yang memulangkan kecocokan pertama.
     */
    public function indeksTerdekat(string $keluarga, float $nilai): ?float
    {
        $daftar = $this->indeks($keluarga);

        if ($daftar === []) {
            return null;
        }

        $terpilih = null;
        $jarak = null;

        foreach ($daftar as $titik) {
            $d = abs($titik - $nilai);

            if ($jarak === null || $d < $jarak) {
                $jarak = $d;
                $terpilih = $titik;
            }
        }

        return $terpilih;
    }

    /**
     * Daftar titik tabel keluarga ini, urut seperti di master.
     *
     * @return list<float>
     */
    public function indeks(string $keluarga): array
    {
        $kunci = $keluarga === self::KELUARGA_BERKANAL ? 'recorder' : 'kalibrator';

        return array_map('floatval', self::muat()['index_titik'][$kunci] ?? []);
    }

    /**
     * Koreksi meter/indikator standar (`Koreksi STD Meter`) di titik `$indeks`.
     *
     * `$kanal` WAJIB untuk `recorder`, diabaikan untuk Constant/Yokogawa.
     * `null` = tabelnya nggak memuat kombinasi itu — bedakan dari `0.0`, yang
     * artinya lab memang mencatat koreksi nol di situ.
     */
    public function koreksiMeter(string $keluarga, string $tipeSensor, float $indeks, ?int $kanal = null): ?float
    {
        return $this->selMeter($keluarga, 'koreksi', $tipeSensor, $indeks, $kanal);
    }

    /** U95 sertifikat meter/indikator standar di titik `$indeks` (belum dibagi k). */
    public function u95Meter(string $keluarga, string $tipeSensor, float $indeks, ?int $kanal = null): ?float
    {
        return $this->selMeter($keluarga, 'u95', $tipeSensor, $indeks, $kanal);
    }

    /** Drift meter/indikator standar per tipe sensor (`Tabel_Drift_*`). */
    public function driftMeter(string $keluarga, string $tipeSensor): ?float
    {
        $nilai = self::muat()['meter'][$keluarga]['drift'][$tipeSensor] ?? null;

        return is_numeric($nilai) ? (float) $nilai : null;
    }

    /** Koreksi sensor/probe standar (`Koreksi STD Sensor`) — kolomnya per NOMOR sensor. */
    public function koreksiSensor(string $keluarga, string $tipeSensor, int $noSensor, float $indeks): ?float
    {
        return $this->selSensor($keluarga, 'koreksi', $tipeSensor, $noSensor, $indeks);
    }

    /** U95 sertifikat sensor/probe standar di titik `$indeks`. */
    public function u95Sensor(string $keluarga, string $tipeSensor, int $noSensor, float $indeks): ?float
    {
        return $this->selSensor($keluarga, 'u95', $tipeSensor, $noSensor, $indeks);
    }

    /** Drift sensor/probe standar (`Drift_sensor` / `Tabel_Drift_Sensor`). */
    public function driftSensor(string $keluarga, string $tipeSensor): ?float
    {
        $nilai = self::muat()['sensor'][self::MEJA_SENSOR[$keluarga] ?? '']['drift'][$tipeSensor] ?? null;

        return is_numeric($nilai) ? (float) $nilai : null;
    }

    /**
     * Semua nomor sensor yang punya kolom untuk tipe ini — buat dropdown lembar
     * kerja & pesan peringatan.
     *
     * @return list<int>
     */
    public function nomorSensorTersedia(string $keluarga, string $tipeSensor): array
    {
        $meja = self::MEJA_SENSOR[$keluarga] ?? '';
        $peta = self::muat()['sensor'][$meja]['koreksi'][$tipeSensor] ?? [];

        $nomor = array_map('intval', array_keys(is_array($peta) ? $peta : []));
        sort($nomor);

        return $nomor;
    }

    /**
     * Dryblock yang dicentang teknisi (`Variasi axial Dryblok A/B`).
     *
     * @return array{nama: string, serial: string, rentang: string, stabilitas: float, keseragaman: float}|null
     */
    public function dryblock(string $kode): ?array
    {
        $b = self::muat()['dryblock'][strtoupper($kode)] ?? null;

        if (! is_array($b)) {
            return null;
        }

        return [
            'nama' => (string) $b['nama'],
            'serial' => (string) $b['serial'],
            'rentang' => (string) $b['rentang'],
            'stabilitas' => (float) $b['stabilitas'],
            'keseragaman' => (float) $b['keseragaman'],
        ];
    }

    /**
     * CMC yang tercetak di kedua master (`DATABASE!R5:S7`), apa adanya.
     *
     * **Ini BUKAN yang jadi lantai U95.** Yang dipakai baris
     * `calibration_capabilities` dari lampiran akreditasi LK-285-IDN, karena itu
     * dokumen yang mengikat lab. Dua-duanya kebetulan sama persis (0,86 / 1,4 /
     * 3,1 °C) — dan justru karena sama, kecocokannya bisa DIUJI: begitu lab
     * memperbarui salah satunya tanpa yang lain, test-nya merah dan bukan
     * sertifikatnya yang jadi korban.
     *
     * @return list<array{min: float, maks: float, u95: float}>
     */
    public function cmcMaster(): array
    {
        return array_map(
            static fn (array $c): array => [
                'min' => (float) $c['min'],
                'maks' => (float) $c['maks'],
                'u95' => (float) $c['u95'],
            ],
            self::muat()['cmc'] ?? [],
        );
    }

    private function selMeter(string $keluarga, string $bagian, string $tipeSensor, float $indeks, ?int $kanal): ?float
    {
        $tabel = self::muat()['meter'][$keluarga][$bagian] ?? null;

        if (! is_array($tabel)) {
            return null;
        }

        $kolom = $keluarga === self::KELUARGA_BERKANAL
            ? ($kanal === null ? null : ($tabel[$tipeSensor][(string) $kanal] ?? null))
            : ($tabel[$tipeSensor] ?? null);

        return $this->sel($kolom, $indeks);
    }

    private function selSensor(string $keluarga, string $bagian, string $tipeSensor, int $noSensor, float $indeks): ?float
    {
        $meja = self::MEJA_SENSOR[$keluarga] ?? '';
        $kolom = self::muat()['sensor'][$meja][$bagian][$tipeSensor][(string) $noSensor] ?? null;

        return $this->sel($kolom, $indeks);
    }

    /**
     * Satu sel: kolom `$kolom` (peta titik → nilai) pada titik `$indeks`.
     *
     * Kunci JSON-nya string apa adanya dari master (`'-20'`, `'0'`, `'25'`),
     * jadi pencocokannya lewat perbandingan NUMERIK — bukan `isset` — supaya
     * `150` dan `150.0` nggak jadi dua titik berbeda.
     *
     * @param  mixed  $kolom
     */
    private function sel($kolom, float $indeks): ?float
    {
        if (! is_array($kolom)) {
            return null;
        }

        foreach ($kolom as $titik => $nilai) {
            if (abs((float) $titik - $indeks) <= 1e-9) {
                return is_numeric($nilai) ? (float) $nilai : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = database_path('data/tabel-standar-tids.json');

        if (! is_file($berkas)) {
            throw new RuntimeException(
                "Tabel standar TIDS nggak ketemu di {$berkas} — tanpa dia sesi TIDS nggak bisa dihitung sama sekali.",
            );
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi)) {
            throw new RuntimeException("Tabel standar TIDS di {$berkas} bukan JSON yang sah.");
        }

        return self::$data = $isi;
    }
}
