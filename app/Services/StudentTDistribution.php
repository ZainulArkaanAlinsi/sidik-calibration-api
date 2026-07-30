<?php

namespace App\Services;

/**
 * Distribusi Student-t: CDF + quantile (invers CDF).
 *
 * Dipakai GumCalculator buat NGITUNG faktor cakupan k dari derajat kebebasan
 * efektif (Welch-Satterthwaite), bukan ngunci k=2. Sertifikat pH asli PT Sidik
 * (012-CAL-524) pakai k dari t-student — mis. veff 277.96 → k 1.96857, bukan 2.
 * Selisih kecil, tapi ikut ke angka U yang dicetak, jadi harus persis.
 *
 * Basisnya fungsi beta tak lengkap teregularisasi I_x(a,b) (Numerical Recipes),
 * karena CDF Student-t = 1 − ½·I_{df/(df+t²)}(df/2, ½) buat t ≥ 0.
 */
class StudentTDistribution
{
    private const MAXIT = 200;

    private const EPS = 3.0e-12;

    private const FPMIN = 1.0e-300;

    /**
     * CDF Student-t: peluang T ≤ $t buat derajat kebebasan $df.
     */
    public function cdf(float $t, float $df): float
    {
        $x = $df / ($df + $t * $t);
        $ib = 0.5 * $this->regularizedIncompleteBeta($df / 2.0, 0.5, $x);

        return $t >= 0.0 ? 1.0 - $ib : $ib;
    }

    /**
     * Quantile (invers CDF): t sedemikian hingga cdf(t, df) = $p.
     *
     * Buat faktor cakupan 95% dua-sisi, panggil quantile(0.975, veff). CDF-nya
     * monoton naik di t, jadi biseksi aman & konvergen ke presisi mesin.
     */
    public function quantile(float $p, float $df): float
    {
        $lo = 0.0;
        $hi = 1.0e6;

        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2.0;

            if ($this->cdf($mid, $df) < $p) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return ($lo + $hi) / 2.0;
    }

    /**
     * Fungsi beta tak lengkap teregularisasi I_x(a,b). Numerical Recipes §6.4.
     */
    private function regularizedIncompleteBeta(float $a, float $b, float $x): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }

        $bt = exp(
            $this->lnGamma($a + $b) - $this->lnGamma($a) - $this->lnGamma($b)
            + $a * log($x) + $b * log(1.0 - $x),
        );

        // Pakai deret yang konvergen cepat: kalau x di sisi "kiri" titik balik
        // pakai langsung, kalau nggak pakai simetri I_x(a,b) = 1 − I_{1−x}(b,a).
        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $bt * $this->betacf($a, $b, $x) / $a;
        }

        return 1.0 - $bt * $this->betacf($b, $a, 1.0 - $x) / $b;
    }

    /** Pecahan berlanjut buat fungsi beta tak lengkap. Numerical Recipes betacf. */
    private function betacf(float $a, float $b, float $x): float
    {
        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;

        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        if (abs($d) < self::FPMIN) {
            $d = self::FPMIN;
        }
        $d = 1.0 / $d;
        $h = $d;

        for ($m = 1; $m <= self::MAXIT; $m++) {
            $m2 = 2 * $m;

            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            if (abs($d) < self::FPMIN) {
                $d = self::FPMIN;
            }
            $c = 1.0 + $aa / $c;
            if (abs($c) < self::FPMIN) {
                $c = self::FPMIN;
            }
            $d = 1.0 / $d;
            $h *= $d * $c;

            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            if (abs($d) < self::FPMIN) {
                $d = self::FPMIN;
            }
            $c = 1.0 + $aa / $c;
            if (abs($c) < self::FPMIN) {
                $c = self::FPMIN;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;

            if (abs($del - 1.0) < self::EPS) {
                break;
            }
        }

        return $h;
    }

    /** ln Γ(x) — aproksimasi Lanczos (g=7), akurat ~15 digit buat x > 0. */
    private function lnGamma(float $x): float
    {
        static $cof = [
            676.5203681218851, -1259.1392167224028, 771.32342877765313,
            -176.61502916214059, 12.507343278686905, -0.13857109526572012,
            9.9843695780195716e-6, 1.5056327351493116e-7,
        ];

        if ($x < 0.5) {
            return log(M_PI / sin(M_PI * $x)) - $this->lnGamma(1.0 - $x);
        }

        $x -= 1.0;
        $a = 0.99999999999980993;
        $t = $x + 7.5;

        foreach ($cof as $i => $c) {
            $a += $c / ($x + $i + 1.0);
        }

        return 0.5 * log(2.0 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }
}
