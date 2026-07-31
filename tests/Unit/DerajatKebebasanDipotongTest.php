<?php

namespace Tests\Unit;

use App\Services\StudentTDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `v_eff` dipotong ke bawah sebelum dicari `t`-nya (GUM G.4.1).
 *
 * Ini bukan detail kosmetik. Waktu Type A dominan, `v_eff`-nya kecil dan
 * pemotongannya ngubah angka yang kecetak di sertifikat:
 *
 *     v_eff 4.92  tepat  -> t=2.5830 -> U=0.1266 -> "0,13"
 *     v_eff 4.92  potong -> t=2.7764 -> U=0.1361 -> "0,14"
 *
 * Arahnya juga penting: motong ke bawah bikin `k` LEBIH BESAR, jadi U yang
 * dilaporkan lebih besar. Ketidakpastian nggak boleh dilaporin lebih kecil
 * dari yang bisa dipertanggungjawabkan.
 */
class DerajatKebebasanDipotongTest extends TestCase
{
    /**
     * Empat nilai `k` yang ditulis lembar manual lab. Keempatnya cocok sama
     * versi DIPOTONG, dan NGGAK ADA satu pun yang cocok sama versi tepat —
     * itu buktinya labnya sendiri ngikutin GUM, bukan kita ngikutin Excel.
     *
     * @return array<string, array{float, float}>
     */
    public static function dariLembarLab(): array
    {
        return [
            'titik pH 4' => [277.96023159473606, 1.9685650464208695],
            'titik pH 7' => [223.13211787627418, 1.9706589608358136],
            'titik pH 10' => [221.49458541210612, 1.9707562704894734],
            'Type A dominan' => [4.92139217, 2.7764451051977987],
        ];
    }

    #[DataProvider('dariLembarLab')]
    public function test_k_cocok_sama_lembar_lab_kalau_veff_dipotong(
        float $veff,
        float $kLembar,
    ): void {
        $t = new StudentTDistribution;

        $this->assertEqualsWithDelta(
            $kLembar,
            $t->quantile(0.975, max(1.0, floor($veff))),
            1e-9,
            'k mestinya dihitung dari v_eff yang DIPOTONG ke bawah.',
        );
    }

    #[DataProvider('dariLembarLab')]
    public function test_veff_tanpa_dipotong_NGGAK_cocok(
        float $veff,
        float $kLembar,
    ): void {
        $t = new StudentTDistribution;

        // Dikunci terbalik: kalau suatu saat pemotongannya dilepas, test ini
        // yang ngasih tau — bukan pelanggan yang ngebandingin sertifikat sama
        // lembar manual.
        $this->assertNotEqualsWithDelta(
            $kLembar,
            $t->quantile(0.975, $veff),
            1e-9,
        );
    }
}
