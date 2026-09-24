<?php

namespace Tests\Unit;

use App\Services\Calibration\GayaCalculator;
use App\Services\GumCalculator;
use Tests\TestCase;

/**
 * Budget ketidakpastian GAYA diadu ke master — memakai `GumCalculator` yang
 * SUDAH ADA, bukan mesin agregasi kedua.
 *
 * ## Kenapa berkas ini ada sebelum profilnya ditulis
 *
 * Ini pertanyaan yang harus dijawab paling awal: apakah mesin agregasi repo ini
 * memulangkan angka master untuk alat gaya? Kalau tidak, seluruh rancangan
 * profil harus berubah. Menjawabnya belakangan berarti menulis tiga profil
 * dulu, baru tahu fondasinya tidak cocok.
 *
 * Jawabannya cocok, dan yang menentukan satu hal: **`v_eff` dipotong ke bawah**
 * sebelum dicari `t`-nya. Dengan `v_eff` utuh (241,83) `k` keluar
 * 1,969821961770995; dengan dipotong (241) keluar 1,9698562125951944 — dan yang
 * master catat 1,9698562125960952. Bedanya di desimal kelima, cukup untuk
 * menggeser U95% yang tercetak.
 *
 * `GumCalculator::agregasiBudget()` memang sudah memotong ke bawah, dan
 * alasannya ditulis di sana: bukan meniru Excel, tapi mengikuti GUM G.4.1 —
 * dan lembar manual lab sendiri yang mengikutinya.
 *
 * ## Satu komponen yang SENGAJA tidak dibagi divisornya
 *
 * Baris Drift Standard mengambil `U` langsung jadi `ui`, tanpa dibagi akar 3,
 * padahal kolom Divisor-nya berisi akar 3 seperti tetangganya — di KETIGA
 * workbook. Dampaknya kontribusi drift akar-3 kali lebih besar, dan dia
 * komponen terbesar kedua sesudah sertifikat kalibrator.
 *
 * Direplikasi apa adanya, bukan "dibetulkan": membaginya sendiri menggeser
 * U95% yang sudah tercetak di sertifikat pelanggan. Sudah diangkat sebagai
 * pertanyaan lab bernomor.
 */
class GayaBudgetTest extends TestCase
{
    /**
     * Delapan komponen budget sesi contoh UTM `0169-CAL-324`.
     *
     * Nilai `u` di sini sudah berupa `ui` (sesudah dibagi divisor), persis
     * seperti kolom `ui` di master — termasuk baris drift yang tidak dibagi.
     *
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(): array
    {
        $akar3 = sqrt(3);
        $rsdMaks = 0.013040494540325815;

        return [
            // 1. Sertifikat kalibrator: U95% 0,4% reading, k=2.
            ['u' => 0.4 / 2, 'ci' => 1.0, 'vi' => 200.0],
            // 2. Daya baca UUT: ((0,000981 / 4,905) x 100) / 2 = 0,01%.
            ['u' => 0.01 / $akar3, 'ci' => 1.0, 'vi' => 1e6],
            // 3. Daya baca standar: ((0,0000981 / 5) x 100) / 2 = 0,000981%.
            ['u' => 0.000981 / $akar3, 'ci' => 1.0, 'vi' => 1e6],
            // 4. Temperature: konstanta metode 0,027%.
            ['u' => 0.027 / $akar3, 'ci' => 1.0, 'vi' => 50.0],
            // 5. Drift standar — TIDAK dibagi divisor. Lihat docblock kelas.
            ['u' => 0.0692820323027551, 'ci' => 1.0, 'vi' => 50.0],
            // 6. Pengulangan: RSD MAX / sqrt(12).
            ['u' => $rsdMaks / sqrt(12), 'ci' => 1.0, 'vi' => 11.0],
            // 7. Zero error: (max zero / max test) x 100 = 0 di sesi ini.
            ['u' => 0.0, 'ci' => 1.0, 'vi' => 1e6],
            // 8. Misalignment: STDEV / rata-rata x 100.
            ['u' => 0.021028966278987093 / $akar3, 'ci' => 1.0, 'vi' => 50.0],
        ];
    }

    /**
     * Komponen yang DIRAKIT dari blok sesi harus sama dengan yang diketik di
     * atas — kalau tidak, perakitnya yang salah, bukan mesin agregasinya.
     *
     * Ini yang membedakan "rumusnya benar" dari "kodenya memakai rumus itu".
     * Test di atas membuktikan yang pertama; yang ini membuktikan yang kedua.
     */
    public function test_perakit_komponen_cocok_dengan_master(): void
    {
        $blok = [
            'satuan' => 'kgf',
            'resolusi_uut' => 0.1,          // 0,1 kgf -> 0,000981 kN
            'resolusi_standar' => 0.0000981, // kN, dari sertifikat standar
            'kapasitas_standar' => 5.0,      // kN
            'preload_zero' => [0.0, 0.0, 0.0],
            // Empat sisi X1..X4 dari `Misalignment.csv` master, apa adanya.
            // Rata-ratanya 8,2365 dan STDEV-nya 0,001732050807568772 — dua
            // angka yang ikut dipakai master di kolom budget.
            'misalignment' => [8.237, 8.234, 8.237, 8.238],
        ];

        $dirakit = GayaCalculator::komponenBudget(
            $blok,
            rsdMaks: 0.013040494540325815,
            rentangKn: 4.905,
            u95Standar: 0.4,
            drift: 0.0692820323027551,
        );

        $diketik = $this->komponen();

        $this->assertCount(count($diketik), $dirakit);

        foreach ($diketik as $i => $harap) {
            $this->assertEqualsWithDelta(
                $harap['u'],
                $dirakit[$i]['u'],
                1e-15,
                "Komponen ke-{$i} ({$dirakit[$i]['sumber']}) meleset dari master.",
            );
            $this->assertSame($harap['vi'], $dirakit[$i]['vi']);
        }
    }

    public function test_budget_cocok_master(): void
    {
        $hasil = (new GumCalculator)->agregasiBudget($this->komponen());

        $this->assertEqualsWithDelta(
            0.21269280931915782,
            $hasil['ketidakpastian_gabungan'],
            1e-15,
            'uc meleset dari master.',
        );

        $this->assertEqualsWithDelta(
            241.83321287301314,
            $hasil['derajat_kebebasan_efektif'],
            1e-9,
            'v_eff meleset.',
        );

        $this->assertEqualsWithDelta(
            1.9698562125960952,
            $hasil['faktor_cakupan_k'],
            1e-9,
            'k meleset — kemungkinan v_eff nggak dipotong ke bawah.',
        );

        $this->assertEqualsWithDelta(
            0.4189742518118597,
            $hasil['ketidakpastian_diperluas'],
            1e-12,
            'U (%) meleset dari master.',
        );
    }

    /**
     * U dalam kN, lalu lantai CMC-nya.
     *
     * Di sesi contoh CMC menang tipis (0,0206010 lawan 0,0205507). Itu justru
     * kasus yang penting dijaga: kalau lantai CMC lupa dipasang, angka yang
     * terbit lebih KECIL dari kemampuan yang diakreditasi — sertifikat terlihat
     * lebih presisi daripada yang boleh diklaim lab.
     */
    public function test_lantai_cmc_menang_di_sesi_contoh(): void
    {
        $hasil = (new GumCalculator)->agregasiBudget($this->komponen());

        $rentangKn = 4.905;                        // 500 kgf x 0,00981
        $uKn = $hasil['ketidakpastian_diperluas'] / 100 * $rentangKn;
        $cmcKn = 2.1 * 0.00981;                    // 2,1 kgf, LK-285-IDN

        $this->assertEqualsWithDelta(0.020550687051371717, $uKn, 1e-12);
        $this->assertEqualsWithDelta(0.020600999999999998, $cmcKn, 1e-15);
        $this->assertEqualsWithDelta(0.020600999999999998, max($uKn, $cmcKn), 1e-15);
    }

    /**
     * Kalau drift IKUT dibagi akar 3, angkanya bergeser — dan pergeserannya nyata.
     *
     * Dikunci di sini supaya keputusan lab nanti kelihatan dampaknya, dan supaya
     * tidak ada yang "merapikan" baris itu tanpa sadar mengubah U95% yang
     * terbit.
     */
    public function test_membagi_drift_menggeser_hasil(): void
    {
        $komponen = $this->komponen();
        $komponen[4]['u'] = 0.0692820323027551 / sqrt(3);

        $hasil = (new GumCalculator)->agregasiBudget($komponen);

        $this->assertLessThan(
            0.21269280931915782,
            $hasil['ketidakpastian_gabungan'],
            'Membagi drift harusnya MENURUNKAN uc — kalau tidak, komponennya salah tempat.',
        );

        // Turun dari 0,212693 jadi 0,205032 — sekitar 3,6%. Itu pergeseran yang
        // sampai ke U95% tercetak, bukan yang hilang di pembulatan.
        $this->assertEqualsWithDelta(
            0.2050322685239463,
            $hasil['ketidakpastian_gabungan'],
            1e-12,
            'Besar pergeserannya berubah — periksa lagi sebelum menerimanya.',
        );
    }
}
