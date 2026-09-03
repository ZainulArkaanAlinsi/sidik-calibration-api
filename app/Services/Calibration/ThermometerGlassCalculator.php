<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use InvalidArgumentException;

/**
 * Mesin hitung **Termometer Gelas** (alat ke-19, lampiran akreditasi LK-285-IDN
 * "Suhu dan Kelembapan" no. 4) — MURNI: masuk array, keluar array.
 *
 * Master: `Master_Olah_Data_Suhu_Thermometer_Glass.xlsm`, sesi **0135-CAL-125**
 * (order 2501.16.G, PT Unilever Indonesia Tbk Skin Care Factory, Alla France
 * analog s/n IND-140, 31 Januari 2025).
 *
 * ## Sisi UUT TIDAK dikoreksi — dan itu bukan kelalaian master
 *
 * `S23 = J23+Q23+R23` untuk sisi standar, tapi sisi UUT berhenti di `J42` —
 * kolom `UUT Terkoreksi` memang tidak ada di sheet-nya. Alasannya fisik dan
 * menentukan: termometer gelas dibaca dengan MATA dari kolom raksa. Tidak ada
 * meter, tidak ada kalibrator di jalur itu, jadi tidak ada koreksi instrumen
 * yang bisa ditempelkan.
 *
 * Bedanya dari Thermocouple penting untuk tidak diseragamkan: di sana UUT punya
 * meter sendiri dan `S42 = J42+Q42`. Menyalin rumus Thermocouple ke sini akan
 * menambahkan koreksi kalibrator ke pembacaan mata manusia — kolom `Correction`
 * bergeser sebesar koreksi meter (0,325…0,4 °C di sesi contoh), yaitu lebih
 * besar daripada seluruh angka koreksi yang sedang dilaporkan.
 *
 * ## Dua baris keterulangan bersebelahan, dua pembagi berbeda
 *
 * Ini penyimpangan master yang paling halus di alat ini:
 *
 *   `U26 = N26/SQRT(Q26)`   pengulangan UUT      → ÷√5 = 2,2360  (benar)
 *   `U27 = N27/Q27`         pengulangan STANDAR  → ÷5           (bukan √5)
 *
 * `Q26` dan `Q27` dua-duanya berisi 5, `S26`/`S27` dua-duanya 4 — jadi ini
 * bukan pembagi yang sengaja beda arti, melainkan satu `SQRT` yang hilang di
 * baris kedua. Ditiru ([PEMBAGI_PENGULANGAN_STANDAR]) karena sertifikatnya
 * sudah terbit, dan tiap sesi melahirkan catatan audit `pengulangan_standar_dibagi_n`
 * yang menyebut berapa U95-nya kalau dibetulkan. Di sesi contoh: 1,1174 → 1,1268 °C.
 *
 * ## Uji titik es itu KOMPONEN BUDGET, bukan catatan
 *
 * `N29 = 'PERHITUNGAN FC'!Q46/2` — rentang (Tmax − Tmin) tiga pembacaan titik es
 * 30 menit, dibagi dua, distribusi persegi. Di sesi contoh ketiganya 0,0 jadi
 * komponennya nol dan tidak terlihat mengubah apa pun — justru itu yang bikin
 * dia gampang dikira hiasan. Termometer yang titik esnya melar 0,4 °C menyumbang
 * `u` 0,1155 °C, dan itu bukan angka yang boleh hilang.
 *
 * @see ThermometerGlassProfile
 * @see TabelKalibratorSuhu3Alat
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class ThermometerGlassCalculator
{
    /**
     * Pembagi komponen keterulangan STANDAR: **5**, bukan √5.
     *
     * Lihat blok "Dua baris keterulangan bersebelahan" di docblock kelas.
     */
    public const PEMBAGI_PENGULANGAN_STANDAR = 5.0;

    /** Jumlah pembacaan per titik yang jadi pembagi Type A (`Q26`/`Q27` = 5). */
    public const N_PENGULANGAN = 5;

    /** `vi` sertifikat kalibrator, probe & resolusi standar (`S20`–`S22`). */
    public const VI_SERTIFIKAT = 200;

    /** `vi` drift kalibrator & probe, variasi/stabilitas bath, titik es (`S23`–`S24`, `S28`–`S30`). */
    public const VI_DRIFT = 50;

    /** `vi` resolusi alat yang dikalibrasi (`S25 = 1000000`). */
    public const VI_RESOLUSI_UUT = 1000000;

    /** `vi` kedua komponen keterulangan (`S26`/`S27` = 4 = n − 1). */
    public const VI_PENGULANGAN = 4;

    /** Lihat [ThermocoupleCalculator::FLOOR_V_EFF] — alasannya sama. */
    public const FLOOR_V_EFF = false;

    private ?GumCalculator $gum = null;

    private ?StudentTDistribution $t = null;

    public function __construct(private readonly TabelKalibratorSuhu3Alat $tabel = new TabelKalibratorSuhu3Alat) {}

    /**
     * @param  list<array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>}>  $titik
     * @param  array{merk_kalibrator: string, tipe_sensor: string, no_probe: int, oilbath: string, resolusi: float, resolusi_standar: float, cmc: float, titik_es?: list<float>}  $spek
     * @return array<string, mixed>
     */
    public function hitungSesi(array $titik, array $spek): array
    {
        if ($titik === []) {
            throw new InvalidArgumentException(
                'Sesi Termometer Gelas nggak punya satu pun titik terisi — budget-nya nggak bisa disusun.',
            );
        }

        $merk = (string) $spek['merk_kalibrator'];
        $tipe = (string) $spek['tipe_sensor'];
        $oilbath = strtolower((string) $spek['oilbath']);
        $resolusi = (float) $spek['resolusi'];
        $resolusiStandar = (float) $spek['resolusi_standar'];

        if (! in_array($merk, TabelKalibratorSuhu3Alat::MERK, true)) {
            throw new InvalidArgumentException("Merk kalibrator `{$merk}` nggak punya tabel koreksi Termometer Gelas.");
        }

        if (! in_array($tipe, TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR, true)) {
            throw new InvalidArgumentException("Tipe sensor standar `{$tipe}` nggak dikenal.");
        }

        if (! is_finite($resolusi) || $resolusi <= 0.0) {
            throw new InvalidArgumentException('Resolusi termometer gelas wajib angka > 0 (skala terkecilnya).');
        }

        $alat = TabelKalibratorSuhu3Alat::ALAT_GLASS;
        $probe = $this->tabel->probe($alat, $tipe, (int) $spek['no_probe']);

        if ($probe === null) {
            throw new InvalidArgumentException(sprintf(
                'No. probe %d nggak ada di standar %s — yang punya sertifikat cuma nomor %s.',
                (int) $spek['no_probe'],
                $tipe,
                implode(', ', $this->tabel->nomorProbeTersedia($alat, $tipe)),
            ));
        }

        $siap = [];
        $belum = [];

        foreach ($titik as $t) {
            $hasil = $this->hitungTitik($t, $merk, $tipe, $probe);

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

        $indexMaks = max(array_column($siap, 'index_standar'));
        $stdevStandar = max(array_column($siap, 'standar_deviasi_standar'));
        $stdevUut = max(array_column($siap, 'standar_deviasi_uut'));

        $titikEs = array_values(array_map('floatval', $spek['titik_es'] ?? []));
        // `Q46 = Tmax − Tmin` — RENTANG tiga pembacaan, bukan STDEV-nya.
        $rentangTitikEs = $titikEs === [] ? 0.0 : max($titikEs) - min($titikEs);

        $budget = $this->budget(
            $merk, $tipe, $probe, $oilbath, $resolusi, $resolusiStandar,
            $indexMaks, $stdevStandar, $stdevUut, $rentangTitikEs,
        );
        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));
        $agg = $this->agregasi($dipakai);

        $cmc = (float) $spek['cmc'];
        $uHitung = $agg['ketidakpastian_diperluas'];

        return [
            'titik' => $siap,
            'belum_dihitung' => $belum,
            'probe' => $probe,
            'index_maks' => $indexMaks,
            'standar_deviasi_maks_standar' => $stdevStandar,
            'standar_deviasi_maks_uut' => $stdevUut,
            'rentang_titik_es' => $rentangTitikEs,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $uHitung,
            'cmc' => $cmc,
            'u95_sertifikat' => max($uHitung, $cmc),
            'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
            'catatan_audit' => $this->catatanAudit($dipakai, $stdevStandar, $uHitung, $cmc),
        ];
    }

    /**
     * @param  array{titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>}  $t
     * @return array<string, mixed>
     */
    private function hitungTitik(array $t, string $merk, string $tipe, string $probe): array
    {
        $standar = array_values(array_map('floatval', $t['standar']));
        $uut = array_values(array_map('floatval', $t['uut']));
        $alat = TabelKalibratorSuhu3Alat::ALAT_GLASS;

        // `< 2` di KEDUA sisi, sama dengan `ThermocoupleCalculator` dan
        // `ThermohygroCalculator`. Sebelum ini sisi UUT-nya `< 1`, dan itu
        // satu-satunya dari empat kalkulator suhu yang begitu.
        //
        // Yang dihasilkan asimetri itu bukan penolakan, tapi ANGKA: `stdev()`
        // memulangkan `0.0` untuk n < 2, dan nilai itu masuk komponen budget
        // `pengulangan_uut` yang `disertakan => true` TANPA SYARAT. Jadi satu
        // pembacaan UUT tidak menghasilkan "tidak ada sebaran" — dia
        // menghasilkan "sebarannya nol", dan U95 di sertifikat jadi lebih kecil
        // dari yang bisa dipertanggungjawabkan.
        //
        // Kelas kesalahan ini sudah dijelaskan panjang di `GumCalculator`:
        // *"Satu pembacaan nggak punya sebaran… sertifikatnya bakal ngeklaim
        // ketidakpastian lebih bagus dari yang bisa dibuktiin. Salah yang
        // diam-diam."* Penjaganya ada di sana; yang tidak ada jalannya ke sisi
        // UUT kalkulator ini.
        //
        // Ini TIDAK memblokir teknisi di lapangan: titiknya ditahan dengan
        // alasan yang kebaca, sesinya tetap tersimpan, dan penerbitan
        // sertifikatnya yang ketahan `CalibrationValidator` — pola yang sama
        // dengan tiga penjagaan lain di method ini.
        if (count($standar) < 2 || count($uut) < 2) {
            return ['alasan' => sprintf(
                'Titik %s °C baru punya %d pembacaan standar & %d pembacaan UUT — tiap sisi butuh minimal 2 '
                .'biar STDEV-nya ada artinya.',
                $this->angka((float) $t['titik_ukur']),
                count($standar),
                count($uut),
            )];
        }

        $avgStandar = array_sum($standar) / count($standar);
        $avgUut = array_sum($uut) / count($uut);
        $indexStandar = $this->tabel->indeksTerdekat($alat, $avgStandar);

        if ($indexStandar === null) {
            return ['alasan' => 'Tabel index kalibrator Termometer Gelas kosong — nggak ada titik yang bisa dicocokkan.'];
        }

        $koreksiMeter = $this->tabel->koreksiKalibrator($alat, $merk, $tipe, $indexStandar);
        $koreksiProbe = $this->tabel->koreksiSensor($alat, $probe, $indexStandar);

        if ($koreksiMeter === null || $koreksiProbe === null) {
            return ['alasan' => sprintf(
                'Tabel standar Termometer Gelas nggak punya %s di titik %s °C — titiknya ditahan, bukan '
                .'dikoreksi nol.',
                $koreksiMeter === null ? sprintf('koreksi meter %s %s', $merk, $tipe) : sprintf('koreksi probe %s', $probe),
                $this->angka($indexStandar),
            )];
        }

        $standarTerkoreksi = $avgStandar + $koreksiMeter + $koreksiProbe;

        return [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => (float) $t['titik_ukur'],
            'pembacaan_standar' => $standar,
            'pembacaan_uut' => $uut,
            'rata_rata_standar' => $avgStandar,
            'rata_rata_uut' => $avgUut,
            'standar_deviasi_standar' => $this->stdev($standar),
            'standar_deviasi_uut' => $this->stdev($uut),
            'index_standar' => $indexStandar,
            'koreksi_meter_standar' => $koreksiMeter,
            'koreksi_probe_standar' => $koreksiProbe,
            'standar_terkoreksi' => $standarTerkoreksi,
            // Sisi UUT apa adanya — lihat docblock kelas.
            'uut_terkoreksi' => $avgUut,
            'koreksi' => $standarTerkoreksi - $avgUut,
        ];
    }

    /**
     * Sebelas komponen `PERHITUNGAN U95%!B20:B30`, urut seperti di master.
     *
     * @return list<array<string, mixed>>
     */
    private function budget(
        string $merk,
        string $tipe,
        string $probe,
        string $oilbath,
        float $resolusi,
        float $resolusiStandar,
        float $indexMaks,
        float $stdevStandar,
        float $stdevUut,
        float $rentangTitikEs,
    ): array {
        $sqrt3 = sqrt(3.0);
        $alat = TabelKalibratorSuhu3Alat::ALAT_GLASS;
        $merkTercetak = TabelKalibratorSuhu3Alat::MERK_TERCETAK[$merk] ?? $merk;

        $u95Meter = $this->tabel->u95Kalibrator($alat, $merk, $tipe, $indexMaks);
        $u95Probe = $this->tabel->u95Sensor($alat, $probe, $indexMaks);
        $driftMeter = $this->tabel->driftKalibrator($alat, $merk, $tipe);
        $driftProbe = $this->tabel->driftSensor($alat, $tipe);
        $bath = $this->tabel->oilbath($oilbath);

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
                    ? sprintf('Sertifikat probe standar %s — tabel U95-nya nggak punya titik %s °C', $probe, $this->angka($indexMaks))
                    : sprintf('Sertifikat probe standar %s (titik %s °C, U=%s °C, k=2)', $probe, $this->angka($indexMaks), $this->angka($u95Probe)),
                'distribusi' => 'normal',
                'u' => ($u95Probe ?? 0.0) / TabelKalibratorSuhu3Alat::K_SERTIFIKAT,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => $u95Probe !== null,
            ],
            [
                'sumber' => 'resolusi_standar',
                'keterangan' => sprintf('Resolusi kalibrator standar %s °C (÷2, ÷√3)', $this->angka($resolusiStandar)),
                'distribusi' => 'persegi',
                'u' => ($resolusiStandar / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => true,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => $driftMeter === null
                    ? sprintf('Drift kalibrator %s %s — nggak ada di tabel drift', $merkTercetak, $tipe)
                    : sprintf('Drift kalibrator %s %s (%s °C, ÷√3)', $merkTercetak, $tipe, $this->angka($driftMeter)),
                'distribusi' => 'persegi',
                'u' => ($driftMeter ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $driftMeter !== null,
            ],
            [
                'sumber' => 'drift_sensor',
                'keterangan' => $driftProbe === null
                    ? sprintf('Drift probe standar %s — nggak ada di tabel drift', $tipe)
                    : sprintf('Drift probe standar %s (%s °C, ÷√3)', $tipe, $this->angka($driftProbe)),
                'distribusi' => 'persegi',
                'u' => ($driftProbe ?? 0.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $driftProbe !== null,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Skala terkecil termometer gelas %s °C (÷2, ÷√3)', $this->angka($resolusi)),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_RESOLUSI_UUT,
                'disertakan' => true,
            ],
            [
                'sumber' => 'pengulangan_uut',
                'keterangan' => sprintf('Pengulangan pembacaan UUT — STDEV terbesar %s °C (÷√%d)', $this->angka($stdevUut), self::N_PENGULANGAN),
                'distribusi' => 't-student',
                'u' => $stdevUut / sqrt(self::N_PENGULANGAN),
                'ci' => 1.0,
                'vi' => self::VI_PENGULANGAN,
                'disertakan' => true,
            ],
            [
                'sumber' => 'pengulangan_standar',
                'keterangan' => sprintf(
                    'Pengulangan pembacaan standar — STDEV terbesar %s °C (÷%d mengikuti master, bukan ÷√%d)',
                    $this->angka($stdevStandar),
                    self::N_PENGULANGAN,
                    self::N_PENGULANGAN,
                ),
                'distribusi' => 't-student',
                'u' => $stdevStandar / self::PEMBAGI_PENGULANGAN_STANDAR,
                'ci' => 1.0,
                'vi' => self::VI_PENGULANGAN,
                'disertakan' => true,
            ],
            [
                'sumber' => 'variasi_spasial_bath',
                'keterangan' => $bath === null
                    ? sprintf('Variasi spasial oilbath %s — nggak ada tabelnya', $oilbath)
                    : sprintf('Variasi spasial oilbath %s (%s °C, ÷2, ÷√3)', $oilbath, $this->angka($bath['variasi_spasial'])),
                'distribusi' => 'persegi',
                'u' => (($bath['variasi_spasial'] ?? 0.0) / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $bath !== null,
            ],
            [
                'sumber' => 'stabilitas_titik_es',
                'keterangan' => sprintf('Rentang uji titik es %s °C (Tmax−Tmin, ÷2, ÷√3)', $this->angka($rentangTitikEs)),
                'distribusi' => 'persegi',
                'u' => ($rentangTitikEs / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => true,
            ],
            [
                'sumber' => 'stabilitas_bath',
                'keterangan' => $bath === null
                    ? sprintf('Stabilitas oilbath %s — nggak ada tabelnya', $oilbath)
                    : sprintf('Stabilitas oilbath %s (%s °C, ÷2, ÷√3)', $oilbath, $this->angka($bath['stabilitas'])),
                'distribusi' => 'persegi',
                'u' => (($bath['stabilitas'] ?? 0.0) / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => $bath !== null,
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
     * @param  list<array<string, mixed>>  $dipakai
     * @return list<array<string, mixed>>
     */
    private function catatanAudit(array $dipakai, float $stdevStandar, float $uHitung, float $cmc): array
    {
        $catatan = [];

        if ($stdevStandar > 0.0) {
            $benar = array_map(
                static fn (array $k): array => $k['sumber'] === 'pengulangan_standar'
                    ? [...$k, 'u' => $stdevStandar / sqrt(self::N_PENGULANGAN)]
                    : $k,
                $dipakai,
            );
            $aggBenar = $this->agregasi($benar);

            $catatan[] = [
                'kode' => 'pengulangan_standar_dibagi_n',
                'pesan' => sprintf(
                    'Komponen keterulangan STANDAR dibagi %d, bukan √%d (`U27 = N27/Q27` — satu `SQRT` yang '
                    .'hilang; baris di atasnya `U26 = N26/SQRT(Q26)` benar). Ditiru mengikuti sertifikat yang '
                    .'sudah terbit. Kalau dibetulkan, U95 sesi ini jadi %s °C, bukan %s °C.',
                    self::N_PENGULANGAN,
                    self::N_PENGULANGAN,
                    $this->angka($aggBenar['ketidakpastian_diperluas']),
                    $this->angka($uHitung),
                ),
            ];
        }

        if (! self::FLOOR_V_EFF) {
            $catatan[] = [
                'kode' => 'v_eff_tidak_dipotong',
                'pesan' => '`v_eff` dipakai apa adanya (pecahan) mengikuti aproksimasi polinomial master.',
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
            'titik' => [], 'belum_dihitung' => $belum, 'probe' => null, 'index_maks' => null,
            'standar_deviasi_maks_standar' => 0.0, 'standar_deviasi_maks_uut' => 0.0, 'rentang_titik_es' => 0.0,
            'budget' => [], 'ketidakpastian_gabungan' => 0.0, 'derajat_kebebasan_efektif' => null,
            'faktor_cakupan_k' => 0.0, 'ketidakpastian_diperluas' => 0.0, 'cmc' => 0.0,
            'u95_sertifikat' => 0.0, 'sumber_u95' => 'hitung', 'catatan_audit' => [],
        ];
    }

    /**
     * @param  list<float>  $nilai
     */
    private function stdev(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;

        return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $rata) ** 2, $nilai)) / ($n - 1));
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 6, ',', '.'), '0'), ',');
    }
}
