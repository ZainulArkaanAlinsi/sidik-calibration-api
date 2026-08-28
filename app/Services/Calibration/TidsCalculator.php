<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use InvalidArgumentException;

/**
 * Mesin hitung **TIDS** — Temperatur Indikator dengan Sensor, lampiran
 * akreditasi LK-285-IDN "Suhu dan Kelembapan" no. 2. MURNI: masuk array, keluar
 * array, tidak menyentuh DB, request, atau Eloquent.
 *
 * Master: DUA workbook TIDS ber-password yang turun dari lab 28 Agt 2026 —
 * `… TIDS - Recorder Graptech.xlsm` (sesi contoh `071-CAL-325`, order
 * `2503.09.A`, Multiparameter Suhu s/n IT-01, 3 Maret 2025) dan
 * `… TIDS - Yokogawa K,N.xlsm` (Thermometer Bola Basah, set point 35 °C).
 * Sheet `PERHITUNGAN FC` + `PERHITUNGAN U95%` + `Variasi axial Dryblok A/B`.
 *
 * Keempat sheet yang selama ini disebut hilang untuk TIDS ADA di dua workbook
 * ini, dan tabel CMC-nya berbunyi **0,86 / 1,4 / 3,1 °C** — baris no. 2 TIDS,
 * bukan no. 5 Thermocouple yang berbunyi 0,84/1,5/3,3. Itu yang membedakannya
 * dari kekeliruan yang nyaris terjadi 27 Agt 2026 (lihat §K2
 * `docs/permintaan-user-7.md`): waktu itu label sheet-nya cocok tapi angkanya
 * tidak. Kali ini dua-duanya cocok.
 *
 * ## Bentuk lembarnya: PASANGAN deret, 5 ulangan — bukan 5 UUT
 *
 * Ini koreksi tafsir yang penting dan datang dari master, bukan dari kertas.
 * Kepala kolom PDF `SIDIK-FM-CAL-0506 Rev.4` berbunyi `0" (UUT1)`…`90" (UUT5)`
 * dan sempat dibaca sebagai LIMA ALAT dalam satu lembar. Dua workbook ini
 * menulis kolom yang sama sebagai `0" (PRT1)`…`80" (PRT5)` di tabel standar dan
 * `10" (PRT1)`…`90" (PRT5)` di tabel UUT, lalu memakainya sebagai
 * `AVERAGE(D:I)` + `STDEV(D:I)` per BARIS. Jadi:
 *
 *   satu baris  = satu set point
 *   lima kolom  = lima ULANGAN, standar & UUT dibaca bergantian tiap 10 detik
 *
 *   0″ standar · 10″ UUT · 20″ standar · 30″ UUT · … · 80″ standar · 90″ UUT
 *
 * Persis bentuk Thermocouple, Termometer Gelas & Thermohygrometer — lihat
 * [ProfilSuhuPasangan]. Pertanyaan terbuka K1 ("5 UUT jadi 1 sesi atau 5 sesi
 * terpisah?") karena itu gugur: tidak pernah ada lima UUT.
 *
 * ## Rantai koreksinya BEDA antara sisi standar dan sisi UUT
 *
 *   sisi standar  →  rata² + koreksi METER standar + koreksi SENSOR standar
 *   sisi UUT      →  rata² apa adanya
 *
 * `S23 = J23+Q23+R23` versus `J42` yang langsung dipakai `SERTIFIKAT!J20`.
 * Alasannya fisik: UUT membawa sensornya sendiri — justru penyimpangan sensor
 * itu yang sedang diukur. Beda dari Thermocouple, yang sisi UUT-nya masih
 * dikoreksi meter kalibrator karena di sana UUT dibaca LEWAT meter kalibrator.
 *
 *   `SERTIFIKAT!L20 = E20 − J20` → koreksi = standar terkoreksi − rata² UUT.
 *
 * ## SATU budget untuk seluruh sesi, dua belas komponen
 *
 * `PERHITUNGAN U95%` punya satu tabel `B24:B35` dan satu baris
 * `Uncertainty 95% ±` di bawah tabel sertifikat — bukan kolom per titik.
 * Komponen ke-7 (keterulangan) memakai `M23` = STDEV terbesar di tabel
 * **standar**, bukan tabel UUT: master memang menghitung dua-duanya (`M23` &
 * `M42`) lalu cuma memakai yang pertama.
 *
 * ## Uji titik es 0 °C akhirnya punya arti
 *
 * Pertanyaan terbuka ketiga di docblock `TidsProfile` — apa yang dilakukan
 * terhadap `Pembacaan Awal` & `Pembacaan Akhir` di titik es — dijawab kedua
 * master dengan rumus yang sama: `O35 = 0.5 * 'INPUT DATA'!P51`, dan
 * `P51 = ABS(N50−P50)`. Jadi selisih awal-akhir jadi komponen **Drift UUT**,
 * setengah-lebar, distribusi persegi. Bukan syarat lolos, bukan cuma catatan.
 *
 * ## Empat penyimpangan master yang DITIRU — dan kenapa
 *
 * Aturan repo ini sudah dipakai TITS (`SERTAKAN_DRIFT_MATI`), Thermocouple
 * (`type_a_tidak_masuk_budget`) dan Enclosure: **master direproduksi apa
 * adanya**, karena sertifikat yang sudah diserahkan ke pelanggan lahir dari
 * workbook itu — lalu tiap penyimpangan melahirkan catatan audit yang menyebut
 * berapa angkanya kalau dibetulkan. Yang memutuskan manajer teknis lab.
 *
 * Empat di sini, keempatnya menggeser U95 dan tidak satu pun memunculkan error:
 *
 *  1. **`O24` recorder menunjuk sel TETAP** `Standar_Recorder!T30` — sel di
 *     blok Type N, kolom CH17, baris 0 °C. Nilainya 0,83 dan dia dipakai apa
 *     pun tipe sensor & kanal sesinya; tabel U95 recorder sendiri berbunyi 0,67
 *     untuk seluruh kolom Type K. Sesi contoh memakai Type K.
 *  2. **`O25` recorder literal `0,14`** — tabel `TABEL NILAI U95% TERMOKOPEL`
 *     di workbook yang sama berbunyi 0,44 (Type K) & 0,76 (Type N).
 *  3. **`N27` recorder menunjuk `Standar_Recorder!AM9`** — sel di tabel
 *     KOREKSI (CH16 Type K, −20 °C), bukan di `Tabel_Drift_Recorder` yang ada,
 *     bernama, dan tidak dipakai siapa pun (Type N 0,25 · Type K 0,5).
 *     Nilainya −0,2: setengah-lebar NEGATIF, yang cuma mungkin kalau sumbernya
 *     memang bukan tabel drift.
 *  4. **`AC36` keluarga Constant/Yokogawa berhenti di baris 32** —
 *     `SUM(AC24:AD32)`, jadi tiga komponen terakhir (Self Heating, Interpolasi,
 *     Drift UUT) lahir, ditampilkan, lalu tidak ikut dijumlah. Workbook
 *     Recorder menjumlah keduabelasnya (`SUM(AC24:AD35)`). Dua master, satu
 *     alat, dua jawaban.
 *
 * Ketiga yang pertama & yang keempat sama-sama menggeser U95 ke arah LEBIH
 * KECIL, jadi keempatnya wajib kelihatan sebelum sertifikat disetujui —
 * `TidsProfile::peringatanSesi()` menaikkannya ke layar, bukan cuma ke jejak
 * audit.
 *
 * ## Yang TIDAK ditiru: sel kosong dibaca nol
 *
 * Tiap VLOOKUP master dibungkus `IFNA(…,"")`, dan cabang terakhirnya
 * `VLOOKUP(…, 100, 0)` — kolom ke-100 di tabel 42 kolom. Kombinasi yang tidak
 * ada karena itu memulangkan KOSONG, dan kosong ikut dijumlah `J+Q+R` sebagai
 * nol. Paling nyata di **PRT PT100 + recorder**: koreksi meter DAN koreksi
 * sensor dua-duanya hilang, sertifikat terbit, tidak ada error di mana pun.
 * Di sini titik seperti itu DIBLOKIR dengan alasan yang kebaca.
 *
 * @see TabelStandarTids
 * @see App\Services\Calibration\Profiles\TidsProfile
 * @see docs/pertanyaan-lab-tids-workbook.md
 */
class TidsCalculator
{
    /** Keluarga standar berbasis recorder — budget-nya beda di empat tempat. */
    public const KELUARGA_RECORDER = 'recorder';

    /**
     * `O24` workbook Recorder: `=Standar_Recorder!T30`, sel tetap. Lihat
     * penyimpangan 1 di docblock kelas.
     */
    public const U95_METER_RECORDER_TETAP = 0.83;

    /** `O25` workbook Recorder: literal. Lihat penyimpangan 2. */
    public const U95_SENSOR_RECORDER_TETAP = 0.14;

    /** `N26` workbook Recorder: literal in-homogeneity termokopel. */
    public const INHOMOGENITAS_RECORDER = 0.6;

    /** `N27` workbook Recorder: `=Standar_Recorder!AM9`. Lihat penyimpangan 3. */
    public const DRIFT_METER_RECORDER_TETAP = -0.2;

    /**
     * `N26` workbook Constant/Yokogawa: `=0.25%*MAX(set point)/2`.
     *
     * Set point yang dipakai master `MAX('INPUT DATA'!L33:L34)` — DUA baris
     * pertama saja, bukan seluruh tabel. Di sini dipakai set point tertinggi
     * seluruh sesi; bedanya cuma muncul kalau baris ke-3 ke bawah lebih panas
     * dari dua baris pertama, dan di situ angka master justru yang terlalu
     * kecil. Selisihnya dilaporkan lewat catatan audit.
     */
    public const KOEF_INHOMOGENITAS_KALIBRATOR = 0.0025;

    /**
     * `O34` kedua master: `=[13]Sheet2!$B$7` / `=[7]Sheet2!$B$7` — tautan ke
     * workbook luar yang TIDAK ikut dikirim. Nilai yang ke-cache di dua-duanya
     * sama persis, jadi dia konstanta lab, bukan turunan sesi.
     *
     * Divisornya `Q34 = 1` — satu-satunya komponen persegi yang TIDAK dibagi
     * √3 di seluruh tabel. Ditiru; catatan auditnya menyebut ini.
     */
    public const KETIDAKPASTIAN_INTERPOLASI = 0.19788162882115856;

    /** `O33` kedua master: nol, dan tetap dijumlahkan sebagai nol. */
    public const SELF_HEATING_RTD = 0.0;

    /** Ulangan tercetak di kertasnya, kedua tabel — `D22:I22` = 1..5. */
    public const PENGULANGAN_TERCETAK = 5;

    /** `vi` sertifikat kalibrator & sensor (`S24`, `S25`). */
    public const VI_SERTIFIKAT = 200;

    /** `vi` in-homogeneity: 200 di workbook Recorder, 50 di Constant/Yokogawa (`S26`). */
    public const VI_INHOMOGENITAS_RECORDER = 200;

    /** `vi` seluruh komponen persegi selain in-homogeneity & daya baca (`S27`–`S35`). */
    public const VI_PERSEGI = 50;

    /** `vi` daya baca UUT (`S29 = 1000000`) — praktis tak hingga. */
    public const VI_DAYA_BACA = 1000000;

    /**
     * Tiga komponen terakhir (Self Heating, Interpolasi, Drift UUT) ikut
     * dijumlah untuk keluarga mana.
     *
     * Ini penyimpangan ke-4 di docblock kelas, ditulis sebagai saklar supaya
     * jawaban lab nanti cukup mengubah satu baris — bukan membedah `budget()`.
     *
     * @var array<string, bool>
     */
    public const TIGA_KOMPONEN_TERAKHIR = [
        'recorder' => true,     // `AC36 = SUM(AC24:AD35)`
        'constant' => false,    // `AC36 = SUM(AC24:AD32)`
        'yokogawa' => false,    // idem
    ];

    /**
     * `v_eff` **tidak** dipotong ke bawah sebelum dicari `k`-nya.
     *
     * Sama seperti TITS, Enclosure & ketiga alat suhu pasangan: master memakai
     * aproksimasi polinomial t-student atas `v_eff` pecahan apa adanya
     * (`AC39 = 1.95996 + 2.37356/v + …`), sementara
     * `GumCalculator::agregasiBudget()` memotong (GUM G.4.1). Di sesi contoh
     * Recorder `v_eff` 82,78 dan selisih `k`-nya di bawah presisi cetak, tapi
     * aturannya dipegang konsisten supaya sesi ber-`v_eff` kecil tidak diam-diam
     * berbeda dari sertifikat yang sudah terbit.
     */
    public const FLOOR_V_EFF = false;

    private ?GumCalculator $gum = null;

    private ?StudentTDistribution $t = null;

    public function __construct(private readonly TabelStandarTids $tabel = new TabelStandarTids) {}

    /**
     * Hitung satu sesi TIDS utuh.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>, no_sensor: int}>  $titik
     * @param  array{keluarga_standar: string, tipe_sensor: string, dryblock: string, resolusi: float, cmc: float, titik_es?: list<float>}  $spek
     * @return array{
     *     titik: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>,
     *     standar_deviasi_maks: float, index_maks: float|null, set_point_maks: float, rentang_titik_es: float,
     *     budget: list<array<string, mixed>>, ketidakpastian_gabungan: float,
     *     derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float,
     *     cmc: float, u95_sertifikat: float, sumber_u95: string, catatan_audit: list<array<string, mixed>>
     * }
     */
    public function hitungSesi(array $titik, array $spek): array
    {
        if ($titik === []) {
            throw new InvalidArgumentException(
                'Sesi TIDS nggak punya satu pun titik terisi — budget ketidakpastiannya nggak bisa disusun.',
            );
        }

        $keluarga = strtolower((string) $spek['keluarga_standar']);
        $tipe = (string) $spek['tipe_sensor'];
        $dryblock = strtoupper((string) $spek['dryblock']);
        $resolusi = (float) $spek['resolusi'];

        if (! in_array($keluarga, TabelStandarTids::KELUARGA, true)) {
            throw new InvalidArgumentException("Keluarga standar `{$keluarga}` nggak punya tabel TIDS.");
        }

        if (! in_array($tipe, TabelStandarTids::TIPE_SENSOR, true)) {
            throw new InvalidArgumentException("Tipe sensor standar `{$tipe}` nggak dikenal di lembar TIDS.");
        }

        if (! is_finite($resolusi) || $resolusi <= 0.0) {
            throw new InvalidArgumentException(
                'Resolusi UUT wajib angka > 0 — komponen `Readability UUT` budget-nya lahir dari situ.',
            );
        }

        $siap = [];
        $belum = [];

        foreach ($titik as $t) {
            $hasil = $this->hitungTitik($t, $keluarga, $tipe);

            if (isset($hasil['alasan'])) {
                $belum[] = ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $hasil['alasan']];

                continue;
            }

            $siap[] = $hasil;
        }

        usort($belum, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        if ($siap === []) {
            return $this->sesiKosong($belum);
        }

        // `P37 = MAX(P23:P36)` — index tabel TERTINGGI di antara pembacaan
        // STANDAR sesi ini. Dia yang memilih baris U95 meter & U95 sensor, jadi
        // satu titik panas menaikkan budget seluruh sesi. Arah konservatif yang
        // memang dipilih master.
        $indexMaks = max(array_column($siap, 'index_standar'));

        // `N30 = 'PERHITUNGAN FC'!M23` — STDEV terbesar di tabel STANDAR.
        // Master juga menghitung `M42` (tabel UUT) lalu tidak memakainya.
        $barisStdev = $this->barisStdevTerbesar($siap);
        $stdevMaks = $barisStdev['standar_deviasi_standar'];
        $nUlangan = count($barisStdev['pembacaan_standar']);
        $stdevMaksUut = max(array_column($siap, 'standar_deviasi_uut'));

        // `U22 = 'PERHITUNGAN FC'!D56 = MAX(B42:C55)` — set point UUT tertinggi.
        // Dia yang memilih pita CMC, dan (di keluarga kalibrator) menghitung
        // komponen in-homogeneity.
        //
        // Dihitung dari SELURUH titik yang dikirim, bukan cuma yang berhasil
        // dihitung. `MAX` master menyapu seluruh kolom set point tabel UUT, dan
        // baris yang koreksinya nggak ketemu tetap punya set point di situ.
        // Selain lebih setia, ini juga yang bikin pita CMC (dipilih
        // `TidsProfile::kemampuanUntukTitik()` dari titik yang sama) dan
        // komponen in-homogeneity nggak bisa diam-diam memakai dua set point
        // tertinggi yang berbeda.
        $setPointMaks = max(array_map(static fn (array $t): float => (float) $t['titik_ukur'], $titik));

        // `P51 = ABS(N50−P50)` — rentang uji titik es 0 °C.
        $rentangEs = $this->rentangTitikEs($spek['titik_es'] ?? []);

        $budget = $this->budget($keluarga, $tipe, $dryblock, $resolusi, $indexMaks, $setPointMaks, $stdevMaks, $nUlangan, $rentangEs, $barisStdev['no_sensor']);
        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));
        $agg = $this->agregasi($dipakai);

        $cmc = (float) $spek['cmc'];
        $uHitung = $agg['ketidakpastian_diperluas'];

        return [
            'titik' => $siap,
            'belum_dihitung' => $belum,
            'standar_deviasi_maks' => $stdevMaks,
            'standar_deviasi_maks_uut' => $stdevMaksUut,
            'index_maks' => $indexMaks,
            'set_point_maks' => $setPointMaks,
            'rentang_titik_es' => $rentangEs,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $uHitung,
            'cmc' => $cmc,
            // Lantai CMC (ILAC-P14, `AC42 = MAX(AC40:AI41)`): lab tidak boleh
            // mengklaim ketidakpastian lebih baik dari kemampuan
            // terakreditasinya.
            'u95_sertifikat' => max($uHitung, $cmc),
            'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
            'catatan_audit' => $this->catatanAudit($keluarga, $tipe, $dipakai, $budget, $uHitung, $cmc, $indexMaks, $barisStdev['no_sensor']),
        ];
    }

    /**
     * Satu titik: dua deret pembacaan → standar terkoreksi & rata-rata UUT.
     *
     * @param  array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>, no_sensor: int}  $t
     * @return array<string, mixed>
     */
    private function hitungTitik(array $t, string $keluarga, string $tipe): array
    {
        $standar = array_values(array_map('floatval', $t['standar']));
        $uut = array_values(array_map('floatval', $t['uut']));
        $noSensor = (int) $t['no_sensor'];

        if (count($standar) < 2 || count($uut) < 2) {
            return ['alasan' => sprintf(
                'Set point %s °C baru punya %d pembacaan standar & %d pembacaan UUT — tiap sisi butuh minimal '
                .'2 biar STDEV-nya ada artinya. Kertasnya minta lima.',
                $this->angka((float) $t['titik_ukur']),
                count($standar),
                count($uut),
            )];
        }

        $kanal = $keluarga === TabelStandarTids::KELUARGA_BERKANAL
            ? $this->tabel->kanalRecorder($tipe, $noSensor)
            : null;

        if ($keluarga === TabelStandarTids::KELUARGA_BERKANAL && $kanal === null) {
            return ['alasan' => sprintf(
                'No. Termokopel %d (%s) nggak menunjuk kanal recorder mana pun. Tabel koreksi Graptech cuma '
                .'punya kolom CH1..CH20 untuk Type K (nomor 1..16) & Type N (nomor 3..12); PRT PT100 nggak '
                .'punya kolom sama sekali di recorder. Master memulangkan `VLOOKUP(…,100,0)` yang error lalu '
                .'dibungkus IFNA jadi KOSONG — koreksi meter & koreksi sensor dua-duanya hilang tanpa error. '
                .'Di sini titiknya ditahan.',
                $noSensor,
                $tipe,
            )];
        }

        $nomorSah = $this->tabel->nomorSensorTersedia($keluarga, $tipe);

        if (! in_array($noSensor, $nomorSah, true)) {
            return ['alasan' => sprintf(
                'No. Termokopel %d nggak ada di tabel sensor %s — yang punya sertifikat cuma nomor %s.',
                $noSensor,
                $tipe,
                $nomorSah === [] ? '(nggak ada)' : implode(', ', $nomorSah),
            )];
        }

        $avgStandar = array_sum($standar) / count($standar);
        $avgUut = array_sum($uut) / count($uut);

        $index = $this->tabel->indeksTerdekat($keluarga, $avgStandar);

        if ($index === null) {
            return ['alasan' => 'Tabel index standar TIDS kosong — nggak ada titik yang bisa dicocokkan.'];
        }

        $koreksiMeter = $this->tabel->koreksiMeter($keluarga, $tipe, $index, $kanal);
        $koreksiSensor = $this->tabel->koreksiSensor($keluarga, $tipe, $noSensor, $index);

        foreach ([
            [$koreksiMeter, sprintf(
                'koreksi meter %s %s%s di titik %s °C',
                TabelStandarTids::KELUARGA_TERCETAK[$keluarga] ?? $keluarga,
                $tipe,
                $kanal === null ? '' : " CH{$kanal}",
                $this->angka($index),
            )],
            [$koreksiSensor, sprintf(
                'koreksi sensor standar %s No. %d di titik %s °C',
                $tipe,
                $noSensor,
                $this->angka($index),
            )],
        ] as [$nilai, $sebutan]) {
            if ($nilai === null) {
                return ['alasan' => sprintf(
                    'Tabel standar TIDS nggak punya %s. Master membiarkan sel yang hilang pulang KOSONG lalu '
                    .'menjumlahkannya sebagai nol — sertifikat terbit dengan koreksi yang hilang diam-diam. '
                    .'Di sini titiknya ditahan.',
                    $sebutan,
                )];
            }
        }

        $standarTerkoreksi = $avgStandar + $koreksiMeter + $koreksiSensor;

        return [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => (float) $t['titik_ukur'],
            'no_sensor' => $noSensor,
            'kanal' => $kanal,
            'pembacaan_standar' => $standar,
            'pembacaan_uut' => $uut,
            'rata_rata_standar' => $avgStandar,
            'rata_rata_uut' => $avgUut,
            'standar_deviasi_standar' => $this->stdev($standar),
            'standar_deviasi_uut' => $this->stdev($uut),
            'index_standar' => $index,
            'koreksi_meter_standar' => $koreksiMeter,
            'koreksi_sensor_standar' => $koreksiSensor,
            'standar_terkoreksi' => $standarTerkoreksi,
            // Sisi UUT TIDAK dikoreksi — `SERTIFIKAT!J20 = 'PERHITUNGAN FC'!J42`
            // langsung dari rata-rata. Lihat docblock kelas.
            'uut_terkoreksi' => $avgUut,
            // `SERTIFIKAT!L20 = E20−J20`. Kalau dibalik, alat yang membaca
            // 0,94 °C terlalu rendah tercetak sebagai membaca 0,94 °C terlalu
            // tinggi.
            'koreksi' => $standarTerkoreksi - $avgUut,
        ];
    }

    /**
     * Dua belas komponen `PERHITUNGAN U95%!B24:B35`, urut seperti di master.
     *
     * @return list<array<string, mixed>>
     */
    private function budget(
        string $keluarga,
        string $tipe,
        string $dryblock,
        float $resolusi,
        float $indexMaks,
        float $setPointMaks,
        float $stdevMaks,
        int $nUlangan,
        float $rentangEs,
        int $noSensorStdev,
    ): array {
        $sqrt3 = sqrt(3.0);
        $recorder = $keluarga === self::KELUARGA_RECORDER;
        $tercetak = TabelStandarTids::KELUARGA_TERCETAK[$keluarga] ?? $keluarga;
        $blok = $this->tabel->dryblock($dryblock);
        $sertakanTiga = self::TIGA_KOMPONEN_TERAKHIR[$keluarga] ?? true;

        [$u95Meter, $ketMeter] = $recorder
            ? [self::U95_METER_RECORDER_TETAP, sprintf(
                'Sertifikat %s — master menunjuk sel TETAP `Standar_Recorder!T30` (%s °C), bukan tabel U95 '
                .'per tipe sensor & kanal. Ditiru.',
                $tercetak,
                $this->angka(self::U95_METER_RECORDER_TETAP),
            )]
            : [$this->tabel->u95Meter($keluarga, $tipe, $indexMaks), null];

        [$u95Sensor, $ketSensor] = $recorder
            ? [self::U95_SENSOR_RECORDER_TETAP, sprintf(
                'Sertifikat sensor standar %s — master menulis literal %s °C, bukan tabel U95 termokopel. Ditiru.',
                $tipe,
                $this->angka(self::U95_SENSOR_RECORDER_TETAP),
            )]
            : [$this->tabel->u95Sensor($keluarga, $tipe, $noSensorStdev, $indexMaks), null];

        $inhomogenitas = $recorder
            ? self::INHOMOGENITAS_RECORDER
            : self::KOEF_INHOMOGENITAS_KALIBRATOR * $setPointMaks / 2.0;

        [$driftMeter, $ketDrift] = $recorder
            ? [self::DRIFT_METER_RECORDER_TETAP, sprintf(
                'Drift %s — master menunjuk `Standar_Recorder!AM9` (%s °C), sel di tabel KOREKSI (CH16 Type K, '
                .'−20 °C), bukan `Tabel_Drift_Recorder`. Setengah-lebar negatif itu tandanya. Ditiru.',
                $tercetak,
                $this->angka(self::DRIFT_METER_RECORDER_TETAP),
            )]
            : [$this->tabel->driftMeter($keluarga, $tipe), null];

        $driftSensor = $this->tabel->driftSensor($keluarga, $tipe);

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => $ketMeter ?? ($u95Meter === null
                    ? sprintf('Sertifikat kalibrator %s %s — tabel U95-nya nggak punya titik %s °C', $tercetak, $tipe, $this->angka($indexMaks))
                    : sprintf('Sertifikat kalibrator %s %s (titik %s °C, U=%s °C, k=2)', $tercetak, $tipe, $this->angka($indexMaks), $this->angka($u95Meter))),
                'distribusi' => 'normal',
                'u' => ($u95Meter ?? 0.0) / TabelStandarTids::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => $u95Meter !== null,
            ],
            [
                'sumber' => 'ketidakpastian_sensor',
                'keterangan' => $ketSensor ?? ($u95Sensor === null
                    ? sprintf('Sertifikat sensor standar %s No. %d — tabel U95-nya nggak punya titik %s °C', $tipe, $noSensorStdev, $this->angka($indexMaks))
                    : sprintf('Sertifikat sensor standar %s No. %d (titik %s °C, U=%s °C, k=2)', $tipe, $noSensorStdev, $this->angka($indexMaks), $this->angka($u95Sensor))),
                'distribusi' => 'normal',
                'u' => ($u95Sensor ?? 0.0) / TabelStandarTids::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => $u95Sensor !== null,
            ],
            [
                'sumber' => 'inhomogenitas_termokopel',
                'keterangan' => $recorder
                    ? sprintf('In-homogeneity termokopel %s °C (literal master, ÷√3)', $this->angka($inhomogenitas))
                    : sprintf(
                        'In-homogeneity termokopel 0,25%% × set point tertinggi %s °C ÷ 2 = %s °C (÷√3)',
                        $this->angka($setPointMaks),
                        $this->angka($inhomogenitas),
                    ),
                'distribusi' => 'persegi',
                'u' => $inhomogenitas / $sqrt3,
                'ci' => 1.0,
                'vi' => $recorder ? self::VI_INHOMOGENITAS_RECORDER : self::VI_PERSEGI,
                'disertakan' => true,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => $ketDrift ?? ($driftMeter === null
                    ? sprintf('Drift kalibrator %s %s — nggak ada di tabel drift', $tercetak, $tipe)
                    : sprintf('Drift kalibrator %s %s (%s °C, ÷√3)', $tercetak, $tipe, $this->angka($driftMeter))),
                'distribusi' => 'persegi',
                'u' => ($driftMeter ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $driftMeter !== null,
            ],
            [
                'sumber' => 'drift_sensor',
                'keterangan' => $driftSensor === null
                    ? sprintf('Drift sensor standar %s — nggak ada di tabel drift', $tipe)
                    : sprintf('Drift sensor standar %s (%s °C, ÷√3)', $tipe, $this->angka($driftSensor)),
                'distribusi' => 'persegi',
                'u' => ($driftSensor ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $driftSensor !== null,
            ],
            [
                'sumber' => 'daya_baca_uut',
                'keterangan' => sprintf('Readability UUT %s °C (÷2, ÷√3)', $this->angka($resolusi)),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DAYA_BACA,
                'disertakan' => true,
            ],
            [
                'sumber' => 'keterulangan_pembacaan',
                'keterangan' => sprintf(
                    'Keterulangan pembacaan STANDAR — STDEV terbesar %s °C dari %d ulangan (÷√%d)',
                    $this->angka($stdevMaks),
                    $nUlangan,
                    $nUlangan,
                ),
                'distribusi' => 't-student',
                'u' => $stdevMaks / sqrt(max(1, $nUlangan)),
                'ci' => 1.0,
                'vi' => max(1, $nUlangan - 1),
                'disertakan' => true,
            ],
            [
                'sumber' => 'stabilitas_media',
                'keterangan' => $blok === null
                    ? sprintf('Stabilitas media kalibrasi dryblock %s — nggak ada tabelnya', $dryblock)
                    : sprintf('Stabilitas media kalibrasi dryblock %s (%s, %s °C, ÷√3)', $dryblock, $blok['nama'], $this->angka($blok['stabilitas'])),
                'distribusi' => 'persegi',
                'u' => ($blok['stabilitas'] ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $blok !== null,
            ],
            [
                'sumber' => 'keseragaman_media',
                'keterangan' => $blok === null
                    ? sprintf('Keseragaman media kalibrasi dryblock %s — nggak ada tabelnya', $dryblock)
                    : sprintf('Keseragaman media kalibrasi dryblock %s (%s, %s °C, ÷√3)', $dryblock, $blok['nama'], $this->angka($blok['keseragaman'])),
                'distribusi' => 'persegi',
                'u' => ($blok['keseragaman'] ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $blok !== null,
            ],
            [
                'sumber' => 'self_heating_rtd',
                'keterangan' => sprintf(
                    'Self heating sensor RTD %s °C (÷√3)%s',
                    $this->angka(self::SELF_HEATING_RTD),
                    $sertakanTiga ? '' : ' — TIDAK ikut dijumlah, `AC36` master berhenti di baris 32',
                ),
                'distribusi' => 'persegi',
                'u' => self::SELF_HEATING_RTD / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $sertakanTiga,
            ],
            [
                'sumber' => 'interpolasi',
                'keterangan' => sprintf(
                    'Ketidakpastian interpolasi %s °C (divisor 1 — satu-satunya komponen persegi yang nggak '
                    .'dibagi √3 di master)%s',
                    $this->angka(self::KETIDAKPASTIAN_INTERPOLASI),
                    $sertakanTiga ? '' : ' — TIDAK ikut dijumlah, `AC36` master berhenti di baris 32',
                ),
                'distribusi' => 'persegi',
                'u' => self::KETIDAKPASTIAN_INTERPOLASI,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $sertakanTiga,
            ],
            [
                'sumber' => 'drift_uut',
                'keterangan' => sprintf(
                    'Drift UUT dari uji titik es 0 °C — ½ × selisih awal-akhir %s °C = %s °C (÷√3)%s',
                    $this->angka($rentangEs),
                    $this->angka(0.5 * $rentangEs),
                    $sertakanTiga ? '' : ' — TIDAK ikut dijumlah, `AC36` master berhenti di baris 32',
                ),
                'distribusi' => 'persegi',
                'u' => (0.5 * $rentangEs) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_PERSEGI,
                'disertakan' => $sertakanTiga,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dipakai
     * @return array{ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float}
     */
    private function agregasi(array $dipakai): array
    {
        $gum = $this->gum ??= new GumCalculator;
        $komponen = array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $dipakai,
        );

        $agg = $gum->agregasiBudget($komponen);

        if (self::FLOOR_V_EFF || $agg['derajat_kebebasan_efektif'] === null) {
            return $agg;
        }

        $k = ($this->t ??= new StudentTDistribution)
            ->quantile(0.975, max(1.0, $agg['derajat_kebebasan_efektif']));

        return $gum->agregasiBudget($komponen, $k);
    }

    /**
     * Jejak audit: tiap penyimpangan master yang ditiru menyebut berapa
     * hasilnya kalau dibetulkan. Angkanya DIHITUNG, bukan ditaksir.
     *
     * @param  list<array<string, mixed>>  $dipakai
     * @param  list<array<string, mixed>>  $budget
     * @return list<array<string, mixed>>
     */
    private function catatanAudit(
        string $keluarga,
        string $tipe,
        array $dipakai,
        array $budget,
        float $uHitung,
        float $cmc,
        float $indexMaks,
        int $noSensor,
    ): array {
        $catatan = [];
        $sqrt3 = sqrt(3.0);

        if ($keluarga === self::KELUARGA_RECORDER) {
            $u95MeterTabel = $this->tabel->u95Meter($keluarga, $tipe, $indexMaks, $this->tabel->kanalRecorder($tipe, $noSensor));
            $u95SensorTabel = $this->tabel->u95Sensor($keluarga, $tipe, $noSensor, $indexMaks);
            $driftTabel = $this->tabel->driftMeter($keluarga, $tipe);

            $ganti = [];

            if ($u95MeterTabel !== null) {
                $ganti['ketidakpastian_standar'] = $u95MeterTabel / TabelStandarTids::K_SERTIFIKAT;
            }

            if ($u95SensorTabel !== null) {
                $ganti['ketidakpastian_sensor'] = $u95SensorTabel / TabelStandarTids::K_SERTIFIKAT;
            }

            if ($driftTabel !== null) {
                $ganti['drift_standar'] = $driftTabel / $sqrt3;
            }

            if ($ganti !== []) {
                $catatan[] = [
                    'kode' => 'tids_recorder_sel_tetap',
                    'pesan' => sprintf(
                        'Workbook Recorder mengambil tiga angka budget dari sel TETAP, bukan dari tabelnya '
                        .'sendiri: U95 kalibrator `T30` = %s °C (tabel U95 recorder %s: %s), U95 sensor literal '
                        .'%s °C (tabel U95 termokopel: %s), drift kalibrator `AM9` = %s °C — sel di tabel '
                        .'KOREKSI, sementara `Tabel_Drift_Recorder` yang ada & bernama berbunyi %s. Ditiru apa '
                        .'adanya supaya cocok dengan sertifikat yang sudah terbit. Kalau ketiganya dibaca dari '
                        .'tabel, U95 sesi ini jadi %s °C, bukan %s °C.',
                        $this->angka(self::U95_METER_RECORDER_TETAP),
                        $tipe,
                        $u95MeterTabel === null ? '(kosong)' : $this->angka($u95MeterTabel),
                        $this->angka(self::U95_SENSOR_RECORDER_TETAP),
                        $u95SensorTabel === null ? '(kosong)' : $this->angka($u95SensorTabel),
                        $this->angka(self::DRIFT_METER_RECORDER_TETAP),
                        $driftTabel === null ? '(kosong)' : $this->angka($driftTabel),
                        $this->angka($this->agregasi($this->gantiKomponen($dipakai, $ganti))['ketidakpastian_diperluas']),
                        $this->angka($uHitung),
                    ),
                ];
            }
        }

        if (! (self::TIGA_KOMPONEN_TERAKHIR[$keluarga] ?? true)) {
            $tambahan = array_values(array_filter(
                $budget,
                static fn (array $k): bool => in_array($k['sumber'], ['self_heating_rtd', 'interpolasi', 'drift_uut'], true),
            ));

            $catatan[] = [
                'kode' => 'tids_tiga_komponen_tidak_dijumlah',
                'pesan' => sprintf(
                    'Workbook Constant/Yokogawa menghitung dua belas komponen lalu menjumlah sembilan — '
                    .'`AC36 = SUM(AC24:AD32)` berhenti sebelum Self Heating, Interpolasi, & Drift UUT. '
                    .'Workbook Recorder untuk alat yang SAMA menjumlah keduabelasnya (`SUM(AC24:AD35)`). '
                    .'Ditiru per workbook. Kalau ketiganya ikut, U95 sesi ini jadi %s °C, bukan %s °C.',
                    $this->angka($this->agregasi([...$dipakai, ...array_map(
                        static fn (array $k): array => [...$k, 'disertakan' => true],
                        $tambahan,
                    )])['ketidakpastian_diperluas']),
                    $this->angka($uHitung),
                ),
            ];
        }

        if (! self::FLOOR_V_EFF) {
            $catatan[] = [
                'kode' => 'v_eff_tidak_dipotong',
                'pesan' => '`v_eff` dipakai apa adanya (pecahan) mengikuti aproksimasi polinomial master, '
                    .'bukan dipotong ke bawah seperti GUM G.4.1 yang dipakai sepuluh alat lain.',
            ];
        }

        if ($cmc > $uHitung) {
            $catatan[] = [
                'kode' => 'lantai_cmc',
                'pesan' => sprintf(
                    'U95 hitung %s °C di bawah CMC terakreditasi %s °C — yang diterbitkan CMC (ILAC-P14).',
                    $this->angka($uHitung),
                    $this->angka($cmc),
                ),
            ];
        }

        return $catatan;
    }

    /**
     * Salinan daftar komponen dengan beberapa `u` diganti — buat menghitung
     * angka "kalau dibetulkan" di catatan audit tanpa menyentuh budget aslinya.
     *
     * @param  list<array<string, mixed>>  $dipakai
     * @param  array<string, float>  $ganti
     * @return list<array<string, mixed>>
     */
    private function gantiKomponen(array $dipakai, array $ganti): array
    {
        return array_map(
            static fn (array $k): array => isset($ganti[$k['sumber']]) ? [...$k, 'u' => $ganti[$k['sumber']]] : $k,
            $dipakai,
        );
    }

    /**
     * Baris ber-STDEV standar terbesar — `MAX(K23:L36)` master.
     *
     * Barisnya, bukan cuma angkanya: `n` ulangan & No. Termokopel-nya ikut
     * menentukan pembagi `√n`, `vi = n−1`, dan kolom U95 sensor.
     *
     * @param  list<array<string, mixed>>  $siap
     * @return array<string, mixed>
     */
    private function barisStdevTerbesar(array $siap): array
    {
        $terpilih = $siap[0];

        foreach ($siap as $t) {
            if ($t['standar_deviasi_standar'] > $terpilih['standar_deviasi_standar']) {
                $terpilih = $t;
            }
        }

        return $terpilih;
    }

    /**
     * `P51 = ABS(N50−P50)` — selisih pembacaan awal & akhir uji titik es.
     *
     * Kurang dari dua angka = nol, dan nol itu jawaban yang benar: tanpa dua
     * pembacaan tidak ada selisih yang bisa dihitung. Komponen `drift_uut`
     * jadi nol dan `TidsProfile::peringatanSesi()` yang memberitahu teknisi.
     *
     * @param  list<float|string|null>  $titikEs
     */
    private function rentangTitikEs(array $titikEs): float
    {
        $angka = array_values(array_map('floatval', array_filter(
            $titikEs,
            static fn ($v): bool => $v !== null && $v !== '' && is_numeric($v),
        )));

        return count($angka) < 2 ? 0.0 : abs(max($angka) - min($angka));
    }

    /**
     * @param  list<array{titik_ke: int, alasan: string}>  $belum
     * @return array<string, mixed>
     */
    private function sesiKosong(array $belum): array
    {
        return [
            'titik' => [],
            'belum_dihitung' => $belum,
            'standar_deviasi_maks' => 0.0,
            'standar_deviasi_maks_uut' => 0.0,
            'index_maks' => null,
            'set_point_maks' => 0.0,
            'rentang_titik_es' => 0.0,
            'budget' => [],
            'ketidakpastian_gabungan' => 0.0,
            'derajat_kebebasan_efektif' => null,
            'faktor_cakupan_k' => 0.0,
            'ketidakpastian_diperluas' => 0.0,
            'cmc' => 0.0,
            'u95_sertifikat' => 0.0,
            'sumber_u95' => 'hitung',
            'catatan_audit' => [],
        ];
    }

    /**
     * STDEV sampel (`STDEV()` Excel, pembagi n−1).
     *
     * @param  list<float>  $nilai
     */
    private function stdev(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;
        $jumlah = array_sum(array_map(static fn (float $v): float => ($v - $rata) ** 2, $nilai));

        return sqrt($jumlah / ($n - 1));
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 6, ',', '.'), '0'), ',');
    }
}
