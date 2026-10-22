<?php

namespace App\Services\Calibration;

use App\Services\Acuan\SumberAcuan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/**
 * Tabel standar suhu untuk **tiga alat** yang mendarat bareng — Thermocouple,
 * Termometer Gelas, dan Thermohygrometer — dibaca dari
 * `database/data/tabel-master-suhu-3alat.json`.
 *
 * Berdiri sendiri di luar [TabelKalibratorSuhu] (yang melayani TITS) karena
 * tabelnya memang **bukan tabel yang sama**, walau nama sheet-nya sama-sama
 * `STANDAR KALIBRATOR`. TITS memisahkan koreksi kalibrator per MODE
 * (measure/source, 135 sel berbeda); ketiga alat ini tidak punya mode sama
 * sekali — kalibratornya selalu jadi pembanding, tidak pernah jadi sumber
 * sinyal. Menumpangkannya ke berkas TITS berarti memilih salah satu dari dua
 * tabel mode untuk alat yang tidak punya mode, dan itu pilihan yang tidak ada
 * jawaban benarnya.
 *
 * ## Ejaan tipe sensor diseragamkan waktu diekstrak
 *
 * Master menulis tiga ejaan untuk barang yang sama: `PT100` di header tabel
 * koreksi, `PRT PT100` di dropdown `Standar_Sensor`, dan `RTD` di tabel CMC.
 * Berkas data ini memakai **`RTD`** untuk ketiganya, sama seperti
 * [TabelKalibratorSuhu::TIPE_SENSOR], supaya satu kosakata berlaku di seluruh
 * repo. Ejaan aslinya tercatat di sini, bukan di kode pemanggil.
 *
 * ## Pencocokan titik: TERDEKAT, bukan interpolasi
 *
 * `PERHITUNGAN FC!P23` memakai
 * `INDEX(Index_Kalibrator, MATCH(MIN(ABS(Index_Kalibrator − rata²)), …))` —
 * titik tabel yang paling dekat, tanpa interpolasi. Dua hal yang gampang salah
 * di sini dan dua-duanya menggeser angka sertifikat tanpa error:
 *
 *  1. Yang dicocokkan **rata-rata pembacaan**, BUKAN set point. Sesi Thermocouple
 *     contoh set point 150 °C membaca rata-rata 150,1 — dan 150,1 lebih dekat ke
 *     titik tabel 200 (49,9) daripada ke 100 (50,1). Master memang mengambil
 *     baris 200. Mencocokkan set point-nya akan mengambil baris 100 dan
 *     menggeser koreksi dari −0,14 ke −0,17.
 *  2. Tabelnya **jarang**: −100, −20, 0, 25, 50, 100, 200, 300, … Titik 148,6
 *     mendarat di baris 100, bukan di 150 yang tidak ada.
 *
 * ## Tabel Yokogawa Thermocouple datang dari CACHE TAUTAN LUAR
 *
 * `Koreksi_Yokogawa` & `U95_Yokogawa` di workbook Thermocouple menunjuk
 * `'[4]STANDAR KALIBRATOR'` — berkas lain (`Master Olda Enclosure -
 * Yokogawa.xlsm`) yang tidak ikut dikirim. Nilainya diambil dari cache tautan
 * luar workbook itu sendiri, dan cache hanya menyimpan sel yang benar-benar
 * PERNAH DITARIK. Jadi tabel itu bisa berlubang di titik yang belum pernah
 * dipakai sesi mana pun.
 *
 * Konsekuensinya diambil serius: [koreksiKalibrator] balik `null` untuk sel
 * yang tidak ada, dan pemanggilnya WAJIB memblokir titiknya dengan alasan yang
 * kebaca. Master sendiri tidak begitu — rumusnya dibungkus `IFNA(…,"")`, jadi
 * sel yang hilang jadi kosong, dan kosong dibaca nol oleh penjumlahan
 * sesudahnya. Koreksi yang hilang senyap itu persis kelas kesalahan yang
 * dilarang di repo ini.
 *
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class TabelKalibratorSuhu3Alat
{
    public const ALAT_THERMOCOUPLE = 'thermocouple';

    public const ALAT_GLASS = 'thermometer_glass';

    public const ALAT_THERMOHYGRO = 'thermohygro';

    /** Merk kalibrator yang punya tabel koreksi & U95 sendiri. */
    public const MERK = ['constant', 'yokogawa'];

    public const MERK_TERCETAK = [
        'constant' => 'Constant 40T',
        'yokogawa' => 'Yokogawa CA 150 Handy Cal',
    ];

    /**
     * Tipe sensor STANDAR yang benar-benar bisa dipilih.
     *
     * `DATABASE!Q20:V23` mendaftar delapan baris (sampai Type J), tapi cuma tiga
     * teratas yang punya S/N, tanggal kalibrasi, dan ketertelusuran. Lima
     * sisanya baris kosong yang menunggu alat dibeli — memasukkannya ke dropdown
     * berarti teknisi bisa memilih standar yang tidak dimiliki lab.
     *
     * @var list<string>
     */
    public const TIPE_SENSOR_STANDAR = ['RTD', 'Type K', 'Type N'];

    /**
     * Faktor cakupan sertifikat kalibrator & sensor — kolom `Divisor` baris
     * `Ketidakpastian Baku Indikator Standar` (`Q20 = 2`).
     */
    public const K_SERTIFIKAT = 2.0;

    /**
     * Titik tabel TERDEKAT ke sebuah nilai — `MATCH(MIN(ABS(…)))` master.
     *
     * Seri sama dekat (nilai tepat di tengah dua titik) dimenangkan yang
     * PERTAMA di daftar, sama seperti `MATCH(…, 0)` Excel yang memulangkan
     * kecocokan pertama.
     */
    public function indeksTerdekat(string $alat, float $nilai): ?float
    {
        $indeks = $this->bagian($alat)['index_kalibrator'] ?? null;

        if (! is_array($indeks) || $indeks === []) {
            return null;
        }

        $terpilih = null;
        $jarak = null;

        foreach ($indeks as $titik) {
            $d = abs((float) $titik - $nilai);

            if ($jarak === null || $d < $jarak) {
                $jarak = $d;
                $terpilih = (float) $titik;
            }
        }

        return $terpilih;
    }

    /**
     * Koreksi kalibrator (`Koreksi STD Meter`) di titik tabel `$indeks`.
     *
     * `null` = tabelnya tidak memuat kombinasi itu. Bedakan dari `0.0`, yang
     * artinya lab memang mencatat koreksi nol di titik itu.
     */
    public function koreksiKalibrator(string $alat, string $merk, string $tipeSensor, float $indeks): ?float
    {
        return $this->selBaris($this->bagian($alat)['koreksi_kalibrator'][$merk] ?? [], $indeks, $tipeSensor);
    }

    /** U95 sertifikat kalibrator di titik tabel `$indeks` (belum dibagi k). */
    public function u95Kalibrator(string $alat, string $merk, string $tipeSensor, float $indeks): ?float
    {
        return $this->selBaris($this->bagian($alat)['u95_kalibrator'][$merk] ?? [], $indeks, $tipeSensor);
    }

    /** Koreksi probe sensor standar (`Koreksi STD Sensor`) — kolomnya per PROBE, bukan per tipe. */
    public function koreksiSensor(string $alat, string $probe, float $indeks): ?float
    {
        return $this->selBaris($this->bagian($alat)['koreksi_sensor'] ?? [], $indeks, $probe);
    }

    /** U95 sertifikat probe sensor standar di titik tabel `$indeks`. */
    public function u95Sensor(string $alat, string $probe, float $indeks): ?float
    {
        return $this->selBaris($this->bagian($alat)['u95_sensor'] ?? [], $indeks, $probe);
    }

    /**
     * Nama probe dari (tipe sensor standar, No. Termokopel) — peta dari rumus
     * `PERHITUNGAN FC!R23`.
     *
     * Rentang nomornya BEDA per tipe dan itu bukan gaya penulisan: Type K punya
     * 16 probe (`TCK-01`…`TCK-16`), Type N sepuluh yang penomorannya MULAI DARI
     * 3 (`TCN3`…`TCN12`), dan RTD cuma satu yang nomornya 17. Catatan di lembar
     * kerjanya sendiri menuliskannya: *"If using Thermocouple Type N, No.
     * Thermocouple START FROM 3. If using PRT PT100 (RTD), No. Thermocouple ALL
     * 17."*
     *
     * `null` = pasangan itu tidak menunjuk probe mana pun. Master memulangkan
     * `VLOOKUP(…, 100, 0)` yang error lalu dibungkus `IFNA(…,"")` — kolom
     * koreksi jadi KOSONG, dan kosong ikut dijumlah sebagai nol. Di sini
     * pemanggilnya memblokir titiknya; lihat docblock kelas.
     */
    public function probe(string $alat, string $tipeSensor, int $nomor): ?string
    {
        $peta = $this->bagian($alat)['probe_per_tipe'][$tipeSensor] ?? null;

        return is_array($peta) ? ($peta[(string) $nomor] ?? null) : null;
    }

    /**
     * Semua nomor probe yang sah untuk satu tipe sensor — buat dropdown lembar
     * kerja & pesan peringatan.
     *
     * @return list<int>
     */
    public function nomorProbeTersedia(string $alat, string $tipeSensor): array
    {
        $peta = $this->bagian($alat)['probe_per_tipe'][$tipeSensor] ?? [];

        $nomor = array_map(static fn (string $n): int => (int) $n, array_keys(is_array($peta) ? $peta : []));
        sort($nomor);

        return $nomor;
    }

    /** Drift kalibrator per tipe sensor (`Tabel_Drift_Constant` / `Tabel_Drift_Victor`). */
    public function driftKalibrator(string $alat, string $merk, string $tipeSensor): ?float
    {
        $nilai = $this->bagian($alat)['drift_kalibrator'][$merk][$tipeSensor] ?? null;

        return is_numeric($nilai) ? (float) $nilai : null;
    }

    /** Drift probe sensor standar (`Tabel_Drift_Sensor`). */
    public function driftSensor(string $alat, string $tipeSensor): ?float
    {
        $nilai = $this->bagian($alat)['drift_sensor'][$tipeSensor] ?? null;

        return is_numeric($nilai) ? (float) $nilai : null;
    }

    /**
     * Variasi aksial & antar-lubang dryblock (`Variasi axial Dryblok A/B`).
     *
     * @return array{axial_u: float, radial_max: float}|null
     */
    public function dryblock(string $kode): ?array
    {
        $b = $this->bagian(self::ALAT_THERMOCOUPLE)['dryblock'][strtoupper($kode)] ?? null;

        return is_array($b) ? ['axial_u' => (float) $b['axial_u'], 'radial_max' => (float) $b['radial_max']] : null;
    }

    /**
     * Variasi spasial & stabilitas oilbath (`Variasi Spasial & stab Oilbath`).
     *
     * @return array{variasi_spasial: float, stabilitas: float}|null
     */
    public function oilbath(string $kode): ?array
    {
        $b = $this->bagian(self::ALAT_GLASS)['oilbath'][strtolower($kode)] ?? null;

        return is_array($b)
            ? ['variasi_spasial' => (float) $b['variasi_spasial'], 'stabilitas' => (float) $b['stabilitas']]
            : null;
    }

    /**
     * Tipe pencelupan termometer gelas (`Tipe_Thermometer`) — tercetak di
     * sertifikat sebagai baris tersendiri di atas tabel hasil.
     *
     * @return list<array{nilai: int, label: string}>
     */
    public function tipeThermometer(): array
    {
        return array_map(
            static fn (array $t): array => ['nilai' => (int) $t['nilai'], 'label' => (string) $t['label']],
            $this->bagian(self::ALAT_GLASS)['tipe_thermometer'] ?? [],
        );
    }

    /** Sudut thermohygro: koreksi standar per titik suhu / kelembapan. */
    public function koreksiThermohygro(string $parameter, float $nilai): ?float
    {
        $baris = $this->bagian(self::ALAT_THERMOHYGRO)[$parameter === 'kelembaban' ? 'koreksi_rh' : 'koreksi_suhu'] ?? [];

        if ($baris === []) {
            return null;
        }

        $terpilih = null;
        $jarak = null;

        foreach ($baris as $b) {
            $d = abs((float) $b['titik'] - $nilai);

            if ($jarak === null || $d < $jarak) {
                $jarak = $d;
                $terpilih = (float) $b['koreksi'];
            }
        }

        return $terpilih;
    }

    /**
     * Angka tetap thermohygro (U95 kalibrator, drift, stabilitas & homogenitas
     * chamber) — semuanya konstanta lab, bukan turunan sesi.
     *
     * @return array<string, mixed>
     */
    public function thermohygro(): array
    {
        return $this->bagian(self::ALAT_THERMOHYGRO);
    }

    /**
     * CMC lembar kerja alat ini, apa adanya dari `DATABASE!R5:S7`.
     *
     * **Ini BUKAN yang dipakai jadi lantai U95.** Yang dipakai baris
     * `calibration_capabilities` dari lampiran akreditasi LK-285-IDN, karena itu
     * dokumen yang mengikat lab. Dua-duanya kebetulan sama persis untuk ketiga
     * alat ini (Thermocouple 0,84/1,5/3,3 · Gelas 0,58/1,0 · Thermohygro
     * 1,7/4,8), dan justru karena sama, kecocokannya bisa DIUJI — begitu lab
     * memperbarui salah satunya tanpa yang lain, test-nya merah dan bukan
     * sertifikatnya yang jadi korban.
     *
     * @return list<array{rentang: string, u: float}>
     */
    public function cmcMaster(string $alat): array
    {
        return array_map(
            static fn (array $c): array => [
                'rentang' => (string) ($c['rentang'] ?? $c['parameter'] ?? ''),
                'u' => (float) $c['u'],
            ],
            $this->bagian($alat)['cmc'] ?? [],
        );
    }

    /**
     * Ambil satu sel: baris ber-`titik` == `$indeks`, kolom `$kunci`.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    private function selBaris(array $baris, float $indeks, string $kunci): ?float
    {
        foreach ($baris as $b) {
            if (abs((float) $b['titik'] - $indeks) > 1e-9) {
                continue;
            }

            $nilai = $b[$kunci] ?? null;

            return is_numeric($nilai) ? (float) $nilai : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function bagian(string $alat): array
    {
        return $this->muat()[$alat] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function muat(): array
    {
        $berkas = database_path('data/tabel-master-suhu-3alat.json');

        if (! is_file($berkas)) {
            throw new RuntimeException(
                "Tabel standar suhu 3 alat nggak ketemu di {$berkas} — tanpa dia Thermocouple, Termometer "
                .'Gelas, & Thermohygrometer nggak bisa dihitung sama sekali.',
            );
        }

        // Paket `suhu_3alat`: cache di SumberAcuan, dikunci (versi, sha256) —
        // bukan statis di kelas ini.
        $isi = app(SumberAcuan::class)->isi('suhu_3alat', $berkas);

        if ($isi === []) {
            throw new RuntimeException("Tabel standar suhu 3 alat di {$berkas} bukan JSON yang sah.");
        }

        return $isi;
    }

    /** Dipakai test yang menukar berkas tabelnya. */
    public static function lupakan(): void
    {
        SumberAcuan::lupakanCache();
    }

    /**
     * Skema `tabel-master-suhu-3alat.json` untuk Studio Data Acuan (paket
     * `suhu_3alat`, dipakai ketiga profil lewat `ProfilSuhuPasangan`). Alamat
     * dibuktikan peta sel `database/data/peta-sel/suhu_3alat.json` terhadap
     * ketiga master .xlsm asli & ekspor CSV. Kolom tipe sensor/probe bertipe
     * `desimal_opsional`: tabel master JARANG — baris tanpa tipe yang tidak
     * dikalibrasi di titik itu tidak membawa kuncinya (bukan null, bukan nol).
     *
     * @return array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}
     */
    public static function skemaAcuan(): array
    {
        $kolom = static fn (string $kode, string $label, string $tipe, ?string $satuan, ?string $asal, bool $perlu = false): array => [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'satuan' => $satuan,
            'asal' => $asal,
            'perlu_konfirmasi' => $perlu,
        ];
        $lembar = static fn (string $kode, string $label, string $jalur, string $jenis, ?string $kunci, array $kol): array => [
            'kode' => $kode,
            'label' => $label,
            'jalur' => $jalur,
            'jenis' => $jenis,
            'kunci' => $kunci,
            'kolom' => $kol,
            'boleh_tambah_baris' => false,
        ];
        $huruf = static fn (int $k): string => Coordinate::stringFromColumnIndex($k);
        $SK = 'STANDAR KALIBRATOR';
        $tipe = ['RTD', 'Type K', 'Type N', 'Type B', 'Type T', 'Type R', 'Type S', 'Type J'];
        $probe = ['RTD'];

        for ($i = 1; $i <= 16; $i++) {
            $probe[] = sprintf('TCK-%02d', $i);
        }

        for ($i = 3; $i <= 12; $i++) {
            $probe[] = "TCN{$i}";
        }

        $daftar = [];
        $nama = [self::ALAT_THERMOCOUPLE => 'Thermocouple', self::ALAT_GLASS => 'Thermometer Glass'];

        foreach ($nama as $alat => $n) {
            $kolIndeks = [$kolom('index_kalibrator', 'Titik indeks kalibrator', 'daftar_angka', '°C', "{$SK}!B10:B27"),
                $kolom('_sumber', 'Catatan sumber', 'teks', null, null)];

            if ($alat === self::ALAT_THERMOCOUPLE) {
                $kolIndeks[] = $kolom('_yokogawa_dari_tautan_luar', 'Catatan tautan luar Yokogawa', 'teks', null, null);
                $kolIndeks[] = $kolom('stabilitas_cold_junction', 'Stabilitas cold junction', 'desimal', '°C', 'PERHITUNGAN U95%!N22');
                $kolIndeks[] = $kolom('ac_pick_up', 'AC pick-up', 'desimal', '°C', 'PERHITUNGAN U95%!N25');
            }

            $daftar[] = $lembar("{$alat}_umum", "{$n} — indeks & konstanta", $alat, 'objek', null, $kolIndeks);

            foreach (['koreksi_kalibrator' => ['Koreksi', 10, 27], 'u95_kalibrator' => ['U95', 32, 49]] as $bagian => [$lb, $b0, $b1]) {
                foreach (['constant' => ['Constant 40T', 3], 'yokogawa' => ['Yokogawa CA 150', 14]] as $merk => [$labelMerk, $k0]) {
                    // Thermocouple: tabel Yokogawa = nama rentang ke TAUTAN LUAR
                    // ('[4]STANDAR KALIBRATOR'), bukan sel lembar ini — tidak dipetakan.
                    $luar = $alat === self::ALAT_THERMOCOUPLE && $merk === 'yokogawa';
                    $kol = [$kolom('titik', 'Titik kalibrasi', 'desimal', '°C', $luar ? null : "{$SK}!B{$b0}:B{$b1}")];

                    foreach ($tipe as $j => $t) {
                        $h = $huruf($k0 + $j);
                        $kol[] = $kolom($t, $t, 'desimal_opsional', '°C', $luar ? null : "{$SK}!{$h}{$b0}:{$h}{$b1}");
                    }

                    $daftar[] = $lembar("{$alat}_{$bagian}_{$merk}", "{$n} — {$lb} kalibrator {$labelMerk}", "{$alat}/{$bagian}/{$merk}", 'daftar', 'titik', $kol);
                }
            }

            foreach (['koreksi_sensor' => ['Koreksi', 64, 78], 'u95_sensor' => ['U95', 85, 99]] as $bagian => [$lb, $b0, $b1]) {
                $kol = [$kolom('titik', 'Titik kalibrasi', 'desimal', '°C', "{$SK}!B{$b0}:B{$b1}")];

                foreach ($probe as $j => $p) {
                    $h = $huruf(3 + $j);
                    $kol[] = $kolom($p, $p, 'desimal_opsional', '°C', "{$SK}!{$h}{$b0}:{$h}{$b1}");
                }

                $daftar[] = $lembar("{$alat}_{$bagian}", "{$n} — {$lb} sensor standar", "{$alat}/{$bagian}", 'daftar', 'titik', $kol);
            }

            foreach (['constant' => 'Y', 'yokogawa' => 'AA'] as $merk => $kv) {
                $daftar[] = $lembar("{$alat}_drift_kalibrator_{$merk}", "{$n} — drift kalibrator {$merk}", "{$alat}/drift_kalibrator/{$merk}", 'objek', null, [
                    $kolom('RTD', 'PRT PT100', 'desimal', '°C', "{$SK}!{$kv}6"),
                    $kolom('Type N', 'Thermocouple Type N', 'desimal', '°C', "{$SK}!{$kv}7"),
                    $kolom('Type K', 'Thermocouple Type K', 'desimal', '°C', "{$SK}!{$kv}8"),
                ]);
            }

            $daftar[] = $lembar("{$alat}_drift_sensor", "{$n} — drift sensor standar", "{$alat}/drift_sensor", 'objek', null, [
                $kolom('RTD', 'PRT PT100', 'desimal', '°C', "{$SK}!Y12"),
                $kolom('Type N', 'Thermocouple Type N', 'desimal', '°C', "{$SK}!Y13"),
                $kolom('Type K', 'Thermocouple Type K', 'desimal', '°C', "{$SK}!Y14"),
            ]);

            // Nomor termokopel -> probe: turunan rumus PERHITUNGAN FC!R23, bukan tabel sel.
            foreach (['Type K' => 'type_k', 'Type N' => 'type_n', 'RTD' => 'rtd'] as $t => $kt) {
                $daftar[] = $lembar("{$alat}_probe_{$kt}", "{$n} — probe {$t} per nomor", "{$alat}/probe_per_tipe/{$t}", 'baris', 'nomor', [
                    $kolom('nomor', 'No. termokopel', 'teks', null, null),
                    $kolom('probe', 'Probe', 'teks', null, null),
                ]);
            }
        }

        foreach (['A', 'B'] as $b) {
            $daftar[] = $lembar('thermocouple_dryblock_'.strtolower($b), "Thermocouple — dryblock {$b}", "thermocouple/dryblock/{$b}", 'objek', null, [
                $kolom('axial_u', 'Ketidakpastian axial', 'desimal', '°C', "Variasi axial Dryblok {$b}!G28"),
                $kolom('radial_max', 'Variasi antar lubang (maks)', 'desimal', '°C', "Variasi axial Dryblok {$b}!B39"),
            ]);
        }

        $daftar[] = $lembar('thermocouple_cmc', 'Thermocouple — CMC master', 'thermocouple/cmc', 'daftar', 'rentang', [
            $kolom('rentang', 'Rentang', 'teks', '°C', 'DATABASE!R5:R7'),
            $kolom('u', 'CMC', 'desimal', '°C', 'DATABASE!S5:S7'),
        ]);

        foreach (['satu' => ['C', 'D'], 'dua' => ['M', 'N']] as $ob => [$kv, $ks]) {
            $daftar[] = $lembar("thermometer_glass_oilbath_{$ob}", "Thermometer Glass — oilbath {$ob}", "thermometer_glass/oilbath/{$ob}", 'objek', null, [
                $kolom('variasi_spasial', 'Variasi spasial (maks)', 'desimal', '°C', "Variasi Spasial & stab Oilbath!{$kv}12"),
                $kolom('stabilitas', 'Stabilitas (maks)', 'desimal', '°C', "Variasi Spasial & stab Oilbath!{$ks}12"),
            ]);
        }

        $daftar[] = $lembar('thermometer_glass_tipe', 'Thermometer Glass — tipe pencelupan', 'thermometer_glass/tipe_thermometer', 'daftar', 'label', [
            $kolom('nilai', 'Nilai', 'desimal', null, 'DATABASE!Q32:Q34'),
            $kolom('label', 'Tipe', 'teks', null, 'DATABASE!R32:R34'),
        ]);
        $daftar[] = $lembar('thermometer_glass_cmc', 'Thermometer Glass — CMC master', 'thermometer_glass/cmc', 'daftar', 'rentang', [
            $kolom('rentang', 'Rentang', 'teks', '°C', 'DATABASE!R5:R6'),
            $kolom('u', 'CMC', 'desimal', '°C', 'DATABASE!S5:S6'),
        ]);

        $ST = 'STANDAR_KALIBRATOR';
        $CH = 'stabilitas dan homogenitas Cham';
        $daftar[] = $lembar('thermohygro_umum', 'Thermohygro — catatan', 'thermohygro', 'objek', null, [
            $kolom('_sumber', 'Catatan sumber', 'teks', null, null),
        ]);
        $daftar[] = $lembar('thermohygro_koreksi_suhu', 'Thermohygro — koreksi standar suhu', 'thermohygro/koreksi_suhu', 'daftar', 'titik', [
            $kolom('titik', 'Titik', 'desimal', '°C', "{$ST}!D20:D26"),
            $kolom('koreksi', 'Koreksi', 'desimal', '°C', "{$ST}!E20:E26"),
        ]);
        $daftar[] = $lembar('thermohygro_koreksi_rh', 'Thermohygro — koreksi standar kelembapan', 'thermohygro/koreksi_rh', 'daftar', 'titik', [
            $kolom('titik', 'Titik', 'desimal', '%RH', "{$ST}!D6:D14"),
            $kolom('koreksi', 'Koreksi', 'desimal', '%RH', "{$ST}!E6:E14"),
        ]);
        $daftar[] = $lembar('thermohygro_u95', 'Thermohygro — U95 kalibrator', 'thermohygro/u95_kalibrator', 'objek', null, [
            $kolom('suhu', 'Suhu', 'desimal', '°C', "{$ST}!E27"),
            $kolom('rh', 'Kelembapan', 'desimal', '%RH', "{$ST}!E15"),
        ]);
        $daftar[] = $lembar('thermohygro_drift', 'Thermohygro — drift kalibrator', 'thermohygro/drift_kalibrator', 'objek', null, [
            $kolom('suhu', 'Suhu', 'desimal', '°C', "{$ST}!G4"),
            $kolom('rh', 'Kelembapan', 'desimal', '%RH', "{$ST}!I4"),
        ]);

        foreach ([
            'biobase_suhu' => ['Biobase suhu', 'B14', 'C14', '°C'],
            'biobase_rh' => ['Biobase kelembapan', 'F13', 'G13', '%RH'],
            'gea_rh' => ['Gea kelembapan', 'F30', 'G30', '%RH'],
        ] as $ch => [$label, $sh, $ss, $sat]) {
            $daftar[] = $lembar("thermohygro_chamber_{$ch}", "Thermohygro — chamber {$label}", "thermohygro/chamber/{$ch}", 'objek', null, [
                $kolom('homogenitas', 'Homogenitas (maks)', 'desimal', $sat, "{$CH}!{$sh}"),
                $kolom('stabilitas', 'Stabilitas (maks)', 'desimal', $sat, "{$CH}!{$ss}"),
            ]);
        }

        $daftar[] = $lembar('thermohygro_cmc', 'Thermohygro — CMC master', 'thermohygro/cmc', 'daftar', 'parameter', [
            $kolom('parameter', 'Parameter', 'teks', null, 'DATABASE!R5:R6'),
            $kolom('u', 'CMC', 'desimal', null, 'DATABASE!S5:S6'),
        ]);

        return ['skema_versi' => 1, 'sumber_json' => 'tabel-master-suhu-3alat.json', 'lembar' => $daftar];
    }
}
