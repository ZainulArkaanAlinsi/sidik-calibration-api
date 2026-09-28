<?php

namespace App\Services\Calibration;

use App\Services\GumCalculator;
use InvalidArgumentException;

/**
 * Rantai hitung keluarga TEKANAN — empat master (DRUCK07G, DRUCK13G, SPMK,
 * Differential) dalam satu mesin, bedanya dipegang tabel & peta per varian.
 *
 * ## Apa yang sebenarnya diukur
 *
 * Perbandingan langsung. Satu sumber tekanan bercabang ke alat pelanggan (UUT)
 * dan kalibrator standar; teknisi memompa sampai jarum UUT tepat di angka
 * setelan, lalu mencatat bacaan STANDAR. Jadi variabel bebasnya setelan UUT,
 * yang diukur bacaan standar — kebalikan intuisi, dan kalau tertukar tanda
 * koreksi di seluruh sertifikat ikut terbalik.
 *
 * ## Bukti sebelum kode
 *
 * Rantai ini ditulis ulang dari `docs/skrip/gen-tabel-standar-tekanan.py`,
 * yang mengadu 962 sel (PERHITUNGAN FC, PERHITUNGAN U95%, SERTIFIKAT) keempat
 * master ke cache Excel dengan selisih terbesar 1,6·10⁻¹¹ pada angka ~144
 * (1·10⁻¹³ relatif). `TekananMasterTest` mengadu kelas ini ke keluaran skrip
 * yang sama.
 *
 * ## Dua mode, dan kenapa dua-duanya ada
 *
 * `MODE_BENAR` dipakai sesi. `MODE_MASTER` meniru persis tiga cacat master
 * yang MENGECILKAN U95 — dipakai test rekonsiliasi dan jejak audit, supaya
 * orang yang menyetujui sesi bisa melihat angka versi master berdampingan
 * dengan angka yang terbit (AGENTS.md §Olah data butir 4). Keputusan pengguna
 * 28 Sep 2026: ketiganya DIHITUNG BENAR, bukan ditiru.
 *
 *   T-1  DRUCK07G `AH24 = VLOOKUP(ABC4, …)` — salah ketik; U95 kalibrator
 *        selalu terbaca dari set point 0. Benar: set point indeks titik itu.
 *   T-2  `'PERHITUNGAN FC'!I44` KOSONG di 07G/13G/Differential — komponen
 *        pengulangan selalu nol. Benar: rumus induk SPMK `MAX(U24:V37)`.
 *   T-11 DRUCK13G `AD24` cabang Vacum membaca kolom 4 (U95) sebagai koreksi
 *        UP. Benar: kolom 2, sama dengan cabang Non Vacum.
 *
 * Kejanggalan METODE sengaja DITIRU di kedua mode dan diangkat sebagai
 * pertanyaan lab: pembagi pengulangan `3` bukan `√3` (T-3), drift dari
 * spesifikasi pabrik (T-7/T-8), zero error dari baris PERTAMA saja.
 *
 * ## Tidak ada pembulatan
 *
 * Tidak satu pun rumus perhitungan keempat master memakai `ROUND`. Pembulatan
 * cuma di lapisan cetak (`k` dicetak `ROUND(k;1)`).
 */
class TekananCalculator
{
    public const MODE_BENAR = 'benar';

    public const MODE_MASTER = 'master';

    public const PENGULANGAN = 3;

    /**
     * Varian yang membaca U95 kalibrator per titik lalu diambil MAX-nya
     * (`AH38`/`AI38`). Dua sisanya membaca U95 di set point INDEKS TERBESAR
     * (`VLOOKUP(AC38, …)`).
     */
    private const U95_MAKS_PER_TITIK = [TabelStandarTekanan::DRUCK07G, TabelStandarTekanan::DRUCK13G];

    private ?GumCalculator $gum = null;

    /**
     * @param  array{satuan: string, tampilan: string, rasio_jarum: ?string, resolusi: float, kapasitas: float,
     *               media?: ?int, jenis_tekanan?: ?string, beda_tinggi?: ?float,
     *               titik: list<array{titik_ke?: int, setelan: float, up: list<float>, down: list<float>}>}  $m
     * @return array<string, mixed>
     */
    public function hitungSesi(string $varian, array $m, string $mode = self::MODE_BENAR): array
    {
        if (TabelStandarTekanan::varian($varian) === null) {
            throw new InvalidArgumentException("Varian tekanan tidak dikenal: {$varian}");
        }

        $f = TabelStandarTekanan::faktor($varian, (string) $m['satuan']);

        if ($f === null) {
            throw new InvalidArgumentException(sprintf(
                'Satuan "%s" tidak ada di daftar varian %s (%s).',
                $m['satuan'],
                $varian,
                implode(', ', TabelStandarTekanan::satuanTersedia($varian)),
            ));
        }

        $pembagiRes = TabelStandarTekanan::pembagiResolusi((string) $m['tampilan'], $m['rasio_jarum'] ?? null);

        if ($pembagiRes === null) {
            throw new InvalidArgumentException('Alat analog wajib punya rasio jarum/NST (1/2, 1/5, 1/10).');
        }

        if ($m['titik'] === []) {
            throw new InvalidArgumentException('Sesi tekanan belum punya satu titik pun.');
        }

        $spmk = $varian === TabelStandarTekanan::SPMK;
        $rho = isset($m['media']) && $m['media'] !== null
            ? TabelStandarTekanan::massaJenisMedia($varian, (int) $m['media'])
            : null;
        $g = TabelStandarTekanan::gravitasiLokal();

        // Koreksi beda tinggi SPMK: R43 = Δh·ρ·g (Pa), R44 = R43/10⁵ (Bar), dan
        // R44 itu DITAMBAHKAN ke bacaan standar UP dan DOWN (AF24 → AG/AH).
        // Panduan eksternal cuma menyebutnya di budget — ini temuan dari sel.
        $koreksiTinggi = null;
        $r43 = null;

        if ($spmk) {
            if ($rho === null) {
                throw new InvalidArgumentException('SPMK wajib memilih media (Water/Oil/Air): massa jenisnya masuk koreksi beda tinggi.');
            }

            $r43 = (float) ($m['beda_tinggi'] ?? 0.0) * $rho * $g;
            $koreksiTinggi = $r43 / 100000;
        }

        $rentang = TabelStandarTekanan::rentang($varian);
        $perTitik = [];

        foreach (array_values($m['titik']) as $i => $t) {
            $up = array_map('floatval', array_values($t['up']));
            $down = array_map('floatval', array_values($t['down']));

            if (count($up) !== self::PENGULANGAN || count($down) !== self::PENGULANGAN) {
                throw new InvalidArgumentException(sprintf(
                    'Titik ke-%d wajib tepat %d bacaan UP dan %d bacaan DOWN.',
                    $t['titik_ke'] ?? $i + 1,
                    self::PENGULANGAN,
                    self::PENGULANGAN,
                ));
            }

            $setelan = (float) $t['setelan'];
            $e = $setelan * $f;
            $gK = array_map(static fn (float $v): float => $v * $f, $up);
            $jK = array_map(static fn (float $v): float => $v * $f, $down);
            $rataUp = ($gK[0] + $gK[1] + $gK[2]) / 3;
            $rataDown = ($jK[0] + $jK[1] + $jK[2]) / 3;
            $indeks = TabelStandarTekanan::indeksTerdekat($varian, $e);
            $baris = TabelStandarTekanan::baris($varian, (float) $indeks);
            $koreksiUp = (float) ($baris['koreksi_up'] ?? 0.0);
            $koreksiDown = (float) ($baris['koreksi_down'] ?? 0.0);

            if ($mode === self::MODE_MASTER
                && $varian === TabelStandarTekanan::DRUCK13G
                && ($m['jenis_tekanan'] ?? null) === 'vakum') {
                // T-11, ditiru HANYA di mode master.
                $koreksiUp = (float) ($baris['u95'] ?? 0.0);
            }

            $terkoreksiUp = $spmk ? $rataUp + $koreksiUp + $koreksiTinggi : $rataUp + $koreksiUp;
            $terkoreksiDown = $spmk ? $rataDown + $koreksiDown + $koreksiTinggi : $rataDown + $koreksiDown;

            $u95Titik = null;

            if (in_array($varian, self::U95_MAKS_PER_TITIK, true)) {
                $u95Titik = $mode === self::MODE_MASTER && $varian === TabelStandarTekanan::DRUCK07G
                    // T-1, ditiru HANYA di mode master.
                    ? (float) (TabelStandarTekanan::baris($varian, 0.0)['u95'] ?? 0.0)
                    : ($baris['u95'] ?? null);
            }

            $perTitik[] = [
                'titik_ke' => (int) ($t['titik_ke'] ?? $i + 1),
                'setelan' => $setelan,
                'E' => $e,
                'up_kerja' => $gK,
                'down_kerja' => $jK,
                'rata_up' => $rataUp,
                'rata_down' => $rataDown,
                'deviasi_up' => $e - $rataUp,
                'deviasi_down' => $e - $rataDown,
                'stdev_up' => self::stdev($gK, $rataUp),
                'stdev_down' => self::stdev($jK, $rataDown),
                'histeresis' => [$gK[0] - $jK[0], $gK[1] - $jK[1], $gK[2] - $jK[2]],
                'histeresis_rata' => $rataUp - $rataDown,
                'indeks' => $indeks,
                'koreksi_up' => $koreksiUp,
                'koreksi_down' => $koreksiDown,
                'koreksi_tinggi' => $koreksiTinggi,
                'terkoreksi_up' => $terkoreksiUp,
                'terkoreksi_down' => $terkoreksiDown,
                'u95_titik' => $u95Titik === null ? null : (float) $u95Titik,
                'di_luar_rentang' => $rentang !== null && ($e < $rentang[0] || $e > $rentang[1]),
                // Nilai TAMPIL (satuan pilihan teknisi) — yang dicetak sertifikat.
                'standar_up_tampil' => $terkoreksiUp / $f,
                'standar_down_tampil' => $terkoreksiDown / $f,
                'koreksi_up_tampil' => $terkoreksiUp / $f - $setelan,
                'koreksi_down_tampil' => $terkoreksiDown / $f - $setelan,
                'histeresis_tampil' => [
                    ($gK[0] - $jK[0]) / $f,
                    ($gK[1] - $jK[1]) / $f,
                    ($gK[2] - $jK[2]) / $f,
                ],
            ];
        }

        $maksKoreksi = 0.0;
        $maksSdUp = 0.0;
        $maksSdDown = 0.0;
        $maksIndeks = null;

        foreach ($perTitik as $p) {
            $maksKoreksi = max($maksKoreksi, abs($p['deviasi_up']), abs($p['deviasi_down']));
            $maksSdUp = max($maksSdUp, $p['stdev_up']);
            $maksSdDown = max($maksSdDown, $p['stdev_down']);
            $maksIndeks = $maksIndeks === null ? $p['indeks'] : max($maksIndeks, $p['indeks']);
        }

        // `Q39 = MAX(G38:I38)`, dan G38..I38 cuma membaca baris 24 — titik
        // PERTAMA, apa pun setelannya. Master mengandaikan titik pertama = nol.
        // Ditiru apa adanya (kejanggalan metode, bukan salin-tempel);
        // `TekananProfile` menyorotnya kalau titik pertama bukan nol.
        $pertama = $perTitik[0];
        $zero = max(
            abs($pertama['up_kerja'][0] - $pertama['down_kerja'][0]),
            abs($pertama['up_kerja'][1] - $pertama['down_kerja'][1]),
            abs($pertama['up_kerja'][2] - $pertama['down_kerja'][2]),
        );

        if (in_array($varian, self::U95_MAKS_PER_TITIK, true)) {
            $u95Kalibrator = null;

            foreach ($perTitik as $p) {
                if ($p['u95_titik'] !== null) {
                    $u95Kalibrator = $u95Kalibrator === null ? $p['u95_titik'] : max($u95Kalibrator, $p['u95_titik']);
                }
            }
        } else {
            $u95Kalibrator = TabelStandarTekanan::baris($varian, (float) $maksIndeks)['u95'] ?? null;
        }

        $resolusiKerja = (float) $m['resolusi'] * $f;
        $kapasitasKerja = (float) $m['kapasitas'] * $f;
        $maksStdev = max($maksSdUp, $maksSdDown);
        $drift = TabelStandarTekanan::drift($varian);
        $resStd = (float) (TabelStandarTekanan::standar($varian)['resolusi'] ?? 0.0);

        $uUlang = $mode === self::MODE_MASTER && ! $spmk
            ? 0.0   // T-2, ditiru HANYA di mode master.
            : $maksStdev;

        $akar3 = sqrt(3);
        $komponen = [
            self::komponen('sertifikat_kalibrator', 'Ketidakpastian Baku Sertifikat Kalibrasi Calibrator',
                'normal', (float) ($u95Kalibrator ?? 0.0), 2.0, 60.0, 1.0),
            self::komponen('daya_baca_uut', 'Ketidakpastian Baku Daya Baca Alat yang dikalibrasi',
                'persegi', $resolusiKerja / $pembagiRes, $akar3, 50.0, 1.0),
            self::komponen('daya_baca_standar', 'Ketidakpastian Baku Daya Baca Standar',
                'persegi', $resStd / 2, $akar3, 50.0, 1.0),
            self::komponen('drift_standar', 'Ketidakpastian Drift Standard',
                'persegi', (float) ($drift['nilai'] ?? 0.0), $akar3, 50.0, 1.0),
        ];

        $l43 = $l44 = $l45 = null;

        if ($spmk) {
            // Koefisien sensitivitas beda level — L43..L45 SPMK, urutan
            // perkaliannya disalin dari sel: (R40)*(R42)*(Q38).
            $bedaLevel = TabelStandarTekanan::bedaLevel();
            $l43 = $rho * $g * $maksKoreksi;
            $l44 = $l43 / 100000;
            $l45 = $l44 / $kapasitasKerja;
            $komponen[] = self::komponen('beda_level', 'Ketidakpastian Beda Level', 'normal',
                (float) $bedaLevel['u'], (float) $bedaLevel['pembagi'], (float) $bedaLevel['vi'], $l45);
            $komponen[] = self::komponen('pengulangan', 'Ketidakpastian Baku Pengulangan Pembacaan',
                't-student', $uUlang, 3.0, 2.0, 1.0);
            $komponen[] = self::komponen('zero_error', 'Ketidakpastian Zero Error',
                'persegi', $zero, $akar3, 50.0, 1.0);
        } else {
            $komponen[] = self::komponen('zero_error', 'Ketidakpastian Zero Error',
                'persegi', $zero, $akar3, 50.0, 1.0);
            $komponen[] = self::komponen('pengulangan', 'Ketidakpastian Baku Pengulangan Pembacaan',
                't-student', $uUlang, 3.0, 2.0, 1.0);
        }

        // Satu-satunya mesin agregasi repo: GumCalculator (Welch-Satterthwaite,
        // v_eff dipotong ke bawah sebelum inverse-t — persis TINV Excel).
        $agregat = ($this->gum ??= new GumCalculator)->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $komponen,
        ));

        $veff = $agregat['derajat_kebebasan_efektif'];

        return [
            'varian' => $varian,
            'mode' => $mode,
            'satuan' => (string) $m['satuan'],
            'satuan_kerja' => TabelStandarTekanan::satuanKerja($varian),
            'faktor' => $f,
            'per_titik' => $perTitik,
            'agregat' => [
                'maks_koreksi' => $maksKoreksi,
                'maks_stdev_up' => $maksSdUp,
                'maks_stdev_down' => $maksSdDown,
                'maks_stdev' => $maksStdev,
                'zero_deviasi' => $zero,
                'maks_indeks' => $maksIndeks,
                'u95_kalibrator' => $u95Kalibrator,
                'resolusi_kerja' => $resolusiKerja,
                'kapasitas_kerja' => $kapasitasKerja,
                'R43' => $r43,
                'koreksi_tinggi' => $koreksiTinggi,
                'L43' => $l43,
                'L44' => $l44,
                'L45' => $l45,
            ],
            'komponen' => $komponen,
            'uc' => (float) $agregat['ketidakpastian_gabungan'],
            'veff' => $veff,
            // v_eff PECAHAN dan df BULAT yang dipakai inverse-t disimpan
            // terpisah: tanpa keduanya, selisih ke Excel tidak bisa ditelusuri.
            'df' => $veff === null ? null : (int) max(1.0, floor($veff)),
            'k' => (float) $agregat['faktor_cakupan_k'],
            'U' => (float) $agregat['ketidakpastian_diperluas'],
        ];
    }

    /** @return array<string, mixed> */
    private static function komponen(
        string $sumber,
        string $keterangan,
        string $distribusi,
        float $u95,
        float $pembagi,
        float $vi,
        float $ci,
    ): array {
        $u = $u95 / $pembagi;

        return [
            'sumber' => $sumber,
            'keterangan' => $keterangan,
            'distribusi' => $distribusi,
            'U' => $u95,
            'pembagi' => $pembagi,
            'vi' => $vi,
            'ci' => $ci,
            'u' => $u,
            'uici' => $u * $ci,
        ];
    }

    /**
     * STDEV sampel Excel (pembagi n−1), dua lintasan.
     *
     * Tiga bacaan identik wajib memulangkan NOL BULAT: `(3x)/3` bisa meleset
     * satu bit dari `x`, dan sisa pembulatan itu masuk budget sebagai
     * pengulangan "hampir nol" — Excel sendiri mencetak 0. Ambang relatifnya
     * sama dengan `GumCalculator::standarDeviasiSampel()`.
     *
     * @param  list<float>  $x
     */
    private static function stdev(array $x, float $rata): float
    {
        $jumlah = 0.0;

        foreach ($x as $v) {
            $jumlah += ($v - $rata) ** 2;
        }

        $s = sqrt($jumlah / (count($x) - 1));

        return $s < max(abs($rata), 1.0) * 1e-12 ? 0.0 : $s;
    }
}
