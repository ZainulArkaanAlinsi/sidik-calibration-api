<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Formula;
use App\Models\Standard;
use App\Services\Calibration\TabelKalibratorSuhu;
use App\Services\Calibration\TitsCalculator;
use Carbon\Carbon;

/**
 * Profil TITS — **Temperature Indikator Tanpa Sensor** (alat ke-11). Metode
 * `SIDIK-IK-CAL-0502_Rev.3` (`DATABASE!C70`), form sertifikat
 * `SIDIK-FM-CAL-2403_Rev. 0`.
 *
 * Master: `Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` (sesi
 * **01-CAL-625**, order 22506.01.A, PT Sistem Dirgantara Inovasi Teknologi,
 * Graphtech GL840, 8 Mei 2025) dan `… fungsi Source utk UUT.xlsm` (sesi
 * **0159-CAL-626**, order 2606.08.C, PT GE Nusantara Turbine Services, Siemens
 * Simatic IPC477FE, 10 Juni 2026).
 *
 * ## "Tanpa sensor" itu bagian dari nama alat, bukan keterangan
 *
 * Yang dikalibrasi indikator/recorder/controller suhu yang menerima sinyal
 * termokopel atau RTD dari luar — sensornya sendiri TIDAK ikut. Kalibratornya
 * yang berperan sebagai sensor tiruan. Karena itu tiap sesi harus tahu **tipe
 * sensor** yang disimulasikan (Type K/N/B/T/R/S/J atau RTD): koreksi kalibrator,
 * ketidakpastiannya, drift-nya, dan CMC lab semuanya berbeda per tipe.
 *
 * Saudaranya, TIDS (Temperature Indikator **Dengan** Sensor,
 * `SIDIK-IK-CAL-0503`), alat yang lain lagi dan belum dibuat.
 *
 * ## Dua MODE, dan mode itu milik sesi — bukan milik alat
 *
 * Satu indikator bisa dikalibrasi dua-duanya, dan lab menerbitkan dua sertifikat
 * dengan dua nomor:
 *
 *  - **Measure** — UUT membaca; kalibrator men-source sinyal. Yang diulang
 *    enam kali bacaan UUT.
 *  - **Source** — UUT men-source; kalibrator yang membaca. Yang diulang enam
 *    kali bacaan kalibrator, dan setpoint-nya justru angka di UUT.
 *
 * Karena itu mode disimpan di `calibration_sessions.mode_kalibrasi`, bukan di
 * master alat. Arah perhitungannya berbalik antara keduanya — lihat
 * [TitsCalculator].
 *
 * ## Kenapa profil ini mengambil alih [hitungPerGrup]
 *
 * `PERHITUNGAN U95%` cuma punya SATU tabel budget untuk seluruh sesi, dan
 * komponen keterulangannya diberi makan STDEV TERBESAR dari semua titik
 * (`M23 = MAX(K23:L40)`). Sembilan titik keluar dengan U95 yang sama, dan angka
 * itu tidak bisa diturunkan dari satu titik saja — persis alasan
 * Spectrophotometer mengambil alih hook yang sama.
 *
 * ## Titiknya TIDAK tetap
 *
 * Beda dari sepuluh alat sebelumnya yang titiknya konstanta (pH 4/7/10, gas
 * 101/25/50/17,9). Titik TITS ditentukan rentang UUT yang datang: sesi contoh
 * measure memakai −20…1000 sembilan titik, sesi source 0…1200 delapan titik.
 * [TITIK_SARAN] cuma saran awal di layar; yang berlaku angka yang diketik
 * teknisi.
 *
 * ## UP & DOWN
 *
 * Tiap titik dibaca naik tiga kali lalu turun tiga kali. Master merata-rata dan
 * mencari STDEV atas keenamnya sekaligus, jadi di sini UP/DOWN cuma urutan:
 * `pembacaan_ke` 1–3 UP, 4–6 DOWN. Histeresis tidak dilaporkan terpisah di
 * sertifikat TITS.
 *
 * ## Yang TIDAK divonis
 *
 * Master tidak punya satu pun sel yang membandingkan hasil ke batas
 * keberterimaan, dan sertifikatnya berhenti di baris `Uncertainty 95%`. Sama
 * seperti Autoklaf, DO Meter, dan Gas Detector: [punyaToleransi] `false`,
 * `keputusan` sesi null.
 *
 * ## Kejanggalan master
 *
 * Empat yang ditiru (dengan catatan audit) dan satu yang tidak — semuanya
 * diuraikan di [TitsCalculator]. Dua lagi yang murni soal cetakan:
 *
 *  1. **`k` dicetak nol desimal** (`SERTIFIKAT!O30` format `0`). `k = 2,40`
 *     tercetak `2`, dan pembaca sertifikat tidak punya cara tahu bedanya dari
 *     `k = 2` yang sebenarnya. Ditiru lewat [desimalFaktorCakupan] karena itu
 *     bentuk dokumen yang sudah terbit; ditanyakan ke lab.
 *  2. **Desimal `U95` beda antar dua workbook** — `0.00` di Measure, `0.0` di
 *     Source. Yang dipakai DUA untuk keduanya ([desimalU95]): 1,2 tercetak
 *     `1,20` (tidak mengubah nilai), sementara memaksa Source ke satu desimal
 *     akan membulatkan U95 measure 0,853 jadi `0,9`.
 *
 * @see TitsCalculator
 * @see TabelKalibratorSuhu
 * @see docs/pertanyaan-lab-tits.md
 */
class TitsProfile extends CalibrationProfile
{
    /**
     * Nomor formulir LEMBAR KERJA-nya belum ada.
     *
     * Seluruh berkas cuma memuat `SIDIK-FM-CAL-2403_Rev. 0` di footer sheet
     * `SERTIFIKAT` — itu formulir sertifikat, dipakai bersama semua alat, bukan
     * lembar kerjanya. Sengaja `null`, bukan ditebak: nomor formulir itu dokumen
     * terkendali, dan menaruh nomor karangan di lembar yang ikut diaudit lebih
     * mahal daripada kolom kosong yang jelas kosong. Sama seperti Gas Detector.
     */
    public const KODE_DOKUMEN = null;

    /** Metode kalibrasi, `DATABASE!C70` baris 2 — yang tercetak di sertifikat. */
    public const KODE_METODE = 'SIDIK-IK-CAL-0502_Rev.3';

    /** Satuan tunggal seluruh alat ini. */
    public const SATUAN = '°C';

    /**
     * Dua arah pembacaan per titik: UP lalu DOWN.
     *
     * Dipakai [TitsCalculator] untuk menurunkan jumlah pengulangan PER ARAH dari
     * jumlah pembacaan total — master menulis `Q23 = 3` (bukan 6) sebagai
     * pembagi & derajat kebebasan Type A.
     */
    public const ARAH_PER_TITIK = 2;

    /** Pembacaan per arah di master (`INPUT DATA` D/F/H) — UP 3×, DOWN 3×. */
    public const PENGULANGAN_PER_ARAH = 3;

    /**
     * Judul kolom NILAI per mode — `INPUT DATA` B31 di kedua workbook.
     *
     * @var array<string, string>
     */
    public const JUDUL_NILAI = [
        TabelKalibratorSuhu::MODE_MEASURE => 'Standard Indication',
        TabelKalibratorSuhu::MODE_SOURCE => 'UUT Indication',
    ];

    /**
     * Judul kolom PENGULANGAN per mode — `INPUT DATA` D31.
     *
     * @var array<string, string>
     */
    public const JUDUL_PENGULANGAN = [
        TabelKalibratorSuhu::MODE_MEASURE => 'Reading Unit Under Test',
        TabelKalibratorSuhu::MODE_SOURCE => 'Reading Standard',
    ];

    /**
     * Titik yang muncul sebagai SARAN awal di lembar kerja — dari sesi measure
     * contoh (`INPUT DATA` B33:B49).
     *
     * Bukan daftar tertutup: rentang UUT beda-beda, dan sesi source contoh
     * memakai deret yang lain sama sekali (0…1200 kelipatan 200). Teknisi boleh
     * menambah, menghapus, dan mengubah angkanya — lihat `titik_bisa_diubah` di
     * [bentukLembarKerja].
     *
     * @var list<float>
     */
    public const TITIK_SARAN = [-20.0, 10.0, 50.0, 100.0, 200.0, 400.0, 600.0, 800.0, 1000.0];

    /**
     * Tipe sensor → label `parameter` baris CMC di lampiran akreditasi
     * (`database/data/kemampuan-kalibrasi.json`, kelompok "Suhu dan
     * Kelembapan", alat "Temperature Indicator tanpa Sensor").
     *
     * Perlu peta karena dua dokumen menamai barang yang sama dengan cara
     * berbeda: master Excel menulis `Type K` di dropdown enclosure, lampiran
     * akreditasi menulis `Thermocouple sensor type K`. Yang dipakai jadi kunci
     * di seluruh modul ini ejaan master (itu yang diketik teknisi); yang dipakai
     * mencari baris CMC ejaan lampiran (itu yang mengikat lab).
     *
     * `Type B` tidak punya baris CMC sama sekali — lab belum mengklaimnya, dan
     * tabel kalibratornya pun setengah kosong. Sesi Type B tetap bisa disimpan,
     * tapi tidak akan menghasilkan U95 sampai barisnya ada.
     *
     * @var array<string, string>
     */
    public const PARAMETER_CMC = [
        'Type K' => 'Thermocouple sensor type K',
        'Type J' => 'Thermocouple sensor type J',
        'Type T' => 'Thermocouple sensor type T',
        'Type N' => 'Thermocouple sensor type N',
        'Type R' => 'Thermocouple sensor type R',
        'Type S' => 'Thermocouple sensor type S',
        'RTD' => 'Resistance sensor',
    ];

    /**
     * Rentang berlaku CMC per tipe sensor — dipakai teks peringatan saja; angka
     * CMC yang masuk hitungan dibaca dari master `calibration_capabilities`
     * (`CalibrationCapabilitySeeder`), supaya lab bisa memperbaruinya tanpa
     * rilis kode.
     *
     * Diambil dari LAMPIRAN AKREDITASI, bukan dari `DATABASE!T5:T11` master
     * Excel — dan keduanya berbeda di satu baris: sensor resistif (`RTD`)
     * tertulis **−20…800 °C** di lampiran, **−10…800 °C** di master. Yang
     * dipakai lampiran: itu dokumen yang mengikat lab, dan yang lebih longgar
     * di sini justru yang resmi. Ditanyakan ke lab.
     *
     * @var array<string, array{cmc: float, min: float, maks: float}>
     */
    public const CMC_TIPE_SENSOR = [
        'Type K' => ['cmc' => 0.63, 'min' => -20.0, 'maks' => 1000.0],
        'Type J' => ['cmc' => 0.63, 'min' => 0.0, 'maks' => 1000.0],
        'Type T' => ['cmc' => 0.56, 'min' => -20.0, 'maks' => 400.0],
        'Type N' => ['cmc' => 0.83, 'min' => -20.0, 'maks' => 1000.0],
        'Type R' => ['cmc' => 1.0, 'min' => 0.0, 'maks' => 1000.0],
        'Type S' => ['cmc' => 1.2, 'min' => 0.0, 'maks' => 1000.0],
        'RTD' => ['cmc' => 0.5, 'min' => -20.0, 'maks' => 800.0],
    ];

    /**
     * Baris STANDARD yang tercetak di lembar kerja.
     *
     * Dua kalibrator, dan yang dipakai sesi ini yang dicentang teknisi — merk-nya
     * yang menentukan tabel koreksi mana yang dibaca ([TabelKalibratorSuhu]).
     *
     * @var list<array{label: string, cocok: list<string>}>
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'Temperature Calibrator Constant 40T', 'cocok' => ['Temperature Calibrator Constant 40T', '99875850']],
        ['label' => 'Temperature Calibrator Yokogawa CA 150 Handy Cal', 'cocok' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005']],
    ];

    private ?TitsCalculator $kalkulator = null;

    public function __construct(private readonly TabelKalibratorSuhu $tabel = new TabelKalibratorSuhu) {}

    public function kode(): string
    {
        return 'tits';
    }

    /**
     * Ejaan PERSIS seperti di lampiran akreditasi — huruf kecil di "tanpa".
     *
     * Ini kunci pencocokan ke `equipments.nama_alat_kemampuan` DAN ke
     * `calibration_capabilities.nama_alat`; beda satu huruf berarti alatnya
     * jatuh ke profil default (pH) tanpa error apa pun.
     */
    public function namaAlatKemampuan(): string
    {
        return 'Temperature Indicator tanpa Sensor';
    }

    public function kodeFormula(): string
    {
        return Formula::KODE_GUM_TITS;
    }

    public function besaran(): string
    {
        return 'suhu';
    }

    /**
     * Tidak divonis PASS/FAIL — master tidak punya kolom batas keberterimaan.
     * Lihat docblock kelas.
     */
    public function punyaToleransi(): bool
    {
        return false;
    }

    /**
     * SATU U95 untuk seluruh sesi, dicetak sebagai baris di bawah tabel
     * (`SERTIFIKAT!E29 = 'Uncertainty 95% ±'`), bukan kolom per titik. Itu
     * memang bentuk budget-nya: satu tabel `PERHITUNGAN U95%` untuk semua titik.
     */
    public function u95PerTitik(): bool
    {
        return false;
    }

    /** Satuan tunggal — `°C` di seluruh kolom, kedua mode. */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return self::SATUAN;
    }

    /**
     * Kolom hasil sertifikat: SATU desimal (`SERTIFIKAT!E20:L28` format `0.0`,
     * sama di kedua workbook).
     */
    public function desimalSertifikat(): ?int
    {
        return 1;
    }

    /**
     * `U95`: DUA desimal. Master Measure menulis `0.00`, master Source `0.0` —
     * yang dipakai yang lebih teliti. Lihat kejanggalan nomor 2 di docblock
     * kelas.
     */
    public function desimalU95(): ?int
    {
        return 2;
    }

    /**
     * `k` NOL desimal, mengikuti `SERTIFIKAT!O30` (format `0`).
     *
     * Ditiru karena itu bentuk sertifikat yang sudah terbit, walau artinya
     * `k = 2,40` tercetak `2`. Ditanyakan ke lab di
     * `docs/pertanyaan-lab-tits.md`.
     */
    public function desimalFaktorCakupan(): ?int
    {
        return 0;
    }

    /** `PERHITUNGAN FC!P15` format `0.0` — satu desimal untuk suhu ruang. */
    public function desimalSuhuEnv(): ?int
    {
        return 1;
    }

    /** Kelembaban ikut sel yang sama, `P16` format `0.0`. */
    public function desimalKelembabanEnv(): ?int
    {
        return 1;
    }

    /**
     * Kalibrator dibaca NOMINAL — tidak ada kurva suhu larutan di alat ini.
     * Koreksi kalibratornya datang dari tabel sertifikatnya sendiri, bukan dari
     * polinomial suhu.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return false;
    }

    /**
     * Baris `Mode : …` yang tercetak persis di atas tabel hasil
     * (`SERTIFIKAT!E18 = CONCATENATE("Mode : Measure ", 'INPUT DATA'!X7)`).
     *
     * Bukan hiasan: dua sertifikat alat yang sama, dengan angka yang sama
     * bentuknya, hanya dibedakan baris ini dan nomor sertifikatnya.
     */
    public function catatanAtasTabelHasil(CalibrationSession $sesi): ?string
    {
        $mode = $this->modeSesi($sesi);
        $tipe = $this->tipeSensorSesi($sesi);

        if ($mode === null) {
            return null;
        }

        return trim(sprintf('Mode : %s %s', $mode === TabelKalibratorSuhu::MODE_MEASURE ? 'Measure' : 'Source', $tipe ?? ''));
    }

    /**
     * SENGAJA `null` — alat ini tidak punya budget per titik. U95-nya lahir
     * sekali untuk seluruh sesi lewat [hitungPerGrup]. Balik `null` bikin
     * `GumCalculator::hitungTitik()` jatuh ke jalur CMC apa adanya kalau suatu
     * saat terpanggil untuk alat ini, bukan menyusun budget karangan yang
     * kelihatan sah.
     */
    public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
        array $konteksTitik = [],
    ): ?array {
        return null;
    }

    /**
     * Hitung seluruh sesi sekaligus — pengganti jalur per-titik untuk alat ini.
     *
     * Mode & tipe sensor dibaca dari `konteks` tiap titik, bukan dari master
     * alat: dua-duanya milik SESI (satu indikator dikalibrasi dua mode, dan bisa
     * dengan tipe sensor berbeda tiap kali). Pemanggil mengisinya dari
     * `calibration_sessions.mode_kalibrasi` & `.tipe_sensor`.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard, konteks?: array<string, mixed>}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        if ($titik === []) {
            return ['hitungan' => [], 'belum_dihitung' => []];
        }

        $titik = array_values($titik);
        $konteks = $titik[0]['konteks'] ?? [];
        $mode = $this->normalkanMode($konteks['mode_tits'] ?? null);
        $tipeSensor = $this->normalkanTipeSensor($konteks['tipe_sensor'] ?? null);
        $standar = $titik[0]['standard'];
        $merk = $this->merkKalibrator($standar);

        // Tanpa salah satu dari tiga hal ini, hitungannya BUKAN cuma kurang
        // teliti — dia mengarang. Yang dilaporkan alasannya per titik, bukan
        // pengecualian yang menghentikan seluruh permintaan: teknisi masih boleh
        // menyimpan lembar kerja yang belum lengkap.
        $kurang = $this->syaratKurang($mode, $tipeSensor, $merk, $standar);

        if ($kurang !== null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    static fn (array $t): array => ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $kurang],
                    $titik,
                ),
            ];
        }

        $kemampuan = $this->kemampuanTipeSensor($equipment, $tipeSensor);

        if ($kemampuan === null) {
            return [
                'hitungan' => [],
                'belum_dihitung' => array_map(
                    static fn (array $t): array => [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => isset(self::PARAMETER_CMC[$tipeSensor])
                            ? sprintf(
                                'CMC buat sensor %s ("%s") belum ada di master kemampuan kalibrasi — jalankan '
                                .'CalibrationCapabilitySeeder dulu.',
                                $tipeSensor,
                                self::PARAMETER_CMC[$tipeSensor],
                            )
                            : sprintf(
                                'Lab belum punya klaim CMC buat sensor %s — lampiran akreditasi cuma memuat '
                                .'Type K/J/T/N/R/S dan Resistance sensor. Sesinya boleh disimpan, tapi U95-nya '
                                .'nggak bisa diterbitkan.',
                                $tipeSensor,
                            ),
                    ],
                    $titik,
                ),
            ];
        }

        // Titik yang tidak punya baris di tabel koreksi kalibrator dibuang
        // dengan alasan yang kebaca, bukan dipaksa memakai koreksi nol.
        $siap = [];
        $belumDihitung = [];

        foreach ($titik as $t) {
            $setpoint = (float) $t['titik_ukur'];

            if ($this->tabel->koreksi($mode, $merk, $tipeSensor, $setpoint) === null) {
                $belumDihitung[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Tabel koreksi kalibrator %s mode %s untuk sensor %s nggak punya satu pun titik — '
                        .'titik %s °C nggak bisa dikoreksi.',
                        TabelKalibratorSuhu::MERK_TERCETAK[$merk] ?? $merk,
                        $mode,
                        $tipeSensor,
                        $setpoint,
                    ),
                ];

                continue;
            }

            $siap[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => $setpoint,
                'pembacaan' => $t['pembacaan'],
            ];
        }

        if ($siap === []) {
            usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return ['hitungan' => [], 'belum_dihitung' => $belumDihitung];
        }

        $hasil = ($this->kalkulator ??= new TitsCalculator($this->tabel))->hitungSesi($siap, [
            'mode' => $mode,
            'merk_kalibrator' => $merk,
            'tipe_sensor' => $tipeSensor,
            'resolusi' => (float) $equipment->resolusi,
            'cmc' => (float) $kemampuan->ketidakpastian_terbaik,
        ]);

        $hitungan = $this->barisHitungan($hasil, $standar, $kemampuan);

        usort($hitungan, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);
        usort($belumDihitung, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return ['hitungan' => $hitungan, 'belum_dihitung' => $belumDihitung];
    }

    /**
     * Peringatan yang muncul di layar teknisi & admin SEBELUM sertifikat terbit.
     *
     * Tiga hal yang dijaga, dan ketiganya jenis kesalahan yang tidak memunculkan
     * error di mana pun kalau lolos:
     *
     *  1. **Mode atau tipe sensor belum diisi.** Tanpa keduanya sesi tidak
     *     kehitung sama sekali — lebih baik teknisi tahu sekarang, bukan waktu
     *     sertifikatnya ditahan admin.
     *  2. **Titik di luar rentang RLK tipe sensor itu.** CMC Type T cuma berlaku
     *     sampai 400 °C; titik 600 °C di sesi Type T tetap keluar angkanya, tapi
     *     angka itu di luar yang diakui akreditasi.
     *  3. **Titik jauh dari titik tabel kalibrator terdekat.** Koreksi diambil
     *     dari titik tabel TERDEKAT tanpa interpolasi — setpoint 1100 dengan
     *     tabel yang cuma punya 1000 & 1200 mengambil koreksi titik 100 °C
     *     jauhnya, dan itu tidak kelihatan dari angka manapun di sertifikat.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $peringatan = [];
        $mode = $this->modeSesi($sesi);
        $tipeSensor = $this->tipeSensorSesi($sesi);

        if ($mode === null) {
            $peringatan[] = [
                'kode' => 'tits_mode_kosong',
                'pesan' => 'Mode kalibrasi (Measure / Source) belum dipilih. Arah perhitungan koreksi berbalik '
                    .'antara keduanya, jadi tanpa ini sesinya nggak bisa dihitung sama sekali.',
            ];
        }

        if ($tipeSensor === null) {
            $peringatan[] = [
                'kode' => 'tits_tipe_sensor_kosong',
                'pesan' => 'Tipe sensor (Type K/N/B/T/R/S/J atau RTD) belum dipilih. Koreksi kalibrator, '
                    .'ketidakpastiannya, drift-nya, dan CMC lab semuanya beda per tipe.',
            ];
        }

        if ($mode === null || $tipeSensor === null) {
            return $peringatan;
        }

        $rlk = self::CMC_TIPE_SENSOR[$tipeSensor] ?? null;
        $merk = $this->merkKalibrator($sesi->rawMeasurements->first()?->standard);

        foreach ($sesi->uncertaintyCalculations as $baris) {
            $setpoint = (float) $baris->titik_ukur;

            if ($rlk !== null && ($setpoint < $rlk['min'] || $setpoint > $rlk['maks'])) {
                $peringatan[] = [
                    'kode' => 'tits_titik_luar_rlk',
                    'pesan' => sprintf(
                        'Titik ke-%d (%s °C) di luar rentang RLK sensor %s (%s…%s °C) — angkanya tetap keluar '
                        .'tapi di luar yang diakui akreditasi.',
                        $baris->titik_ke,
                        $setpoint,
                        $tipeSensor,
                        $rlk['min'],
                        $rlk['maks'],
                    ),
                ];
            }

            if ($merk === null) {
                continue;
            }

            $koreksi = $this->tabel->koreksi($mode, $merk, $tipeSensor, $setpoint);

            if ($koreksi !== null && abs($koreksi['titik'] - $setpoint) > self::JARAK_TITIK_WAJAR) {
                $peringatan[] = [
                    'kode' => 'tits_titik_jauh_dari_tabel',
                    'pesan' => sprintf(
                        'Titik ke-%d (%s °C) mengambil koreksi kalibrator dari titik tabel %s °C — selisih %s °C. '
                        .'Tabelnya nggak diinterpolasi, jadi koreksi %s °C dipakai utuh.',
                        $baris->titik_ke,
                        $setpoint,
                        $koreksi['titik'],
                        round(abs($koreksi['titik'] - $setpoint), 3),
                        $koreksi['nilai'],
                    ),
                ];
            }
        }

        return $peringatan;
    }

    /**
     * Selisih setpoint ke titik tabel kalibrator yang masih dianggap wajar.
     *
     * 50 °C: cukup longgar untuk setpoint bulat yang meleset dari titik tabel
     * (995 vs 1000), cukup ketat untuk menangkap setpoint yang jatuh di tengah
     * dua titik tabel yang berjauhan — 1100 di antara 1000 & 1200 kena, dan
     * memang itu kasus yang paling berbahaya.
     */
    public const JARAK_TITIK_WAJAR = 50.0;

    public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array
    {
        $bentuk = $this->bentukLengkap();

        if ($untukAdmin) {
            $bentuk['bagian'][] = $this->bagianAdmin();
        }

        return $this->tautkanStandarTitik($this->tautkanStandar($bentuk));
    }

    /**
     * Kalibrator yang menempel ke tiap baris tabel sebagai NILAI AWAL.
     *
     * Beda arti dari sembilan alat lain: di sana tiap titik memang punya
     * standarnya sendiri (buffer pH 4 tidak bisa dipakai untuk titik 7). Di sini
     * satu kalibrator melayani semua titik, dan yang dipilih ditentukan
     * teknisi lewat "Usage Check" — pasangan ini cuma mengisi kotaknya supaya
     * lembar kerja tidak datang dengan sembilan baris ber-`standard_id` kosong
     * yang harus diisi satu per satu.
     *
     * **Yang dipasang Yokogawa**, bukan Constant, dan itu bukan urutan abjad:
     * kedua sesi master memakainya, dan sertifikat Constant 40T sudah lewat masa
     * berlaku (28 Agustus 2025). Kalau lab memakai Constant, teknisi mengganti
     * pilihannya — dan `TitsProfile` membaca merk dari standar yang benar-benar
     * dikirim, bukan dari daftar ini.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return array_map(
            static fn (float $t): array => [
                'titik' => $t,
                'standar' => ['Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005'],
            ],
            self::TITIK_SARAN,
        );
    }

    /**
     * Baris `uncertainty_calculations` untuk tiap titik.
     *
     * Semua titik membawa `uc` / `v_eff` / `k` / `U95` yang SAMA — itu memang
     * yang dicetak sertifikatnya (satu baris `Uncertainty 95% ±` di bawah tabel,
     * bukan per titik). Yang beda per titik cuma nilai standar terkoreksi,
     * pembacaan UUT, koreksi, STDEV, dan Type A-nya sendiri.
     *
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function barisHitungan(array $hasil, Standard $standar, CalibrationCapability $kemampuan): array
    {
        // RSS komponen Type B saja — yang IKUT dijumlah DAN bukan pengulangan.
        // Aturannya identik dengan `GumCalculator::hitungDariBudget()` supaya dua
        // jalur ini tidak berbeda arti untuk kolom yang sama.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter(
                $hasil['budget'],
                static fn (array $k): bool => $k['disertakan'] && $k['distribusi'] !== 't-student',
            ),
        )));

        $audit = $this->jejakAudit($hasil, $kemampuan);
        $sekarang = Carbon::now();

        return array_map(static fn (array $t): array => [
            'standard_id' => $standar->id,
            'titik_ke' => $t['titik_ke'],
            // Kolom `Standard Reading` sertifikat — setpoint + koreksi kalibrator
            // (mode measure) atau rata-rata bacaan standar + koreksi (mode
            // source). Setpoint mentahnya tetap utuh di `raw_measurements`.
            'titik_ukur' => $t['titik_ukur'],
            // Kolom `Unit Under Test`.
            'rata_rata' => $t['rata_rata'],
            'error' => $t['error'],
            'koreksi' => $t['koreksi'],
            'standar_deviasi' => $t['standar_deviasi'],
            'jumlah_pengulangan' => $t['jumlah_pengulangan'],
            'type_a' => $t['type_a'],
            'type_b_components' => $audit,
            'type_b' => $typeB,
            'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
            'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
            'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
            'ketidakpastian_diperluas' => $hasil['u95_sertifikat'],
            // Tidak divonis PASS/FAIL — lihat [punyaToleransi].
            'toleransi' => null,
            'keputusan' => null,
            'calculated_at' => $sekarang,
        ], $hasil['titik']);
    }

    /**
     * Jejak audit yang disimpan di `uncertainty_calculations.type_b_components`.
     *
     * Bentuknya **LIST komponen**, bukan peta bertingkat — `CalibrationResource`
     * memetakannya dengan `array_map(fn (array $k) => …)`, jadi peta bertingkat
     * bikin seluruh endpoint sesi meledak HTTP 500 begitu ada satu sesi alat ini
     * dibuka. Ketahuan lewat `kalibrasi:uji-profil`, bukan lewat suite — test
     * profil ini tidak pernah melewati resource.
     *
     * Isinya komponen budget dulu, lalu tiap catatan penyimpangan master sebagai
     * baris tersendiri, lalu konteks sesi (mode/tipe sensor/kalibrator) yang
     * tanpa itu pembaca jejak audit tidak punya cara tahu tabel mana yang
     * dipakai.
     *
     * @param  array<string, mixed>  $hasil
     * @return list<array<string, mixed>>
     */
    private function jejakAudit(array $hasil, CalibrationCapability $kemampuan): array
    {
        $audit = array_map(static fn (array $k): array => [
            'sumber' => $k['sumber'],
            'keterangan' => $k['keterangan'],
            'distribusi' => $k['distribusi'],
            'nilai' => $k['u'] * $k['ci'],
            'u_baku' => $k['u'],
            'ci' => $k['ci'],
            'vi' => $k['vi'],
            // Ditulis eksplisit termasuk waktu `true`: pembaca jejak audit harus
            // bisa lihat komponen mana yang IKUT tanpa hafal kuirk master.
            'disertakan' => $k['disertakan'],
        ], $hasil['budget']);

        foreach ($hasil['catatan_audit'] as $catatan) {
            $audit[] = [
                'sumber' => $catatan['kode'],
                'keterangan' => $catatan['pesan'],
                'distribusi' => '-',
                'nilai' => $hasil['ketidakpastian_diperluas'],
            ];
        }

        $audit[] = [
            'sumber' => 'konteks_sesi',
            'keterangan' => sprintf(
                'Mode %s, sensor %s, kalibrator %s. STDEV terbesar %s °C dari %d titik, %d pembacaan per arah. '
                .'CMC "%s" %s °C, U95 dilaporkan dari %s.',
                $hasil['mode'],
                $hasil['tipe_sensor'],
                TabelKalibratorSuhu::MERK_TERCETAK[$hasil['merk_kalibrator']] ?? $hasil['merk_kalibrator'],
                rtrim(rtrim(number_format($hasil['standar_deviasi_maks'], 8, '.', ''), '0'), '.'),
                count($hasil['titik']),
                $hasil['jumlah_pengulangan'],
                (string) $kemampuan->parameter,
                rtrim(rtrim(number_format($hasil['cmc'], 8, '.', ''), '0'), '.'),
                $hasil['sumber_u95'] === 'cmc' ? 'lantai CMC' : 'hitungan budget',
            ),
            'distribusi' => '-',
            'nilai' => $hasil['ketidakpastian_diperluas'],
            'mode' => $hasil['mode'],
            'tipe_sensor' => $hasil['tipe_sensor'],
            'merk_kalibrator' => $hasil['merk_kalibrator'],
            'cmc' => $hasil['cmc'],
            'cmc_parameter' => $kemampuan->parameter,
            'sumber_u95' => $hasil['sumber_u95'],
            'ketidakpastian_diperluas_hitung' => $hasil['ketidakpastian_diperluas'],
        ];

        return $audit;
    }

    /**
     * Alasan sesi ini belum bisa dihitung, atau `null` kalau syaratnya lengkap.
     */
    private function syaratKurang(?string $mode, ?string $tipeSensor, ?string $merk, ?Standard $standar): ?string
    {
        if ($mode === null) {
            return 'Mode kalibrasi (Measure / Source) belum dipilih di sesi ini. Arah perhitungan koreksi '
                .'berbalik antara keduanya, jadi hitungannya nggak boleh nebak.';
        }

        if ($tipeSensor === null) {
            return 'Tipe sensor (Type K/N/B/T/R/S/J atau RTD) belum dipilih di sesi ini. Koreksi kalibrator, '
                .'ketidakpastian, drift, dan CMC semuanya beda per tipe.';
        }

        if ($merk === null) {
            return sprintf(
                'Kalibrator standar "%s" nggak dikenali merknya — tabel koreksinya cuma ada buat Constant '
                .'& Yokogawa. Betulin kolom `merk` standar itu di master.',
                $standar?->nama ?? '(kosong)',
            );
        }

        if ($this->tabel->drift($mode, $merk, $tipeSensor) === null
            && $this->tabel->u95Maksimum($mode, $merk, $tipeSensor) === null) {
            return sprintf(
                'Kalibrator %s mode %s nggak punya tabel sama sekali buat sensor %s.',
                TabelKalibratorSuhu::MERK_TERCETAK[$merk] ?? $merk,
                $mode,
                $tipeSensor,
            );
        }

        return null;
    }

    /**
     * CMC untuk tipe sensor ini, dicocokkan lewat kolom `parameter`.
     *
     * BUKAN lewat `range_min`/`range_max` seperti sepuluh alat lain: rentang RLK
     * ketujuh tipe sensor saling tumpang tindih (Type K −20…1000 dan Type N
     * −20…1000 identik), jadi pencocokan berbasis rentang akan memulangkan baris
     * pertama yang muat dan diam-diam memakai CMC tipe lain.
     */
    private function kemampuanTipeSensor(Equipment $equipment, string $tipeSensor): ?CalibrationCapability
    {
        $parameter = self::PARAMETER_CMC[$tipeSensor] ?? null;

        if ($parameter === null) {
            return null;
        }

        return CalibrationCapability::query()
            ->where('nama_alat', $this->namaAlatKemampuan())
            ->where('parameter', $parameter)
            ->first();
    }

    /** Mode sesi, dinormalkan ke `measure`/`source` atau `null`. */
    private function modeSesi(CalibrationSession $sesi): ?string
    {
        return $this->normalkanMode($sesi->mode_kalibrasi);
    }

    /** Tipe sensor sesi, dinormalkan ke ejaan [TabelKalibratorSuhu::TIPE_SENSOR]. */
    private function tipeSensorSesi(CalibrationSession $sesi): ?string
    {
        return $this->normalkanTipeSensor($sesi->tipe_sensor);
    }

    private function normalkanMode(mixed $mode): ?string
    {
        $mode = is_string($mode) ? strtolower(trim($mode)) : null;

        return in_array($mode, [TabelKalibratorSuhu::MODE_MEASURE, TabelKalibratorSuhu::MODE_SOURCE], true)
            ? $mode
            : null;
    }

    /**
     * Ejaan tipe sensor diseragamkan sebelum dipakai jadi kunci tabel.
     *
     * Master sendiri memakai dua nama untuk barang yang sama — `PT100` di header
     * tabel STANDAR KALIBRATOR, `RTD` di tabel CMC dan dropdown enclosure. Yang
     * berlaku di sini `RTD`; `pt100` diterima sebagai alias supaya data yang
     * datang dari lembar kertas tidak ditolak karena beda nama.
     */
    private function normalkanTipeSensor(mixed $tipe): ?string
    {
        if (! is_string($tipe)) {
            return null;
        }

        $bersih = strtolower(preg_replace('/[\s_-]+/', ' ', trim($tipe)) ?? '');

        if (in_array($bersih, ['pt100', 'pt 100', 'rtd', 'prt'], true)) {
            return 'RTD';
        }

        foreach (TabelKalibratorSuhu::TIPE_SENSOR as $dikenal) {
            if ($bersih === strtolower($dikenal)) {
                return $dikenal;
            }
        }

        // "type k" ditulis "k" doang di sebagian lembar kertas.
        foreach (TabelKalibratorSuhu::TIPE_SENSOR as $dikenal) {
            if (str_starts_with($dikenal, 'Type ') && $bersih === strtolower(substr($dikenal, 5))) {
                return $dikenal;
            }
        }

        return null;
    }

    /**
     * Merk kalibrator dari master `standards`, jadi kunci tabel koreksi.
     *
     * Dibaca dari kolom `merk`, bukan dari nama: nama standar diketik admin dan
     * bentuknya bebas ("Temperature Calibrator Yokogawa CA 150 Handy Cal"),
     * sementara `merk` kolom terkendali yang isinya memang satu kata.
     */
    private function merkKalibrator(?Standard $standar): ?string
    {
        $merk = strtolower(trim((string) ($standar?->merk ?? '')));

        return in_array($merk, TabelKalibratorSuhu::MERK, true) ? $merk : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - Temperature Indicator Tanpa Sensor (TITS)',
            'jumlah_pengulangan' => self::PENGULANGAN_PER_ARAH,
            'arah_pembacaan' => ['UP', 'DOWN'],
            'larutan_standar' => self::TITIK_SARAN,
            'satuan' => self::SATUAN,
            'satuan_suhu' => '°C',
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — lembar kerja '
                .'tetap bisa dikirim. Titik yang datanya belum cukup nggak ikut dihitung. Khusus alat ini, '
                .'MODE (Measure/Source) dan TIPE SENSOR wajib dipilih: arah perhitungan koreksi berbalik antara '
                .'dua mode, dan koreksi kalibrator beda per tipe sensor.',
            'bagian' => [
                [
                    'kode' => 'identitas_alat',
                    'halaman' => 1,
                    'judul' => 'EQUIPMENT IDENTITY AND CUSTOMER DATA',
                    'field' => [
                        $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                        $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', '1. Name', 'teks', sumber: 'otomatis'),
                        $this->field('alat_merk', '2. Merk/Manufacture', 'teks'),
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                        $this->field('spesifikasi_alat.rentang_ukur', '5. Rentang Ukur', 'angka', satuan: self::SATUAN),
                        $this->field('spesifikasi_alat.kapasitas', '6. Kapasitas Alat', 'angka', satuan: self::SATUAN),
                        $this->field('spesifikasi_alat.resolusi', '7. Resolusi Alat', 'angka', satuan: self::SATUAN),
                    ],
                ],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'OWNER',
                    'field' => [
                        $this->field('pemilik_nama', '1. Name', 'teks'),
                        $this->field('pemilik_alamat', '2. Address', 'teks_panjang'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'STANDARD',
                    'baris' => self::STANDARD_TERCETAK,
                    'field' => [
                        $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                        $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                    ],
                ],
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION DATA',
                    'field' => [
                        // DUA field yang cuma alat ini punya, dan dua-duanya
                        // menentukan angka — bukan catatan.
                        $this->field('mode_kalibrasi', '1. Mode', 'pilihan', pilihan: [
                            ['nilai' => TabelKalibratorSuhu::MODE_MEASURE, 'label' => 'Measure (UUT membaca)'],
                            ['nilai' => TabelKalibratorSuhu::MODE_SOURCE, 'label' => 'Source (UUT men-source)'],
                        ]),
                        $this->field('tipe_sensor', '2. Temperature Type', 'pilihan', pilihan: array_map(
                            static fn (string $t): array => ['nilai' => $t, 'label' => $t],
                            TabelKalibratorSuhu::TIPE_SENSOR,
                        )),
                        $this->field('lokasi', '3. Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'Inlab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('room_id', 'Ruangan', 'pilihan', sumber: 'master_ruangan'),
                        $this->field(
                            'calibration_method_id',
                            '4. Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION RESULT',
                    'field' => [
                        $this->field('suhu_awal', 'Env. Condition — First', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'Env. Condition — First', 'angka', satuan: '%RH'),
                        $this->field('suhu_akhir', 'Env. Condition — End', 'angka', satuan: '°C'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End', 'angka', satuan: '%RH'),
                        $this->field(
                            'thermohygro_standard_id',
                            'Environmental Meter Used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                    ],
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before Adjustment Reading'),
                        $this->tabelHasil('sesudah_adjustment', 'After Adjustment Reading'),
                    ],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 1,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                        $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bagianAdmin(): array
    {
        return [
            'kode' => 'administratif',
            'halaman' => 1,
            'judul' => 'Data Administratif (Admin)',
            'field' => [
                $this->field('nomor_order', 'Order Number', 'teks', hanyaAdmin: true),
                $this->field('certificate.nomor', 'Certificate Number', 'teks', sumber: 'otomatis', hanyaAdmin: true),
                $this->field('suhu_ketidakpastian', 'U95% Suhu', 'angka', sumber: 'otomatis', satuan: '°C', hanyaAdmin: true),
                $this->field('kelembaban_ketidakpastian', 'U95% Kelembaban', 'angka', sumber: 'otomatis', satuan: '%RH', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * Satu tabel hasil: baris = titik, kolom = pembacaan, enam kali per titik
     * (UP 1–3, DOWN 1–3).
     *
     * `titik_bisa_diubah` = baris tabel ini SARAN, bukan daftar tertutup —
     * satu-satunya alat sejauh ini yang begitu. Rentang UUT beda-beda, dan
     * memaksa sembilan titik master ke alat berentang 0–200 °C berarti teknisi
     * mengosongkan enam baris tiap sesi.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul): array
    {
        $nomor = range(1, self::PENGULANGAN_PER_ARAH * self::ARAH_PER_TITIK);

        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'satuan' => self::SATUAN,
            // Judul kedua kolom BERTUKAR mengikuti mode — master menulisnya
            // `Standard Indication` / `Reading UUT` di mode measure dan
            // `UUT Indication` / `Reading Standard` di mode source.
            //
            // Dikirim DUA KALI, dan itu disengaja. Kunci polos `judul_nilai`
            // tetap STRING karena sepuluh alat lain mengisinya string dan
            // aplikasi teknisi membacanya `as String?` — dikirim peta, cast itu
            // melempar `TypeError` dan yang gagal bukan satu kolom melainkan
            // SELURUH lembar (`LembarKerja.fromJson` di luar jangkauan
            // `parseListAman`). Petanya menyusul di kunci `_per_mode`, yang
            // dibaca layar yang sudah mengerti mode.
            //
            // Yang dipakai sebagai nilai polos varian MEASURE, bukan tanda
            // hubung atau teks netral: aplikasi versi lama tetap menggambar
            // lembar yang kebaca, cuma judulnya salah kalau modenya source.
            'judul_nilai' => self::JUDUL_NILAI[TabelKalibratorSuhu::MODE_MEASURE],
            'judul_nilai_per_mode' => self::JUDUL_NILAI,
            'judul_pengulangan' => self::JUDUL_PENGULANGAN[TabelKalibratorSuhu::MODE_MEASURE],
            'judul_pengulangan_per_mode' => self::JUDUL_PENGULANGAN,
            'titik_bisa_diubah' => true,
            'baris' => array_map(
                static fn (float $t): array => [
                    'titik_ukur' => $t,
                    'label' => rtrim(rtrim(number_format($t, 1, ',', '.'), '0'), ',').' °C',
                    'satuan' => self::SATUAN,
                ],
                self::TITIK_SARAN,
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => self::SATUAN, 'tipe' => 'angka', 'satuan' => self::SATUAN],
            ],
            // Enam pembacaan per titik: 1–3 UP, 4–6 DOWN.
            //
            // `pengulangan` tetap DAFTAR ANGKA, alasannya sama seperti
            // `judul_nilai` di atas: aplikasi menyaringnya `whereType<num>()`,
            // jadi daftar objek lolos tanpa error tapi menghasilkan nol kolom
            // pembacaan — lembar kerja yang terbuka rapi dan tidak bisa diisi.
            'pengulangan' => $nomor,
            // Arah & labelnya menyusul di kunci sendiri, supaya layar tidak
            // menghitung "1–3 UP, 4–6 DOWN" dari indeks. Kalau lab suatu saat
            // membaca empat kali per arah, yang berubah cukup di sini.
            'pengulangan_arah' => array_map(
                static fn (int $i): array => [
                    'ke' => $i,
                    'arah' => $i <= self::PENGULANGAN_PER_ARAH ? 'UP' : 'DOWN',
                    'label' => sprintf(
                        '%s X%d',
                        $i <= self::PENGULANGAN_PER_ARAH ? 'UP' : 'DOWN',
                        (($i - 1) % self::PENGULANGAN_PER_ARAH) + 1,
                    ),
                ],
                $nomor,
            ),
        ];
    }

    /**
     * Cocokin baris STANDARD tercetak ke master `standards` lab (nama/serial).
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNull('parameter_kondisi')
            ->get(['id', 'nama', 'merk', 'serial_number', 'no_sertifikat', 'tertelusur_ke']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $master->first(fn (Standard $s): bool => collect($baris['cocok'])
                        ->contains(fn (string $kunci): bool => $s->nama === $kunci
                            || $s->serial_number === $kunci));

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'merk' => $cocok?->merk,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }

    /**
     * @param  list<array<string, string>>  $pilihan
     * @return array<string, mixed>
     */
    private function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
    ): array {
        return [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            'wajib' => false,
            'sumber' => $sumber,
            'satuan' => $satuan,
            'pilihan' => $pilihan,
            'hanya_admin' => $hanyaAdmin,
        ];
    }
}
