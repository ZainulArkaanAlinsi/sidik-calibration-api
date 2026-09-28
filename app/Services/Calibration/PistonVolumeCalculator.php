<?php

namespace App\Services\Calibration;

use App\Services\Calibration\TabelStandarPistonVolume as Tabel;
use App\Services\GumCalculator;
use InvalidArgumentException;

/**
 * Rantai hitung gravimetri PISTON VOLUME — dua master (Fixed & Graduated),
 * metode SIDIK-IK-CAL-0522 / ISO 8655.
 *
 * ## Bukti sebelum kode
 *
 * Ditulis ulang dari `docs/skrip/gen-tabel-standar-piston-volume.py`, yang
 * mengadu 274 sel kedua master ke cache Excel (selisih terbesar 2·10⁻¹⁴).
 * `PistonVolumeMasterTest` mengadu kelas ini ke keluaran skrip yang sama.
 *
 * ## V20 BUKAN rumus Volumetric Glassware
 *
 *   Fixed      V20 = m̄·(1/(ρw−ρa))·(1−ρa/ρb)·(1−α(t−20))
 *   Graduated  V20 = (m̄/ρb)·((ρb−ρa)/(ρw−ρa))·(1−α(t−20))
 *
 * Faktor muai TERPISAH di luar. `VolumetricGlasswareCalculator` memakai bentuk
 * Cuckow (muai BERSARANG di suku apung) dengan ρb 7,95 — panduan eksternal
 * keliru menyebut keduanya sama. Kedua bentuk piston ekuivalen aljabar tapi
 * urutan operasinya disalin per master supaya bitnya sama.
 *
 * ## Dua master, dua metode — dan itu ditiru, bukan diseragamkan
 *
 *   komponen            Fixed                          Graduated
 *   densitas air  U     √((1e-6/2)² + Δρ²)             √(1e-6² + (Δρ/1,73)²)   (lihat G-9)
 *                 ÷     2                              √3
 *   suhu air      ÷     √3                             2
 *   ci acuan            titik tunggal                  m̄ titik MAX, ρw & t titik MIN
 *
 * ## Mode master vs benar
 *
 * Cacat salin-tempel yang MERUSAK DATA dihitung benar di `MODE_BENAR`; mode
 * master meniru persis, dipakai test rekonsiliasi dan jejak audit:
 *
 *   G-2  ambang tara-ulang m7 titik MIN 20 g (saudaranya 200 g)
 *   G-8  m7 titik MID/MAX mengambil M4 (`J32`/`L32`) alih-alih M7
 *   G-9  `P87 = (SQRT(P86^2)+(H87^2))` — kurung salah, U densitas air mengecil
 *   G-7  pita CMC bilangan bulat (lihat `TabelStandarPistonVolume::cmc`)
 */
class PistonVolumeCalculator
{
    public const MODE_BENAR = 'benar';

    public const MODE_MASTER = 'master';

    public const FIXED = 'fixed';

    public const GRADUATED = 'graduated';

    public const PEMINDAHAN = 10;

    private ?GumCalculator $gum = null;

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public function hitungSesi(array $m, string $mode = self::MODE_BENAR): array
    {
        $kel = (string) $m['keluarga'];
        $k = Tabel::konstanta();
        [$a1, $a2, $a3, $a4, $a5] = [
            (float) $k['tanaka']['a1'], (float) $k['tanaka']['a2'], (float) $k['tanaka']['a3'],
            (float) $k['tanaka']['a4'], (float) $k['tanaka']['a5'],
        ];
        $rhoB = (float) $k['densitas_anak_timbangan'];
        $alfa = (float) $k['koefisien_muai'];

        $bal = Tabel::timbangan((string) $m['timbangan']);

        if ($bal === null) {
            throw new InvalidArgumentException(sprintf(
                'Timbangan "%s" tidak ada di tabel standar (%s).',
                $m['timbangan'],
                implode(', ', Tabel::namaTimbangan()),
            ));
        }

        $faktorNominal = in_array($m['satuan'], ['µl', 'µL', 'ul'], true) ? 0.001 : 1.0;
        $T = ($m['suhu_ruang'][0] + $m['suhu_ruang'][1]) / 2;
        $RH = ($m['kelembaban'][0] + $m['kelembaban'][1]) / 2;
        $P = ($m['tekanan_udara'][0] + $m['tekanan_udara'][1]) / 2;
        $ra = ((0.34848 * $P) - (0.009 * $RH) * exp(0.061 * $T)) / ($T + 273.15) / 1000;
        $tanaka = static fn (float $t): float => $a5 * (1 - ((($t + $a1)) ** 2 * ($t + $a2)) / ($a3 * ($t + $a4))) / 1000;

        $per = [];

        foreach ($m['titik'] as $t) {
            $kum = array_map('floatval', array_values($t['kumulatif']));

            if (count($kum) !== self::PEMINDAHAN + 1) {
                throw new InvalidArgumentException(sprintf(
                    'Titik %s wajib TEPAT %d massa kumulatif (M0..M10), terisi %d.',
                    $t['label'],
                    self::PEMINDAHAN + 1,
                    count($kum),
                ));
            }

            $mi = $this->selisih($kum, $mode, $kel, (string) $t['label'], (string) $m['satuan']);
            $pen = (float) ($t['penguapan'] ?? 0.0);
            $miK = array_map(static fn (float $x): float => $x + $pen, $mi);
            $mbar = array_sum($miK) / count($miK);
            $s = self::stdev($miK, $mbar);
            [$ta, $tb] = [(float) $t['suhu_air'][0], (float) $t['suhu_air'][1]];
            $rataBaca = ($ta + $tb) / 2;
            $idx = Tabel::indeksSuhuTerdekat($rataBaca);
            $km = (float) Tabel::koreksiMeter($idx);
            $ks = (float) Tabel::koreksiSensor($idx);
            $t1 = $ta + $km + $ks;
            $t2 = $tb + $km + $ks;
            $tt = ($t1 + $t2) / 2;
            $r1 = $tanaka($t1);
            $r2 = $tanaka($t2);
            $rw = ($r1 + $r2) / 2;
            $nominalMl = (float) $t['nominal'] * $faktorNominal;
            $v20 = $kel === self::FIXED
                ? $mbar * (1 / ($rw - $ra)) * (1 - ($ra / $rhoB)) * (1 - ($alfa * ($tt - 20)))
                : ($mbar / $rhoB) * (($rhoB - $ra) / ($rw - $ra)) * (1 - ($alfa * ($tt - 20)));

            $per[] = [
                'label' => (string) $t['label'],
                'nominal' => (float) $t['nominal'],
                'nominal_ml' => $nominalMl,
                'kumulatif' => $kum,
                'm' => $mi,
                'm_terkoreksi' => $miK,
                'm_rata' => $mbar,
                'stdev' => $s,
                'suhu_rata_baca' => $rataBaca,
                'indeks_suhu' => $idx,
                'koreksi_meter' => $km,
                'koreksi_sensor' => $ks,
                't_terkoreksi' => [$t1, $t2],
                't_rata' => $tt,
                'rho_air_titik' => [$r1, $r2],
                'rho_air' => $rw,
                'V20' => $v20,
                'deviasi' => $v20 - $nominalMl,
                'deviasi_ul' => ($v20 - $nominalMl) * 1000,
            ];
        }

        $semuaT = array_merge(...array_map(static fn (array $p): array => $p['t_terkoreksi'], $per));
        $semuaRho = array_merge(...array_map(static fn (array $p): array => $p['rho_air_titik'], $per));
        $maksS = max(array_map(static fn (array $p): float => $p['stdev'], $per));
        $dT = max($semuaT) - min($semuaT);
        $dRho = max($semuaRho) - min($semuaRho);

        if ($kel === self::FIXED) {
            $uRho = sqrt(((1 / 1000000) / 2) ** 2 + $dRho ** 2);              // Q59
            [$divRho, $divT] = [2.0, sqrt(3)];
            [$j3, $j5, $j7] = [$per[0]['m_rata'], $per[0]['rho_air'], $per[0]['t_rata']];
        } else {
            $h87 = $dRho / 1.73;
            $p86 = 1 / 1000000;
            $uRho = $mode === self::MODE_MASTER
                ? (sqrt($p86 ** 2) + ($h87 ** 2))                              // G-9, ditiru
                : sqrt($p86 ** 2 + $h87 ** 2);
            [$divRho, $divT] = [sqrt(3), 2.0];
            $akhir = $per[count($per) - 1];
            [$j3, $j5, $j7] = [$akhir['m_rata'], $per[0]['rho_air'], $per[0]['t_rata']];
        }

        [$j4, $j6, $j8] = [$ra, $rhoB, $alfa];
        $uMassa = sqrt(((float) $bal['resolusi'] / sqrt(3)) ** 2 + ($maksS / 2) ** 2 + ((float) $bal['u95'] / 2) ** 2);
        $uSuhu = sqrt((Tabel::u95Termometer() / 2) ** 2 + (Tabel::u95Sensor() / 2) ** 2 + ($dT / (2 * sqrt(3))) ** 2);

        // Koefisien sensitivitas DISALIN dari sel H36:H41 — jangan diturunkan
        // ulang secara simbolik; urutan operasinya yang membuat bitnya sama.
        $muai = (1 - ($j8 * ($j7 - 20)));
        $ci = [
            (($j6 - $j4) / ($j6 * ($j5 - $j4))) * $muai,
            $j3 * (($j6 - $j5) / ($j6 * (($j5 - $j4) ** 2))) * $muai,
            (($j5 - $j4) - $j3 * (($j6 - $j4)) / ($j6 * (($j5 - $j4)))) * $muai,
            $j3 * $j4 / ($j6 ** 2 * ($j5 - $j4)) * $muai,
            (-$j3 * $j8 * ($j6 - $j4) * ($j7 - 20) / ($j6 * ($j5 - $j4))),
            -($j3 * ($j6 - $j4)) / ($j6 * ($j5 - $j4)),
            1.0,
        ];

        $definisi = [
            ['massa_air', 'Weight of Destillate Water', 'normal', $uMassa, 2.0, 18.0],
            ['densitas_udara', 'Density of Air', 'persegi', 0.1 * $j4, sqrt(3), 50.0],
            ['densitas_air', 'Density of Destillate Water', $divRho === 2.0 ? 'normal' : 'persegi', $uRho, $divRho, 60.0],
            ['densitas_anak_timbangan', 'Density of Weight Standard', 'persegi', 0.1 * $j6, sqrt(3), 50.0],
            ['suhu_air', 'Temperature of Destillate Water', $divT === 2.0 ? 'normal' : 'persegi', $uSuhu, $divT, 60.0],
            ['koefisien_muai', 'Expansion Coefficient of Material', 'persegi', 0.1 * $j8, sqrt(3), 40.0],
            // `E42` master berisi ANGKA 1,7320508075688772 (bukan rumus SQRT).
            ['operator', 'Operator', 'persegi', (float) $k['u_operator'], 1.7320508075688772, 50.0],
        ];

        $komponen = [];

        foreach ($definisi as $i => [$kode, $ket, $dist, $u95, $div, $vi]) {
            $u = $u95 / $div;
            $komponen[] = [
                'sumber' => $kode,
                'keterangan' => $ket,
                'distribusi' => $dist,
                'U' => $u95,
                'pembagi' => $div,
                'vi' => $vi,
                'ci' => $ci[$i],
                'u' => $u,
                'uici' => $u * $ci[$i],
            ];
        }

        $agregat = ($this->gum ??= new GumCalculator)->agregasiBudget(array_map(
            static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
            $komponen,
        ));
        $veff = $agregat['derajat_kebebasan_efektif'];
        $uExp = (float) $agregat['ketidakpastian_diperluas'];
        $kapasitasMl = (float) $m['kapasitas'] * $faktorNominal;
        $cmc = $mode === self::MODE_MASTER
            ? $this->cmcMaster((string) $m['jenis'], $kapasitasMl)
            : Tabel::cmc((string) $m['jenis'], $kapasitasMl);

        // `J49 = IF(D33="µL", MAX(I47*1000, J48), MAX(I47, J48))`. CMC master
        // untuk satuan µl sudah dikali 1000 di J48; di sini dibandingkan dalam
        // ml lalu dikonversi, hasilnya sama.
        $u95Ml = $cmc === null ? $uExp : max($uExp, $cmc);

        return [
            'keluarga' => $kel,
            'mode' => $mode,
            'satuan' => (string) $m['satuan'],
            'faktor_nominal' => $faktorNominal,
            'per_titik' => $per,
            'rho_udara' => $ra,
            'suhu_ruang_rata' => $T,
            'kelembaban_rata' => $RH,
            'tekanan_rata' => $P,
            'maks_stdev' => $maksS,
            'U_massa' => $uMassa,
            'U_suhu' => $uSuhu,
            'U_rho' => $uRho,
            'delta_suhu' => $dT,
            'delta_rho' => $dRho,
            'acuan' => ['J3' => $j3, 'J4' => $j4, 'J5' => $j5, 'J6' => $j6, 'J7' => $j7, 'J8' => $j8],
            'komponen' => $komponen,
            'uc' => (float) $agregat['ketidakpastian_gabungan'],
            'veff' => $veff,
            'df' => $veff === null ? null : (int) max(1.0, floor($veff)),
            'k' => (float) $agregat['faktor_cakupan_k'],
            'U' => $uExp,
            'cmc' => $cmc,
            'u95_ml' => $u95Ml,
            // Yang DICETAK: dalam satuan alat. `*1000`, bukan `/0,001` — bit
            // terakhirnya beda, dan master menulis `I47*1000`.
            'u95' => $faktorNominal === 1.0 ? $u95Ml : $u95Ml * 1000,
        ];
    }

    /**
     * m_i = M_i − M_{i−1}; Graduated punya cabang tara-ulang di m4 & m7
     * (`IF(AND(G15="ml", M10>200), M_n, M_n − M_{n−1})`) — timbangan di-tara
     * ulang begitu massa terakumulasi melewati 200 g.
     *
     * @param  list<float>  $kum
     * @return list<float>
     */
    private function selisih(array $kum, string $mode, string $kel, string $label, string $satuan): array
    {
        $m = [];

        for ($i = 1; $i <= self::PEMINDAHAN; $i++) {
            $m[] = $kum[$i] - $kum[$i - 1];
        }

        if ($kel !== self::GRADUATED) {
            return $m;
        }

        $tara = $satuan === 'ml';

        if ($tara && $kum[10] > 200) {
            $m[3] = $kum[4];
        }

        if ($mode === self::MODE_MASTER) {
            $ambang = $label === 'MIN' ? 20.0 : 200.0;                 // G-2
            $ambil = $label === 'MIN' ? $kum[7] : $kum[4];             // G-8
        } else {
            [$ambang, $ambil] = [200.0, $kum[7]];
        }

        if ($tara && $kum[10] > $ambang) {
            $m[6] = $ambil;
        }

        return $m;
    }

    /**
     * Cacat master (kode log metode) yang BENAR-BENAR mengubah angka sesi ini
     * — sesi yang memicunya ditahan sampai Technical Manager memutuskan
     * (keputusan 3 di log metode). Syaratnya ikut `selisih()` & `cmcMaster()`:
     *
     *  - G-2: titik MIN, satuan ml, 20 < M10 ≤ 200 g (di atas 200 g master &
     *    aplikasi sama-sama mengambil M7);
     *  - G-8: titik MID/MAX, satuan ml, M10 > 200 g (master mengambil M4);
     *  - G-7: kapasitas jatuh di celah pita bulat (master "cek range").
     *
     * G-9 sengaja TIDAK di sini: selalu beda, porsinya 0,026 % dari jumlah
     * (u_i c_i)², dan U95 tetap di lantai CMC — bukan alasan menahan.
     *
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    public function penyimpanganTerpicu(array $m): array
    {
        $terpicu = [];

        if ($m['keluarga'] === self::GRADUATED && $m['satuan'] === 'ml') {
            foreach ($m['titik'] as $t) {
                $kum = array_values($t['kumulatif']);
                $m10 = (float) ($kum[self::PEMINDAHAN] ?? 0.0);

                if ($t['label'] === 'MIN' && $m10 > 20 && $m10 <= 200) {
                    $terpicu['G-2'] = true;
                }

                if ($t['label'] !== 'MIN' && $m10 > 200) {
                    $terpicu['G-8'] = true;
                }
            }
        }

        $faktor = in_array($m['satuan'], ['µl', 'µL', 'ul'], true) ? 0.001 : 1.0;
        $kapasitasMl = (float) $m['kapasitas'] * $faktor;

        if ($this->cmcMaster((string) $m['jenis'], $kapasitasMl) === null
            && Tabel::cmc((string) $m['jenis'], $kapasitasMl) !== null) {
            $terpicu['G-7'] = true;
        }

        return array_keys($terpicu);
    }

    /** Pita CMC master apa adanya — batas bilangan bulat, celah = `null` (G-7). */
    private function cmcMaster(string $jenis, float $n): ?float
    {
        $pita = fn (float $maks): ?float => Tabel::cmc($jenis, $maks);

        return match ($jenis) {
            Tabel::BURET_DIGITAL => $n <= 10 ? $pita(10) : ($n >= 11 && $n <= 50 ? $pita(50) : null),
            Tabel::PISTON_PIPETTE => $n <= 1 ? $pita(1) : ($n >= 2 && $n <= 5 ? $pita(5) : ($n >= 6 && $n <= 10 ? $pita(10) : null)),
            Tabel::DISPENSETT => $n <= 10 ? $pita(10) : ($n >= 11 && $n <= 50 ? $pita(50) : ($n >= 51 && $n <= 100 ? $pita(100) : null)),
            default => null,
        };
    }

    /** @param  list<float>  $x */
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
