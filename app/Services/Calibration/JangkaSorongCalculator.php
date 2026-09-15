<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Mesin hitung **Jangka Sorong (Vernier Caliper)** — lampiran akreditasi
 * LK-285-IDN no. 35 (0-300 mm, CMC 0,015 mm), metode `SIDIK-IK-CAL-0520_Rev.2`.
 *
 * Satu workbook master (`Master Olah Data Caliper 2026 (std caliper
 * checker+gb).xlsm`, password `spirit285`), dibuktikan lebih dulu di Python:
 * 311 sel cocok pada 5·10⁻⁶. Master Height Gauge TURUNAN master ini, jadi
 * [HeightGaugeCalculator] contekan terdekatnya — tapi bedanya banyak.
 *
 * ## TIGA budget tingkat SESI, bukan satu
 *
 * Sheet `PERHITUNGAN U95%` punya tiga kolom budget — Outside (baris 5-14),
 * Inside (24-33), dan Depth (43-53) — dan sertifikat mencetak satu
 * `Uncertainty U95% = ±` di bawah tiap tabel. Outside & Inside sepuluh
 * komponen, Depth SEBELAS (Meja Rata granit ikut). Ketiganya dalam mm.
 *
 * ## Bentuk satu titik
 *
 *     H (total)          = Σ nilai terkoreksi slot nominal (titik nol: 0)
 *     standar terkoreksi = H + H·((α_avg·δϴ) + (X·δα)) − ld − lw − lg − lf
 *     koreksi            = standar terkoreksi − rata-rata pembacaan
 *
 * `X` = ϴ untuk Outside, αs untuk Inside & Depth — ditiru apa adanya dari
 * `PERHITUNGAN!AH37` lawan `AH72`/`AH106`. Keduanya nol selama δα = 0
 * (αs = αt = 1,2·10⁻⁶), dan keempat suku deformasi nol di seluruh master.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 * Semuanya membuat U lebih besar, sama, atau menahan penerbitan — tidak pernah
 * diam-diam lebih kecil:
 *
 *  1. **Lantai CMC hilang** (`AA20`/`AA39`/`AA59` kosong). Vernier Caliper ADA
 *     di lampiran, dan angka 15 µm tersedia di `DATABASE!T5` tapi tidak
 *     tersambung. Caliper digital resolusi 0,01 terbit ≈ 0,0077 mm di master —
 *     separuh CMC terakreditasi. Di sini `U95 = max(U, 0,015)`, dan kapasitas
 *     di luar 0-300 mm (atau kosong) menahan seluruh sesi. Pertanyaan §2.
 *  2. **Repeatability Depth menunjuk sel kosong** (`K43 = PERHITUNGAN!AD121`),
 *     jadi selalu nol. Maksudnya `AI121` (STDEV terbesar titik Depth) — itu
 *     yang dipakai di sini. Pertanyaan §3.
 *  3. **Bacaan Depth dari workbook lain** (`[3]INPUT DATA`). Di sini dari
 *     lembar sesi ini sendiri. Pertanyaan §4.
 *  4. **Kolom termal cuma terisi di baris pertama tiap blok** — suku koreksi
 *     termal di sini dihitung untuk SEMUA titik (preseden Height Gauge).
 *  5. **Umur drift dari `NOW()`** — di sini tanggal kalibrasi sesi.
 *
 * ## Yang DITIRU walau janggal — `docs/pertanyaan-lab-jangka-sorong.md`
 *
 *  - Drift `/12` untuk selisih HARI dan pembagi `√6` pada komponen `rect.`
 *    (sama dengan Height Gauge §1-§2).
 *  - Inside memakai `Lmaks`, ϴ, dan δϴ milik Outside. Di sini `Lmaks` = nominal
 *    terbesar Outside ∪ Inside — tidak pernah lebih kecil dari master.
 *  - Inside & Depth: STDEV per titik cuma dari LIMA pembacaan pertama
 *    (`STDEV(I:M)`), rata-rata dari semuanya.
 *  - Depth: ci suhu dari balok 100 mm (tautan luar), ci muai 50 diketik,
 *    drift tanpa faktor umur, pembagi repeatability √10 dengan vi 9.
 *  - vi Type B 60, vi resolusi 10⁹.
 */
class JangkaSorongCalculator
{
    public const GRUP = ['outside', 'inside', 'depth'];

    private ?GumCalculator $gum = null;

    private ?TabelStandarJangkaSorong $tabel = null;

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
     * Umur sertifikat Caliper Checker (hari) pada `$tanggalKalibrasi`, atau
     * `null` kalau sesi mendahului sertifikat yang tersimpan — perlakuan yang
     * sama dengan [HeightGaugeCalculator::umurStandarHari].
     */
    public function umurCaliperCheckerHari(DateTimeInterface $tanggalKalibrasi): ?float
    {
        $standar = new DateTimeImmutable($this->tabel()->standarCaliperChecker()['tanggal_kalibrasi']);
        $hari = ($tanggalKalibrasi->getTimestamp() - $standar->getTimestamp()) / 86400;

        return $hari >= 0 ? $hari : null;
    }

    /**
     * Total nominal satu titik (mm), atau `null` kalau satu slot pun tidak
     * terdaftar di tabel grupnya.
     *
     * Nominal 0 pada Outside/Inside itu titik NOL (rahang tertutup), sah, dan
     * tidak dicari di tabel — master menulisnya `H37 = C37` tanpa VLOOKUP. Di
     * Depth nominal 0 tidak punya arti dan ditolak.
     *
     * @param  array<int|string, mixed>  $nominal
     */
    public function totalNominal(string $grup, array $nominal): ?float
    {
        $tabel = $this->tabel();
        $total = 0.0;
        $adaSlot = false;

        foreach ($this->deretAngka($nominal) as $n) {
            if ($n === 0.0 && $grup !== 'depth') {
                $adaSlot = true;

                continue;
            }

            $nilai = match ($grup) {
                'outside' => $tabel->nilaiOutside($n),
                'inside' => $tabel->nilaiInside($n),
                default => $tabel->nilaiKeping($n),
            };

            if ($nilai === null) {
                return null;
            }

            $total += $nilai;
            $adaSlot = true;
        }

        return $adaSlot ? $total : null;
    }

    /**
     * Kesejajaran muka ukur — `koreksi = nominal − pembacaan` per posisi
     * (`PERHITUNGAN!H126:H128`). Tidak masuk budget dan tidak punya batas
     * kelulusan di master; dicetak apa adanya.
     *
     * @param  array<int|string, mixed>  $baris  list of {posisi, nominal, pembacaan}
     * @return list<array{posisi: string, nominal: float, pembacaan: float, koreksi: float}>
     */
    public function kesejajaran(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $b) {
            if (! is_array($b) || ! is_numeric($b['nominal'] ?? null) || ! is_numeric($b['pembacaan'] ?? null)) {
                continue;
            }

            $hasil[] = [
                'posisi' => (string) ($b['posisi'] ?? ''),
                'nominal' => (float) $b['nominal'],
                'pembacaan' => (float) $b['pembacaan'],
                'koreksi' => (float) $b['nominal'] - (float) $b['pembacaan'],
            ];
        }

        return $hasil;
    }

    /**
     * Hitung SATU sesi: titik ketiga tabel + tiga budget.
     *
     * Titik yang tidak bisa dihitung dilaporkan lewat `ditolak`, tidak dibuang
     * diam-diam. Tiap grup punya gerbang terbitnya sendiri — Inside yang blok
     * Evaluation-nya kosong tidak boleh menahan Outside yang lengkap.
     *
     * @param  array{outside?: list<array{titik_ke: int, nominal: array<int|string, mixed>, pembacaan: array<int|string, mixed>}>, inside?: list<array<string, mixed>>, depth?: list<array<string, mixed>>}  $titik
     * @param  array{kapasitas_mm: float, resolusi_mm: float, tanggal_kalibrasi: DateTimeInterface, pra_evaluasi_outside: list<float>, pra_evaluasi_inside: list<float>, suhu_ruang_rata_c: float, suhu_uut_c?: float|null, kesejajaran?: list<array<string, mixed>>}  $konteks
     * @return array{grup: array<string, array<string, mixed>>, kesejajaran: list<array<string, mixed>>, pita_cmc: array<string, mixed>|null, ditolak: list<array{titik_ke: int, alasan: string}>, peringatan: list<string>}
     */
    public function hitungSesi(array $titik, array $konteks): array
    {
        $k = $this->tabel()->konstanta();
        $ditolak = [];
        $peringatan = [];

        // Suhu Caliper Checker & suhu UUT diturunkan dari rata-rata suhu ruangan
        // — `PERHITUNGAN!V37` dan `W37` master dua-duanya `G14` = 20,25. Jalur
        // suhu UUT terpisah tetap hidup, seperti Height Gauge.
        $suhuStandar = (float) $konteks['suhu_ruang_rata_c'];
        $suhuUut = isset($konteks['suhu_uut_c']) && is_numeric($konteks['suhu_uut_c'])
            ? (float) $konteks['suhu_uut_c']
            : $suhuStandar;
        $theta = $suhuStandar - (float) $k['suhu_acuan_c'];
        $deltaTheta = $suhuUut - $suhuStandar;
        $alphaS = (float) $k['alpha_per_c'];
        $alphaT = (float) $k['alpha_per_c'];
        $deltaAlpha = $alphaT - $alphaS;
        $alphaRata = ($alphaS + $alphaT) / 2;

        $dihitung = [];

        foreach (self::GRUP as $grup) {
            $dihitung[$grup] = [];

            foreach ($titik[$grup] ?? [] as $t) {
                $total = $this->totalNominal($grup, $t['nominal']);

                if ($total === null) {
                    $ditolak[] = [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => sprintf(
                            'Nominal %s tidak ada di daftar %s terkalibrasi — titik tidak dihitung.',
                            implode(' + ', array_map(static fn ($n): string => (string) $n, $this->deretAngka($t['nominal']))) ?: '(kosong)',
                            $grup === 'depth' ? 'balok ukur' : 'Caliper Checker '.ucfirst($grup),
                        ),
                    ];

                    continue;
                }

                $pembacaan = $this->deretAngka($t['pembacaan']);

                if ($pembacaan === []) {
                    $ditolak[] = [
                        'titik_ke' => (int) $t['titik_ke'],
                        'alasan' => 'Tidak ada pembacaan alat — titik tidak dihitung.',
                    ];

                    continue;
                }

                $rata = array_sum($pembacaan) / count($pembacaan);
                $suku = $grup === 'outside' ? $theta : $alphaS;
                $standarTerkoreksi = $total + $total * (($alphaRata * $deltaTheta) + ($suku * $deltaAlpha));

                // Inside & Depth: STDEV cuma dari lima pembacaan pertama
                // (`STDEV(I:M)`), rata-rata dari semuanya — ditiru, pertanyaan §7.
                $untukStdev = $grup === 'outside' ? $pembacaan : array_slice($pembacaan, 0, 5);

                $dihitung[$grup][] = [
                    'grup' => $grup,
                    'titik_ke' => (int) $t['titik_ke'],
                    'nominal' => $this->deretAngka($t['nominal']),
                    'total_nominal' => $total,
                    'standar_terkoreksi' => $standarTerkoreksi,
                    'pembacaan' => $pembacaan,
                    'rata_rata' => $rata,
                    'koreksi' => $standarTerkoreksi - $rata,
                    'simpangan_baku' => $this->simpanganBaku($untukStdev),
                    'jumlah_pengulangan' => count($pembacaan),
                ];
            }
        }

        $pita = $this->tabel()->pitaCmc((float) $konteks['kapasitas_mm']);
        $resolusiAda = (float) $konteks['resolusi_mm'] > 0.0;
        $alasanSesi = [];

        // Kapasitas KOSONG menahan — tanpa kapasitas, tidak ada yang tahu sesi
        // ini di dalam atau di luar lingkup. Kapasitas di atas 300 mm TIDAK
        // menahan: sesinya terbit tanpa lantai CMC dan tanpa klaim akreditasi
        // (`JangkaSorongProfile::dalamLingkupAkreditasiSesi()`), preseden Height
        // Gauge. Master pun menerbitkan sesi 600 mm-nya; yang tidak boleh ikut
        // hanyalah logo & nomor KAN di sertifikatnya.
        if ((float) $konteks['kapasitas_mm'] <= 0.0) {
            $alasanSesi[] = 'Kapasitas alat belum diisi — pita CMC dan status akreditasi sesi ditentukan '
                .'kapasitas, jadi U95 tidak diterbitkan.';
        }

        if (! $resolusiAda) {
            $alasanSesi[] = 'Resolusi alat belum diisi. Tanpa resolusi, komponen resolusi ketiga budget bernilai nol '
                .'dan U95 terbit lebih kecil dari seharusnya.';
        }

        foreach ($alasanSesi as $alasan) {
            $ditolak[] = ['titik_ke' => 0, 'alasan' => $alasan];
        }

        // Lmaks Outside & Inside: nominal terbesar dari KEDUA tabel. Master
        // memakai `C67` (Outside) untuk keduanya; gabungan tidak pernah lebih
        // kecil dari itu, dan Inside yang lebih panjang dari Outside tidak lagi
        // memungut ci milik tabel lain yang lebih pendek.
        $nominalOi = [];
        foreach (['outside', 'inside'] as $g) {
            foreach ($dihitung[$g] as $h) {
                foreach ($h['nominal'] as $n) {
                    $nominalOi[] = $n;
                }
            }
        }
        $lMaksOi = $nominalOi === [] ? 0.0 : max($nominalOi);

        $umur = $this->umurCaliperCheckerHari($konteks['tanggal_kalibrasi']);

        if ($umur === null) {
            $peringatan[] = 'Tanggal kalibrasi sesi lebih awal dari sertifikat Caliper Checker yang tersimpan, jadi '
                .'umur drift Outside & Inside dianggap nol. Lantai CMC tetap berlaku.';
            $umur = 0.0;
        }

        $grupHasil = [];

        foreach (self::GRUP as $grup) {
            if ($dihitung[$grup] === []) {
                continue;
            }

            $gerbang = [];

            if ($grup === 'depth') {
                $budget = $this->budgetDepth($dihitung['depth'], $konteks, $theta, $deltaTheta, $gerbang, $peringatan);
            } else {
                $budget = $this->budgetCaliperChecker(
                    $grup, $dihitung[$grup], $konteks, $lMaksOi, $theta, $deltaTheta, $umur, $gerbang, $peringatan,
                );
            }

            $agregat = $this->gum()->agregasiBudget(array_map(
                static fn (array $b): array => ['u' => $b['u'], 'ci' => $b['ci'], 'vi' => $b['vi']],
                $budget,
            ));

            // Di luar pita (> 300 mm): U telanjang, persis master — tidak ada
            // lantai CMC yang bisa dipertanggungjawabkan di luar lingkup.
            $u95 = $pita === null
                ? $agregat['ketidakpastian_diperluas']
                : max($agregat['ketidakpastian_diperluas'], (float) $pita['u95_mm']);

            foreach ($gerbang as $alasan) {
                $ditolak[] = ['titik_ke' => 0, 'alasan' => $alasan];
            }

            $grupHasil[$grup] = [
                'titik' => $dihitung[$grup],
                'budget' => $budget,
                'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
                'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
                'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
                'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
                'u95_sertifikat' => $u95,
                'type_b' => sqrt(array_sum(array_map(
                    static fn (array $b): float => ($b['u'] * $b['ci']) ** 2,
                    array_filter($budget, static fn (array $b): bool => $b['distribusi'] !== 't-student'),
                ))),
                // Gerbang DIPATOK eksplisit: syarat sesi (pita CMC, resolusi)
                // DAN syarat grup (dasar repeatability). Umur drift negatif
                // sengaja tidak ikut — dia catatan sertifikat lama, bukan cacat
                // pengukuran.
                'boleh_terbit' => $alasanSesi === [] && $gerbang === [],
                'alasan_tahan' => [...$alasanSesi, ...$gerbang],
            ];
        }

        return [
            'grup' => $grupHasil,
            'kesejajaran' => $this->kesejajaran($konteks['kesejajaran'] ?? []),
            'pita_cmc' => $pita,
            'ditolak' => $ditolak,
            'peringatan' => $peringatan,
        ];
    }

    /**
     * Sepuluh komponen budget Outside (`PERHITUNGAN U95%` baris 5-14) atau
     * Inside (baris 24-33, urutannya beda: selisih suhu pindah ke posisi 5).
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  array<string, mixed>  $konteks
     * @param  list<string>  $gerbang
     * @param  list<string>  $peringatan
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float, satuan: string}>
     */
    public function budgetCaliperChecker(
        string $grup,
        array $dihitung,
        array $konteks,
        float $lMaks,
        float $theta,
        float $deltaTheta,
        float $umurHari,
        array &$gerbang,
        array &$peringatan,
    ): array {
        $k = $this->tabel()->konstanta();
        $akar3 = sqrt(3.0);
        $vi = (float) $k['vi_type_b'];

        $evaluasi = $this->deretAngka($konteks['pra_evaluasi_'.$grup] ?? []);
        $n = count($evaluasi);
        $label = ucfirst($grup);

        if ($n < 2) {
            $gerbang[] = sprintf(
                'Blok Evaluation %s butuh minimal dua pembacaan berulang — dari situ repeatability budget %s '
                .'lahir, dan tanpa itu komponennya nol.',
                $label, $label,
            );
        } elseif (! $this->punyaSebaran($evaluasi)) {
            // Peringatan, bukan penahan: budget ini punya lantai CMC 0,015 mm,
            // dan komponen resolusi sudah menampung sebaran di bawah resolusi.
            // Keputusan yang sama dengan Dial Indicator; pertanyaan §8.
            $peringatan[] = sprintf(
                'Kesepuluh pembacaan Evaluation %s identik, jadi repeatability %s nol. U95 tetap dijaga lantai '
                .'CMC dan komponen resolusi — pastikan memang tidak ada sebaran pada resolusi alat.',
                $label, $label,
            );
        }

        $ciSuhu = $lMaks * (float) $k['u_alpha_caliper_checker_per_c'];
        $ciMuai = $lMaks * $theta;

        $drift = ((float) $k['drift_a_um'] + (float) $k['drift_b_um_per_mm'] * $lMaks) / 1000
            * ($umurHari / (float) $k['drift_pembagi_umur']);

        $kepingMaks = max(array_map(static fn (array $t): int => max(1, count($t['nominal'])), $dihitung));

        $komponen = [
            'pengulangan' => [
                'sumber' => 'pengulangan',
                'keterangan' => "Repeatability — blok Evaluation {$label}",
                'distribusi' => 't-student',
                'u' => $n >= 2 ? $this->simpanganBaku($evaluasi) / sqrt($n) : 0.0,
                'ci' => 1.0,
                'vi' => $n >= 2 ? (float) ($n - 1) : 0.0,
                'satuan' => 'mm',
            ],
            'resolusi_uut' => [
                'sumber' => 'resolusi_uut',
                'keterangan' => 'Resolusi',
                'distribusi' => 'rectangular',
                'u' => ((float) $konteks['resolusi_mm'] / 2) / $akar3,
                'ci' => 1.0,
                'vi' => (float) $k['vi_resolusi'],
                'satuan' => 'mm',
            ],
            'ketidakpastian_standar' => [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => 'Standard Caliper Checker (U ls)',
                'distribusi' => 'normal',
                'u' => $this->tabel()->u95CaliperCheckerMm() / 2,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            'suhu_ruang' => [
                'sumber' => 'suhu_ruang',
                'keterangan' => 'Perubahan suhu terhadap suhu acuan 20 °C',
                'distribusi' => 'rectangular',
                'u' => $theta / $akar3,
                'ci' => $ciSuhu,
                'vi' => $vi,
                'satuan' => '°C',
            ],
            'selisih_suhu' => [
                'sumber' => 'selisih_suhu',
                'keterangan' => 'Selisih suhu Jangka Sorong dengan Caliper Checker',
                'distribusi' => 'rectangular',
                'u' => abs($deltaTheta) / $akar3,
                'ci' => $ciSuhu,
                'vi' => $vi,
                'satuan' => '°C',
            ],
            'koefisien_muai' => [
                'sumber' => 'koefisien_muai',
                'keterangan' => 'Koefisien muai thermal',
                'distribusi' => 'rectangular',
                'u' => (float) $k['u_alpha_caliper_checker_per_c'] / (float) $k['pembagi_muai'],
                'ci' => $ciMuai,
                'vi' => $vi,
                'satuan' => '/°C',
            ],
            'drift_standar' => [
                'sumber' => 'drift_standar',
                'keterangan' => 'Drift standard',
                'distribusi' => 'rectangular',
                'u' => $drift / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm/th',
            ],
            'wringing' => [
                'sumber' => 'wringing',
                'keterangan' => 'Lapisan wringing',
                'distribusi' => 'rectangular',
                'u' => sqrt($kepingMaks * (float) $k['wringing_um_per_keping'] ** 2) / 1000 / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            'geometri' => [
                'sumber' => 'geometri',
                'keterangan' => 'Kesalahan Geometri',
                'distribusi' => 'rectangular',
                'u' => (float) $k['geometri_mm'] / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
            'efek_mekanik' => [
                'sumber' => 'efek_mekanik',
                'keterangan' => 'Efek Mekanik',
                'distribusi' => 'rectangular',
                'u' => (float) $k['efek_mekanik_mm'] / $akar3,
                'ci' => 1.0,
                'vi' => $vi,
                'satuan' => 'mm',
            ],
        ];

        $urutan = $grup === 'outside'
            ? ['pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'koefisien_muai',
                'drift_standar', 'wringing', 'geometri', 'efek_mekanik', 'selisih_suhu']
            : ['pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'selisih_suhu',
                'koefisien_muai', 'drift_standar', 'wringing', 'geometri', 'efek_mekanik'];

        return array_map(static fn (string $kunci): array => $komponen[$kunci], $urutan);
    }

    /**
     * Sebelas komponen budget Depth (`PERHITUNGAN U95%` baris 43-53).
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  array<string, mixed>  $konteks
     * @param  list<string>  $gerbang
     * @param  list<string>  $peringatan
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float, satuan: string}>
     */
    public function budgetDepth(
        array $dihitung,
        array $konteks,
        float $theta,
        float $deltaTheta,
        array &$gerbang,
        array &$peringatan,
    ): array {
        $k = $this->tabel()->konstanta();
        $akar3 = sqrt(3.0);
        $vi = (float) $k['vi_type_b'];

        // Repeatability: STDEV TERBESAR titik Depth (`AI121`), bukan sel kosong
        // `AD121` yang dirujuk master — lihat penyimpangan no. 2. Titik yang
        // cuma punya satu pembacaan tidak punya sebaran untuk dinilai.
        $bisaDinilai = array_values(array_filter(
            $dihitung,
            static fn (array $t): bool => min(5, count($t['pembacaan'])) >= 2,
        ));

        if ($bisaDinilai === []) {
            $gerbang[] = 'Tidak ada titik Depth dengan minimal dua pembacaan — repeatability budget Depth '
                .'lahir dari simpangan baku terbesar titik-titiknya, dan tanpa itu komponennya nol.';
            $sdMaks = 0.0;
        } else {
            $sdMaks = max(array_map(static fn (array $t): float => (float) $t['simpangan_baku'], $bisaDinilai));

            if ($sdMaks <= 0.0) {
                $peringatan[] = 'Seluruh pembacaan titik Depth identik per titik, jadi repeatability Depth nol. '
                    .'U95 tetap dijaga lantai CMC dan komponen resolusi.';
            }
        }

        $nPembagi = (int) $k['pengulangan_pembagi_n'];

        // `C121 = MAX(C106:E120)` — keping TERBESAR (nominal cetak), bukan total.
        $kepingTerbesar = max(array_map(static fn (array $t): float => max($t['nominal']), $dihitung));
        $u95Keping = $this->tabel()->u95Keping($kepingTerbesar) ?? 0.0;

        // Drift Depth: `MAX(H106:H120)` total terkoreksi, TANPA faktor umur.
        $hMaks = max(array_map(static fn (array $t): float => (float) $t['total_nominal'], $dihitung));
        $drift = ((float) $k['drift_a_um'] + (float) $k['drift_b_um_per_mm'] * $hMaks) / 1000;

        $ciSuhu = (float) $k['depth_ci_suhu_cte_per_c'] * (float) $k['depth_ci_suhu_panjang_mm'];
        $kepingMaks = max(array_map(static fn (array $t): int => max(1, count($t['nominal'])), $dihitung));

        return [
            [
                'sumber' => 'pengulangan', 'keterangan' => 'Repeatability — STDEV terbesar titik Depth',
                'distribusi' => 't-student', 'u' => $sdMaks / sqrt($nPembagi), 'ci' => 1.0,
                'vi' => (float) ($nPembagi - 1), 'satuan' => 'mm',
            ],
            [
                'sumber' => 'resolusi_uut', 'keterangan' => 'Resolusi', 'distribusi' => 'rectangular',
                'u' => ((float) $konteks['resolusi_mm'] / 2) / $akar3, 'ci' => 1.0,
                'vi' => (float) $k['vi_resolusi'], 'satuan' => 'mm',
            ],
            [
                'sumber' => 'ketidakpastian_standar', 'keterangan' => 'Standard balok ukur', 'distribusi' => 'normal',
                'u' => $u95Keping / 2, 'ci' => 1.0, 'vi' => $vi, 'satuan' => 'mm',
            ],
            [
                'sumber' => 'suhu_ruang', 'keterangan' => 'Perubahan suhu terhadap suhu acuan 20 °C',
                'distribusi' => 'rectangular', 'u' => $theta / $akar3, 'ci' => $ciSuhu, 'vi' => $vi, 'satuan' => '°C',
            ],
            [
                'sumber' => 'selisih_suhu', 'keterangan' => 'Selisih suhu Jangka Sorong dengan balok ukur',
                'distribusi' => 'rectangular', 'u' => abs($deltaTheta) / $akar3, 'ci' => $ciSuhu, 'vi' => $vi,
                'satuan' => '°C',
            ],
            [
                'sumber' => 'koefisien_muai', 'keterangan' => 'Koefisien muai thermal', 'distribusi' => 'rectangular',
                'u' => (float) $k['u_alpha_balok_ukur_per_c'] / (float) $k['pembagi_muai'],
                'ci' => (float) $k['depth_ci_muai'], 'vi' => $vi, 'satuan' => '/°C',
            ],
            [
                'sumber' => 'drift_standar', 'keterangan' => 'Drift standard', 'distribusi' => 'rectangular',
                'u' => $drift / $akar3, 'ci' => 1.0, 'vi' => $vi, 'satuan' => 'mm/th',
            ],
            [
                'sumber' => 'wringing', 'keterangan' => 'Lapisan wringing', 'distribusi' => 'rectangular',
                'u' => sqrt($kepingMaks * (float) $k['wringing_um_per_keping'] ** 2) / 1000 / $akar3,
                'ci' => 1.0, 'vi' => $vi, 'satuan' => 'mm',
            ],
            [
                'sumber' => 'geometri', 'keterangan' => 'Kesalahan Geometri', 'distribusi' => 'rectangular',
                'u' => (float) $k['geometri_mm'] / $akar3, 'ci' => 1.0, 'vi' => $vi, 'satuan' => 'mm',
            ],
            [
                'sumber' => 'efek_mekanik', 'keterangan' => 'Efek Mekanik', 'distribusi' => 'rectangular',
                'u' => (float) $k['efek_mekanik_mm'] / $akar3, 'ci' => 1.0, 'vi' => $vi, 'satuan' => 'mm',
            ],
            [
                'sumber' => 'meja_granit', 'keterangan' => 'Meja Rata (granite)', 'distribusi' => 'normal',
                'u' => (float) $k['meja_granit_mm'] / 2, 'ci' => 1.0, 'vi' => $vi, 'satuan' => 'mm',
            ],
        ];
    }

    /**
     * Sebaran nol diuji `max !== min`, bukan `stdev > 0` — simpangan baku nilai
     * identik yang tidak representable dalam biner menyisakan ~1e-13.
     *
     * @param  list<float>  $nilai
     */
    private function punyaSebaran(array $nilai): bool
    {
        return count($nilai) >= 2 && max($nilai) !== min($nilai);
    }

    /**
     * Deret angka bersih — yang bukan angka DILEWATI, bukan dibaca nol.
     *
     * @param  array<int|string, mixed>  $nilai
     * @return list<float>
     */
    private function deretAngka(array $nilai): array
    {
        return array_values(array_map('floatval', array_filter($nilai, static fn ($x): bool => is_numeric($x))));
    }

    private function tabel(): TabelStandarJangkaSorong
    {
        // Malas — lihat [HeightGaugeCalculator::tabel].
        return $this->tabel ??= new TabelStandarJangkaSorong;
    }

    private function gum(): GumCalculator
    {
        return $this->gum ??= new GumCalculator;
    }
}
