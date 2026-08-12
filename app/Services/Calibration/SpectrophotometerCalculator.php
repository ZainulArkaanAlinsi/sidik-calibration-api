<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung Spectrophotometer — MURNI: masuk array, keluar array, nggak
 * nyentuh DB, request, atau Eloquent sama sekali.
 *
 * ## Kenapa dia berdiri sendiri, di luar `GumCalculator`
 *
 * Empat alat sebelumnya (pH, Turbidimeter, Chlorine, Refractometer,
 * Conductivity) ngitung SATU U95 PER TITIK: tiap titik punya budget sendiri,
 * dan `GumCalculator::hitungTitik()` emang dibentuk buat itu.
 *
 * Master Spectrophotometer nggak begitu. Sheet `PERHITUNGAN U95%` cuma punya
 * TIGA tabel budget — satu per KELOMPOK pengukuran (Filter Holmium, Filter
 * Didynium, Accuracy %T) — dan tiap tabel dikasih makan `MAX. STDEV` seluruh
 * titik di kelompoknya (sel `PERHITUNGAN!M36`, `M49`, `M67`). Jadi sepuluh
 * titik Holmium keluar dengan U95 yang SAMA, dan angka itu nggak bisa
 * diturunkan dari satu titik doang.
 *
 * `hitungTitik()` cuma lihat satu titik dan nggak punya cara lihat saudaranya,
 * jadi maksain kelompok ke sana berarti ngerombak tanda tangannya buat semua
 * alat. Yang dipisah cuma DAFTAR & SUMBER komponennya; agregasi GUM-nya
 * (uc, Welch–Satterthwaite, k dari t-student, lantai CMC) tetap minjem
 * `GumCalculator::agregasiBudget()` — biar cuma ada SATU implementasi aturan
 * GUM di repo ini.
 *
 * ## Penyimpangan master yang SENGAJA ditiru (dan kenapa)
 *
 * Excel-nya punya tiga kekeliruan yang KEBAWA ke angka sertifikat yang udah
 * terbit. Ditiru persis, TAPI tiap-tiapnya nyetak baris `catatan_audit` yang
 * bilang berapa hasilnya kalau dibetulin — supaya yang milih mana yang benar
 * itu manajer teknis lab, bukan diam-diam kode ini:
 *
 *  1. **Pembagi Type A panjang gelombang** `H = E/SQRT(F)` dengan `F = SQRT(3)`
 *     (sel H10/H26) — jadi pembaginya `3^0,25 = 1,3160740…`, bukan `√3`.
 *     Konvensi GUM buat rata-rata n pembacaan itu `s/√n`. Blok %T pakai
 *     `H = E/F` yang benar (sel H47, `F = SQRT(6)`), jadi salahnya cuma di dua
 *     tabel panjang gelombang. Ini yang bikin U95 Holmium `0,43255707705…`.
 *  2. **Jangkauan SUM blok %T** `SUM(J47:J49)` (sel J52/K52/L52) — melewati
 *     baris 46 (Ketidakpastian Standar, penyumbang TERBESAR: uici 0,25) dan
 *     baris 50 (Spectral Bandwidth). Kalau keduanya diikutkan, U-nya naik dari
 *     0,0580823… ke ±0,5058 dan sertifikatnya berubah dari 0,5 (lantai CMC)
 *     jadi 0,5058 — jadi ini BUKAN kekeliruan kosmetik.
 *  3. **Label distribusi blok %T ketuker** (sel D46 `t-Student` buat
 *     ketidakpastian sertifikat standar, D47 `Normal` buat keterulangan).
 *     Yang ini TIDAK ditiru: `distribusi` dipakai `type_b` buat misahin Type A
 *     dari Type B, jadi niru label yang ketuker bikin RSS Type B salah isi.
 *     Angkanya nggak kesentuh sama sekali — cuma penamaannya yang dibetulin.
 *
 * Yang TIDAK ditiru sama sekali: blok SRE (`PERHITUNGAN U95%` baris 60–76).
 * Lihat [SpectrophotometerProfile] soal itu.
 *
 * @see \App\Services\Calibration\Profiles\SpectrophotometerProfile
 * @see docs/handoff-backend-spectrophotometer.md
 */
class SpectrophotometerCalculator
{
    /** Kelompok pengukuran = satu tabel budget di `PERHITUNGAN U95%`. */
    public const GRUP_HOLMIUM = 'holmium';

    public const GRUP_DIDYNIUM = 'didynium';

    public const GRUP_TRANSMITAN = 'akurasi_transmitan';

    /**
     * Tabel celah spectral bandwidth, sel `N10:O27` di sheet `PERHITUNGAN U95%`.
     * Pasangan `[nilai acuan, nilai terbaca]` dalam nm; sumbernya tercetak di
     * sel N31: **SNSU PK.F-01:2020 Panduan Kalibrasi Spektrofotometer UV-Vis**.
     *
     * Datanya HARDCODE di dalam sheet-nya sendiri (bukan external reference
     * yang putus), jadi aman disalin. Disalin sebagai PASANGAN, bukan sebagai
     * hasil rata-ratanya (0,0755…), supaya angka yang masuk budget tetap bisa
     * ditelusuri balik ke barisnya — dan supaya nambah/ngurangin baris waktu
     * panduannya direvisi nggak perlu ngitung tangan.
     *
     * @var list<array{0: float, 1: float}>
     */
    public const GAP_SPECTRAL_BANDWIDTH = [
        [241.12, 241.12],
        [249.89, 250.03],
        [260.18, 260.19],
        [278.13, 278.10],
        [287.22, 287.52],
        [293.38, 293.43],
        [333.48, 333.47],
        [345.38, 345.42],
        [361.25, 361.12],
        [385.61, 385.80],
        [416.25, 416.57],
        [451.45, 451.32],
        [467.82, 467.90],
        [473.54, 473.47],
        [485.23, 485.25],
        [536.56, 536.86],
        [640.50, 640.79],
        [652.67, 652.66],
    ];

    /**
     * Pembagi ci Spectral Bandwidth di blok %T: `I50 = (E50/1100)*100`.
     * 1100 nm itu ujung atas rentang spektral alat referensi panduannya —
     * celah dalam nm dibagi rentang penuh, dinyatakan dalam persen, jadi
     * koefisien sensitivitas nirsatuan ke besaran %T.
     */
    private const RENTANG_SPEKTRAL_NM = 1100.0;

    /** Derajat kebebasan tiap komponen Type B, persis kolom `vi` di sheet-nya. */
    private const VI_SERTIFIKAT_STANDAR = 200.0;

    private const VI_RESOLUSI = 1_000_000.0;

    private const VI_DRIFT = 50.0;

    private const VI_SPECTRAL_BANDWIDTH = 50.0;

    /** Drift standar gelas filter = 10% dari U95 sertifikatnya (sel E13/E29/E49). */
    private const RASIO_DRIFT_STANDAR = 0.1;

    /**
     * Dibikin MALAS, bukan di konstruktor.
     *
     * `new GumCalculator` bikin `CalibrationProfileRegistry`, yang bikin semua
     * profil — termasuk [SpectrophotometerProfile], yang butuh kelas ini. Kalau
     * dirakit di konstruktor, rantainya muter dan PHP mati kehabisan memori.
     * Ditunda sampai method dipanggil, rantainya udah kelar dirakit.
     */
    private ?GumCalculator $gum = null;

    /**
     * Rata-rata celah spectral bandwidth (nm) — sel `Q30`,
     * `=ABS(AVERAGE(P10:P27))` dengan `P = N - O`.
     */
    public function celahSpectralBandwidth(): float
    {
        $celah = array_map(
            static fn (array $baris): float => $baris[0] - $baris[1],
            self::GAP_SPECTRAL_BANDWIDTH,
        );

        return abs(array_sum($celah) / count($celah));
    }

    /**
     * Statistik satu titik ukur: rata-rata, koreksi, STDEV sampel, Type A.
     *
     * Rumusnya sama persis kayak kolom `Average(λ2)` / `Correction (λ1-λ2)` /
     * `STDEV` di sheet `PERHITUNGAN`:
     *
     *   rata_rata = AVERAGE(X1..Xn)
     *   koreksi   = nilai_standar − rata_rata      (λ1 − λ2)
     *   stdev     = STDEV.S(X1..Xn)                (sampel, penyebut n−1)
     *   type_a    = stdev / √n
     *
     * @param  list<float>  $pembacaan
     * @return array{titik_ke: int, titik_ukur: float, rata_rata: float, error: float, koreksi: float, standar_deviasi: float, jumlah_pengulangan: int, type_a: float}
     *
     * @throws \InvalidArgumentException kalau pembacaannya kurang dari dua —
     *                                   STDEV sampel nggak ada artinya dari satu
     *                                   angka, dan `#DIV/0!` bukan jawaban yang
     *                                   boleh keluar dari API.
     */
    public function hitungTitik(int $titikKe, float $titikUkur, array $pembacaan): array
    {
        $pembacaan = array_values(array_map('floatval', $pembacaan));
        $n = count($pembacaan);

        if ($n < GumCalculator::MIN_PENGULANGAN) {
            throw new \InvalidArgumentException(sprintf(
                'Titik ke-%d cuma punya %d pembacaan, minimal %d — STDEV sampel nggak bisa dihitung dari satu angka.',
                $titikKe,
                $n,
                GumCalculator::MIN_PENGULANGAN,
            ));
        }

        $rataRata = array_sum($pembacaan) / $n;
        $stdev = $this->standarDeviasiSampel($pembacaan, $rataRata);

        return [
            'titik_ke' => $titikKe,
            'titik_ukur' => $titikUkur,
            'rata_rata' => $rataRata,
            // Error = pembacaan − acuan; koreksi = lawannya, dan koreksi yang
            // dicetak di sertifikat (kolom `Correction (λ1-λ2)nm`).
            'error' => $rataRata - $titikUkur,
            'koreksi' => $titikUkur - $rataRata,
            'standar_deviasi' => $stdev,
            'jumlah_pengulangan' => $n,
            'type_a' => $stdev / sqrt($n),
        ];
    }

    /**
     * Hitung SATU kelompok pengukuran utuh: statistik tiap titiknya + satu
     * budget ketidakpastian bersama + U95 sertifikatnya.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>}>  $titik
     * @param  array{grup: string, satuan: string, nama_standar?: string, u_standar: float, k_standar?: float, resolusi: float, cmc: float}  $spek
     * @return array{grup: string, satuan: string, titik: list<array<string, mixed>>, standar_deviasi_maks: float, jumlah_pengulangan: int, budget: list<array<string, mixed>>, ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float, cmc: float, u95_sertifikat: float, sumber_u95: string, catatan_audit: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException kalau kelompoknya kosong, atau salah satu
     *                                   angka wajibnya nggak masuk akal.
     */
    public function hitungGrup(array $titik, array $spek): array
    {
        $grup = $spek['grup'];

        if ($titik === []) {
            throw new \InvalidArgumentException(
                "Kelompok {$grup} nggak punya satu pun titik terisi — budget ketidakpastiannya nggak bisa disusun.",
            );
        }

        foreach (['u_standar', 'resolusi', 'cmc'] as $wajib) {
            if (! isset($spek[$wajib]) || ! is_finite((float) $spek[$wajib]) || (float) $spek[$wajib] < 0.0) {
                throw new \InvalidArgumentException(
                    "Kelompok {$grup}: `{$wajib}` wajib angka >= 0 — tanpa itu budget ketidakpastiannya ngarang.",
                );
            }
        }

        $hasilTitik = array_map(
            fn (array $t): array => $this->hitungTitik(
                (int) $t['titik_ke'],
                (float) $t['titik_ukur'],
                $t['pembacaan'],
            ),
            $titik,
        );

        // `MAX. STDEV` — sel M36 / M49 / M67 di sheet `PERHITUNGAN`. Satu angka
        // buat seluruh kelompok, dan itu yang jadi Type A budget-nya.
        $stdevMaks = max(array_column($hasilTitik, 'standar_deviasi'));

        // Jumlah pengulangan yang nentuin `vi` Type A & pembagi blok %T. Diambil
        // dari titik yang STDEV-nya paling besar, karena baris budget-nya emang
        // baris itu — bukan rata-rata jumlah pengulangan seluruh kelompok.
        $n = 0;
        foreach ($hasilTitik as $t) {
            if ($t['standar_deviasi'] === $stdevMaks) {
                $n = $t['jumlah_pengulangan'];

                break;
            }
        }

        $budget = $grup === self::GRUP_TRANSMITAN
            ? $this->budgetTransmitan($stdevMaks, $n, $spek)
            : $this->budgetPanjangGelombang($stdevMaks, $n, $spek);

        $dipakai = array_values(array_filter($budget, static fn (array $k): bool => $k['disertakan']));

        $gum = $this->gum ??= new GumCalculator;
        $agg = $gum->agregasiBudget($this->untukAgregasi($dipakai));

        // Lantai CMC (ILAC-P14 / sel `=MAX(J20,J21)`): lab nggak boleh ngeklaim
        // ketidakpastian lebih baik dari kemampuan terbaik yang diakreditasi.
        $cmc = (float) $spek['cmc'];
        $uHitung = $agg['ketidakpastian_diperluas'];

        return [
            'grup' => $grup,
            'satuan' => $spek['satuan'],
            'titik' => $hasilTitik,
            'standar_deviasi_maks' => $stdevMaks,
            'jumlah_pengulangan' => $n,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agg['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agg['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agg['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $uHitung,
            'cmc' => $cmc,
            'u95_sertifikat' => max($uHitung, $cmc),
            'sumber_u95' => $cmc > $uHitung ? 'cmc' : 'hitung',
            'catatan_audit' => $this->catatanAudit($budget, $dipakai, $uHitung, $cmc, $gum),
        ];
    }

    /**
     * Budget 5 komponen buat tabel panjang gelombang (Holmium & Didynium) —
     * sel B10:L15 (Holmium) & B26:L31 (Didynium).
     *
     * @param  array<string, mixed>  $spek
     * @return list<array<string, mixed>>
     */
    private function budgetPanjangGelombang(float $stdevMaks, int $n, array $spek): array
    {
        $sqrt3 = sqrt(3.0);
        $uStandar = (float) $spek['u_standar'];
        $kStandar = (float) ($spek['k_standar'] ?? 2.0) ?: 2.0;
        $resolusi = (float) $spek['resolusi'];
        $satuan = $spek['satuan'];
        $namaStandar = $spek['nama_standar'] ?? 'gelas filter';
        $celah = $this->celahSpectralBandwidth();

        // Pembagi Type A master: `E/SQRT(F)` dengan `F = SQRT(3)` → 3^0,25.
        // Lihat blok penyimpangan #1 di dokumentasi kelas ini.
        $pembagiTypeA = sqrt($sqrt3);

        return [
            [
                'sumber' => 'standar_deviasi_maks',
                'keterangan' => sprintf(
                    'MAX. STDEV kelompok = %s %s, dibagi %s (master: E/SQRT(SQRT(3)) — lihat catatan_audit)',
                    $this->angka($stdevMaks), $satuan, $this->angka($pembagiTypeA),
                ),
                'distribusi' => 't-student',
                'u' => $stdevMaks / $pembagiTypeA,
                'ci' => 1.0,
                'vi' => (float) max($n - 1, 1),
                'disertakan' => true,
            ],
            [
                'sumber' => 'ketidakpastian_gelas_filter',
                'keterangan' => sprintf(
                    'Sertifikat %s (U95 = %s %s, k = %s)',
                    $namaStandar, $this->angka($uStandar), $satuan, $this->angka($kStandar),
                ),
                'distribusi' => 'normal',
                'u' => $uStandar / $kStandar,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT_STANDAR,
                'disertakan' => true,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s (÷2, lalu ÷√3)', $this->angka($resolusi), $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_RESOLUSI,
                'disertakan' => true,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf(
                    'Drift %s = 10%% × U95 sertifikatnya = %s %s (÷√3)',
                    $namaStandar, $this->angka($uStandar * self::RASIO_DRIFT_STANDAR), $satuan,
                ),
                'distribusi' => 'normal',
                'u' => ($uStandar * self::RASIO_DRIFT_STANDAR) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => true,
            ],
            [
                'sumber' => 'spectral_bandwidth',
                'keterangan' => sprintf(
                    'Rata-rata celah %s nm dari %d pasang data SNSU PK.F-01:2020 (÷√3)',
                    $this->angka($celah), count(self::GAP_SPECTRAL_BANDWIDTH),
                ),
                'distribusi' => 'persegi',
                'u' => $celah / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_SPECTRAL_BANDWIDTH,
                'disertakan' => true,
            ],
        ];
    }

    /**
     * Budget 5 komponen buat tabel Accuracy %T — sel B46:L50. DUA di antaranya
     * `disertakan = false` karena `SUM(J47:J49)` di master ngelewatin mereka.
     * Lihat blok penyimpangan #2 di dokumentasi kelas ini.
     *
     * @param  array<string, mixed>  $spek
     * @return list<array<string, mixed>>
     */
    private function budgetTransmitan(float $stdevMaks, int $n, array $spek): array
    {
        $sqrt3 = sqrt(3.0);
        $uStandar = (float) $spek['u_standar'];
        $kStandar = (float) ($spek['k_standar'] ?? 2.0) ?: 2.0;
        $resolusi = (float) $spek['resolusi'];
        $satuan = $spek['satuan'];
        $namaStandar = $spek['nama_standar'] ?? 'filter %T';
        $celah = $this->celahSpectralBandwidth();

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat %s (U95 = %s %s, k = %s). Master nyetak distribusi "t-Student" di sel D46; '
                    .'yang benar Normal, dan penamaan itu yang dipakai di sini.',
                    $namaStandar, $this->angka($uStandar), $satuan, $this->angka($kStandar),
                ),
                'distribusi' => 'normal',
                'u' => $uStandar / $kStandar,
                'ci' => 1.0,
                'vi' => self::VI_SERTIFIKAT_STANDAR,
                'disertakan' => false,
                'alasan_dikecualikan' => 'Di luar jangkauan SUM(J47:J49) master (sel J52/K52/L52).',
            ],
            [
                'sumber' => 'keterulangan_pengukuran',
                'keterangan' => sprintf(
                    'MAX. STDEV kelompok = %s %s, dibagi √%d',
                    $this->angka($stdevMaks), $satuan, $n,
                ),
                'distribusi' => 't-student',
                'u' => $stdevMaks / sqrt(max($n, 1)),
                'ci' => 1.0,
                'vi' => (float) max($n - 1, 1),
                'disertakan' => true,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat %s %s (÷2, lalu ÷√3)', $this->angka($resolusi), $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusi / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_RESOLUSI,
                'disertakan' => true,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf(
                    'Drift %s = 10%% × U95 sertifikatnya = %s %s (÷√3)',
                    $namaStandar, $this->angka($uStandar * self::RASIO_DRIFT_STANDAR), $satuan,
                ),
                'distribusi' => 'normal',
                'u' => ($uStandar * self::RASIO_DRIFT_STANDAR) / $sqrt3,
                'ci' => 1.0,
                'vi' => self::VI_DRIFT,
                'disertakan' => true,
            ],
            [
                'sumber' => 'spectral_bandwidth',
                'keterangan' => sprintf(
                    'Rata-rata celah %s nm dari %d pasang data SNSU PK.F-01:2020 (÷√3), '
                    .'ci = (celah / %s nm) × 100 buat nerjemahin nm ke %%T',
                    $this->angka($celah), count(self::GAP_SPECTRAL_BANDWIDTH), $this->angka(self::RENTANG_SPEKTRAL_NM),
                ),
                'distribusi' => 'persegi',
                'u' => $celah / $sqrt3,
                'ci' => ($celah / self::RENTANG_SPEKTRAL_NM) * 100.0,
                'vi' => self::VI_SPECTRAL_BANDWIDTH,
                'disertakan' => false,
                'alasan_dikecualikan' => 'Di luar jangkauan SUM(J47:J49) master (sel J52/K52/L52).',
            ],
        ];
    }

    /**
     * Jejak audit: apa yang dikecualikan master, dan berapa hasilnya kalau
     * SEMUA komponen ikut. Bukan sekadar catatan — ini yang bikin selisihnya
     * bisa dilihat manajer teknis tanpa buka Excel-nya lagi.
     *
     * @param  list<array<string, mixed>>  $budget
     * @param  list<array<string, mixed>>  $dipakai
     * @return list<array<string, mixed>>
     */
    private function catatanAudit(array $budget, array $dipakai, float $uHitung, float $cmc, GumCalculator $gum): array
    {
        $catatan = [[
            'kode' => 'perbandingan_cmc',
            'pesan' => sprintf(
                'U hitung %s vs CMC %s → yang dilaporkan %s',
                $this->angka($uHitung), $this->angka($cmc), $cmc > $uHitung ? 'CMC' : 'hitung',
            ),
        ]];

        $dikecualikan = array_values(array_filter($budget, static fn (array $k): bool => ! $k['disertakan']));

        if ($dikecualikan === []) {
            return $catatan;
        }

        $lengkap = $gum->agregasiBudget($this->untukAgregasi($budget));

        $catatan[] = [
            'kode' => 'komponen_dikecualikan_master',
            'pesan' => sprintf(
                'Master ngecualiin %d komponen dari SUM-nya: %s. Ditiru supaya angka backend sama '
                .'persis sama sertifikat yang udah terbit. Kalau semuanya diikutkan: uc %s, veff %s, '
                .'k %s, U %s (dilaporkan %s sesudah lantai CMC).',
                count($dikecualikan),
                implode(', ', array_column($dikecualikan, 'sumber')),
                $this->angka($lengkap['ketidakpastian_gabungan']),
                $this->angka($lengkap['derajat_kebebasan_efektif'] ?? 0.0),
                $this->angka($lengkap['faktor_cakupan_k']),
                $this->angka($lengkap['ketidakpastian_diperluas']),
                $this->angka(max($lengkap['ketidakpastian_diperluas'], $cmc)),
            ),
            'komponen' => array_column($dikecualikan, 'sumber'),
            'ketidakpastian_gabungan' => $lengkap['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $lengkap['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $lengkap['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $lengkap['ketidakpastian_diperluas'],
            'u95_sertifikat' => max($lengkap['ketidakpastian_diperluas'], $cmc),
        ];

        return $catatan;
    }

    /**
     * Buang kunci yang cuma buat tampilan — `agregasiBudget()` maunya `u/ci/vi`.
     *
     * @param  list<array<string, mixed>>  $komponen
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function untukAgregasi(array $komponen): array
    {
        return array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $komponen,
        );
    }

    /**
     * STDEV sampel (Excel `STDEV.S` / `STDEV`) — penyebut n−1, BUKAN n.
     * Populasi (n) bakal ngasih ketidakpastian yang lebih kecil dari yang bisa
     * dipertanggungjawabkan dari sejumlah pembacaan terbatas.
     *
     * @param  list<float>  $nilai
     */
    private function standarDeviasiSampel(array $nilai, float $rataRata): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $jumlahKuadrat = array_sum(array_map(
            static fn (float $x): float => ($x - $rataRata) ** 2,
            $nilai,
        ));

        return sqrt($jumlahKuadrat / ($n - 1));
    }

    /** Angka buat teks keterangan: presisi cukup, tanpa nol belakang yang bikin ramai. */
    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 8, '.', ''), '0'), '.') ?: '0';
    }
}
