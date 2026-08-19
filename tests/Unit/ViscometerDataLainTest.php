<?php

namespace Tests\Unit;

use App\Models\Standard;
use App\Services\Calibration\Profiles\ViscometerProfile;
use App\Services\GumCalculator;
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
}
