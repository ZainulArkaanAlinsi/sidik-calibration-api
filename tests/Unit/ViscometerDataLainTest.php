<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\Profiles\ViscometerProfile;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Viscometer dengan data yang **BUKAN** sesi master.
 *
 * Seluruh test Viscometer yang lain mengadu keluaran ke workbook master, dan
 * itu memang cara yang benar untuk membuktikan mesinnya sepadan dengan cara
 * lab menghitung. Tapi ada satu hal yang TIDAK bisa dibuktikan cara itu:
 * apakah hasilnya masih benar untuk sesi yang angkanya lain — pelanggan lain,
 * spindle lain, suhu lain.
 *
 * Bedanya nyata. Mesin yang kebetulan cocok di satu sesi contoh dan mesin yang
 * benar-benar menghitung memberi angka yang sama persis di master, lalu
 * berpisah di sesi kedua — dan sesi kedua itu yang dipakai pelanggan.
 *
 * Jadi di sini angka acuannya **dihitung ulang di dalam test dari rumusnya**,
 * bukan disalin dari mana pun. Kalau implementasinya mengambil jalan pintas
 * yang kebetulan pas di master, test ini yang menangkapnya.
 */
class ViscometerDataLainTest extends TestCase
{
    use RefreshDatabase;

    private ViscometerProfile $profil;

    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profil = new ViscometerProfile;
        $this->gum = new GumCalculator;
    }

    /**
     * Standar bertabel suhu, tanpa menyentuh database — `koefisien_suhu`
     * diisi langsung supaya test ini murni menguji aritmetikanya.
     *
     * @param  list<array{suhu: float, nilai: float, u_persen: float}>  $tabel
     */
    private function standarBertabel(array $tabel): Standard
    {
        $s = new Standard;
        $s->koefisien_suhu = ['tabel' => $tabel];

        return $s;
    }

    /** Tabel sertifikat larutan 1000 cP (`ViscometerSeeder`). */
    private function tabel1000(): array
    {
        return [
            ['suhu' => 20.0, 'nilai' => 1504.0, 'u_persen' => 0.23],
            ['suhu' => 25.0, 'nilai' => 1018.0, 'u_persen' => 0.23],
            ['suhu' => 37.78, 'nilai' => 419.5, 'u_persen' => 0.19],
            ['suhu' => 40.0, 'nilai' => 364.6, 'u_persen' => 0.19],
            ['suhu' => 50.0, 'nilai' => 203.2, 'u_persen' => 0.19],
        ];
    }

    /**
     * Interpolasi pada suhu yang TIDAK ada di sesi master.
     *
     * Master cuma menyentuh 26,52 / 27,3 / 24,6 °C. Sesi nyata berikutnya
     * hampir pasti di suhu lain, dan tiap suhu jatuh di ruas tabel yang beda —
     * ruas 20-25 °C kemiringannya −97,2 cP/°C, ruas 25-37,78 °C cuma
     * −46,83 cP/°C. Implementasi yang salah pilih ruas tetap kelihatan wajar
     * di master dan meleset ratusan cP di sini.
     */
    public function test_interpolasi_benar_di_ruas_tabel_mana_pun(): void
    {
        $standar = $this->standarBertabel($this->tabel1000());

        // Tiap pasang: [suhu, ruas bawah, ruas atas] — dihitung ulang dari
        // dua baris pengapitnya, bukan disalin.
        $kasus = [
            [21.0, 20.0, 1504.0, 25.0, 1018.0],
            [23.5, 20.0, 1504.0, 25.0, 1018.0],
            [24.999, 20.0, 1504.0, 25.0, 1018.0],
            [25.001, 25.0, 1018.0, 37.78, 419.5],
            [30.0, 25.0, 1018.0, 37.78, 419.5],
            [37.0, 25.0, 1018.0, 37.78, 419.5],
            [38.5, 37.78, 419.5, 40.0, 364.6],
            [45.0, 40.0, 364.6, 50.0, 203.2],
        ];

        foreach ($kasus as [$suhu, $suhuBawah, $nilaiBawah, $suhuAtas, $nilaiAtas]) {
            $harusnya = $nilaiBawah
                + ($suhu - $suhuBawah) / ($suhuAtas - $suhuBawah) * ($nilaiAtas - $nilaiBawah);

            $this->assertEqualsWithDelta(
                $harusnya,
                $standar->nilaiPadaSuhu($suhu),
                1e-9,
                "Interpolasi pada {$suhu} °C jatuh di ruas yang salah.",
            );
        }
    }

    /**
     * Nilai hasil interpolasi harus MENURUN monoton seiring suhu naik —
     * viskositas tidak pernah naik saat larutan dipanaskan.
     *
     * Penjaga bentuk, bukan penjaga angka: dia menangkap tabel yang terbalik,
     * ruas yang tertukar, dan pembagi yang bertanda salah — kelas kesalahan
     * yang tidak akan pernah terlihat dari satu sesi contoh.
     */
    public function test_kurva_selalu_menurun_di_seluruh_jangkauan(): void
    {
        $standar = $this->standarBertabel($this->tabel1000());
        $sebelumnya = null;

        for ($suhu = 20.0; $suhu <= 50.0; $suhu += 0.25) {
            $nilai = $standar->nilaiPadaSuhu($suhu);

            $this->assertNotNull($nilai, "Suhu {$suhu} °C dalam jangkauan tapi balik null.");

            if ($sebelumnya !== null) {
                $this->assertLessThan(
                    $sebelumnya,
                    $nilai,
                    "Viskositas naik dari suhu sebelumnya di {$suhu} °C.",
                );
            }

            $sebelumnya = $nilai;
        }
    }

    /**
     * Di luar jangkauan tabel TIDAK diekstrapolasi.
     *
     * Kemiringan ruas terakhir tidak berlaku di luar tabel — larutan 60000 cP
     * jatuh dari 95192 cP (20 °C) ke 411,3 cP (100 °C). Angka hasil
     * ekstrapolasi akan kelihatan sah dan salah besar.
     */
    public function test_di_luar_jangkauan_balik_null_bukan_ekstrapolasi(): void
    {
        $standar = $this->standarBertabel($this->tabel1000());

        $this->assertNull($standar->nilaiPadaSuhu(19.9));
        $this->assertNull($standar->nilaiPadaSuhu(50.1));
        $this->assertNull($standar->nilaiPadaSuhu(-5.0));
        $this->assertNull($standar->nilaiPadaSuhu(150.0));
    }

    /**
     * MPE untuk kombinasi spindle & RPM yang tidak dipakai master.
     *
     * Master cuma memakai HA1/63, HA2/62, HA7/62. Daftar spindle ada 63 baris
     * dengan SMC 0,327 sampai 1280 — rentang 3900×. Rumusnya dihitung ulang di
     * sini dari TK & SMC yang dibaca dari tabel profil.
     */
    public function test_mpe_benar_untuk_spindle_rpm_di_luar_master(): void
    {
        $kasus = [
            // [model, spindle, rpm, rata-rata pembacaan]
            ['LV', 'LV1', 12.0, 45.8],
            ['RV', 'RV3', 30.0, 1240.0],
            ['HB', 'HB7', 0.5, 850000.0],
            ['A2', 'SC4-18', 100.0, 12.4],
            ['B5', 'CPE-40 or CPA-40Z', 200.0, 3.9],
            ['L3', 'T-F', 2.5, 96000.0],
        ];

        foreach ($kasus as [$model, $spindle, $rpm, $rataRata]) {
            $tk = $this->profil->tkModel($model);
            $smc = $this->profil->smcSpindle($spindle);

            $this->assertNotNull($tk, "Model {$model} nggak ketemu di TABEL_TK.");
            $this->assertNotNull($smc, "Spindle {$spindle} nggak ketemu di TABEL_SMC.");

            $fullscaleHarusnya = $tk * $smc * 10000.0 / $rpm;

            $this->assertEqualsWithDelta(
                $fullscaleHarusnya,
                $this->profil->fullscale($model, $spindle, $rpm),
                1e-9,
                "Fullscale {$model}/{$spindle}/{$rpm} rpm meleset.",
            );

            // MPE = 1 % × Fullscale + 1 % × rata-rata pembacaan
            $mpeHarusnya = 0.01 * $fullscaleHarusnya + 0.01 * abs($rataRata);

            $this->assertEqualsWithDelta(
                $mpeHarusnya,
                $this->profil->toleransiTitik(
                    1000.0,
                    $rataRata,
                    new \App\Models\Equipment,
                    ['tk' => $model, 'spindle' => $spindle, 'rpm' => $rpm],
                ),
                1e-9,
                "MPE {$model}/{$spindle}/{$rpm} rpm meleset.",
            );
        }
    }

    /**
     * MPE tidak pernah dikarang saat bahannya tidak lengkap.
     *
     * Tanpa spindle atau RPM, Fullscale tidak punya arti — dan toleransi yang
     * dikarang berarti vonis PASS/FAIL yang dikarang.
     */
    public function test_tanpa_spindle_atau_rpm_toleransi_null(): void
    {
        $alat = new \App\Models\Equipment;

        $this->assertNull($this->profil->toleransiTitik(1000.0, 900.0, $alat, [
            'tk' => 'HA', 'spindle' => null, 'rpm' => 62.0,
        ]));
        $this->assertNull($this->profil->toleransiTitik(1000.0, 900.0, $alat, [
            'tk' => 'HA', 'spindle' => 'HA2', 'rpm' => null,
        ]));
        $this->assertNull($this->profil->toleransiTitik(1000.0, 900.0, $alat, [
            'tk' => null, 'spindle' => 'HA2', 'rpm' => 62.0,
        ]));
        // RPM nol: pembagi nol, bukan Fullscale tak hingga.
        $this->assertNull($this->profil->toleransiTitik(1000.0, 900.0, $alat, [
            'tk' => 'HA', 'spindle' => 'HA2', 'rpm' => 0.0,
        ]));
        // Spindle yang nggak ada di tabel — salah ketik jangan jadi angka.
        $this->assertNull($this->profil->toleransiTitik(1000.0, 900.0, $alat, [
            'tk' => 'HA', 'spindle' => 'HA99', 'rpm' => 62.0,
        ]));
    }

    /**
     * Agregasi GUM untuk budget yang angkanya bukan dari master.
     *
     * `uc` dan `veff` dihitung ulang di sini dengan rumus bakunya:
     *
     *   uc   = √( Σ (ci·ui)² )
     *   veff = uc⁴ / Σ ( (ci·ui)⁴ / vi )        (Welch-Satterthwaite)
     */
    public function test_agregasi_gum_benar_untuk_budget_sembarang(): void
    {
        $kasus = [
            [
                ['u' => 0.0847, 'ci' => 1.0, 'vi' => 200],
                ['u' => 0.0289, 'ci' => 1.0, 'vi' => 1_000_000],
                ['u' => 0.2086, 'ci' => 0.0413, 'vi' => 50],
                ['u' => 0.2290, 'ci' => 1.0, 'vi' => 4],
            ],
            [
                ['u' => 12.5, 'ci' => 1.0, 'vi' => 200],
                ['u' => 0.0289, 'ci' => 1.0, 'vi' => 1_000_000],
                ['u' => 0.2086, 'ci' => 53.3, 'vi' => 50],
                ['u' => 88.1, 'ci' => 1.0, 'vi' => 2],
            ],
            [
                ['u' => 0.001, 'ci' => 1.0, 'vi' => 200],
                ['u' => 0.0029, 'ci' => 1.0, 'vi' => 1_000_000],
                ['u' => 0.2086, 'ci' => 0.0004, 'vi' => 50],
                ['u' => 0.0007, 'ci' => 1.0, 'vi' => 9],
            ],
        ];

        foreach ($kasus as $i => $komponen) {
            $jumlahKuadrat = 0.0;
            $penyebut = 0.0;

            foreach ($komponen as $k) {
                $kontribusi = $k['ci'] * $k['u'];
                $jumlahKuadrat += $kontribusi ** 2;
                $penyebut += $kontribusi ** 4 / $k['vi'];
            }

            $ucHarusnya = sqrt($jumlahKuadrat);
            $veffHarusnya = $ucHarusnya ** 4 / $penyebut;

            $r = $this->gum->agregasiBudget($komponen);

            $this->assertEqualsWithDelta(
                $ucHarusnya,
                $r['ketidakpastian_gabungan'],
                1e-12,
                "uc kasus ke-{$i} meleset.",
            );
            $this->assertEqualsWithDelta(
                $veffHarusnya,
                $r['derajat_kebebasan_efektif'],
                1e-9,
                "veff kasus ke-{$i} meleset.",
            );
        }
    }

    /**
     * `uc` tidak pernah lebih kecil dari komponen terbesarnya, dan tidak
     * pernah lebih besar dari jumlah seluruh komponen.
     *
     * Dua batas yang berlaku untuk penjumlahan kuadrat apa pun — dijaga
     * dengan angka acak supaya bukan cuma tiga kasus pilihan yang lolos.
     */
    public function test_uc_selalu_di_antara_komponen_terbesar_dan_jumlahnya(): void
    {
        mt_srand(20260819);

        for ($ulang = 0; $ulang < 200; $ulang++) {
            $komponen = [];
            $terbesar = 0.0;
            $jumlah = 0.0;

            for ($i = 0; $i < 4; $i++) {
                $u = mt_rand(1, 100000) / 1000.0;
                $ci = mt_rand(1, 5000) / 1000.0;
                $komponen[] = ['u' => $u, 'ci' => $ci, 'vi' => mt_rand(2, 500)];

                $kontribusi = $u * $ci;
                $terbesar = max($terbesar, $kontribusi);
                $jumlah += $kontribusi;
            }

            $uc = $this->gum->agregasiBudget($komponen)['ketidakpastian_gabungan'];

            $this->assertGreaterThanOrEqual($terbesar - 1e-9, $uc);
            $this->assertLessThanOrEqual($jumlah + 1e-9, $uc);
        }
    }

    /**
     * Pita pembacaan menerima yang sah dan menolak geseran titik desimal, di
     * ketiga titik — termasuk pembacaan yang jauh dari nominal karena alatnya
     * memang belum di-adjust.
     */
    public function test_pita_pembacaan_nolak_geseran_desimal_bukan_alat_meleset(): void
    {
        $kasus = [
            // [titik, pembacaan, harus diterima?]
            [99.65, 96.7, true],      // sesi master
            [99.65, 52.0, true],      // suhu tinggi, masih sah
            [99.65, 130.0, true],     // suhu rendah, masih sah
            [99.65, 967.0, false],    // 10× — geseran desimal
            [99.65, 9.67, false],     // 0,1×
            [1018.0, 916.3, true],
            [1018.0, 9163.0, false],
            [59003.0, 63181.3, true],
            [59003.0, 19259.0, true], // batas bawah tabel, sah
            [59003.0, 631813.0, false],
            [59003.0, 6318.0, false],
        ];

        foreach ($kasus as [$titik, $pembacaan, $diterima]) {
            $pita = $this->profil->pitaPembacaan($titik);

            $this->assertNotNull($pita, "Titik {$titik} nggak punya pita.");

            $dalamPita = $pembacaan >= $pita['min'] && $pembacaan <= $pita['maks'];
            $rasio = $pembacaan / $titik;
            $dalamRasio = $rasio >= $pita['rasio_min'] && $rasio <= $pita['rasio_maks'];

            $this->assertSame(
                $diterima,
                $dalamPita && $dalamRasio,
                "Pembacaan {$pembacaan} di titik {$titik} salah vonis.",
            );
        }
    }

    /**
     * Sesi UTUH dengan angka yang bukan punya master — hasil akhirnya dihitung
     * ulang di dalam test, dari rumusnya, tanpa satu pun angka disalin.
     *
     * ## Kenapa ini yang paling menentukan
     *
     * Test lain di berkas ini menguji POTONGAN: interpolasi, MPE, agregasi GUM.
     * Tiap potongan bisa benar sendiri-sendiri sementara rangkaiannya salah —
     * salah urutan, salah yang dipakai jadi masukan potongan berikutnya, atau
     * satu potongan diam-diam memakai nilai master alih-alih nilai sesi ini.
     *
     * Di sini seluruh rantai dijalankan sekaligus lewat `hitungTitik()`, dan
     * SEMUA keluarannya — nilai acuan, rata-rata, koreksi, uc, k, U95, MPE,
     * vonis — diadu ke angka yang test ini hitung sendiri dari nol:
     *
     *   nilai acuan  = interpolasi linier tabel sertifikat pada suhu sesi
     *   U95 standar  = nilai acuan x u_persen pada suhu itu
     *   uc           = akar jumlah kuadrat empat komponen
     *   U95          = maks(CMC rentangnya, 2 x uc)
     *   MPE          = 1% UUT + 1% (TK x SMC x 10000 / RPM)
     *   vonis        = |koreksi| + U95 <= MPE ? PASS : FAIL
     *
     * Lima sesi, dan tidak satu pun menyentuh angka master: suhu di ruas tabel
     * yang berbeda-beda, spindle & RPM di luar yang pernah dipakai lab, jumlah
     * pengulangan 2 sampai 5, alat yang membaca DI BAWAH standar (koreksi
     * positif), dan satu alat rusak yang harus jatuh FAIL.
     *
     * @param  list<float>  $pembacaan
     */
    #[DataProvider('sesiSembarang')]
    public function test_hasil_akhir_benar_untuk_input_apa_pun(
        int $titikKe,
        array $pembacaan,
        float $suhuLarutan,
        string $spindle,
        float $rpm,
        string $vonisDiharap,
    ): void {
        $hasil = $this->gum->hitungTitik(
            $titikKe,
            self::NOMINAL[$titikKe],
            $pembacaan,
            $this->alatBerkemampuan(),
            $this->standarKe($titikKe),
            $suhuLarutan,
            25.0,
            ['tk' => 'DV2THA', 'spindle' => $spindle, 'rpm' => $rpm],
        );

        $harap = $this->hitungTangan($titikKe, $pembacaan, $suhuLarutan, $spindle, $rpm);

        $this->assertEqualsWithDelta($harap['titik_ukur'], $hasil['titik_ukur'], 1e-8, 'nilai acuan');
        $this->assertEqualsWithDelta($harap['rata_rata'], $hasil['rata_rata'], 1e-9, 'rata-rata');
        $this->assertEqualsWithDelta($harap['koreksi'], $hasil['koreksi'], 1e-8, 'koreksi');
        $this->assertEqualsWithDelta($harap['uc'], $hasil['ketidakpastian_gabungan'], 1e-8, 'uc');
        $this->assertEqualsWithDelta(2.0, $hasil['faktor_cakupan_k'], 1e-12, 'k');
        $this->assertEqualsWithDelta($harap['u95'], $hasil['ketidakpastian_diperluas'], 1e-7, 'U95');
        $this->assertEqualsWithDelta($harap['mpe'], $hasil['toleransi'], 1e-8, 'MPE');
        $this->assertSame($vonisDiharap, $hasil['keputusan'], 'vonis');
    }

    /** @return array<string, array{int, list<float>, float, string, float, string}> */
    public static function sesiSembarang(): array
    {
        return [
            // Ruas tabel 25-37,78 degC, spindle & RPM yang tidak pernah dipakai
            // lab, tiga pengulangan.
            'titik 1000 di 30,5 degC, HA3 @ 30 rpm, 3 ulang' => [
                2, [742.1, 740.8, 743.6], 30.5, 'HA3', 30.0, 'PASS',
            ],
            // Ruas 20-25 degC, kemiringannya jauh lebih curam.
            'titik 100 di 22,0 degC, HA1 @ 100 rpm, 5 ulang' => [
                1, [119.4, 118.9, 119.8, 119.1, 119.5], 22.0, 'HA1', 100.0, 'PASS',
            ],
            // Alat membaca DI BAWAH standar: koreksinya positif, arah yang
            // tidak pernah muncul di sesi master.
            'titik 60000 di 25 degC, alat baca rendah' => [
                3, [58100.0, 58150.0, 58090.0, 58120.0], 25.0, 'HA7', 50.0, 'PASS',
            ],
            // Jumlah pengulangan paling sedikit yang masih bisa dihitung.
            //
            // RPM-nya 30, bukan 60, dan itu bukan angka asal: Fullscale =
            // TK x SMC x 10000 / RPM, jadi RPM setengahnya bikin MPE dua kali
            // lebih longgar (35,98 vs 22,64 cP). Pada 60 rpm sesi ini FAIL —
            // |koreksi| 21,59 + U95 2,47 = 24,06 lewat dari 22,64 — dan itu
            // vonis yang benar. Yang mau diuji di sini jalur n=2, bukan
            // vonisnya, jadi batasnya dibikin cukup buat lolos.
            'titik 1000 di 26,4 degC, cuma 2 ulang' => [
                2, [930.5, 931.2], 26.4, 'HA2', 30.0, 'PASS',
            ],
            // Alat rusak: melenceng jauh tapi masih dalam satu orde, jadi
            // penjaga orde di HP meloloskannya dan yang memvonis MPE.
            'titik 100 di 25 degC, alat rusak -> FAIL' => [
                1, [88.2, 87.9, 88.6, 88.1, 88.4], 25.0, 'HA1', 60.0, 'FAIL',
            ],
        ];
    }

    /** Nominal @25 degC tiap titik, dipakai sebagai `titik_ukur` yang dikirim. */
    private const NOMINAL = [1 => 99.65, 2 => 1018.0, 3 => 59003.0];

    /**
     * Seluruh rantai dihitung ulang DI SINI, dari rumusnya. Tidak ada satu
     * angka pun yang datang dari `GumCalculator`.
     *
     * @param  list<float>  $pembacaan
     * @return array{titik_ukur: float, rata_rata: float, koreksi: float, uc: float, u95: float, mpe: float}
     */
    private function hitungTangan(
        int $titikKe,
        array $pembacaan,
        float $suhu,
        string $spindle,
        float $rpm,
    ): array {
        $tabel = self::TABEL_STANDAR[$titikKe];

        // Nilai acuan IKUT suhu sesi — itu yang digeser tabel sertifikat.
        $nilai = $this->interpolasi($tabel, $suhu, 1);

        // Tapi komponen ketidakpastiannya diambil di titik NOMINAL (25 °C),
        // bukan digeser ikut suhu. Itu cara master menghitung, dan sengaja
        // ditiru: `U95% Standar` sesi master 0,169405 cP = 99,65 x 0,17 %,
        // yaitu angka pada 25 °C, padahal sesinya diukur pada 26,52 °C. Sama
        // untuk `ci` pengaruh suhu — master menulis `(uT/400) x 99,65`, bukan
        // dikali nilai yang sudah digeser.
        //
        // Ini ditulis eksplisit di sini karena gampang ditebak terbalik: dua
        // dari lima sesi di bawah lolos walaupun ditebak salah, karena suhunya
        // pas di baris tabel sehingga nominal dan nilai tergeser kebetulan sama.
        $nominal = self::NOMINAL[$titikKe];
        $uPersenNominal = $this->interpolasi($tabel, 25.0, 2);

        $n = count($pembacaan);
        $rata = array_sum($pembacaan) / $n;

        // STDEV sampel (pembagi n-1), lalu Type A = s / akar(n).
        $jumlahKuadrat = 0.0;
        foreach ($pembacaan as $p) {
            $jumlahKuadrat += ($p - $rata) ** 2;
        }
        $s = $n > 1 ? sqrt($jumlahKuadrat / ($n - 1)) : 0.0;
        $uA = $s / sqrt($n);

        $uT = sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2);

        $u = [
            ($nominal * $uPersenNominal / 100) / 2,     // sertifikat kalibrator
            (0.1 / 2) / sqrt(3),                        // daya baca alat
            ($uT / sqrt(3)) * (($uT / 400) * $nominal), // pengaruh temperature
            $uA,                                        // pengulangan
        ];

        $uc = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $u)));

        // Lantai CMC: rentang mana yang memuat nilai acuan ini.
        $cmc = 0.0;
        foreach (self::RENTANG_CMC as [$min, $maks, $nilaiCmc]) {
            if ($nilai >= $min && $nilai <= $maks) {
                $cmc = $nilaiCmc;
                break;
            }
        }

        $u95 = max($cmc, 2 * $uc);

        $fullscale = 2 * self::SMC[$spindle] * 10000 / $rpm;
        $mpe = 0.01 * $rata + 0.01 * $fullscale;

        return [
            'titik_ukur' => $nilai,
            'rata_rata' => $rata,
            'koreksi' => $nilai - $rata,
            'uc' => $uc,
            'u95' => $u95,
            'mpe' => $mpe,
        ];
    }

    /** Interpolasi linier kolom ke-`$kolom` tabel sertifikat pada `$suhu`. */
    private function interpolasi(array $tabel, float $suhu, int $kolom): float
    {
        for ($i = 0; $i < count($tabel) - 1; $i++) {
            [$t1, , ] = $tabel[$i];
            [$t2, , ] = $tabel[$i + 1];

            if ($suhu >= $t1 && $suhu <= $t2) {
                $a = $tabel[$i][$kolom];
                $b = $tabel[$i + 1][$kolom];

                return $a + ($b - $a) * (($suhu - $t1) / ($t2 - $t1));
            }
        }

        self::fail("Suhu {$suhu} di luar tabel — sesi uji harus di dalam jangkauan.");
    }

    private const SMC = ['HA1' => 1, 'HA2' => 4, 'HA3' => 10, 'HA7' => 400];

    /**
     * Sama persis dengan `ViscometerCapabilitySeeder`, URUT sama: tiga rentang
     * terakreditasi dulu, baru empat rentang tanpa klaim (di bawah, dua celah
     * antar larutan, dan di atas). Urutannya menentukan — titik yang pas di
     * batas nyangkut ke baris terakreditasi, bukan ke baris tanpa klaim.
     */
    private const RENTANG_CMC = [
        [51.1, 102.0, 0.2],
        [419.5, 1028.0, 2.1],
        [19259.0, 58021.0, 140.0],
        [58021.0, 95192.0, 0.0],
        [6.47, 51.1, 0.0],
        [102.0, 419.5, 0.0],
        [1028.0, 19259.0, 0.0],
    ];

    private const TABEL_STANDAR = [
        1 => [
            [20.0, 134.0, 0.17], [25.0, 99.65, 0.17], [37.78, 51.1, 0.15], [40.0, 45.97, 0.15],
            [50.0, 29.75, 0.13], [60.0, 20.32, 0.13], [80.0, 10.75, 0.13], [98.89, 6.638, 0.08],
            [100.0, 6.47, 0.08],
        ],
        2 => [
            [20.0, 1504.0, 0.23], [25.0, 1018.0, 0.23], [37.78, 419.5, 0.19], [40.0, 364.6, 0.19],
            [50.0, 203.2, 0.19], [60.0, 121.3, 0.17], [80.0, 51.27, 0.15], [98.89, 26.8, 0.13],
            [100.0, 25.9, 0.13],
        ],
        3 => [
            [20.0, 95192.0, 0.23], [25.0, 59003.0, 0.23], [37.78, 19259.0, 0.23], [40.0, 16096.0, 0.23],
            [50.0, 7489.0, 0.22], [60.0, 3732.0, 0.22], [80.0, 1117.0, 0.2], [98.89, 432.7, 0.17],
            [100.0, 411.3, 0.17],
        ],
    ];

    private function standarKe(int $ke): Standard
    {
        $tabel = self::TABEL_STANDAR[$ke];

        return new Standard([
            'nama' => "Viscosity Standard Solution {$ke}",
            'ketidakpastian' => $tabel[1][1] * $tabel[1][2] / 100,
            'satuan_ketidakpastian' => 'cP',
            'faktor_cakupan' => 2,
            'koefisien_suhu' => [
                'tabel' => array_map(
                    static fn (array $b): array => ['suhu' => $b[0], 'nilai' => $b[1], 'u_persen' => $b[2]],
                    $tabel,
                ),
            ],
        ]);
    }

    private function alatBerkemampuan(): Equipment
    {
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::create([
            'organization_id' => $org->id, 'kode' => 'instrumen-analitik', 'nama' => 'Instrumen Analitik',
        ]);

        foreach (self::RENTANG_CMC as [$min, $maks, $cmc]) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Viscometer',
                'parameter' => "viskositas (cP)-{$min}",
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => 'cP',
                'ketidakpastian_terbaik' => $cmc,
                'satuan_ketidakpastian' => 'cP',
                'faktor_cakupan' => 2,
                'metode' => ViscometerProfile::KODE_METODE,
                'u_temperature' => sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2),
            ]);
        }

        $alat = new Equipment([
            'nama_alat' => 'Viscometer',
            'nama_alat_kemampuan' => 'Viscometer',
            'satuan' => 'cP',
            'resolusi' => 0.1,
            'toleransi' => null,
        ]);
        $alat->equipment_category_id = $kategori->id;

        return $alat;
    }
}
