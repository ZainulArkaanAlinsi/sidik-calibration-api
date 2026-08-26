<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use InvalidArgumentException;

/**
 * Mesin hitung **Thermohygrometer** (alat ke-20, lampiran akreditasi LK-285-IDN
 * "Suhu dan Kelembapan" no. 11) — MURNI: masuk array, keluar array.
 *
 * Master: `Master_Olah_Data_Suhu__Kelembapan.xlsm`, sesi **0312-CAL-624**
 * (order 2406.25.AR, PT Gunung Madu Plantations, NOKLEAD NK5253 s/n TR-001,
 * 2 Juli 2024).
 *
 * ## Satu-satunya alat suhu berparameter DUA
 *
 * Sertifikatnya memuat dua tabel terpisah — `1. Temperature` (°C) dan
 * `2. Humidity` (%RH) — dengan U95 masing-masing, dan dua-duanya punya baris CMC
 * sendiri di lampiran akreditasi (1,7 °C dan 4,8 %RH). Tidak ada alat lain di
 * repo ini yang begitu; sepuluh alat lain satu besaran, dan Autoklaf yang
 * dua-besaran (suhu + tekanan) memisahkannya jadi baris matriks, bukan dua tabel
 * ber-U95 sendiri.
 *
 * ## TIGA budget, bukan dua — dan yang ketiga gampang hilang
 *
 * Kelembapan dikalibrasi di **dua chamber berbeda**, karena satu chamber tidak
 * menjangkau seluruh rentang:
 *
 *   `[CHAMBER BIOBASE]`  50 ~ 90 %RH   (`PERHITUNGAN U95%` baris 41–46)
 *   `[CHAMBER GEA]`      30 ~ 49 %RH   (baris 60–65)
 *
 * Dua chamber = dua stabilitas & homogenitas = **dua budget**. Menggabungnya
 * jadi satu budget kelembapan berarti titik 30 %RH memakai homogenitas Biobase
 * (1,8 %RH) padahal chamber-nya GEA (0,8 %RH) — `uc`-nya naik dari 1,69 ke 2,21
 * dan U95-nya dari 3,33 ke 4,33. Di sesi contoh dua-duanya masih tertutup lantai
 * CMC 4,8 jadi sertifikatnya kebetulan sama; begitu lab memperbaiki chamber-nya
 * dan U95 hitung naik di atas 4,8, kesalahan ini langsung terbit.
 *
 * ## Pembagi `N/√Q` — penyimpangan master yang sistematis di sini
 *
 * Delapan dari delapan belas baris budget menulis `U = N/SQRT(Q)` padahal kolom
 * `Q` sudah berisi pembaginya (`SQRT(3)`), jadi akar diambil DUA KALI dan
 * pembagi efektifnya 1,3161 — bukan 1,7321. Baris tetangganya (`U21 = N21/Q21`,
 * `U42 = N42/Q42`) menulis yang benar dengan `Q` yang sama persis, jadi ini bukan
 * konvensi tabel melainkan rumus yang tidak seragam.
 *
 * Kelas kesalahan yang sama sudah terdokumentasi di
 * [TitsCalculator::PEMBAGI_AC_PICKUP]. Ditiru ([akarGanda]) karena sertifikatnya
 * sudah terbit, dan tiap sesi melahirkan catatan audit `pembagi_akar_ganda` yang
 * menyebut berapa U95-nya kalau dibetulkan.
 *
 * Baris `Drift Kalibrator` menambah satu lapis lagi: `Q23 = 0.5*SQRT(3)`
 * (0,8660) lalu `U23 = N23/SQRT(Q23)` — pembagi efektif 0,9306, jadi komponennya
 * DIPERBESAR, bukan diperkecil. Di budget GEA baris drift yang sama justru
 * ditulis benar (`U61 = N61/Q61`, `Q61 = SQRT(3)`). Tiga perlakuan untuk satu
 * komponen dalam satu sheet; semuanya ditiru apa adanya.
 *
 * @see ThermohygroProfile
 * @see TabelKalibratorSuhu3Alat
 * @see docs/pertanyaan-lab-suhu-3alat.md
 */
class ThermohygroCalculator
{
    public const PARAMETER_SUHU = 'suhu';

    public const PARAMETER_KELEMBABAN = 'kelembaban';

    public const CHAMBER_BIOBASE = 'biobase';

    public const CHAMBER_GEA = 'gea';

    /**
     * Batas bawah rentang chamber Biobase (%RH). Di bawahnya chamber GEA yang
     * dipakai — `[CHAMBER GEA] Lingkup RH: 30 ~ 49 %RH`.
     */
    public const AMBANG_CHAMBER_RH = 50.0;

    /** Jumlah pembacaan per titik yang jadi pembagi Type A (`Q22`/`Q43`/`Q63` = 5). */
    public const N_PENGULANGAN = 5;

    /** `vi` sertifikat kalibrator & drift GEA (`S20`, `S61` = 200). */
    public const VI_SERTIFIKAT = 200;

    /** `vi` daya baca, drift, stabilitas & homogenitas chamber (`S21` dst = 1000000). */
    public const VI_TAK_HINGGA = 1000000;

    /** `vi` komponen keterulangan (`S22` = 4 = n − 1). */
    public const VI_PENGULANGAN = 4;

    /** Lihat [ThermocoupleCalculator::FLOOR_V_EFF] — alasannya sama. */
    public const FLOOR_V_EFF = false;

    private ?GumCalculator $gum = null;

    private ?StudentTDistribution $t = null;

    public function __construct(private readonly TabelKalibratorSuhu3Alat $tabel = new TabelKalibratorSuhu3Alat) {}

    /**
     * Chamber yang dipakai satu set point kelembapan.
     *
     * Diturunkan dari set point, bukan dipilih teknisi: yang menentukan
     * kemampuan fisik chamber-nya, dan salah pilih menggeser dua komponen budget
     * tanpa satu pun angka kelihatan janggal.
     */
    public static function chamberUntuk(float $setPointRh): string
    {
        return $setPointRh >= self::AMBANG_CHAMBER_RH ? self::CHAMBER_BIOBASE : self::CHAMBER_GEA;
    }

    /**
     * Hitung satu sesi Thermohygrometer: satu grup suhu + sampai dua grup
     * kelembapan (per chamber), masing-masing dengan budget sendiri.
     *
     * @param  list<array{parameter: string, titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>}>  $titik
     * @param  array{resolusi_suhu: float, resolusi_kelembaban: float, cmc_suhu: float, cmc_kelembaban: float}  $spek
     * @return array{grup: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}
     */
    public function hitungSesi(array $titik, array $spek): array
    {
        if ($titik === []) {
            throw new InvalidArgumentException(
                'Sesi Thermohygrometer nggak punya satu pun titik terisi — budget-nya nggak bisa disusun.',
            );
        }

        foreach (['resolusi_suhu', 'resolusi_kelembaban'] as $kunci) {
            if (! is_finite((float) $spek[$kunci]) || (float) $spek[$kunci] <= 0.0) {
                throw new InvalidArgumentException("`{$kunci}` wajib angka > 0 — komponen daya baca lahir dari situ.");
            }
        }

        $belum = [];
        $perGrup = [];

        foreach ($titik as $t) {
            $parameter = (string) $t['parameter'];

            if (! in_array($parameter, [self::PARAMETER_SUHU, self::PARAMETER_KELEMBABAN], true)) {
                $belum[] = ['titik_ke' => (int) $t['titik_ke'], 'alasan' => "Parameter `{$parameter}` nggak dikenal — cuma `suhu` & `kelembaban`."];

                continue;
            }

            $hasil = $this->hitungTitik($t, $parameter);

            if (isset($hasil['alasan'])) {
                $belum[] = ['titik_ke' => (int) $t['titik_ke'], 'alasan' => $hasil['alasan']];

                continue;
            }

            $chamber = $parameter === self::PARAMETER_SUHU
                ? self::CHAMBER_BIOBASE
                : self::chamberUntuk((float) $t['titik_ukur']);

            $perGrup["{$parameter}|{$chamber}"][] = [...$hasil, 'chamber' => $chamber];
        }

        usort($belum, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

        $grup = [];

        foreach ($perGrup as $kunci => $titikGrup) {
            [$parameter, $chamber] = explode('|', $kunci);

            $resolusi = (float) ($parameter === self::PARAMETER_SUHU ? $spek['resolusi_suhu'] : $spek['resolusi_kelembaban']);
            $cmc = (float) ($parameter === self::PARAMETER_SUHU ? $spek['cmc_suhu'] : $spek['cmc_kelembaban']);

            $stdevUut = max(array_column($titikGrup, 'standar_deviasi_uut'));
            $budget = $this->budget($parameter, $chamber, $resolusi, $stdevUut);
            $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));
            $agg = $this->agregasi($dipakai);
            $uHitung = $agg['ketidakpastian_diperluas'];

            usort($titikGrup, static fn (array $a, array $b): int => $a['titik_ke'] <=> $b['titik_ke']);

            $grup[] = [
                'parameter' => $parameter,
                'chamber' => $chamber,
                'satuan' => $parameter === self::PARAMETER_SUHU ? '°C' : '%RH',
                'titik' => $titikGrup,
                'standar_deviasi_maks_uut' => $stdevUut,
                'budget' => $budget,
                'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
                'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
                'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
                'ketidakpastian_diperluas' => $uHitung,
                'cmc' => $cmc,
                'u95_sertifikat' => max($uHitung, $cmc),
                'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
                'catatan_audit' => $this->catatanAudit($parameter, $chamber, $resolusi, $stdevUut, $dipakai, $uHitung, $cmc),
            ];
        }

        // Urutan sertifikat: `1. Temperature` dulu, baru `2. Humidity`; di dalam
        // kelembapan, Biobase (rentang atas) sebelum GEA — sama seperti letak
        // dua tabelnya di sheet SERTIFIKAT.
        usort($grup, static function (array $a, array $b): int {
            $bobot = static fn (array $g): int => ($g['parameter'] === self::PARAMETER_SUHU ? 0 : 10)
                + ($g['chamber'] === self::CHAMBER_BIOBASE ? 0 : 1);

            return $bobot($a) <=> $bobot($b);
        });

        return ['grup' => $grup, 'belum_dihitung' => $belum];
    }

    /**
     * @param  array{parameter: string, titik_ke: int, titik_ukur: float, standar: list<float>, uut: list<float>}  $t
     * @return array<string, mixed>
     */
    private function hitungTitik(array $t, string $parameter): array
    {
        $standar = array_values(array_map('floatval', $t['standar']));
        $uut = array_values(array_map('floatval', $t['uut']));
        $satuan = $parameter === self::PARAMETER_SUHU ? '°C' : '%RH';

        if (count($standar) < 2 || count($uut) < 2) {
            return ['alasan' => sprintf(
                'Titik %s %s baru punya %d pembacaan standar & %d pembacaan UUT — tiap sisi butuh minimal 2.',
                $this->angka((float) $t['titik_ukur']),
                $satuan,
                count($standar),
                count($uut),
            )];
        }

        $avgStandar = array_sum($standar) / count($standar);
        $avgUut = array_sum($uut) / count($uut);

        // Koreksi standar dicocokkan ke RATA-RATA pembacaan standar, bukan ke
        // set point — sama seperti dua alat suhu lain. Set point 50 °C yang
        // terbaca 54,95 mengambil baris tabel 50; set point-nya sendiri tidak
        // pernah dilihat tabel.
        $koreksi = $this->tabel->koreksiThermohygro($parameter, $avgStandar);

        if ($koreksi === null) {
            return ['alasan' => sprintf(
                'Tabel koreksi standar %s nggak punya satu pun titik — pembacaan %s %s nggak bisa dikoreksi.',
                $parameter,
                $this->angka($avgStandar),
                $satuan,
            )];
        }

        $standarTerkoreksi = $avgStandar + $koreksi;

        return [
            'titik_ke' => (int) $t['titik_ke'],
            'titik_ukur' => (float) $t['titik_ukur'],
            'parameter' => $parameter,
            'pembacaan_standar' => $standar,
            'pembacaan_uut' => $uut,
            'rata_rata_standar' => $avgStandar,
            'rata_rata_uut' => $avgUut,
            'standar_deviasi_standar' => $this->stdev($standar),
            'standar_deviasi_uut' => $this->stdev($uut),
            'koreksi_standar' => $koreksi,
            'standar_terkoreksi' => $standarTerkoreksi,
            // UUT thermohygro dibaca dari layarnya sendiri — tidak ada koreksi
            // instrumen di jalur itu, sama seperti termometer gelas.
            'uut_terkoreksi' => $avgUut,
            'koreksi' => $standarTerkoreksi - $avgUut,
        ];
    }

    /**
     * Enam komponen per grup. Urutan & pembagi mengikuti sheet-nya sendiri —
     * budget GEA memang tidak seurutan dengan dua yang lain.
     *
     * @return list<array<string, mixed>>
     */
    private function budget(string $parameter, string $chamber, float $resolusi, float $stdevUut): array
    {
        $sqrt3 = sqrt(3.0);
        $th = $this->tabel->thermohygro();
        $suhu = $parameter === self::PARAMETER_SUHU;
        $satuan = $suhu ? '°C' : '%RH';

        $u95Kal = (float) ($th['u95_kalibrator'][$suhu ? 'suhu' : 'rh'] ?? 0.0);
        $drift = (float) ($th['drift_kalibrator'][$suhu ? 'suhu' : 'rh'] ?? 0.0);

        $kunciChamber = $suhu ? 'biobase_suhu' : ($chamber === self::CHAMBER_BIOBASE ? 'biobase_rh' : 'gea_rh');
        $stab = (float) ($th['chamber'][$kunciChamber]['stabilitas'] ?? 0.0);
        $homo = (float) ($th['chamber'][$kunciChamber]['homogenitas'] ?? 0.0);

        $standar = [
            'sumber' => 'ketidakpastian_standar',
            'keterangan' => sprintf('Sertifikat Temperature Humidity Meter (U=%s %s, k=2)', $this->angka($u95Kal), $satuan),
            'distribusi' => 'normal',
            'u' => $u95Kal / TabelKalibratorSuhu3Alat::K_SERTIFIKAT,
            'ci' => 1.0,
            'vi' => self::VI_SERTIFIKAT,
            'disertakan' => true,
        ];

        $dayaBaca = [
            'sumber' => 'daya_baca',
            'keterangan' => sprintf('Daya baca alat %s %s (÷2, ÷√3)', $this->angka($resolusi), $satuan),
            'distribusi' => 'persegi',
            'u' => ($resolusi / 2.0) / $sqrt3,
            'ci' => 1.0,
            'vi' => self::VI_TAK_HINGGA,
            'disertakan' => true,
        ];

        $pengulangan = [
            'sumber' => 'pengulangan_uut',
            'keterangan' => sprintf('Pengulangan pembacaan UUT — STDEV terbesar %s %s (÷√%d)', $this->angka($stdevUut), $satuan, self::N_PENGULANGAN),
            'distribusi' => 't-student',
            'u' => $stdevUut / sqrt(self::N_PENGULANGAN),
            'ci' => 1.0,
            'vi' => self::VI_PENGULANGAN,
            'disertakan' => true,
        ];

        $stabilitas = [
            'sumber' => 'stabilitas_chamber',
            'keterangan' => sprintf('Stabilitas climatic chamber %s %s %s (÷√(√3) mengikuti master)', strtoupper($chamber), $this->angka($stab), $satuan),
            'distribusi' => 'persegi',
            'u' => $this->akarGanda($stab, $sqrt3),
            'ci' => 1.0,
            'vi' => self::VI_TAK_HINGGA,
            'disertakan' => true,
        ];

        $homogenitas = [
            'sumber' => 'homogenitas_chamber',
            'keterangan' => sprintf('Homogenitas climatic chamber %s %s %s (÷√(√3) mengikuti master)', strtoupper($chamber), $this->angka($homo), $satuan),
            'distribusi' => 'persegi',
            'u' => $this->akarGanda($homo, $sqrt3),
            'ci' => 1.0,
            'vi' => self::VI_TAK_HINGGA,
            'disertakan' => true,
        ];

        // Budget GEA menulis baris drift-nya BENAR (`U61 = N61/Q61`, `Q61 =
        // SQRT(3)`, `S61 = 200`), sementara dua budget lain memakai `0.5*√3`
        // plus akar ganda. Satu komponen, tiga perlakuan, satu sheet.
        if (! $suhu && $chamber === self::CHAMBER_GEA) {
            $driftKomponen = [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf('Drift kalibrator %s %s (÷√3)', $this->angka($drift), $satuan),
                'distribusi' => 'persegi',
                'u' => $drift / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT,
                'disertakan' => true,
            ];

            return [$standar, $driftKomponen, $dayaBaca, $pengulangan, $stabilitas, $homogenitas];
        }

        $driftKomponen = [
            'sumber' => 'drift_standar',
            'keterangan' => sprintf('Drift kalibrator %s %s (÷√(0,5·√3) mengikuti master)', $this->angka($drift), $satuan),
            'distribusi' => 'persegi',
            'u' => $this->akarGanda($drift, 0.5 * $sqrt3),
            'ci' => 1.0,
            'vi' => self::VI_TAK_HINGGA,
            'disertakan' => true,
        ];

        return [$standar, $dayaBaca, $pengulangan, $driftKomponen, $stabilitas, $homogenitas];
    }

    /**
     * `U = N / SQRT(Q)` — akar diambil lagi dari kolom `Divisor` yang isinya
     * sudah berupa akar. Lihat blok "Pembagi `N/√Q`" di docblock kelas.
     */
    private function akarGanda(float $nilai, float $pembagi): float
    {
        return $pembagi <= 0.0 ? 0.0 : $nilai / sqrt($pembagi);
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
    private function catatanAudit(
        string $parameter,
        string $chamber,
        float $resolusi,
        float $stdevUut,
        array $dipakai,
        float $uHitung,
        float $cmc,
    ): array {
        $catatan = [];
        $sqrt3 = sqrt(3.0);
        $th = $this->tabel->thermohygro();
        $suhu = $parameter === self::PARAMETER_SUHU;
        $drift = (float) ($th['drift_kalibrator'][$suhu ? 'suhu' : 'rh'] ?? 0.0);
        $kunciChamber = $suhu ? 'biobase_suhu' : ($chamber === self::CHAMBER_BIOBASE ? 'biobase_rh' : 'gea_rh');
        $stab = (float) ($th['chamber'][$kunciChamber]['stabilitas'] ?? 0.0);
        $homo = (float) ($th['chamber'][$kunciChamber]['homogenitas'] ?? 0.0);

        // Versi "kalau pembaginya dibetulkan": tiga (atau dua) komponen yang
        // memakai akar ganda dihitung ulang dengan pembagi yang seharusnya.
        $benar = array_map(
            static function (array $k) use ($sqrt3, $drift, $stab, $homo, $chamber, $suhu): array {
                return match ($k['sumber']) {
                    'stabilitas_chamber' => [...$k, 'u' => $stab / $sqrt3],
                    'homogenitas_chamber' => [...$k, 'u' => $homo / $sqrt3],
                    'drift_standar' => (! $suhu && $chamber === self::CHAMBER_GEA)
                        ? $k
                        : [...$k, 'u' => $drift / (0.5 * $sqrt3)],
                    default => $k,
                };
            },
            $dipakai,
        );
        $aggBenar = $this->agregasi($benar);

        if (abs($aggBenar['ketidakpastian_diperluas'] - $uHitung) > 1e-9) {
            $catatan[] = [
                'kode' => 'pembagi_akar_ganda',
                'pesan' => sprintf(
                    'Master menulis `U = N/SQRT(Q)` padahal kolom `Q` sudah berisi pembaginya, jadi akar diambil '
                    .'dua kali. Ditiru mengikuti sertifikat yang sudah terbit. Kalau pembaginya dibetulkan, U95 '
                    .'grup %s%s jadi %s, bukan %s.',
                    $parameter,
                    $suhu ? '' : ' chamber '.strtoupper($chamber),
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
                    'U95 hitung %s di bawah CMC terakreditasi %s — yang diterbitkan CMC (ILAC-P14).',
                    $this->angka($uHitung),
                    $this->angka($cmc),
                ),
            ];
        }

        return $catatan;
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
