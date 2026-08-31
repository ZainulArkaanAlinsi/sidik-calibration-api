<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung lembar **Timbangan** (alat ke-21), meniru tiga workbook master
 * ber-password yang turun dari lab 31 Agt 2026.
 *
 * Acuan metodenya ditulis di sheet `Sekilas Info` ketiga workbook:
 * **NMI Monograph 4 (CSIRO 2010)**. Dari situ lahir dua ketidakpastian yang
 * BEDA dan dua-duanya dicetak di sertifikat:
 *
 *  - **U95% of Correction** — ketidakpastian koreksi kalibrasi. Tidak
 *    memperhitungkan keterulangan pemakaian; kondisi lab terkendali.
 *  - **U95% of Weighing** — ketidakpastian seluruh proses penimbangan, yang
 *    MEMUAT U of Correction sebagai salah satu komponennya, ditambah resolusi
 *    dan eksentrisitas.
 *
 * Urutannya searah: Correction dihitung dulu per titik, hasilnya jadi bahan
 * Weighing di titik yang sama. Membaliknya tidak menghasilkan error, cuma
 * angka Weighing yang kehilangan komponen terbesarnya.
 *
 * ## Bentuk lembar ini beda dari 20 alat sebelumnya
 *
 * Dua puluh alat sebelumnya punya satu tabel: titik ukur turun ke bawah,
 * pengulangan ke kanan. Timbangan punya TUJUH blok yang tidak sebentuk —
 * Scale Observation, Effect of Tare, Accuracy, Repeatability, Loading
 * Influence (eksentrisitas), Hysterisis, dan Drift — dan yang jadi "titik
 * ukur" di sertifikat cuma blok Accuracy. Lima blok lain menyumbang ke budget
 * atau ke pernyataan terpisah (LOP), bukan ke tabel titik.
 *
 * Itu sebabnya seluruh sesi dihitung sekali lewat [hitung], bukan titik demi
 * titik: Sr, Sres, dan rentang eksentrisitas adalah besaran SESI, dan tiap
 * titik akurasi memakai angka yang sama.
 *
 * ## Yang TIDAK ditiru: sel kosong dibaca nol
 *
 * Tiap `VLOOKUP` master dibungkus `IFERROR(…,"")`. Nominal anak timbangan yang
 * tidak ada di tabel pulang KOSONG, dan kosong ikut dijumlah sebagai nol —
 * sertifikat terbit dengan massa standar yang hilang, tanpa satu pun error.
 * Di sini titik seperti itu DIBLOKIR dengan alasan yang kebaca. Pola yang sama
 * sudah dipakai ketiga alat suhu; lihat §11 daftar permintaan.
 */
class TimbanganCalculator
{
    public function __construct(private readonly GumCalculator $gum = new GumCalculator) {}

    /**
     * Hitung SATU sesi Timbangan penuh.
     *
     * @param  array{
     *     varian?: string,
     *     resolusi: float,
     *     kapasitas: float,
     *     satuan?: string,
     *     tipe_display?: string,
     *     akurasi: list<array{titik_ke: int, nominal: list<float>, mref?: float|null, lsub?: float|null, baca: array{z1: float, m: float, m_aksen: float, z2: float}}>,
     *     keterulangan?: array{mid?: array{nominal: float, zi: list<float>, mi: list<float>}, maks?: array{nominal: float, zi: list<float>, mi: list<float>}},
     *     eksentrisitas?: array{beban?: float, baca: array<string, float>},
     *     histeresis?: array{baca1: list<float>, baca2: list<float>},
     * }  $sesi
     * @return array{
     *     varian: string, satuan: string,
     *     titik: list<array<string, mixed>>,
     *     belum_dihitung: list<array{titik_ke: int, alasan: string}>,
     *     keterulangan: array<string, mixed>,
     *     eksentrisitas: array<string, mixed>,
     *     histeresis: float|null,
     *     lop: float|null,
     *     cmc: array<string, mixed>|null,
     * }
     */
    public function hitung(array $sesi): array
    {
        $varian = VarianMasterTimbangan::dari(
            $sesi['varian'] ?? VarianMasterTimbangan::bawaanUntuk(
                (float) $sesi['kapasitas'],
                $sesi['satuan'] ?? null,
            )->kode,
        );

        $resolusi = (float) $sesi['resolusi'];
        $satuan = (string) ($sesi['satuan'] ?? TabelStandarTimbangan::basis($varian->kode));
        $digital = strtolower((string) ($sesi['tipe_display'] ?? 'digital')) !== 'mekanik';

        $ekc = $this->eksentrisitas($sesi['eksentrisitas'] ?? []);
        // Pita CMC master dipilih dari kapasitas dalam KILOGRAM (`INPUT DATA!E4`),
        // sementara sesi gram menuliskan kapasitasnya dalam gram. Tanpa konversi
        // ini, timbangan 54 g jatuh ke pita F (12-60 KG) dan lantai CMC-nya
        // terbit 8 g untuk alat yang kapasitasnya 54 g.
        $pita = TabelStandarTimbangan::pitaCmc(
            $this->keKilogram((float) $sesi['kapasitas'], $satuan),
        );

        $tipeTimbangan = (string) ($sesi['tipe_timbangan'] ?? TabelStandarTimbangan::NON_ANALYTICAL);

        // DUA lintasan, dan urutannya mengikat. Budget keterulangan varian
        // substitusi memakai `Sr` blok AKURASI (bukan tabel keterulangan), jadi
        // seluruh titik akurasi harus selesai dulu sebelum [keterulangan] bisa
        // dipanggil. Menyatukannya jadi satu lintasan berarti titik pertama
        // dihitung dengan Sr yang belum ada — dan nol yang lahir dari situ
        // tidak kelihatan sebagai kegagalan.
        $siap = [];
        $belum = [];
        $kumulatif = 0.0;

        foreach ($sesi['akurasi'] as $titik) {
            $satu = $this->titikAkurasi($titik, $varian, $kumulatif, $tipeTimbangan);

            if (isset($satu['alasan'])) {
                $belum[] = ['titik_ke' => (int) $titik['titik_ke'], 'alasan' => $satu['alasan']];

                continue;
            }

            $kumulatif = $satu['kumulatif'];
            $siap[] = ['titik_ke' => (int) $titik['titik_ke'], 'hitung' => $satu];
        }

        $ket = $this->keterulangan(
            $sesi['keterulangan'] ?? [],
            $varian,
            $resolusi,
            $digital,
            array_map(static fn (array $x): array => $x['hitung'], $siap),
        );

        $hasil = [];

        foreach ($siap as $baris) {
            $titik = ['titik_ke' => $baris['titik_ke']];
            $satu = $baris['hitung'];

            $bKoreksi = $this->budgetKoreksi($satu, $ket, $varian, $resolusi);
            $aKoreksi = $this->gum->agregasiBudget($bKoreksi);

            $bTimbang = $this->budgetPenimbangan($aKoreksi, $ekc, $varian, $resolusi, $digital);
            $aTimbang = $this->gum->agregasiBudget($bTimbang);

            $cmcSatuan = $pita === null ? null : $this->cmcDalamSatuan($pita['cmc_gram'], $satuan);

            $hasil[] = [
                'titik_ke' => (int) $titik['titik_ke'],
                'titik_ukur' => $satu['total_cn'],
                'nominal' => $satu['nominal_total'],
                'rata_nol' => $satu['rata_nol'],
                'rata_beban' => $satu['rata_beban'],
                'deviasi' => $satu['deviasi'],
                'koreksi' => $satu['koreksi'],
                'koreksi_kumulatif' => $varian->substitusi ? $satu['kumulatif'] : null,
                'koreksi_absolut' => $satu['koreksi_absolut'],
                'sr' => $satu['sr'],
                'budget_koreksi' => $bKoreksi,
                'u95_koreksi_hitung' => $aKoreksi['ketidakpastian_diperluas'],
                'uc_koreksi' => $aKoreksi['ketidakpastian_gabungan'],
                'veff_koreksi' => $aKoreksi['derajat_kebebasan_efektif'],
                'k_koreksi' => $aKoreksi['faktor_cakupan_k'],
                // Master: U95% Sertifikat = MAX(U hitung, CMC). Lantai CMC MENANG
                // waktu hitungannya lebih kecil — itu memang aturan lampiran
                // akreditasi (U terbaik yang bisa diklaim lab), bukan pembulatan.
                'u95_koreksi' => $cmcSatuan === null
                    ? $aKoreksi['ketidakpastian_diperluas']
                    : max($aKoreksi['ketidakpastian_diperluas'], $cmcSatuan),
                'budget_penimbangan' => $bTimbang,
                'u95_penimbangan_hitung' => $aTimbang['ketidakpastian_diperluas'],
                'uc_penimbangan' => $aTimbang['ketidakpastian_gabungan'],
                'veff_penimbangan' => $aTimbang['derajat_kebebasan_efektif'],
                'k_penimbangan' => $aTimbang['faktor_cakupan_k'],
                'u95_penimbangan' => $cmcSatuan === null
                    ? $aTimbang['ketidakpastian_diperluas']
                    : max($aTimbang['ketidakpastian_diperluas'], $cmcSatuan),
                'dibatasi_cmc' => $cmcSatuan !== null
                    && $aKoreksi['ketidakpastian_diperluas'] < $cmcSatuan,
            ];
        }

        return [
            'varian' => $varian->kode,
            'satuan' => $satuan,
            'titik' => $hasil,
            'belum_dihitung' => $belum,
            'keterulangan' => $ket,
            'eksentrisitas' => $ekc,
            'histeresis' => $this->histeresis($sesi['histeresis'] ?? []),
            'lop' => $this->limitOfPerformance($hasil, $ket),
            'drift_massa_standar' => $this->driftMassaStandar($hasil, $satuan),
            'cmc' => $pita === null ? null : [
                ...$pita,
                'cmc_satuan' => $this->cmcDalamSatuan($pita['cmc_gram'], $satuan),
            ],
        ];
    }

    /**
     * Satu titik akurasi: rata-rata nol, rata-rata beban, deviasi, koreksi, Sr.
     *
     * Master (`PERHITUNGAN FC`, kolom N..V):
     *
     *   z̄  = AVERAGE(z1, z2)          — nol SEBELUM & SESUDAH beban
     *   m̄  = AVERAGE(m, m')           — dua pembacaan berbeban
     *   ri = m̄ − z̄
     *   C  = ΣCN − ri                 (langsung)
     *   ΔI = CN(Mref) − ri ; Cn = C(n−1) + ΔI   (substitusi)
     *   Sr = STDEV(m, m')             — simpangan baku SATU siklus, n=2
     *
     * @param  array<string, mixed>  $titik
     * @return array<string, mixed>
     */
    private function titikAkurasi(
        array $titik,
        VarianMasterTimbangan $varian,
        float $kumulatifSebelum,
        string $tipeTimbangan,
    ): array {
        // `nominal` datang dalam urutan **Mass 1..6** master, yaitu KOLOM-MAJOR:
        // kolom kiri baris 1..3 dulu, baru kolom kanan baris 1..3
        // (`Correction!B6=FC!B50, C6=FC!B51, E6=FC!B52, G6=FC!C50, …`).
        // Urutannya bukan kosmetik. `uc` sendiri kebal urutan (jumlah kuadrat),
        // jadi salah urut TIDAK ketahuan dari angka akhir varian kg — dan itu
        // justru yang bikin dia mahal. Yang geser:
        //
        //  - slot PERTAMA punya `ci` = 10 di varian substitusi, jadi keping
        //    yang mendarat di situ dikali sepuluh;
        //  - `uStandarMrefSaja` cuma menjumlahkan `u` slot pertama;
        //  - tiap baris drift di budget tercetak dengan nama slotnya, jadi
        //    jejak audit menyebut keping yang salah.
        $nominal = array_values(array_filter(
            array_map(static fn ($x): ?float => is_numeric($x) ? (float) $x : null, $titik['nominal'] ?? []),
            static fn (?float $x): bool => $x !== null,
        ));

        $cn = 0.0;
        $uGram = 0.0;
        $drift = [];
        $cnMref = null;

        foreach ($nominal as $i => $nom) {
            $isLsub = $varian->substitusi && $i > 0;

            if ($isLsub) {
                // Beban substitusi: nilai konvensionalnya = penunjukan yang
                // sudah ditetapkan langkah sebelumnya, dipakai apa adanya
                // (master: `D55 = B55`). Dia tidak punya sertifikat, jadi tidak
                // menyumbang u maupun drift.
                $cn += $nom;
                $drift[] = 0.0;

                continue;
            }

            $baris = TabelStandarTimbangan::cariMassa($nom, $varian->kode, $tipeTimbangan);

            if ($baris === null) {
                return ['alasan' => sprintf(
                    'Anak timbangan nominal %s nggak ada di tabel standar lab (kelas E2 & F1/F2/M2). '
                    .'Titik ini diblokir, bukan dihitung dengan massa standar nol — master '
                    .'membungkus VLOOKUP-nya IFERROR("") dan kosong ikut dijumlah sebagai nol, '
                    .'jadi koreksinya bakal terbit hilang tanpa error.',
                    rtrim(rtrim(number_format($nom, 6, ',', '.'), '0'), ','),
                )];
            }

            $cn += $baris['konvensional'];
            $cnMref ??= $baris['konvensional'];

            // Slot dikenali dari POSISI (`$i === 0`), bukan dari nilainya.
            // Membandingkan massa konvensional bikin dua keping bernominal sama
            // — 20 + 20 kg, kombinasi paling lumrah di lembar ini — dua-duanya
            // lolos sebagai "Mref", dan `u` standar terhitung dobel. Hari ini
            // cabang itu tidak pernah kena (varian substitusi memulangkan
            // `continue` untuk tiap slot > 0), jadi salahnya bakal diam sampai
            // ada varian keempat yang memakainya.
            if (! $varian->uStandarMrefSaja || $i === 0) {
                $uGram += $baris['u'];
            }

            $drift[] = $baris['u_drift'] ?? 0.0;
        }

        $b = $titik['baca'];
        $z = array_values(array_filter([$b['z1'] ?? null, $b['z2'] ?? null], static fn ($x) => is_numeric($x)));
        $m = array_values(array_filter([$b['m'] ?? null, $b['m_aksen'] ?? null], static fn ($x) => is_numeric($x)));

        if ($z === [] || $m === []) {
            return ['alasan' => 'Pembacaan nol atau berbeban titik ini belum lengkap.'];
        }

        $rataNol = array_sum($z) / count($z);
        $rataBeban = array_sum($m) / count($m);
        $deviasi = $rataBeban - $rataNol;

        if ($varian->substitusi) {
            $delta = ($cnMref ?? 0.0) - $deviasi;
            $kumulatif = $kumulatifSebelum + $delta;
            $koreksi = $delta;
            $absolut = abs($kumulatif);
        } else {
            $koreksi = $cn - $deviasi;
            $kumulatif = $kumulatifSebelum;
            $absolut = abs($koreksi);
        }

        return [
            'total_cn' => $cn,
            'nominal_total' => array_sum($nominal),
            'u_gram' => $uGram,
            'drift' => $drift,
            'rata_nol' => $rataNol,
            'rata_beban' => $rataBeban,
            'deviasi' => $deviasi,
            'koreksi' => $koreksi,
            'kumulatif' => $kumulatif,
            'koreksi_absolut' => $absolut,
            'sr' => $this->stdevSampel($m),
        ];
    }

    /**
     * Sembilan (atau sepuluh) komponen budget **U95% of Correction**.
     *
     * @param  array<string, mixed>  $titik
     * @param  array<string, mixed>  $ket
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function budgetKoreksi(
        array $titik,
        array $ket,
        VarianMasterTimbangan $varian,
        float $resolusi,
    ): array {
        $komponen = [[
            'sumber' => 'weight_standard',
            'keterangan' => 'Weight Standard (Calibrated Mass)',
            'distribusi' => 'normal',
            // Kolom `Uncertainty` tabel anak timbangan disimpan dalam GRAM,
            // sementara seluruh sheet bekerja dalam satuan alat. Master
            // membaginya 1000 di tiap sel (`SUM(F..G)/1000`) — ikut di sini,
            // bukan di tabelnya, supaya angkanya tetap sama dengan sertifikat
            // anak timbangan waktu diaudit.
            'u' => ($titik['u_gram'] / 1000.0) / 2.0,
            'ci' => 1.0,
            'vi' => 60.0,
        ]];

        // Enam slot drift, selalu enam — master menyediakan Mass 1..6 dan
        // menjumlahkan slot kosong sebagai nol. Slot kosong ber-u 0 tidak
        // menggeser uc maupun veff, jadi ditulis apa adanya biar barisnya
        // sejajar dengan kertasnya waktu diaudit.
        for ($i = 0; $i < 6; $i++) {
            $komponen[] = [
                'sumber' => 'mass_instability',
                'keterangan' => sprintf(
                    'Mass Instability (Drift)-Mass %s',
                    $i === 0 && $varian->substitusi ? 'Mref' : $i + 1,
                ),
                // PENYIMPANGAN MASTER yang ditiru: kolom "Divider" berbunyi Ö3
                // tapi rumusnya (`=IF(J13="","",J13)`) TIDAK membagi. Nilai di
                // `Tabel_F1drift` memang sudah berupa ketidakpastian baku, jadi
                // membaginya lagi bakal mengecilkan U — tapi label kolomnya
                // tetap salah. Dilaporkan sebagai T3.
                'distribusi' => 'rectangular',
                'u' => (float) ($titik['drift'][$i] ?? 0.0),
                'ci' => $i === 0 ? $varian->ciDriftPertama : 1.0,
                'vi' => 50.0,
            ];
        }

        foreach ($this->komponenKeterulangan($titik, $ket, $varian) as $k) {
            $komponen[] = $k;
        }

        $komponen[] = [
            'sumber' => 'rounding',
            'keterangan' => 'Rounding of Final Result',
            'distribusi' => 'rectangular',
            'u' => ($varian->uPembulatanTetap ?? $resolusi) / $varian->pembagiPembulatan,
            'ci' => 1.0,
            'vi' => 1000.0,
        ];

        return $komponen;
    }

    /**
     * Komponen keterulangan budget koreksi — TIGA wiring yang beda, satu per
     * revisi master. Ini bagian yang paling gampang disamaratakan dan paling
     * mahal kalau salah: dia komponen terbesar di dua dari tiga sesi contoh.
     *
     *  - **KG** (`SR_PITA_KETERULANGAN`) — `Sr` = STDEV blok keterulangan yang
     *    pitanya memuat titik ini (Middle kalau titik <= nominal tengah, kalau
     *    tidak Maximum). Lantainya `Sres = 0,82 a`. `U = Sr/√2` kalau Sr lebih
     *    besar (dibulatkan 4 desimal dulu — master memang membulatkan sebelum
     *    membandingkan), kalau tidak `U = Sres`. `vi` 9 atau 1000.
     *  - **GRAM** (`SR_TITIK_AKURASI`) — `Sr` = STDEV(m, m') blok AKURASI titik
     *    itu sendiri, bukan tabel keterulangan sama sekali. `vi` tetap 1000.
     *    Master memakai cabang eksplisit `IF(N8=0, N9, "cek")`: kalau
     *    `0 < Sr < Sres` dia MENOLAK menebak dan menulis "cek". Ditiru sebagai
     *    pemblokiran, bukan diam-diam memakai Sres — lihat [keteranganCek].
     *  - **SUBSTITUSI** (`SR_DUA_PITA`) — dua komponen sekaligus, MID & MAX,
     *    masing-masing `N × √5`. Silang-kabelnya dijelaskan di
     *    `VarianMasterTimbangan`.
     *
     * @param  array<string, mixed>  $titik
     * @param  array<string, mixed>  $ket
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function komponenKeterulangan(array $titik, array $ket, VarianMasterTimbangan $varian): array
    {
        $sres = (float) $ket['sres'];

        if ($varian->sumberSr === VarianMasterTimbangan::SR_DUA_PITA) {
            return [
                [
                    'sumber' => 'repeatability_mid',
                    'keterangan' => 'Repeatability MID-range',
                    'distribusi' => 't-student',
                    'u' => (float) $ket['u_mid'] * sqrt(5.0),
                    'ci' => 1.0,
                    'vi' => 1000.0,
                ],
                [
                    'sumber' => 'repeatability_max',
                    'keterangan' => 'Repeatability MAX-range',
                    'distribusi' => 't-student',
                    'u' => (float) $ket['u_maks'] * sqrt(5.0),
                    'ci' => 1.0,
                    'vi' => 9.0,
                ],
            ];
        }

        if ($varian->sumberSr === VarianMasterTimbangan::SR_TITIK_AKURASI) {
            $sr = (float) $titik['sr'];

            return [[
                'sumber' => 'repeatability',
                'keterangan' => 'Repeatability',
                'distribusi' => 't-student',
                'u' => $sr > $sres ? $sr / sqrt(2.0) : ($sr === $sres ? $sr : $sres),
                'ci' => 1.0,
                'vi' => $sr > $sres ? 9.0 : 1000.0,
            ]];
        }

        // KG: pita keterulangan.
        $sr = (float) ($titik['total_cn'] <= (float) $ket['nominal_mid'] && $ket['nominal_mid'] > 0.0
            ? $ket['stdev_mid']
            : $ket['stdev_maks']);

        return [[
            'sumber' => 'repeatability',
            'keterangan' => 'Repeatability',
            'distribusi' => 't-student',
            'u' => round($sr, 4) > round($sres, 4) ? $sr / sqrt(2.0) : $sres,
            'ci' => 1.0,
            'vi' => $sr > $sres ? 9.0 : 1000.0,
        ]];
    }

    /**
     * Tiga komponen budget **U95% of Weighing**: resolusi, eksentrisitas, dan
     * U of Correction titik yang sama.
     *
     * @param  array{ketidakpastian_diperluas: float, faktor_cakupan_k: float}  $koreksi
     * @param  array<string, mixed>  $ekc
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function budgetPenimbangan(
        array $koreksi,
        array $ekc,
        VarianMasterTimbangan $varian,
        float $resolusi,
        bool $digital,
    ): array {
        // `Tabel_Resolusi` master: digital → resolusi/2 dibagi √3;
        // mekanik → resolusi/10 dibagi √6.
        $uRes = $digital ? $resolusi / 2.0 : $resolusi / 10.0;
        $pembagiRes = $digital ? sqrt(3.0) : sqrt(6.0);

        $rentang = (float) ($ekc['rentang'] ?? 0.0);
        $uEkc = $varian->eksentrisitasDibagiDua ? $rentang / 2.0 : $rentang;

        $uKoreksi = match ($varian->turunanUKoreksi) {
            VarianMasterTimbangan::U_KOREKSI_BAGI_K => $koreksi['ketidakpastian_diperluas']
                / max($koreksi['faktor_cakupan_k'], 1e-9),
            VarianMasterTimbangan::U_KOREKSI_BAGI_AKAR3 => $koreksi['ketidakpastian_diperluas'] / sqrt(3.0),
            default => $koreksi['ketidakpastian_diperluas'],
        };

        return [
            [
                'sumber' => 'resolution',
                'keterangan' => 'Resolution',
                'distribusi' => 'rectangular',
                'u' => $uRes / $pembagiRes,
                'ci' => 1.0,
                'vi' => 50.0,
            ],
            [
                'sumber' => 'eccentricity',
                'keterangan' => 'Eccentricity',
                'distribusi' => 'rectangular',
                'u' => $uEkc / sqrt(3.0),
                'ci' => 1.0,
                'vi' => 50.0,
            ],
            [
                'sumber' => 'uncertainty_of_correction',
                'keterangan' => 'Uncertainty of Correction',
                'distribusi' => 'rectangular',
                'u' => $uKoreksi,
                'ci' => 1.0,
                'vi' => 50.0,
            ],
        ];
    }

    /**
     * Blok Repeatability: STDEV dua kapasitas, Sres (lantai "timbangan kasar"),
     * dan U keterulangan yang masuk budget.
     *
     * `Sres = 0,82 × a`, dengan `a` = resolusi/2 (digital) atau resolusi/10
     * (mekanik). Master memakainya sebagai LANTAI: timbangan yang sepuluh
     * pembacaannya identik ber-STDEV nol, dan nol di budget berarti mengaku
     * tidak punya sebaran sama sekali.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function keterulangan(
        array $data,
        VarianMasterTimbangan $varian,
        float $resolusi,
        bool $digital,
        array $titikAkurasi = [],
    ): array {
        $a = $digital ? $resolusi / 2.0 : $resolusi / 10.0;
        $sres = 0.82 * $a;

        $olah = function (?array $blok) use ($varian): array {
            if ($blok === null || ($blok['mi'] ?? []) === []) {
                return ['stdev' => 0.0, 'maks_beda' => 0.0, 'n' => 0, 'deviasi' => []];
            }

            $zi = array_map('floatval', $blok['zi'] ?? []);
            $mi = array_map('floatval', $blok['mi'] ?? []);
            $nom = $varian->deviasiKurangiNominal ? (float) ($blok['nominal'] ?? 0.0) : 0.0;

            $dev = [];
            foreach ($mi as $i => $m) {
                $dev[] = $m - ($zi[$i] ?? 0.0) - $nom;
            }

            $beda = [];
            for ($i = 0; $i < count($dev) - 1; $i++) {
                $beda[] = abs($dev[$i] - $dev[$i + 1]);
            }

            return [
                // kg ber-STDEV atas kolom DEVIASI, gram & substitusi atas
                // kolom PEMBACAAN MENTAH. Sama hasilnya selama kolom nol-nya
                // konstan; beda begitu teknisi mencatat nol yang bergerak.
                'stdev' => $this->stdevSampel($varian->stdevAtasPembacaan ? $mi : $dev),
                'maks_beda' => $beda === [] ? 0.0 : max($beda),
                'n' => count($mi),
                'deviasi' => $dev,
            ];
        };

        $mid = $olah($data['mid'] ?? null);
        $maks = $olah($data['maks'] ?? null);
        $nominalMid = (float) ($data['mid']['nominal'] ?? 0.0);
        $nominalMaks = (float) ($data['maks']['nominal'] ?? 0.0);

        // Lantai Sres cuma berlaku buat timbangan yang resolusinya kasar —
        // master menjaganya dengan `resolusi > 0,001`. Timbangan analitik
        // ber-STDEV nol memang ber-STDEV nol.
        $lantai = static fn (float $s): float => ($s === 0.0 && $resolusi > 0.001) ? $sres : $s;

        // STDEV tabel keterulangan apa adanya (sesudah lantai "timbangan
        // kasar"). Ini yang dipakai pemilihan pita varian kg DAN `Maximun
        // STDEV` di rumus LOP.
        $stdevMid = $lantai($mid['stdev']);
        $stdevMaks = $lantai($maks['stdev']);

        // LANTAI budget dua-komponen dipisah dari STDEV di atas, dan itu bukan
        // kerapian: master substitusi membaca lantai MID dari kolom STDEV
        // **Maximum** (`FC!H116`) dan lantai MAX dari kolom ketiga yang di
        // workbook itu tidak ada — jadi yang MAX selalu jatuh ke `0,82 a`.
        // Ditiru (lihat catatan silang-kabel di VarianMasterTimbangan), TAPI
        // cuma buat budget.
        //
        // Disatukan, `Maximun STDEV` ikut tercemar dan LOP substitusi meleset
        // 2,26 × (0,041 − 0,0316) = 0,0212 kg. Sempat begitu, dan yang
        // menangkapnya bukan test budget — LOP-nya sendiri yang harus diadu.
        if ($varian->sumberSr === VarianMasterTimbangan::SR_DUA_PITA) {
            $sresMid = $stdevMaks;
            $sresMaks = $sres;
        } else {
            $sresMid = $stdevMid;
            $sresMaks = $stdevMaks;
        }

        $stdevTerbesar = max($stdevMid, $stdevMaks);

        // `U Mid` / `U Max` master: Sr dibanding Sres, dan yang lebih besar
        // dibagi √2. Sr di sini simpangan baku SATU siklus akurasi (n = 2),
        // bukan STDEV sepuluh pengulangan — itu sebabnya pembaginya √2.
        $uDari = static fn (float $sr, float $sres): float => $sr > $sres
            ? $sr / sqrt(2.0)
            : ($sr === $sres ? $sr : $sres);

        // Sr yang diadu ke lantai itu Sr blok AKURASI terdekat kapasitas
        // tengah & maksimum — bukan STDEV tabel keterulangan. Master menunjuk
        // selnya satu per satu (`FC!V70`, `FC!V86`); yang ditiru NIATNYA.
        $srMid = $this->srTerdekat($titikAkurasi, $nominalMid);
        $srMaks = $this->srTerdekat($titikAkurasi, $nominalMaks);

        return [
            'sres' => $sres,
            'a' => $a,
            'mid' => $mid,
            'maks' => $maks,
            'nominal_mid' => $nominalMid,
            'nominal_maks' => $nominalMaks,
            'stdev_mid' => $stdevMid,
            'stdev_maks' => $stdevMaks,
            // Yang dipakai rumus LOP (`Maximun STDEV`), BUKAN lantai budget.
            'stdev_terbesar' => $stdevTerbesar,
            'sres_mid' => $sresMid,
            'sres_maks' => $sresMaks,
            'u_mid' => $uDari($srMid ?? $mid['stdev'], $sresMid),
            'u_maks' => $uDari($srMaks ?? $maks['stdev'], $sresMaks),
            'sr_mid' => $srMid,
            'sr_maks' => $srMaks,
            // Varian satu-komponen: Sr sesi diadu ke Sres, dibulatkan 4 desimal
            // dulu (master kg) sebelum dibandingkan.
            'u_gabung' => round($stdevTerbesar, 4) > round($sres, 4) ? $stdevTerbesar / sqrt(2.0) : $sres,
            'vi' => $stdevTerbesar > $sres ? 9.0 : 1000.0,
        ];
    }

    /**
     * `Sr` blok akurasi yang titik ukurnya paling dekat ke sebuah kapasitas.
     *
     * @param  list<array<string, mixed>>  $titik
     */
    private function srTerdekat(array $titik, float $kapasitas): ?float
    {
        if ($titik === [] || $kapasitas <= 0.0) {
            return null;
        }

        $pilih = null;
        $jarak = null;

        foreach ($titik as $t) {
            $d = abs((float) $t['total_cn'] - $kapasitas);

            if ($jarak === null || $d < $jarak) {
                $jarak = $d;
                $pilih = (float) $t['sr'];
            }
        }

        return $pilih;
    }

    /**
     * Blok Loading Influence: selisih tiap posisi terhadap CENTER, lalu
     * rentangnya (maks − min). Yang masuk budget rentangnya, bukan selisih
     * terbesar — dua besaran yang gampang tertukar dan bedanya bisa 2×.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function eksentrisitas(array $data): array
    {
        $baca = $data['baca'] ?? [];

        if ($baca === []) {
            return ['rentang' => 0.0, 'selisih' => [], 'min' => 0.0, 'maks' => 0.0];
        }

        $pusat = (float) ($baca['center'] ?? reset($baca));
        $selisih = [];

        foreach ($baca as $posisi => $nilai) {
            $selisih[$posisi] = $pusat - (float) $nilai;
        }

        $min = min($selisih);
        $maks = max($selisih);

        return [
            'beban' => isset($data['beban']) ? (float) $data['beban'] : null,
            'selisih' => $selisih,
            'min' => $min,
            'maks' => $maks,
            'rentang' => $maks - $min,
        ];
    }

    /**
     * Histeresis master: `((p1 + p2 + p3 + p4) − (q1 + q2 + q3 + q4)) / 4`,
     * dengan p = pembacaan naik dan q = pembacaan turun pada beban yang sama.
     *
     * @param  array{baca1?: list<float>, baca2?: list<float>}  $data
     */
    private function histeresis(array $data): ?float
    {
        $b1 = array_map('floatval', $data['baca1'] ?? []);
        $b2 = array_map('floatval', $data['baca2'] ?? []);

        // Tiap deret: [M(p1), M+M', M(q1), Zero, M+M', M(q2), Zero, M(p2)]
        if (count($b1) < 8 || count($b2) < 8) {
            return null;
        }

        $naik = $b1[0] + $b1[7] + $b2[0] + $b2[7];
        $turun = $b1[2] + $b1[5] + $b2[2] + $b2[5];

        return ($naik - $turun) / 4.0;
    }

    /**
     * `Drift Massa Standar (d)` — kotak **7. DRIFT** di kertas.
     *
     * Master menghitungnya bertingkat, dan tiap tingkat punya jebakannya:
     *
     * ```
     *   E94 = MAX( Σ u tiap blok titik ) / 2      -> gram
     *   E95 = E94 / 1000                          -> kg
     *   d   = 0,1 × E95
     * ```
     *
     * Dua hal yang gampang salah kalau ditulis ulang dari ingatan:
     *
     *  1. Yang diambil MAKSIMUM antar titik, bukan totalnya.
     *  2. Pembaginya 2, lalu dikonversi ke kg — dan kertas ini menulis
     *     satuannya kg bahkan untuk timbangan gram.
     *
     * ## Kenapa `u_gram` yang dipakai, dan kapan itu berhenti benar
     *
     * Master menjumlahkan `u` SELURUH baris blok titik (`SUM(F50:G53)`),
     * sementara `u_gram` menuruti `uStandarMrefSaja` — di varian substitusi
     * cuma slot Mref yang dihitung. Hari ini keduanya sama persis, dan itu
     * bukan kebetulan: beban substitusi (`Lsub`) tidak punya sertifikat, jadi
     * baris-baris itu memang menyumbang nol di master maupun di sini.
     *
     * Sempat dibuat penjumlah kedua (`u_gram_semua`) untuk membedakannya, lalu
     * DIBUANG setelah testnya sendiri membuktikan dia selalu sama dengan
     * `u_gram`. Yang perlu diingat kalau suatu saat `Lsub` mulai bersertifikat:
     * angka ini yang duluan melenceng, dan dia tidak masuk budget mana pun —
     * jadi tidak ada satu pun test budget yang bakal merah.
     *
     * Cuma formulir metode SUBSTITUSI yang punya kotaknya; dua master lain
     * tidak mencetak bagian ini. Tetap dihitung untuk semua varian karena
     * bahannya sama dan nilainya tidak dipakai perhitungan lain — yang
     * membedakan cuma dicetak atau tidak.
     *
     * @param  list<array<string, mixed>>  $titik
     */
    private function driftMassaStandar(array $titik, string $satuan): ?float
    {
        $perTitik = [];

        foreach ($titik as $t) {
            if (! isset($t['u_gram'])) {
                continue;
            }

            $perTitik[] = (float) $t['u_gram'];
        }

        if ($perTitik === []) {
            return null;
        }

        // `/ 1000` sekali saja: `u` di tabel anak timbangan memang bersatuan
        // GRAM untuk semua varian, termasuk yang alatnya bersatuan kg.
        return 0.1 * (max($perTitik) / 2.0) / 1000.0;
    }

    /**
     * Limit of Performance: `F = ±(2,26 × STDEV max + |C max| + U(C max))`.
     *
     * 2,26 itu t-student 95% untuk 9 derajat kebebasan (n = 10 pengulangan) —
     * dituliskan tetap di master, bukan dihitung, jadi ditulis tetap di sini
     * juga supaya angkanya sama dengan kertasnya.
     *
     * ## `U(C max)` itu U HITUNGAN, bukan U95 yang terbit
     *
     * Master melihatnya lewat `VLOOKUP(Cmax, Tabel_U_Correction, 3)`, dan kolom
     * ketiga tabel itu (`V50 = 'PERHITUNGAN U95% - Correction'!R25`) berisi
     * **Stretch Uncertainty `k · uc`** — BUKAN baris `U95% Sertifikat` dua baris
     * di bawahnya yang sudah dilantai CMC.
     *
     * Bedanya nyata, bukan desimal terakhir: di sesi kg lantai CMC 0,033 kg
     * menang atas hitungan 0,0240 kg, jadi memakai angka yang terbit bikin LOP
     * 0,0885 kg alih-alih 0,0795 kg — **11% terlalu besar**. Sempat salah di
     * sini, dan yang menangkapnya bukan test parity budget (LOP tidak diadu di
     * situ) melainkan membaca ulang rumus masternya sel demi sel. Sekarang
     * diadu juga — lihat `TimbanganMasterTest::test_lop_cocok_master()`.
     *
     * `STDEV max` juga bukan `Uncertainty of Repeatability` yang sebaris di
     * bawahnya: master menunjuk `H109`, baris **Maximun STDEV**, bukan `H110`
     * yang sudah dibagi √n.
     *
     * @param  list<array<string, mixed>>  $titik
     * @param  array<string, mixed>  $ket
     */
    private function limitOfPerformance(array $titik, array $ket): ?float
    {
        if ($titik === []) {
            return null;
        }

        $terbesar = null;

        foreach ($titik as $t) {
            if ($terbesar === null || $t['koreksi_absolut'] > $terbesar['koreksi_absolut']) {
                $terbesar = $t;
            }
        }

        if ($terbesar === null) {
            return null;
        }

        return 2.26 * (float) $ket['stdev_terbesar']
            + (float) $terbesar['koreksi_absolut']
            + (float) $terbesar['u95_koreksi_hitung'];
    }

    /** CMC master disimpan dalam GRAM; sertifikat mencetaknya dalam satuan alat. */
    private function cmcDalamSatuan(float $cmcGram, string $satuan): float
    {
        return $this->gram($satuan) ? $cmcGram : $cmcGram / 1000.0;
    }

    private function keKilogram(float $nilai, string $satuan): float
    {
        return $this->gram($satuan) ? $nilai / 1000.0 : $nilai;
    }

    private function gram(string $satuan): bool
    {
        return strtolower(trim($satuan)) === 'g';
    }

    /** @param  list<float>  $nilai */
    private function stdevSampel(array $nilai): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        $rata = array_sum($nilai) / $n;
        $jumlah = 0.0;

        foreach ($nilai as $x) {
            $jumlah += ($x - $rata) ** 2;
        }

        return sqrt($jumlah / ($n - 1));
    }
}
