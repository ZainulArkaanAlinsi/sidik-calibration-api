<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;

/**
 * Mesin hitung kelompok **Putaran** — dipakai bersama oleh Infrared Tachometer
 * dan Centrifuge.
 *
 * Satu mesin untuk dua alat karena kedua workbook master (`Master Olda
 * Tachometer.xlsm`, `Master Olda Centrifuge.xlsm`) memuat sheet `PERHITUNGAN`
 * yang identik baris demi baris — sampai ke data contohnya. Yang membedakan
 * kedua alat cuma tabel CMC-nya, dan itu datang dari lampiran akreditasi lewat
 * `CalibrationCapability`, bukan dari sini.
 *
 * ## Bentuk satu titik
 *
 * Set point = penunjukan ALAT PELANGGAN (putaran yang disetel di centrifuge,
 * atau nominal yang dibaca tachometer-nya). Pembacaan = lima kali baca
 * TACHOMETER STANDAR pada putaran yang sama. Jadi:
 *
 *     nilai benar = rata-rata pembacaan standar + koreksi sertifikat standar
 *     koreksi     = nilai benar − set point          (yang dicetak sertifikat)
 *     error       = −koreksi
 *
 * ## Kenapa budget lahir per BLOK TIGA TITIK
 *
 * Sheet `PERHITUNGAN` menaruh tiga titik per baris (kolom G, I, K), dan sheet
 * `PERHITUNGAN U95%` membuat SATU budget per baris itu — memakai simpangan baku
 * TERBESAR di antara ketiganya, dan pita ketidakpastian sertifikat milik
 * `Indexed Value` TERTINGGI di antara ketiganya. Satu U95 lalu tercetak sama
 * untuk ketiga titik.
 *
 * Itu tidak bisa dinyatakan lewat `komponenBudget()` yang per titik, makanya
 * profilnya lewat `hitungPerGrup()`. Blok disusun dari tiga `titik_ke` berurutan
 * — geometri lembarnya, bukan selera.
 *
 * ## Penyimpangan master yang TIDAK ditiru
 *
 * Tiga hal dihitung benar di sini dan berbeda dari master. Ketiganya membuat
 * ketidakpastian kita LEBIH BESAR, tidak pernah lebih kecil, dan ketiganya
 * ditegakkan arahnya oleh test:
 *
 *  1. **Drift standar.** `K35` master = `MAX(K13:K34)/2`, tapi kolom `K` cuma
 *     berrumus di 5 dari 15 baris berdata. Melengkapinya menaikkan
 *     setengah-lebar drift 2,25 → 2,75 rpm. Lihat
 *     [TabelStandarPutaran::driftSetengahLebar].
 *  2. **Pengulangan blok 1 Centrifuge.** Master memakai `PERHITUNGAN!G34`
 *     (satu sel) sementara sepuluh blok lain memakai `MAX(...)` sebaris penuh.
 *     Workbook Tachometer memakai `MAX(G34:L34)` di blok yang sama persis —
 *     jadi bentuk yang benar terbukti dari master itu sendiri, bukan dari
 *     penalaran kita.
 *  3. **Blok 5 Tachometer.** Rusak di tiga tempat sekaligus: pita sertifikat
 *     menunjuk `F15` (1,6) padahal titik tertingginya 15000 rpm yang bernaung
 *     di pita `F18` (3,1); `u_drift` menunjuk `'[1]Drift Std Kalibrator'!K54`
 *     — sel KOSONG di workbook LAIN, ter-cache sebagai 0; dan pengulangannya
 *     menunjuk baris 113 yang kosong, juga 0. Ketiganya dihitung benar di sini.
 *
 * Selebihnya ditiru persis: sembilan blok sisanya cocok sampai 5·10⁻⁶, dan
 * seluruh 33 nilai turunan per titik cocok tanpa kecuali.
 */
class PutaranCalculator
{
    /** Jumlah titik per blok budget — tiga kolom ukur per baris lembar master. */
    public const TITIK_PER_BLOK = 3;

    /**
     * Ambang resolusi tachometer standar. Sampai 10000 rpm sertifikatnya
     * mencatat satu desimal (0,1 rpm); di atasnya bilangan bulat (1 rpm).
     * Dibaca balik dari blok budget master: blok ber-nominal tertinggi 10000
     * memakai 0,1 dan yang 15000 memakai 1.
     */
    private const AMBANG_RESOLUSI_STANDAR = 10_000.0;

    public function __construct(
        private readonly TabelStandarPutaran $tabel = new TabelStandarPutaran,
        private ?GumCalculator $gum = null,
    ) {}

    /**
     * Daya baca TACHOMETER STANDAR di sekitar `$setPoint` — 0,1 rpm sampai
     * 10000 rpm, 1 rpm di atasnya.
     *
     * Publik karena bukan cuma budget yang butuh: yang tercatat di
     * `raw_measurements` kelompok ini adalah pembacaan STANDARNYA, jadi
     * pemeriksa "bukan kelipatan resolusi" dan lembar perhitungan harus memakai
     * penggaris yang sama. Satu tempat yang memutuskan, supaya ambangnya tidak
     * bisa menyimpang antara budget dan pemeriksa.
     */
    public function resolusiStandar(float $setPoint): float
    {
        $nominal = $this->tabel->nominalTerdekat($setPoint) ?? $setPoint;

        return $nominal > self::AMBANG_RESOLUSI_STANDAR ? 1.0 : 0.1;
    }

    /**
     * Hitung satu blok (sampai tiga titik) sekaligus.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>}>  $titik
     * @param  array{resolusi_uut: float, cmc: float|null, satuan: string, alat?: string|null, blok_ke?: int|null}  $konteks  `alat` (`centrifuge`/`tachometer`) dan `blok_ke` (1-based, urutan blok tiga titik di lembar master) HANYA menentukan catatan audit; angka tidak bergantung pada keduanya.
     * @return array{titik: list<array<string, mixed>>, budget: list<array<string, mixed>>, ketidakpastian_gabungan: float, derajat_kebebasan_efektif: float|null, faktor_cakupan_k: float, ketidakpastian_diperluas: float, u95_sertifikat: float, type_b: float, ditolak: list<array{titik_ke: int, alasan: string}>, catatan_audit: list<array<string, mixed>>}
     */
    public function hitungBlok(array $titik, array $konteks): array
    {
        $dihitung = [];
        $ditolak = [];

        foreach ($titik as $t) {
            $setPoint = (float) $t['titik_ukur'];
            $pembacaan = array_values(array_filter(
                $t['pembacaan'],
                static fn ($v): bool => is_numeric($v),
            ));
            $pembacaan = array_map(static fn ($v): float => (float) $v, $pembacaan);

            // Titik hantu: master melahirkan `CORRECTION` bukan-nol dari baris
            // yang seluruh pembacaannya kosong (dibaca nol), dan angka itu
            // tercetak seperti titik sungguhan. Diblokir dengan alasan yang
            // kebaca, bukan ditiru.
            if ($setPoint <= 0.0 || count($pembacaan) < 2) {
                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Titik %d belum bisa dihitung: set point %s dengan %d pembacaan. '
                        .'Butuh set point > 0 dan minimal 2 pembacaan.',
                        $t['titik_ke'], $setPoint, count($pembacaan),
                    ),
                ];

                continue;
            }

            $nominal = $this->tabel->nominalTerdekat($setPoint);
            $koreksiStd = $nominal === null ? null : $this->tabel->koreksi($nominal);

            if ($koreksiStd === null) {
                // Ditampung dulu: `end()` menerima REFERENSI, jadi
                // `end($this->tabel->sertifikat())` melempar "Only variables
                // should be passed by reference". Cabang ini dulu nggak pernah
                // punya jalan masuk (lihat `TabelStandarPutaran::nominalTerdekat`),
                // jadi kesalahannya ikut nggak pernah kelihatan.
                $pita = $this->tabel->sertifikat();

                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Set point %s rpm ada di luar jangkauan sertifikat kalibrator '
                        .'(%s–%s rpm) — koreksi standarnya nggak ada, jadi titik ini nggak '
                        .'dihitung. Kalibrasi di luar jangkauan itu keputusan manajer teknis, '
                        .'bukan angka yang boleh ditebak dari baris terdekat.',
                        $setPoint,
                        $pita[0]['nominal'] ?? '-',
                        end($pita)['nominal'] ?? '-',
                    ),
                ];

                continue;
            }

            $n = count($pembacaan);
            $rata = array_sum($pembacaan) / $n;
            $sd = $this->standarDeviasi($pembacaan, $rata);
            $terkoreksi = $rata + $koreksiStd;
            $koreksi = $terkoreksi - $setPoint;

            $dihitung[] = [
                'titik_ke' => (int) $t['titik_ke'],
                'titik_ukur' => $setPoint,
                'nominal_standar' => $nominal,
                'koreksi_standar' => $koreksiStd,
                'rata_rata' => $rata,
                'nilai_terkoreksi' => $terkoreksi,
                'koreksi' => $koreksi,
                'error' => -$koreksi,
                'standar_deviasi' => $sd,
                'jumlah_pengulangan' => $n,
                'type_a' => $sd / sqrt($n),
            ];
        }

        if ($dihitung === []) {
            return [
                'titik' => [], 'budget' => [], 'ketidakpastian_gabungan' => 0.0,
                'derajat_kebebasan_efektif' => null, 'faktor_cakupan_k' => 2.0,
                'ketidakpastian_diperluas' => 0.0, 'u95_sertifikat' => 0.0,
                'type_b' => 0.0, 'ditolak' => $ditolak, 'catatan_audit' => [],
            ];
        }

        $budget = $this->budget($dihitung, $konteks, $ditolak);

        if ($budget === []) {
            return [
                'titik' => [], 'budget' => [], 'ketidakpastian_gabungan' => 0.0,
                'derajat_kebebasan_efektif' => null, 'faktor_cakupan_k' => 2.0,
                'ketidakpastian_diperluas' => 0.0, 'u95_sertifikat' => 0.0,
                'type_b' => 0.0, 'ditolak' => $ditolak, 'catatan_audit' => [],
            ];
        }

        $agregat = ($this->gum ??= new GumCalculator)->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $budget,
        ));

        // Lantai CMC: master menutup tiap blok dengan `MAX(U; CMC)`. U95 yang
        // terbit tidak boleh lebih kecil dari kemampuan terbaik yang diakui
        // lampiran akreditasi.
        $u95 = max($agregat['ketidakpastian_diperluas'], (float) ($konteks['cmc'] ?? 0.0));

        // RSS komponen Type B saja — aturannya disamakan dengan
        // `GumCalculator::hitungDariBudget()` biar kolom `type_b` nggak beda
        // arti antar-alat.
        $typeB = sqrt(array_sum(array_map(
            static fn (array $k): float => ($k['u'] * $k['ci']) ** 2,
            array_filter($budget, static fn (array $k): bool => $k['distribusi'] !== 't-student'),
        )));

        return [
            'titik' => $dihitung,
            'budget' => $budget,
            'ketidakpastian_gabungan' => $agregat['ketidakpastian_gabungan'],
            'derajat_kebebasan_efektif' => $agregat['derajat_kebebasan_efektif'],
            'faktor_cakupan_k' => $agregat['faktor_cakupan_k'],
            'ketidakpastian_diperluas' => $agregat['ketidakpastian_diperluas'],
            'u95_sertifikat' => $u95,
            'type_b' => $typeB,
            'ditolak' => $ditolak,
            // Catatan tambahan SAJA — tidak ada angka di atas yang bergantung padanya.
            'catatan_audit' => $this->catatanPenyimpangan($dihitung, $budget, $agregat, $konteks),
        ];
    }

    /**
     * Lima komponen budget satu blok, urutannya sama dengan sheet `PERHITUNGAN
     * U95%` master.
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  array{resolusi_uut: float, cmc: float|null, satuan: string}  $konteks
     * @param  list<array{titik_ke: int, alasan: string}>  $ditolak
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>
     */
    private function budget(array $dihitung, array $konteks, array &$ditolak): array
    {
        $nominalTertinggi = max(array_map(
            static fn (array $t): float => (float) $t['nominal_standar'],
            $dihitung,
        ));

        $uSertifikat = $this->tabel->u95Sertifikat($nominalTertinggi);

        if ($uSertifikat === null) {
            foreach ($dihitung as $t) {
                $ditolak[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => sprintf(
                        'Nominal %s rpm ada DI BAWAH pita ketidakpastian pertama sertifikat '
                        .'kalibrator (60 rpm) — U95 blok ini nggak punya sumber, jadi nggak dihitung.',
                        $nominalTertinggi,
                    ),
                ];
            }

            return [];
        }

        $sqrt3 = sqrt(3);
        // Lewat [resolusiStandar] supaya ambangnya satu tempat — `nominal_standar`
        // memang sudah nominal sertifikat, jadi pemetaan ulangnya no-op di sini.
        $resolusiStandar = $this->resolusiStandar($nominalTertinggi);
        $resolusiUut = (float) $konteks['resolusi_uut'];
        $satuan = $konteks['satuan'];

        // Pengulangan: simpangan baku TERBESAR di blok, dibagi √n titik itu.
        // Dipilih lewat `type_a` (= s/√n), bukan lewat `s` mentah, supaya blok
        // yang titiknya beda jumlah pengulangan tetap punya arti. `n` yang
        // dipakai buat `vi` ikut titik yang menang — dipungut dalam satu
        // lintasan, bukan dicari ulang lewat pembandingan float.
        $typeA = 0.0;
        $nPengulangan = 2;

        foreach ($dihitung as $t) {
            if ((float) $t['type_a'] >= $typeA) {
                $typeA = (float) $t['type_a'];
                $nPengulangan = (int) $t['jumlah_pengulangan'];
            }
        }

        return [
            [
                'sumber' => 'ketidakpastian_standar',
                'keterangan' => sprintf(
                    'Sertifikat kalibrator %s (U95=%s %s pada pita %s %s, k=2)',
                    $this->tabel->standar()['nama'], $uSertifikat, $satuan, $nominalTertinggi, $satuan,
                ),
                'distribusi' => 'normal',
                'u' => $uSertifikat / 2.0,
                'ci' => 1.0,
                'vi' => 60.0,
            ],
            [
                'sumber' => 'resolusi_standar',
                'keterangan' => sprintf('Daya baca tachometer standar %s %s', $resolusiStandar, $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusiStandar / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'resolusi_alat',
                'keterangan' => sprintf('Daya baca alat yang dikalibrasi %s %s', $resolusiUut, $satuan),
                'distribusi' => 'persegi',
                'u' => ($resolusiUut / 2.0) / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'drift_standar',
                'keterangan' => sprintf(
                    'Drift tachometer standar (setengah-lebar %s %s dari %d sertifikat)',
                    $this->tabel->driftSetengahLebar(), $satuan, $this->tabel->jumlahSnapshotDrift(),
                ),
                'distribusi' => 'persegi',
                'u' => $this->tabel->driftSetengahLebar() / $sqrt3,
                'ci' => 1.0,
                'vi' => 1_000_000.0,
            ],
            [
                'sumber' => 'pengulangan_pembacaan',
                'keterangan' => sprintf('Pengulangan %d pembacaan, simpangan baku terbesar di blok', $nPengulangan),
                'distribusi' => 't-student',
                'u' => $typeA,
                'ci' => 1.0,
                'vi' => (float) max($nPengulangan - 1, 1),
            ],
        ];
    }

    /**
     * Pita sertifikat kalibrator yang dirujuk blok 5 Tachometer di master
     * (`'SERTIFIKAT KALIBRATOR'!F15`) — pita 5000 rpm, 1,6 rpm — padahal
     * titik tertingginya bernaung di pita `F18` (15000 rpm, 3,1 rpm).
     */
    private const NOMINAL_PITA_RUJUKAN_MASTER_BLOK5 = 5000.0;

    /** Daya baca standar yang dipakai budget master di blok 5 Tachometer (fixture `waktu-frekuensi-master.json`). */
    private const RESOLUSI_STANDAR_MASTER_BLOK5 = 0.1;

    /**
     * Catatan audit penyimpangan SADAR dari master (AGENTS.md "Olah data"
     * butir 4): tiap catatan menyebut sel master, nilai master vs nilai
     * aplikasi, selisihnya, dan dasarnya — supaya orang yang membuka jejak
     * sesi tahu angka ini berbeda dari master tanpa membuka kode.
     *
     * Hanya MENCATAT. Tidak ada nilai di `$budget`/`$agregat` yang diubah; versi
     * master dihitung di salinan terpisah, lewat agregasi yang sama
     * (`GumCalculator::agregasiBudget`).
     *
     * Kunci baku seperti `pengulangan_standar_dibagi_n`
     * (ThermometerGlassCalculator): `kode`/`pesan`, plus angka terstruktur untuk
     * yang membaca JSON-nya.
     *
     * @param  list<array<string, mixed>>  $dihitung
     * @param  list<array<string, mixed>>  $budget
     * @param  array{ketidakpastian_diperluas: float}  $agregat
     * @param  array<string, mixed>  $konteks
     * @return list<array<string, mixed>>
     */
    private function catatanPenyimpangan(array $dihitung, array $budget, array $agregat, array $konteks): array
    {
        $alat = $konteks['alat'] ?? null;
        $blokKe = (int) ($konteks['blok_ke'] ?? 0);
        $satuan = (string) $konteks['satuan'];
        $cmc = (float) ($konteks['cmc'] ?? 0.0);
        $sqrt3 = sqrt(3);

        $posisi = [];
        foreach ($budget as $i => $k) {
            $posisi[$k['sumber']] = $i;
        }

        $uApp = static fn (string $sumber): float => (float) $budget[$posisi[$sumber]]['u'];
        $viApp = static fn (string $sumber): float => (float) $budget[$posisi[$sumber]]['vi'];

        /** @var list<array<string, mixed>> $beda */
        $beda = [];

        if ($alat === 'tachometer' && $blokKe === 5) {
            // Blok 5 Tachometer: tiga kerusakan sekaligus (docs/pertanyaan-lab-waktu-frekuensi.md §2).
            $nominalTertinggi = max(array_map(static fn (array $t): float => (float) $t['nominal_standar'], $dihitung));
            $pitaMaster = $this->tabel->u95Sertifikat(self::NOMINAL_PITA_RUJUKAN_MASTER_BLOK5);
            $pitaApp = $this->tabel->u95Sertifikat($nominalTertinggi);

            if ($pitaMaster !== null) {
                $beda[] = [
                    'kode' => 'putaran_pita_sertifikat_blok5_f15',
                    'sel' => "PERHITUNGAN U95% blok 5 (baris 98-109) -> 'SERTIFIKAT KALIBRATOR'!F15",
                    'sumber' => 'ketidakpastian_standar',
                    'u_master' => $pitaMaster / 2.0,
                    'vi_master' => $viApp('ketidakpastian_standar'),
                    'uraian' => sprintf(
                        'Pita U95 sertifikat kalibrator: master %s %s (F15, pita %s %s); aplikasi %s %s (F18, pita nominal tertinggi blok %s %s).',
                        self::angka($pitaMaster), $satuan, self::angka(self::NOMINAL_PITA_RUJUKAN_MASTER_BLOK5), $satuan,
                        self::angka((float) $pitaApp), $satuan, self::angka($nominalTertinggi), $satuan,
                    ),
                    'dasar' => 'Titik tertinggi blok ini bernaung di pita F18; lima blok tetangga memakai pita nominal tertingginya sendiri (docs/pertanyaan-lab-waktu-frekuensi.md §2).',
                ];
            }

            // Temuan audit 11 Nov 2026: budget master blok 5 memakai daya baca
            // standar 0,1 rpm (ter-cache u = 0,0289), sedangkan aturan ambang
            // [AMBANG_RESOLUSI_STANDAR] memberi 1 rpm untuk nominal 15000.
            // Tanpa baris ini U95 'versi master' tidak bisa direkonsiliasi ke 1,6949.
            $resolusiApp = $this->resolusiStandar($nominalTertinggi);

            if (abs($resolusiApp - self::RESOLUSI_STANDAR_MASTER_BLOK5) > 1e-12) {
                $beda[] = [
                    'kode' => 'putaran_resolusi_standar_blok5_ambang',
                    'sel' => 'PERHITUNGAN U95% blok 5 -> komponen daya baca tachometer standar (ter-cache u = 0,028868 = 0,1/2/sqrt(3))',
                    'sumber' => 'resolusi_standar',
                    'u_master' => (self::RESOLUSI_STANDAR_MASTER_BLOK5 / 2.0) / $sqrt3,
                    'vi_master' => $viApp('resolusi_standar'),
                    'uraian' => sprintf(
                        'Daya baca tachometer standar: master %s %s di blok ini; aplikasi %s %s (aturan ambang %s %s untuk nominal tertinggi blok %s %s).',
                        self::angka(self::RESOLUSI_STANDAR_MASTER_BLOK5), $satuan,
                        self::angka($resolusiApp), $satuan,
                        self::angka(self::AMBANG_RESOLUSI_STANDAR), $satuan,
                        self::angka($nominalTertinggi), $satuan,
                    ),
                    'dasar' => 'Aplikasi menuruti aturan ambang budget (keputusan pemilik 1 Sep 2026, docs/pertanyaan-lab-waktu-frekuensi.md §11); angka master di blok ini 0,1 padahal nominalnya di atas ambang. Belum ada jawaban lab untuk blok ini secara khusus.',
                ];
            }

            $beda[] = [
                'kode' => 'putaran_drift_blok5_tautan_luar_k54',
                'sel' => "PERHITUNGAN U95% blok 5 -> '[1]Drift Std Kalibrator'!K54",
                'sumber' => 'drift_standar',
                'u_master' => 0.0,
                'vi_master' => $viApp('drift_standar'),
                'uraian' => sprintf(
                    'Drift standar: master 0 %s (tautan ke workbook LAIN, sel K54 kosong, nilai tinggal cache); aplikasi setengah-lebar %s %s.',
                    $satuan, self::angka($this->tabel->driftSetengahLebar()), $satuan,
                ),
                'dasar' => 'Kerusakan salin-tempel (tautan luar [1]); blok lain memakai K35 (docs/pertanyaan-lab-waktu-frekuensi.md §2).',
            ];

            $beda[] = [
                'kode' => 'putaran_pengulangan_blok5_baris_kosong',
                'sel' => 'PERHITUNGAN U95% blok 5 -> MAX(PERHITUNGAN!G113:L113)',
                'sumber' => 'pengulangan_pembacaan',
                'u_master' => 0.0,
                'vi_master' => $viApp('pengulangan_pembacaan'),
                'uraian' => sprintf(
                    'Pengulangan: master 0 %s (baris 113 kosong); aplikasi simpangan baku terbesar blok / sqrt(n) = %s %s.',
                    $satuan, self::angka($uApp('pengulangan_pembacaan')), $satuan,
                ),
                'dasar' => 'Kerusakan salin-tempel; lima blok lain memakai baris 34/49/64/79/94 (docs/pertanyaan-lab-waktu-frekuensi.md §2).',
            ];
        } elseif ($alat === 'centrifuge' || $alat === 'tachometer') {
            // Drift kalibrator: berlaku di SEMUA blok selain blok 5 Tachometer
            // (di blok itu master malah memakai 0, lihat atas).
            $driftMaster = $this->tabel->driftSetengahLebar(lengkap: false);
            $driftApp = $this->tabel->driftSetengahLebar();

            if (abs($driftApp - $driftMaster) > 1e-12) {
                $beda[] = [
                    'kode' => 'putaran_drift_standar_kolom_k_tidak_lengkap',
                    'sel' => "PERHITUNGAN U95%!K35 = MAX(K13:K34)/2 atas tabel 'Drift Std Kalibrator' kolom K",
                    'sumber' => 'drift_standar',
                    'u_master' => $driftMaster / $sqrt3,
                    'vi_master' => $viApp('drift_standar'),
                    'uraian' => sprintf(
                        'Drift standar: master setengah-lebar %s %s (kolom K hanya berrumus di 5 dari 15 baris berdata); aplikasi %s %s (seluruh 15 baris), selisih %s %s.',
                        self::angka($driftMaster), $satuan, self::angka($driftApp), $satuan,
                        self::angka($driftApp - $driftMaster, true), $satuan,
                    ),
                    'dasar' => 'Rumus kolom K tidak ikut tersalin di master (docs/pertanyaan-lab-waktu-frekuensi.md §3); dilengkapi, arah aplikasi lebih besar ditegakkan WaktuFrekuensiMasterTest::test_drift_lengkap_lebih_besar_dari_versi_master.',
                ];
            }
        }

        if ($alat === 'centrifuge' && $blokKe === 1 && $dihitung !== []) {
            // G34 = pengulangan kolom PERTAMA blok (titik dengan titik_ke terkecil) saja.
            $pertama = $dihitung[0];

            $beda[] = [
                'kode' => 'putaran_pengulangan_blok1_sel_tunggal_g34',
                'sel' => 'PERHITUNGAN U95% blok 1 -> PERHITUNGAN!G34 (satu sel)',
                'sumber' => 'pengulangan_pembacaan',
                'u_master' => (float) $pertama['type_a'],
                'vi_master' => (float) max((int) $pertama['jumlah_pengulangan'] - 1, 1),
                'uraian' => sprintf(
                    'Pengulangan: master %s %s (hanya sel G34 = titik pertama blok); aplikasi %s %s (simpangan baku terbesar sebaris, MAX(G34:L34)).',
                    self::angka((float) $pertama['type_a']), $satuan, self::angka($uApp('pengulangan_pembacaan')), $satuan,
                ),
                'dasar' => 'Sepuluh blok lain di kedua workbook memakai MAX() sebaris, dan workbook Tachometer memakai MAX(G34:L34) di blok yang sama (WaktuFrekuensiMasterTest::test_blok_rusak_centrifuge_dihitung_lebih_besar).',
            ];
        }

        if ($beda === []) {
            return [];
        }

        $u95AppHitung = (float) $agregat['ketidakpastian_diperluas'];
        $u95AppCetak = max($u95AppHitung, $cmc);

        $catatan = [];
        $semua = [];

        foreach ($beda as $b) {
            $ganti = [$b['sumber'] => ['u' => $b['u_master'], 'vi' => $b['vi_master']]];
            $semua[$b['sumber']] = $ganti[$b['sumber']];
            $u95Master = $this->u95DenganPengganti($budget, $ganti);

            $catatan[] = [
                'kode' => $b['kode'],
                'pesan' => sprintf(
                    '%s Efek komponen ini saja pada U95 blok sebelum lantai CMC: master %s %s, aplikasi %s %s, selisih %s %s. Sel master: %s. Dasar: %s',
                    $b['uraian'],
                    self::angka($u95Master), $satuan, self::angka($u95AppHitung), $satuan,
                    self::angka($u95AppHitung - $u95Master, true), $satuan,
                    $b['sel'], $b['dasar'],
                ),
                'sel_master' => $b['sel'],
                'komponen' => $b['sumber'],
                'u_baku_master' => $b['u_master'],
                'u_baku_aplikasi' => $uApp($b['sumber']),
                'u95_blok_master_terisolasi' => $u95Master,
                'u95_blok_aplikasi' => $u95AppHitung,
            ];
        }

        $u95MasterSemua = $this->u95DenganPengganti($budget, $semua);
        $u95MasterCetak = max($u95MasterSemua, $cmc);

        $catatan[] = [
            'kode' => 'putaran_ringkasan_penyimpangan_master',
            'pesan' => sprintf(
                'Dengan SEMUA penyimpangan di atas disamakan dengan master, U95 blok ini %s %s sebelum lantai CMC dan %s %s tercetak (lantai CMC %s %s); '
                .'aplikasi %s %s sebelum lantai dan %s %s tercetak, selisih tercetak %s %s.',
                self::angka($u95MasterSemua), $satuan, self::angka($u95MasterCetak), $satuan, self::angka($cmc), $satuan,
                self::angka($u95AppHitung), $satuan, self::angka($u95AppCetak), $satuan,
                self::angka($u95AppCetak - $u95MasterCetak, true), $satuan,
            ),
            'u95_blok_master_semua' => $u95MasterSemua,
            'u95_cetak_master' => $u95MasterCetak,
            'u95_cetak_aplikasi' => $u95AppCetak,
            'lantai_cmc' => $cmc,
        ];

        return $catatan;
    }

    /**
     * U95 blok (sebelum lantai CMC) bila komponen tertentu diganti nilai master.
     * Salinan `$budget` — yang asli tidak tersentuh.
     *
     * @param  list<array<string, mixed>>  $budget
     * @param  array<string, array{u: float, vi: float}>  $ganti  dikunci `sumber` komponen
     */
    private function u95DenganPengganti(array $budget, array $ganti): float
    {
        $komponen = array_map(static function (array $k) use ($ganti): array {
            $g = $ganti[$k['sumber']] ?? null;

            return [
                'u' => $g === null ? $k['u'] : $g['u'],
                'ci' => $k['ci'],
                'vi' => $g === null ? $k['vi'] : $g['vi'],
            ];
        }, $budget);

        return (float) ($this->gum ??= new GumCalculator)->agregasiBudget($komponen)['ketidakpastian_diperluas'];
    }

    /** Angka untuk teks audit — tanpa pembulatan semu: 6 desimal, nol belakang dibuang. */
    private static function angka(float $nilai, bool $bertanda = false): string
    {
        $teks = rtrim(rtrim(number_format($nilai, 6, '.', ''), '0'), '.');
        $teks = ($teks === '' || $teks === '-0') ? '0' : $teks;

        return $bertanda && $nilai > 0 ? '+'.$teks : $teks;
    }

    /** @param  list<float>  $nilai */
    private function standarDeviasi(array $nilai, float $rata): float
    {
        $n = count($nilai);

        if ($n < 2) {
            return 0.0;
        }

        return sqrt(array_sum(array_map(
            static fn (float $x): float => ($x - $rata) ** 2,
            $nilai,
        )) / ($n - 1));
    }
}
