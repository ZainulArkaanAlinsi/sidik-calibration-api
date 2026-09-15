<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Mesin hitung **Dial Indicator** — lampiran akreditasi LK-285-IDN no. 36,
 * kelompok Panjang.
 *
 * Satu workbook master (`Master Olah Data_Dial Indicator.xlsm`, password
 * `spirit285`). Rumusnya dibuktikan di Python lebih dulu: 108 sel — kesepuluh
 * titik, kesepuluh komponen budget (ui, ci, vi), dan kelima agregat — cocok
 * pada 1·10⁻⁹. Dijaga `DialIndicatorMasterTest`.
 *
 * ## Satu budget untuk SATU SESI, dalam mm
 *
 * `PERHITUNGAN U95%` cuma punya satu kolom, dan sertifikatnya mencetak satu
 * baris `Uncertainty U95% = ±` di bawah seluruh titik. Satuannya mm
 * (`AF19 = I5 = "mm"`) — sama dengan Height Gauge, BUKAN µm seperti Micrometer.
 *
 * ## Bentuk satu titik
 *
 *     H  = Σ nilai_terkoreksi(keping)            tumpukan balok ukur
 *     Y  = H + H·(α_avg·δϴ + αs·δα) − ld − lw − lg
 *     koreksi = Y − rata-rata pembacaan
 *
 * `δϴ = |suhu GB − suhu UUT|` dan `δα = |αs − αt|` — dua-duanya nol di master,
 * karena suhu GB dan suhu UUT sama-sama `PERHITUNGAN!G14` (rata-rata suhu
 * ruangan) dan αs = αt. Jalurnya ditiru utuh; isinya nol.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 * Ketiganya membuat U lebih besar atau menahan penerbitan, tidak pernah
 * diam-diam lebih kecil:
 *
 *  1. **Panjang koefisien sensitivitas dari keping, bukan tumpukan.**
 *     `C61 = MAX(C31:E60)` menyapu kolom NOMINAL KEPING, jadi yang terpakai
 *     keping terbesar (21 mm) padahal tumpukan terpanjang di sesi contoh
 *     21 + 1,8 + 1,7 = 24,5 mm. Di sini dipakai total nominal TERBESAR —
 *     keputusan yang sama dengan penyimpangan no. 3 Micrometer. ci komponen
 *     suhu & muai naik 24,5/21×; U95 sesi contoh bergeser di digit ke-7.
 *  2. **Umur drift dari `NOW()`.** `DATABASE!X11 = NOW()`; di snapshot yang
 *     kami terima 2025-12-19, sementara sesinya 2024-05-06. Di sini dari
 *     tanggal kalibrasi sesi — U95 sesi yang sama bisa diulang.
 *  3. **Suku termal cuma di baris 31.** `S`/`U`/`V`/`W`/`X` cuma terisi di titik
 *     pertama, dan rumus `AB` baris lain membaca sel kosong sebagai nol. Hari
 *     ini tidak menggeser apa pun (δϴ dan δα nol), tapi di sini suku itu
 *     dihitung untuk SEMUA titik dari nilai tingkat-sesi yang sama.
 *
 * ## Yang DITIRU walau janggal — pertanyaan lab
 *
 * `docs/pertanyaan-lab-dial-indicator.md`:
 *
 *  - Repeatability dari SEPULUH pembacaan Evaluation tapi dibagi `√5` dengan
 *    `vi = 4` (`N5`, `Q5`) — bentuk "lima pembacaan". Membetulkannya ke `√10`
 *    MENGECILKAN u, jadi ditiru. §1.
 *  - Drift `/12` padahal selisihnya hari (preseden Height Gauge §1). §2.
 *  - Pembagi `√6` untuk komponen berlabel `rect.` (preseden Height Gauge §2). §3.
 *  - Komponen suhu memakai `Uα = 2·Δα` sebagai ci, dan komponen muai memakai
 *    `Uα` sebagai u-nya. §4.
 */
class DialIndicatorCalculator
{
    private ?GumCalculator $gum = null;

    private ?TabelStandarDialIndicator $tabel = null;

    /**
     * Simpangan baku contoh (n-1), sama dengan `STDEV()` Excel.
     *
     * @param  list<float>  $nilai
     */
    public function simpanganBaku(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;

        return sqrt(array_sum(array_map(static fn (float $x): float => ($x - $rata) ** 2, $nilai)) / ($n - 1));
    }

    /**
     * Total nominal satu tumpukan (mm) — jumlah nilai TERKOREKSI tiap keping.
     *
     * Balik `null` kalau tumpukannya kosong atau salah satu keping tidak
     * terdaftar. Master membungkusnya `IFERROR(...; "")` dan keping yang tidak
     * ketemu cuma hilang dari `SUM` — titiknya tetap terbit dengan total yang
     * lebih pendek.
     *
     * @param  array<int|string, mixed>  $keping
     */
    public function totalNominal(array $keping): ?float
    {
        $nominal = $this->deretAngka($keping);

        if ($nominal === []) {
            return null;
        }

        $total = 0.0;

        foreach ($nominal as $n) {
            $nilai = $this->tabel()->nilaiKeping($n);

            if ($nilai === null) {
                return null;
            }

            $total += $nilai;
        }

        return $total;
    }

    /**
     * Keping yang tidak terdaftar di tabel balok ukur — dipakai buat alasan
     * penolakan yang menyebut angkanya, bukan cuma "ada yang salah".
     *
     * @param  array<int|string, mixed>  $keping
     * @return list<float>
     */
    public function kepingTakTerdaftar(array $keping): array
    {
        return array_values(array_filter(
            $this->deretAngka($keping),
            fn (float $n): bool => $this->tabel()->nilaiKeping($n) === null,
        ));
    }

    /**
     * Umur sertifikat balok ukur dalam hari pada tanggal sesi, atau `null`
     * kalau sesi mendahului sertifikat yang tersimpan (sesi historis — drift
     * nol, dicatat, tetap terbit; preseden Micrometer).
     */
    public function umurStandarHari(DateTimeInterface $tanggalKalibrasi): ?float
    {
        $standar = new DateTimeImmutable($this->tabel()->standar()['tanggal_kalibrasi']);
        // `U.u`, bukan `getTimestamp()` — yang kedua membuang pecahan detik, dan
        // `NOW()` master menyimpannya (10:06:10,38); drift lalu meleset 6·10⁻⁹.
        $hari = ((float) $tanggalKalibrasi->format('U.u') - (float) $standar->format('U.u')) / 86400;

        return $hari >= 0 ? $hari : null;
    }

    /**
     * Hitung SATU sesi: titik + satu budget sepuluh komponen.
     *
     * @param  list<array{titik_ke: int, keping: array<int|string, mixed>, pembacaan: array<int|string, mixed>}>  $titik  semua mm
     * @param  array{kapasitas_mm: float, resolusi_mm: float, tanggal_kalibrasi: DateTimeInterface, pra_evaluasi: array<int|string, mixed>, balok_pra_evaluasi: array<int|string, mixed>, suhu_ruang_rata_c: float, suhu_uut_c?: float|null}  $konteks
     * @return array<string, mixed>
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $k = $this->tabel()->konstanta();
        $ditolak = [];
        $dihitung = [];

        $suhuStandar = (float) $konteks['suhu_ruang_rata_c'];
        $suhuUut = isset($konteks['suhu_uut_c']) && is_numeric($konteks['suhu_uut_c'])
            ? (float) $konteks['suhu_uut_c']
            : $suhuStandar;
        $theta = (($suhuStandar + $suhuUut) / 2) - (float) $k['suhu_acuan_c'];   // Q31
        $deltaTheta = abs($suhuStandar - $suhuUut);                               // X31
        $alphaS = (float) $k['alpha_per_c'];                                      // S31
        $alphaT = (float) $k['alpha_per_c'];                                      // U31
        $deltaAlpha = abs($alphaS - $alphaT);                                     // V31
        $alphaRata = ($alphaS + $alphaT) / 2;                                     // W31

        foreach ($titik as $t) {
            $tak = $this->kepingTakTerdaftar($t['keping']);

            if ($tak !== []) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => sprintf(
                        'Balok ukur %s mm tidak ada di daftar Gauge Block terkalibrasi (GB-9122-0) — '
                        .'titik tidak dihitung. Pakai hanya nominal keping yang terdaftar.',
                        implode(' + ', array_map(static fn (float $n): string => self::angkaTampil($n), $tak)),
                    ),
                ];

                continue;
            }

            $total = $this->totalNominal($t['keping']);

            if ($total === null) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => 'Nominal balok ukur belum diisi — titik tidak dihitung.',
                ];

                continue;
            }

            $pembacaan = $this->deretAngka($t['pembacaan']);

            if ($pembacaan === []) {
                $ditolak[] = [
                    'titik_ke' => $t['titik_ke'],
                    'alasan' => 'Tidak ada pembacaan alat — titik tidak dihitung.',
                ];

                continue;
            }

            $rata = array_sum($pembacaan) / count($pembacaan);
            $standarTerkoreksi = $total + $total * (($alphaRata * $deltaTheta) + ($alphaS * $deltaAlpha));

            $dihitung[] = [
                'titik_ke' => $t['titik_ke'],
                'keping' => $this->deretAngka($t['keping']),
                'total_nominal' => $total,
                'standar_terkoreksi' => $standarTerkoreksi,
                'pembacaan' => $pembacaan,
                'rata_rata' => $rata,
                'koreksi' => $standarTerkoreksi - $rata,
                'simpangan_baku' => $this->simpanganBaku($pembacaan),
                'jumlah_pengulangan' => count($pembacaan),
            ];
        }

        $pita = $this->tabel()->pitaCmc((float) $konteks['kapasitas_mm']);

        if ($dihitung === []) {
            return [
                'titik' => [], 'budget' => [], 'l_maks_mm' => 0.0, 'ketidakpastian_gabungan' => 0.0,
                'derajat_kebebasan_efektif' => null, 'faktor_cakupan_k' => 2.0,
                'ketidakpastian_diperluas' => 0.0, 'u95_sertifikat' => 0.0, 'type_b' => 0.0,
                'pita_cmc' => $pita, 'evaluasi_tanpa_sebaran' => false, 'boleh_terbit' => false,
                'ditolak' => $ditolak,
            ];
        }

        // Panjang sensitivitas: total nominal TERBESAR — penyimpangan no. 1.
        $lMaks = max(array_map(static fn (array $t): float => (float) $t['total_nominal'], $dihitung));
        $kepingMaks = max(array_map(static fn (array $t): int => count($t['keping']), $dihitung));

        $budget = $this->budget($konteks, $lMaks, $kepingMaks, $theta, $deltaTheta, $ditolak);
        $agregat = $this->gum()->agregasiBudget(array_map(
            static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
            $budget,
        ));

        if ($pita === null) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => sprintf(
                    'Kapasitas %s mm di luar keempat pita CMC terakreditasi (0-25, 0-50, 0-100, 0-300 mm) '
                    .'atau belum diisi — U95 tidak diterbitkan karena tidak punya lantai CMC.',
                    self::angkaTampil((float) $konteks['kapasitas_mm']),
                ),
            ];
        }

        $praEvaluasi = $this->deretAngka($konteks['pra_evaluasi'] ?? []);
        $balokEvaluasi = $this->deretAngka($konteks['balok_pra_evaluasi'] ?? []);

        return [
            'titik' => $dihitung,
            'budget' => $budget,
            'l_maks_mm' => $lMaks,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            // `MAX(AA19:AA20)` master — lantai CMC. Tanpa pita, 0 = belum terbit.
            'u95_sertifikat' => $pita === null
                ? 0.0
                : max($agregat['ketidakpastian_diperluas'], (float) $pita['u95_mm']),
            'type_b' => sqrt(array_sum(array_map(
                static fn (array $b): float => ($b['u'] * $b['ci']) ** 2,
                array_filter($budget, static fn (array $b): bool => $b['distribusi'] !== 't-student'),
            ))),
            'pita_cmc' => $pita,
            'evaluasi_tanpa_sebaran' => count($praEvaluasi) >= 2 && ! $this->punyaSebaran($praEvaluasi),
            // Gerbang DIPATOK eksplisit, bukan "tidak ada satu pun penolakan" —
            // `budget()` juga mencatat hal yang tidak menahan (umur drift
            // negatif). Yang menahan: tanpa pita CMC, Evaluation < 2 pembacaan,
            // balok ukur Evaluation kosong/tak terdaftar, atau resolusi kosong.
            //
            // Sepuluh pembacaan Evaluation yang IDENTIK sengaja TIDAK menahan,
            // beda dari Micrometer & Height Gauge. Di sana nilai identik
            // terbukti data rusak (635,0 sepuluh kali). Di sini justru bentuk
            // NORMAL: dial resolusi 0,01 mm membaca 25,01 sepuluh kali di sesi
            // contoh master, dan kesepuluh titiknya pun identik per baris.
            // Komponen resolusi (u = r/(2√3)) sudah menampung keterulangan yang
            // tertutup resolusi, dan lantai CMC berdiri di bawahnya — jadi
            // pengulangan nol tidak menerbitkan U yang terlalu kecil. Diangkat
            // sebagai peringatan sesi dan pertanyaan lab §5.
            'boleh_terbit' => $pita !== null
                && count($praEvaluasi) >= 2
                && $balokEvaluasi !== []
                && $this->kepingTakTerdaftar($balokEvaluasi) === []
                && (float) $konteks['resolusi_mm'] > 0.0,
            'ditolak' => $ditolak,
        ];
    }

    /**
     * Sepuluh komponen budget (mm), urut `PERHITUNGAN U95%` baris 5..14.
     *
     * Publik supaya `DialIndicatorMasterTest` bisa mengadu KESEPULUH komponen ke
     * master dengan `L = C61` master sendiri — penyimpangan no. 1 cuma
     * menggeser ci dua komponen, dan menguji rumusnya terpisah dari pilihan
     * panjangnya membuat keduanya tidak saling menutupi.
     *
     * @param  array<string, mixed>  $konteks
     * @param  list<array{titik_ke: int, alasan: string}>  $ditolak
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float, satuan: string}>
     */
    public function budget(
        array $konteks,
        float $lMaks,
        int $kepingMaks,
        float $theta,
        float $deltaTheta,
        array &$ditolak,
    ): array {
        $k = $this->tabel()->konstanta();
        $akar3 = sqrt(3.0);
        $vi = (float) $k['vi_type_b'];
        $uAlpha = (float) $k['delta_alpha_per_c'] * (float) $k['u_alpha_pengali'];   // W22

        $praEvaluasi = $this->deretAngka($konteks['pra_evaluasi'] ?? []);
        $nUlang = count($praEvaluasi);

        if ($nUlang < 2) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Blok Evaluation butuh minimal dua pembacaan berulang untuk simpangan baku — '
                    .'tanpa itu komponen keterulangan tidak punya dasar.',
            ];
        }

        if ((float) $konteks['resolusi_mm'] <= 0.0) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Resolusi alat belum diisi. Tanpa resolusi, komponen resolusi budget bernilai nol '
                    .'dan U95 terbit lebih kecil dari seharusnya.',
            ];
        }

        $balokEvaluasi = $this->deretAngka($konteks['balok_pra_evaluasi'] ?? []);
        $balokTak = $this->kepingTakTerdaftar($balokEvaluasi);

        if ($balokEvaluasi === [] || $balokTak !== []) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => $balokEvaluasi === []
                    ? 'Balok ukur blok Evaluation belum diisi — komponen "Standard balok ukur" lahir dari '
                        .'keping itu, dan tanpa dia komponennya nol.'
                    : sprintf(
                        'Balok ukur Evaluation %s mm tidak ada di daftar Gauge Block terkalibrasi.',
                        implode(' + ', array_map(static fn (float $n): string => self::angkaTampil($n), $balokTak)),
                    ),
            ];
        }

        // `O25 = SQRT(ΣH23:M23²)` µm, `P25 = O25/1000` mm.
        $uStandar = sqrt(array_sum(array_map(
            fn (float $n): float => $this->tabel()->ketidakpastianKeping($n) ** 2,
            $balokTak === [] ? $balokEvaluasi : [],
        ))) / 1000;

        $umurHari = $this->umurStandarHari($konteks['tanggal_kalibrasi']);

        if ($umurHari === null) {
            $ditolak[] = [
                'titik_ke' => 0,
                'alasan' => 'Tanggal kalibrasi sesi lebih awal dari tanggal kalibrasi balok ukur standar yang '
                    .'tersimpan, jadi umur drift dianggap nol. Lantai CMC tetap berlaku.',
            ];
            $umurHari = 0.0;
        }

        $kapasitas = (float) $konteks['kapasitas_mm'];
        // `K10 = ((0,02 + 0,00025·L21)·1)/1000·((X11 − W13)/12)` — `/12` untuk
        // selisih HARI ditiru, pertanyaan lab §2.
        $drift = ((float) $k['drift_a_um'] + (float) $k['drift_b_um_per_mm'] * $kapasitas)
            / 1000
            * ($umurHari / (float) $k['drift_pembagi_umur']);

        $ciSuhu = $lMaks * $uAlpha;   // V8 = C61·K9

        return [
            [
                'sumber' => 'pengulangan',
                'keterangan' => 'Repeatability (blok Evaluation)',
                'distribusi' => 't-student',
                // `/√5`, `vi = 4` walau pembacaannya sepuluh — §1.
                'u' => $nUlang >= 2
                    ? $this->simpanganBaku($praEvaluasi) / sqrt((float) $k['pengulangan_pembagi_n'])
                    : 0.0,
                'ci' => 1.0,
                'vi' => $nUlang >= 2 ? (float) $k['pengulangan_pembagi_n'] - 1 : 0.0,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'resolusi_uut',
                'keterangan' => 'Resolusi',
                'distribusi' => 'rectangular',
                'u' => ((float) $konteks['resolusi_mm'] / 2) / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => 'Standard balok ukur',
                'distribusi' => 'normal',
                'u' => $uStandar / 2,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'suhu_ruang',
                'keterangan' => 'Perubahan suhu terhadap suhu acuan 20 °C',
                'distribusi' => 'rectangular',
                'u' => $theta / $akar3,
                'ci' => $ciSuhu,
                'vi' => $vi,
                'satuan' => '/°C',
            ],
            [
                'sumber' => 'koefisien_muai',
                'keterangan' => 'Koefisien muai thermal',
                'distribusi' => 'rectangular',
                // `√6` walau berlabel rect. — §3.
                'u' => $uAlpha / (float) $k['pembagi_muai'],
                'ci' => $lMaks * $theta,
                'vi' => $vi,
                'satuan' => '/°C',
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => 'Drift standard',
                'distribusi' => 'rectangular',
                'u' => $drift / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm/tahun',
            ],
            [
                'sumber' => 'wringing',
                'keterangan' => 'Lapisan wringing',
                'distribusi' => 'rectangular',
                // `K11 = SQRT(B61·0,05²)/1000` — B61 jumlah keping terbanyak.
                'u' => (sqrt($kepingMaks * (float) $k['wringing_um_per_keping'] ** 2) / 1000) / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'tegak_lurus',
                'keterangan' => 'Kesalahan ketegaklurusan',
                'distribusi' => 'rectangular',
                'u' => (float) $k['tegak_lurus_mm'] / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'meja_granit',
                'keterangan' => 'Meja Granit (meja rata)',
                'distribusi' => 'normal',
                'u' => (float) $k['meja_granit_mm'] / 2,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            [
                'sumber' => 'selisih_suhu',
                'keterangan' => 'Selisih suhu Dial dengan balok ukur',
                'distribusi' => 'rectangular',
                'u' => $deltaTheta / $akar3,
                'ci' => $ciSuhu,   // V14 = V8
                'vi' => $vi,
                'satuan' => '°C',
            ],
        ];
    }

    /** @param  list<float>  $nilai */
    private function punyaSebaran(array $nilai): bool
    {
        return count($nilai) >= 2 && max($nilai) !== min($nilai);
    }

    /**
     * Deret angka bersih — yang bukan angka DILEWATI, bukan dibaca nol. Satu nol
     * di antara sepuluh pembacaan 25,01 menggelembungkan simpangan bakunya
     * ribuan kali, tanpa satu pun error.
     *
     * @param  array<int|string, mixed>  $nilai
     * @return list<float>
     */
    private function deretAngka(array $nilai): array
    {
        return array_values(array_map('floatval', array_filter($nilai, static fn ($x): bool => is_numeric($x))));
    }

    private static function angkaTampil(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');
    }

    private function tabel(): TabelStandarDialIndicator
    {
        // Malas — lingkaran profil → kalkulator → GumCalculator → registry.
        return $this->tabel ??= new TabelStandarDialIndicator;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
