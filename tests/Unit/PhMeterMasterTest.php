<?php

namespace Tests\Unit;

use App\Services\GumCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pH Meter diadu ke **KEDUA** workbook masternya, bukan salah satu.
 *
 * ## Kenapa dua, dan kenapa itu bukan kemewahan
 *
 * Lab punya dua master olah data pH, dan dua-duanya asli — bedanya bukan revisi
 * melainkan PERANGKAT yang dipakai waktu sesi itu dikerjakan:
 *
 *  - `Master Olah Data_pH for trial` — termometer standar Yokogawa CA 150 Handy
 *    Cal, U95 0,72 °C, jadi UTemperature 0,36124783736376886. Alat yang
 *    dikalibrasi resolusinya 0,01 pH.
 *  - `pH_meter_IMTE-WQ-129` — termometer standar Constant/SH 10 (S/N
 *    99875850/20), U95 0,5 °C, jadi UTemperature 0,25179356624028343. Alatnya
 *    resolusi 0,001 pH.
 *
 * Selama ini cuma yang pertama yang pernah diadu, dan angka yang kedua sempat
 * DICATAT SEBAGAI SALAH BACA di komentar `PhMeterCapabilitySeeder` ("angka
 * termometernya kebaca 0.25, bukan 0.36"). Itu keliru: workbook IMTE-WQ-129
 * menulis `U95% Thermometer 0.5` dan `UTemperature 0.25179356624028343` di
 * kepala sheetnya sendiri. Satu angka sah yang dikira salah ketik adalah cara
 * paling sunyi buat kehilangan master — berikutnya dia "dirapikan" dan tidak
 * ada yang merah.
 *
 * Berkas ini yang menahannya: BENTUK budgetnya satu, ISI-nya dua, dan keduanya
 * wajib reproduksi lembar manualnya di 5·10⁻⁶.
 *
 * ## Yang TIDAK boleh disimpulkan dari sini
 *
 * Bahwa salah satu UTemperature "yang benar". Keduanya benar untuk sesinya
 * masing-masing. Yang disimpan `PhMeterCapabilitySeeder` cuma yang Yokogawa,
 * dan itu keputusan sadar sampai lab memutuskan apakah UTemperature ikut
 * standar suhu sesi — `docs/pertanyaan-lab-ph-dua-master.md` §1.
 */
class PhMeterMasterTest extends TestCase
{
    /** Master lab menulis 15–17 angka berarti; 5·10⁻⁶ jauh di dalamnya. */
    private const TOL = 5e-6;

    /**
     * Konstanta yang IDENTIK di kedua workbook — dan itu temuannya.
     *
     * `ci_suhu`, `u_perbedaan_suhu`, dan `ci_perbedaan_suhu` sudah diadu baris
     * demi baris di kedua sheet dan sama persis. Itu yang membuktikan cuma
     * `UTemperature` (dan resolusi alat) yang ikut perangkat — bukan seluruh
     * bloknya. Kalau salah satu dari ketiga angka ini ternyata beda antar
     * workbook, anggapan itu runtuh dan seeder per-titiknya ikut salah.
     *
     * `ci_perbedaan_suhu` di pH 4 bernilai 1 sementara dua titik lain pecahan.
     * Itu keanehan master yang ditiru apa adanya di kedua workbook — lihat
     * `PhMeterCapabilitySeeder`.
     *
     * @var array<string, array{ciSuhu: float, uPerbedaanSuhu: float, ciPerbedaanSuhu: float}>
     */
    private const BERSAMA = [
        'pH 4' => ['ciSuhu' => 0.0007700000000001594, 'uPerbedaanSuhu' => 0.01, 'ciPerbedaanSuhu' => 1.0],
        'pH 7' => ['ciSuhu' => 0.0035199999999999676, 'uPerbedaanSuhu' => 0.02, 'ciPerbedaanSuhu' => 0.0030400000000003757],
        'pH 10' => ['ciSuhu' => 0.010209999999998942, 'uPerbedaanSuhu' => 0.05, 'ciPerbedaanSuhu' => 0.009489999999999554],
    ];

    /** CMC lampiran akreditasi LK-285-IDN no. 41. */
    private const CMC = ['pH 4' => 0.023, 'pH 7' => 0.021, 'pH 10' => 0.031];

    /**
     * Lima komponen budget pH persis urutan sheet `PERHITUNGAN U95%`.
     *
     * Sengaja disusun di sini alih-alih memanggil `PhMeterProfile::
     * komponenBudget()`: yang diuji berkas ini adalah apakah ANGKA masternya
     * reproduksi, dan profil butuh model Eloquent (`Equipment`, `Standard`,
     * `CalibrationCapability`) yang menyeret seluruh basis data ke test unit.
     * Susunan yang sama di sisi profil dijaga test sesi pH lewat jalur
     * sebenarnya.
     *
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private static function budget(
        float $uTemperature,
        float $resolusi,
        float $uStandar,
        float $ciSuhu,
        float $uPerbedaanSuhu,
        float $ciPerbedaanSuhu,
        float $typeA,
    ): array {
        $sqrt3 = sqrt(3);

        return [
            // Sertifikat kalibrator buffer, normal k=2.
            ['u' => $uStandar / 2.0, 'ci' => 1.0, 'vi' => 200],
            // Daya baca alat: setengah resolusi, persegi.
            ['u' => ($resolusi / 2.0) / $sqrt3, 'ci' => 1.0, 'vi' => 1_000_000],
            // Suhu. Masternya melabeli barisnya "rect." tapi MEMBAGINYA 2 —
            // ditiru apa adanya di kedua workbook.
            ['u' => $uTemperature / 2.0, 'ci' => $ciSuhu, 'vi' => 200],
            ['u' => $uPerbedaanSuhu / $sqrt3, 'ci' => $ciPerbedaanSuhu, 'vi' => 50],
            // Keterulangan: s/√n, v = n−1 = 4.
            ['u' => $typeA, 'ci' => 1.0, 'vi' => 4],
        ];
    }

    /**
     * @return iterable<string, array{0: float, 1: float, 2: array<string, array<string, float>>}>
     */
    public static function masterProvider(): iterable
    {
        // `alat-alat-Pt-Sidik/instrument-analiitk/pH_meter_IMTE-WQ-129/
        //  PERHITUNGAN U95%.csv`, tiga blok "Point Ukur".
        yield 'IMTE-WQ-129 (Constant/SH 10, U95 0,5 °C)' => [
            0.25179356624028343,
            0.001,
            [
                'pH 4' => [
                    'uStandar' => 0.02, 'typeA' => 0.0002449489742781821,
                    'uc' => 0.011553616928549545, 'veff' => 246.71502225955618,
                    'k' => 1.969654176178919, 'u95' => 0.022756629833289067,
                ],
                'pH 7' => [
                    'uStandar' => 0.02, 'typeA' => 0.0002449489742783996,
                    'uc' => 0.010017033162901414, 'veff' => 201.36173725372464,
                    'k' => 1.9718365067798587, 'u95' => 0.019751951680233523,
                ],
                'pH 10' => [
                    'uStandar' => 0.03, 'typeA' => 0.0007483314773550144,
                    'uc' => 0.015078814688219583, 'veff' => 204.16236023814994,
                    'k' => 1.9716608894937595, 'u95' => 0.02973030918068659,
                ],
            ],
        ];

        // `Master Olah Data_pH for trial_CSV/PERHITUNGAN U95%.csv`. Berkasnya
        // sudah dikeluarkan dari repo (commit 4427494, "buang berkas mati,
        // keluarin data pelanggan dari git") — angkanya dipatok di sini justru
        // KARENA itu: master yang tidak ada berkasnya dan tidak ada testnya
        // sama saja dengan master yang hilang.
        //
        // Dua titiknya ber-`typeA` NOL: sesi contoh itu mencatat pembacaan yang
        // identik semua, jadi simpangan bakunya memang 0. Bukan sel kosong yang
        // kebaca nol — sheet `PERHITUNGAN`-nya menuliskan angkanya.
        yield 'for trial (Yokogawa CA 150, U95 0,72 °C)' => [
            0.36124783736376886,
            0.01,
            [
                'pH 4' => [
                    'uStandar' => 0.02, 'typeA' => 0.0,
                    'uc' => 0.011903193270260157, 'veff' => 277.96023159473606,
                    'k' => 1.9685650464208695, 'u95' => 0.02343221021262627,
                ],
                'pH 7' => [
                    'uStandar' => 0.02, 'typeA' => 0.002449489742783126,
                    'uc' => 0.010711619968364562, 'veff' => 223.13211787627418,
                    'k' => 1.9706589608358136, 'u95' => 0.02110894987572546,
                ],
                'pH 10' => [
                    'uStandar' => 0.03, 'typeA' => 0.0,
                    'uc' => 0.015388610956781186, 'veff' => 221.49458541210612,
                    'k' => 1.9707562704894734, 'u95' => 0.030327201537199536,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array<string, float>>  $titik
     */
    #[DataProvider('masterProvider')]
    public function test_budget_lima_komponen_reproduksi_lembar_manual(
        float $uTemperature,
        float $resolusi,
        array $titik,
    ): void {
        $gum = new GumCalculator;

        foreach ($titik as $nama => $harap) {
            $hasil = $gum->agregasiBudget(self::budget(
                $uTemperature,
                $resolusi,
                $harap['uStandar'],
                self::BERSAMA[$nama]['ciSuhu'],
                self::BERSAMA[$nama]['uPerbedaanSuhu'],
                self::BERSAMA[$nama]['ciPerbedaanSuhu'],
                $harap['typeA'],
            ));

            foreach ([
                'ketidakpastian_gabungan' => $harap['uc'],
                'derajat_kebebasan_efektif' => $harap['veff'],
                'faktor_cakupan_k' => $harap['k'],
                'ketidakpastian_diperluas' => $harap['u95'],
            ] as $kolom => $nilai) {
                $this->assertEqualsWithDelta(
                    $nilai,
                    (float) $hasil[$kolom],
                    abs($nilai) * self::TOL,
                    "{$nama}: `{$kolom}` nggak cocok sama lembar manualnya.",
                );
            }
        }
    }

    /**
     * Master LAMA membuktikan lantai CMC tidak selalu menutupi: di pH 4 & pH 7
     * hasil hitungnya MELEWATI CMC, dan yang tercetak di sertifikat justru
     * angka hitungnya.
     *
     * Ini pasangan wajib buat test di bawahnya. Tanpa ini, "selisih
     * UTemperature ketutup CMC" kebaca seperti sifat permanen alat pH — padahal
     * dia cuma kebetulan yang bergantung resolusi alat dan seberapa berisik
     * pembacaannya.
     */
    public function test_master_lama_menembus_lantai_cmc_di_dua_titik(): void
    {
        $gum = new GumCalculator;

        $typeA = ['pH 4' => 0.0, 'pH 7' => 0.002449489742783126, 'pH 10' => 0.0];
        $uStandar = ['pH 4' => 0.02, 'pH 7' => 0.02, 'pH 10' => 0.03];
        $tembus = ['pH 4' => true, 'pH 7' => true, 'pH 10' => false];

        foreach ($tembus as $nama => $harusTembus) {
            $u = (float) $gum->agregasiBudget(self::budget(
                0.36124783736376886, 0.01, $uStandar[$nama],
                self::BERSAMA[$nama]['ciSuhu'],
                self::BERSAMA[$nama]['uPerbedaanSuhu'],
                self::BERSAMA[$nama]['ciPerbedaanSuhu'],
                $typeA[$nama],
            ))['ketidakpastian_diperluas'];

            $harusTembus
                ? $this->assertGreaterThan(
                    self::CMC[$nama],
                    $u,
                    "{$nama}: master lama menerbitkan {$u} DI ATAS CMC. Kalau sekarang di bawah, "
                    .'budgetnya menyusut dan sertifikat lama nggak bisa direproduksi lagi.',
                )
                : $this->assertLessThan(self::CMC[$nama], $u, "{$nama}: mestinya masih ketutup CMC.");
        }
    }

    /**
     * Untuk alat resolusi 0,001, selisih kedua UTemperature masih ketutup
     * lantai CMC di KETIGA titiknya.
     *
     * Ini yang bikin konstanta Yokogawa boleh tetap dipakai buat alat yang
     * sebenarnya dikalibrasi pakai Constant/SH 10 tanpa satu pun angka tercetak
     * berubah. Begitu asumsi itu jebol — CMC diturunkan, atau pembacaannya
     * lebih berisik — test ini merah DULUAN, sebelum sertifikatnya terbit
     * dengan U yang mengaku lebih besar dari yang lab hitung sendiri.
     */
    public function test_selisih_utemperature_masih_ketutup_lantai_cmc(): void
    {
        $gum = new GumCalculator;

        $sesi = [
            'pH 4' => ['uStandar' => 0.02, 'typeA' => 0.0002449489742781821],
            'pH 7' => ['uStandar' => 0.02, 'typeA' => 0.0002449489742783996],
            'pH 10' => ['uStandar' => 0.03, 'typeA' => 0.0007483314773550144],
        ];

        foreach ($sesi as $nama => $s) {
            $u = [];
            foreach ([0.36124783736376886, 0.25179356624028343] as $uT) {
                $u[] = (float) $gum->agregasiBudget(self::budget(
                    $uT, 0.001, $s['uStandar'],
                    self::BERSAMA[$nama]['ciSuhu'],
                    self::BERSAMA[$nama]['uPerbedaanSuhu'],
                    self::BERSAMA[$nama]['ciPerbedaanSuhu'],
                    $s['typeA'],
                ))['ketidakpastian_diperluas'];
            }

            foreach ($u as $nilai) {
                $this->assertLessThan(
                    self::CMC[$nama],
                    $nilai,
                    "{$nama}: U hitung ({$nilai}) sudah NAIK di atas CMC (".self::CMC[$nama].'), jadi pilihan '
                    .'UTemperature sekarang benar-benar mengubah angka yang tercetak di sertifikat. '
                    .'Berhenti — ini bukan test yang boleh "dibetulkan" dengan menaikkan batasnya; '
                    .'jawab dulu `docs/pertanyaan-lab-ph-dua-master.md` §1.',
                );
            }

            // Dan yang Yokogawa memang yang lebih BESAR — arahnya aman
            // (melaporkan U lebih besar dari yang lab hitung, bukan lebih kecil).
            $this->assertGreaterThan($u[1], $u[0], "{$nama}: arah selisihnya kebalik.");
        }
    }
}
