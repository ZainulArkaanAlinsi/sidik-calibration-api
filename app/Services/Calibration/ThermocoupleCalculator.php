<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use InvalidArgumentException;

/**
 * Mesin hitung **Thermocouple** (alat ke-18, lampiran akreditasi LK-285-IDN
 * "Suhu dan Kelembapan" no. 5) — MURNI: masuk array, keluar array, tidak
 * menyentuh DB, request, atau Eloquent.
 *
 * Master: `Master_Olah_Data_Suhu_Thermocouple.xlsm`, sesi **0513-CAL-1124**
 * (order 2411.50.I, PT Kaldu Sari Nabati Indonesia, Hanna HI93530 s/n J0037794,
 * 3 Desember 2024), sheet `PERHITUNGAN FC` + `PERHITUNGAN U95%`.
 *
 * ## Yang dikalibrasi: termokopel BESERTA indikatornya
 *
 * Bedanya dari TITS (indikator saja, sensornya kalibrator yang menirukan) dan
 * dari Enclosure (ruang, bukan alat ukur): di sini UUT-nya termometer termokopel
 * utuh — probe + meter — dicelup ke dryblock bareng probe standar lab, lalu
 * dua-duanya dibaca bergantian tiap 10 detik dalam satu sapuan 90 detik:
 *
 *   0″ standar · 10″ UUT · 20″ standar · 30″ UUT · … · 80″ standar · 90″ UUT
 *
 * Jadi tiap titik punya DUA deret lima pembacaan, dan dua-duanya masuk
 * perhitungan dengan peran yang berbeda. Itu sebabnya alat ini tidak muat di
 * jalur datar `measurements[i].pembacaan` yang dipakai sepuluh alat lain — lihat
 * [ThermocoupleProfile::butuhPasanganStandarUut].
 *
 * ## Rantai koreksinya BEDA antara sisi standar dan sisi UUT
 *
 * Ini yang paling gampang diseragamkan dan paling mahal kalau diseragamkan:
 *
 *   sisi standar  →  rata² + koreksi METER kalibrator + koreksi PROBE standar
 *   sisi UUT      →  rata² + koreksi METER kalibrator          (probe TIDAK)
 *
 * `S23 = J23+Q23+R23` versus `S42 = J42+Q42`. Alasannya fisik: probe standar lab
 * cuma ada di sisi standar — UUT membawa probe-nya sendiri, dan justru
 * penyimpangan probe itu yang sedang diukur. Menambahkan koreksi probe standar
 * ke sisi UUT berarti mengoreksi UUT dengan kesalahan alat lain, dan kolom
 * `Correction` bergeser sebesar koreksi probe (di sesi contoh sampai 0,275 °C)
 * tanpa satu pun angka kelihatan janggal.
 *
 * ## Index dicocokkan ke RATA-RATA, bukan ke set point
 *
 * Lihat [TabelKalibratorSuhu3Alat] — set point 150 °C yang terbaca 150,1
 * mengambil baris tabel 200, bukan 100.
 *
 * ## Budget: SATU untuk seluruh sesi, dan TANPA komponen keterulangan
 *
 * `PERHITUNGAN U95%` punya satu tabel sembilan komponen (`AC29 =
 * SUM(AC20:AD28)`), dan tidak satu pun di antaranya STDEV pembacaan — walaupun
 * `PERHITUNGAN FC` menghitung `M23 = MAX(K23:L36)` dan memajangnya. Angka itu
 * lahir, ditampilkan, lalu tidak dipakai siapa pun.
 *
 * Itu **tidak ditiru diam-diam**: `standar_deviasi_maks` tetap dihitung dan ikut
 * dipulangkan, dan tiap sesi melahirkan catatan audit `type_a_tidak_masuk_budget`
 * yang menyebut berapa U95-nya kalau komponen itu disertakan. Yang memutuskan
 * manajer teknis lab, bukan diam-diam kode ini.
 *
 * ## Yang TIDAK ditiru: sel kosong dibaca nol
 *
 * Tiap VLOOKUP di master dibungkus `IFNA(…,"")`. Kombinasi (merk, tipe sensor,
 * titik) yang tidak ada di tabel karena itu memulangkan KOSONG, dan kosong ikut
 * dijumlah `J+Q+R` sebagai nol — sertifikat terbit dengan koreksi kalibrator
 * yang hilang, tanpa error di mana pun. Di sini titik seperti itu DIBLOKIR
 * dengan alasan yang kebaca. Bahayanya nyata, bukan teoretis: tabel Yokogawa
 * alat ini datang dari cache tautan luar yang memang berlubang.
 *
 * @see ThermocoupleProfile
 * @see TabelKalibratorSuhu3Alat
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class ThermocoupleCalculator
{
    /** Stabilitas cold junction, `PERHITUNGAN U95%!N22 = 0.1` °C — konstanta lab. */
    public const STABILITAS_COLD_JUNCTION = 0.1;

    /** Pengaruh AC Pick-up, `N25 = 0.1` °C — konstanta lab (induksi jala-jala). */
    public const AC_PICK_UP = 0.1;

    /** `vi` sertifikat kalibrator, sensor, cold junction & AC pick-up (`S20`–`S22`, `S25`). */
    public const VI_SERTIFIKAT = 200;

    /** `vi` drift kalibrator & drift sensor (`S23`, `S24`). */
    public const VI_DRIFT = 50;

    /** `vi` AC pick-up (`S25 = 1000000`) — praktis tak hingga. */
    public const VI_AC_PICK_UP = 1000000;

    /** `vi` variasi aksial, antar-lubang & daya baca (`S26`–`S28`). */
    public const VI_DRYBLOCK = 8;

    /**
     * `v_eff` **tidak** dipotong ke bawah sebelum dicari `k`-nya.
     *
     * Sama seperti TITS: master memakai aproksimasi polinomial t-student atas
     * `v_eff` pecahan apa adanya (`AC32 = 1.95996 + 2.37356/v + …`), sementara
     * `GumCalculator::agregasiBudget()` memotong (GUM G.4.1). Di sesi contoh
     * `v_eff` 196,89 selisihnya jauh di bawah presisi cetak, tapi aturannya
     * dipegang konsisten supaya sesi ber-`v_eff` kecil tidak diam-diam berbeda
     * dari sertifikat yang sudah terbit.
     */
    public const FLOOR_V_EFF = false;

    private ?GumCalculator $gum = null;

    private ?StudentTDistribution $t = null;

    public function __construct(private readonly TabelKalibratorSuhu3Alat $tabel = new TabelKalibratorSuhu3Alat) {}

    /**
     * Hitung satu sesi Thermocouple utuh.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>, no_probe: int}>  $titik
     * @param  array{merk_kalibrator: string, tipe_sensor: string, dryblock: string, resolusi: float, cmc: float}  $spek
     * @return array{
     *     titik: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>,
     *     standar_deviasi_maks: float, index_maks: float|null, budget: list<array<string, mixed>>,
     *     ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float,
     *     ketidakpastian_diperluas: float, cmc: float, u95_sertifikat: float, sumber_u95: string,
     *     catatan_audit: list<array<string, mixed>>
     * }
     */
    public function hitungSesi(array $titik, array $spek): array
    {
        if ($titik === []) {
            throw new InvalidArgumentException(
                'Sesi Thermocouple nggak punya satu pun titik terisi — budget ketidakpastiannya nggak bisa disusun.',
            );
        }

        $merk = (string) $spek['merk_kalibrator'];
        $tipe = (string) $spek['tipe_sensor'];
        $dryblock = strtoupper((string) $spek['dryblock']);
        $resolusi = (float) $spek['resolusi'];

        if (! in_array($merk, TabelKalibratorSuhu3Alat::MERK, true)) {
            throw new InvalidArgumentException("Merk kalibrator `{$merk}` nggak punya tabel koreksi Thermocouple.");
        }

        if (! in_array($tipe, TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR, true)) {
            throw new InvalidArgumentException("Tipe sensor standar `{$tipe}` nggak dikenal.");
        }

        if (! is_finite($resolusi) || $resolusi <= 0.0) {
            throw new InvalidArgumentException(
                'Resolusi indikator wajib angka > 0 — komponen daya baca budget-nya lahir dari situ.',
            );
        }

        $siap = [];
        $belum = [];

        foreach ($titik as $t) {
            $hasil = $this->hitungTitik($t, $merk, $tipe);

            if (isset($hasil['alasan'])) {
                $belum[] = ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $hasil['alasan']];

                continue;
            }

            $siap[] = $hasil;
        }

        if ($siap === []) {
            usort($belum, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            return $this->sesiKosong($belum);
        }

        // `P37 = MAX(P23:P36)` — index tabel TERTINGGI di antara pembacaan
        // STANDAR sesi ini. Dia yang memilih baris U95 kalibrator & U95 probe,
        // jadi satu titik panas menaikkan budget seluruh sesi. Itu memang arah
        // konservatif yang dipilih master.
        $indexMaks = max(array_column($siap, 'index_standar'));
        $stdevMaks = max(array_map(
            static fn (array $t): float => max($t['standar_deviasi_standar'], $t['standar_deviasi_uut']),
            $siap,
        ));

        $budget = $this->budget($merk, $tipe, $dryblock, $resolusi, $indexMaks);
        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));
        $agg = $this->agregasi($dipakai);

        $cmc = (float) $spek['cmc'];
        $uHitung = $agg['ketidakpastian_diperluas'];

        usort($belum, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        return [
            'titik' => $siap,
            'belum_dihitung' => $belum,
            'standar_deviasi_maks' => $stdevMaks,
            'index_maks' => $indexMaks,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $uHitung,
            'cmc' => $cmc,
            // Lantai CMC (ILAC-P14, `AC35 = MAX(AC33:AI34)`): lab tidak boleh
            // mengklaim ketidakpastian lebih baik dari kemampuan
            // terakreditasinya.
            'u95_sertifikat' => max($uHitung, $cmc),
            'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
            'catatan_audit' => $this->catatanAudit($dipakai, $stdevMaks, count($siap), $uHitung, $cmc),
        ];
    }

    /**
     * Satu titik: dua deret pembacaan → dua nilai terkoreksi → koreksi.
     *
     * @param  array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>, no_probe: int}  $t
     * @return array<string, mixed>
     */
    private function hitungTitik(array $t, string $merk, string $tipe): array
    {
        $standar = array_values(array_map('floatval', $t['standar']));
        $uut = array_values(array_map('floatval', $t['uut']));
        $noProbe = (int) $t['no_probe'];
        $alat = TabelKalibratorSuhu3Alat::ALAT_THERMOCOUPLE;

        if (count($standar) < 2 || count($uut) < 2) {
            return ['alasan' => sprintf(
                'Titik %s °C baru punya %d pembacaan standar & %d pembacaan UUT — tiap sisi butuh minimal 2 '
                .'biar STDEV-nya ada artinya.',
                $this->angka((float) $t['titik_ukur']),
                count($standar),
                count($uut),
            )];
        }

        $probe = $this->tabel->probe($alat, $tipe, $noProbe);

        if ($probe === null) {
            $tersedia = $this->tabel->nomorProbeTersedia($alat, $tipe);

            return ['alasan' => sprintf(
                'No. Termokopel %d nggak ada di standar %s — yang punya sertifikat cuma nomor %s. Master '
                .'membiarkan pasangan yang nggak cocok pulang KOSONG lalu menjumlahkannya sebagai nol; di sini '
                .'titiknya ditahan supaya koreksi probe nggak hilang diam-diam.',
                $noProbe,
                $tipe,
                $tersedia === [] ? '(nggak ada)' : implode(', ', $tersedia),
            )];
        }

        $avgStandar = array_sum($standar) / count($standar);
        $avgUut = array_sum($uut) / count($uut);

        $indexStandar = $this->tabel->indeksTerdekat($alat, $avgStandar);
        $indexUut = $this->tabel->indeksTerdekat($alat, $avgUut);

        if ($indexStandar === null || $indexUut === null) {
            return ['alasan' => 'Tabel index kalibrator Thermocouple kosong — nggak ada titik yang bisa dicocokkan.'];
        }

        $koreksiMeterStandar = $this->tabel->koreksiKalibrator($alat, $merk, $tipe, $indexStandar);
        $koreksiProbe = $this->tabel->koreksiSensor($alat, $probe, $indexStandar);
        $koreksiMeterUut = $this->tabel->koreksiKalibrator($alat, $merk, $tipe, $indexUut);

        foreach ([
            [$koreksiMeterStandar, sprintf('koreksi meter %s %s di titik %s °C', $merk, $tipe, $this->angka($indexStandar))],
            [$koreksiProbe, sprintf('koreksi probe %s di titik %s °C', $probe, $this->angka($indexStandar))],
            [$koreksiMeterUut, sprintf('koreksi meter %s %s di titik %s °C', $merk, $tipe, $this->angka($indexUut))],
        ] as [$nilai, $sebutan]) {
            if ($nilai === null) {
                return ['alasan' => sprintf(
                    'Tabel standar Thermocouple nggak punya %s. Tabel Yokogawa alat ini diambil dari cache '
                    .'tautan luar yang cuma menyimpan sel yang pernah ditarik, jadi lubang begini memang '
                    .'mungkin — titiknya ditahan, bukan dikoreksi nol.',
                    $sebutan,
                )];
            }
        }

        $standarTerkoreksi = $avgStandar + $koreksiMeterStandar + $koreksiProbe;
        $uutTerkoreksi = $avgUut + $koreksiMeterUut;

        return [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => (float) $t['titik_ukur'],
            'no_probe' => $noProbe,
            'probe' => $probe,
            'pembacaan_standar' => $standar,
            'pembacaan_uut' => $uut,
            'rata_rata_standar' => $avgStandar,
            'rata_rata_uut' => $avgUut,
            'standar_deviasi_standar' => $this->stdev($standar),
            'standar_deviasi_uut' => $this->stdev($uut),
            'index_standar' => $indexStandar,
            'index_uut' => $indexUut,
            'koreksi_meter_standar' => $koreksiMeterStandar,
            'koreksi_probe_standar' => $koreksiProbe,
            'koreksi_meter_uut' => $koreksiMeterUut,
            'standar_terkoreksi' => $standarTerkoreksi,
            'uut_terkoreksi' => $uutTerkoreksi,
            // `SERTIFIKAT!L20 = E20-J20` — standar dikurangi UUT. Kalau
            // dibalik, alat yang membaca 0,48 °C terlalu tinggi tercetak
            // sebagai membaca 0,48 °C terlalu rendah.
            'koreksi' => $standarTerkoreksi - $uutTerkoreksi,
        ];
    }

    /**
     * Sembilan komponen `PERHITUNGAN U95%!B20:B28`, urut seperti di master.
     *
     * @return list<array<string, mixed>>
     */
    private function budget(string $merk, string $tipe, string $dryblock, float $resolusi, float $indexMaks): array
    {
        $sqrt3 = sqrt(3.0);
        $alat = TabelKalibratorSuhu3Alat::ALAT_THERMOCOUPLE;
        $merkTercetak = TabelKalibratorSuhu3Alat::MERK_TERCETAK[$merk] ?? $merk;

        $u95Meter = $this->tabel->u95Kalibrator($alat, $merk, $tipe, $indexMaks);

        // `O21` memilih kolom U95 probe dari TIPE sensornya saja — kolom 3 untuk
        // Type K, 19 untuk Type N, 2 untuk RTD — jadi selalu probe PERTAMA tipe
        // itu, bukan probe yang benar-benar dipakai titik mana pun. Ditiru,
        // karena seluruh kolom TCK-01..TCK-13 (dan TCN3..TCN12) memang berisi
        // angka yang sama; yang berbeda cuma TCK-14..16 yang bernilai 0 dan
        // memang belum pernah dipakai. Kalau lab suatu saat mengisi ketiganya,
        // catatan audit di bawah yang memunculkannya.
        $probeUtama = $this->tabel->probe($alat, $tipe, $this->tabel->nomorProbeTersedia($alat, $tipe)[0] ?? 0);
        $u95Probe = $probeUtama === null ? null : $this->tabel->u95Sensor($alat, $probeUtama, $indexMaks);

        $driftMeter = $this->tabel->driftKalibrator($alat, $merk, $tipe);
        $driftProbe = $this->tabel->driftSensor($alat, $tipe);
        $blok = $this->tabel->dryblock($dryblock);

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => $u95Meter === null
                    ? sprintf('Sertifikat kalibrator %s %s — tabel U95-nya nggak punya titik %s °C', $merkTercetak, $tipe, $this->angka($indexMaks))
                    : sprintf('Sertifikat kalibrator %s %s (titik %s °C, U=%s °C, k=2)', $merkTercetak, $tipe, $this->angka($indexMaks), $this->angka($u95Meter)),
                'distribusi' => 'normal',
                'u' => ($u95Meter ?? 0.0) / TabelKalibratorSuhu3Alat::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => $u95Meter !== null,
            ],
            [
                'sumber' => 'ketidakpastian_sensor',
                'keterangan' => $u95Probe === null
                    ? sprintf('Sertifikat probe standar %s — tabel U95-nya nggak punya titik %s °C', $tipe, $this->angka($indexMaks))
                    : sprintf('Sertifikat probe standar %s (%s, titik %s °C, U=%s °C, k=2)', $tipe, $probeUtama, $this->angka($indexMaks), $this->angka($u95Probe)),
                'distribusi' => 'normal',
                'u' => ($u95Probe ?? 0.0) / TabelKalibratorSuhu3Alat::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => $u95Probe !== null,
            ],
            [
                'sumber' => 'stabilitas_cold_junction',
                'keterangan' => sprintf('Stabilitas cold junction %s °C (÷√3)', $this->angka(self::STABILITAS_COLD_JUNCTION)),
                'distribusi' => 'normal',
                'u' => self::STABILITAS_COLD_JUNCTION / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => true,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => $driftMeter === null
                    ? sprintf('Drift kalibrator %s %s — nggak ada di tabel drift', $merkTercetak, $tipe)
                    : sprintf('Drift kalibrator %s %s (%s °C, ÷2)', $merkTercetak, $tipe, $this->angka($driftMeter)),
                'distribusi' => 'normal',
                'u' => ($driftMeter ?? 0.0) / TabelKalibratorSuhu3Alat::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $driftMeter !== null,
            ],
            [
                'sumber' => 'drift_sensor',
                'keterangan' => $driftProbe === null
                    ? sprintf('Drift probe standar %s — nggak ada di tabel drift', $tipe)
                    : sprintf('Drift probe standar %s (%s °C, ÷2)', $tipe, $this->angka($driftProbe)),
                'distribusi' => 'normal',
                'u' => ($driftProbe ?? 0.0) / TabelKalibratorSuhu3Alat::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $driftProbe !== null,
            ],
            [
                'sumber' => 'ac_pick_up',
                'keterangan' => sprintf('Pengaruh AC Pick-up %s °C (÷√3)', $this->angka(self::AC_PICK_UP)),
                'distribusi' => 'persegi',
                'u' => self::AC_PICK_UP / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_AC_PICK_UP,
                'disertakan' => true,
            ],
            [
                'sumber' => 'variasi_aksial',
                'keterangan' => $blok === null
                    ? sprintf('Variasi aksial dryblock %s — nggak ada tabelnya', $dryblock)
                    : sprintf('Variasi aksial dryblock %s (%s °C, ÷√3)', $dryblock, $this->angka($blok['axial_u'])),
                'distribusi' => 'persegi',
                'u' => ($blok['axial_u'] ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRYBLOCK,
                'disertakan' => $blok !== null,
            ],
            [
                'sumber' => 'variasi_antar_lubang',
                'keterangan' => $blok === null
                    ? sprintf('Variasi antar-lubang dryblock %s — nggak ada tabelnya', $dryblock)
                    : sprintf('Variasi antar-lubang dryblock %s (%s °C, ÷√3)', $dryblock, $this->angka($blok['radial_max'])),
                'distribusi' => 'persegi',
                'u' => ($blok['radial_max'] ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRYBLOCK,
                'disertakan' => $blok !== null,
            ],
            [
                'sumber' => 'daya_baca_indikator',
                'keterangan' => sprintf('Daya baca indikator %s °C (÷2, ÷√3)', $this->angka($resolusi)),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRYBLOCK,
                'disertakan' => true,
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
     * Jejak audit: tiap keputusan yang menyimpang dari GUM lurus menyebut berapa
     * hasilnya kalau dibetulkan.
     *
     * @param  list<array<string, mixed>>  $dipakai
     * @return list<array<string, mixed>>
     */
    private function catatanAudit(array $dipakai, float $stdevMaks, int $jumlahTitik, float $uHitung, float $cmc): array
    {
        $catatan = [];

        // Komponen keterulangan yang master hitung tapi tidak pakai. Angka
        // "kalau disertakan"-nya dihitung beneran, bukan ditaksir.
        if ($stdevMaks > 0.0) {
            $n = 5;
            $dengan = [...$dipakai, ['u' => $stdevMaks / sqrt($n), 'ci' => 1.0, 'vi' => $n - 1]];
            $aggDengan = $this->agregasi($dengan);

            $catatan[] = [
                'kode' => 'type_a_tidak_masuk_budget',
                'pesan' => sprintf(
                    'Master menghitung STDEV terbesar %s °C dari %d titik (`PERHITUNGAN FC!M23`) lalu TIDAK '
                    .'memasukkannya ke budget — sembilan komponen `AC29` semuanya Type B. Ditiru apa adanya. '
                    .'Kalau komponen keterulangan itu disertakan (÷√%d, vi %d), U95 sesi ini jadi %s °C, bukan %s °C.',
                    $this->angka($stdevMaks),
                    $jumlahTitik,
                    $n,
                    $n - 1,
                    $this->angka($aggDengan['ketidakpastian_diperluas']),
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
     * @param  list<array{titik_ke: int, alasan: string}>  $belum
     * @return array<string, mixed>
     */
    private function sesiKosong(array $belum): array
    {
        return [
            'titik' => [],
            'belum_dihitung' => $belum,
            'standar_deviasi_maks' => 0.0,
            'index_maks' => null,
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
