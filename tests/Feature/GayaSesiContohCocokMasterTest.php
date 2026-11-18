<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\MengumpulkanGagalPerProfil;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka yang TERCETAK di sertifikat gaya diadu ke sertifikat master.
 *
 * ## Kenapa test ini ada padahal sudah ada test unit DAN sapuan sertifikat
 *
 * `GayaCalculatorTest` mengunci rantai hitungnya, `GayaBudgetTest` mengunci
 * budgetnya — keduanya berhenti di nilai antara.
 * `SertifikatSemuaAlatSatuHalamanTest` merender PDF-nya, tapi yang diperiksa
 * cuma dia muat satu halaman. Di antara keduanya ada celah selebar seluruh
 * tabel hasil: **kolom mana yang mendarat di mana, dan berapa angka di
 * belakang koma.**
 *
 * Celah itu bukan hipotetis. Jalan pertama test ini, 24 Sep 2026, menemukan
 * kedua sesi gaya mencetak `Standard Value` dan `Unit Under Test` **bertukar
 * tempat**, dan yang mendarat di `Standard Value` dibagi faktor satuan sekali
 * lagi — titik 100 kgf tercetak `10193,7`. Sebabnya
 * `CertificateSnapshotBuilder` mengambil `standard_value` dari `titik_ukur`
 * selama `nilaiStandarDariKoreksi()` masih `false`, sementara di alat gaya
 * `titik_ukur` menyimpan set point dalam satuan alat. PDF-nya tetap muat satu
 * halaman, `phpunit` tetap hijau, dan nol error terbit.
 *
 * Karena itu yang diadu di sini **snapshot sertifikat**, bukan kolom
 * `uncertainty_calculations`. Snapshot itu yang dibekukan dan dicetak; kolom
 * mentahnya cuma bahan.
 *
 * ## Yang dijaga
 *
 * Kesepuluh titik Load Cell dan keenam titik UTM, tiga kolom masing-masing,
 * PLUS bentuk bulatnya. Angka pembandingnya disalin dari `SERTIFIKAT.csv`
 * kedua workbook — bukan dari keluaran kode ini.
 *
 * ## Tambahan PK-5 (18 Nov 2026): TEKS cetak renderer asli
 *
 * Yang di atas berhenti di snapshot. `test_angka_cetak_tiga_alat_gaya_...` menerbitkan ketiga sesi
 * contoh (Load Cell, UTM, Proving Ring) lewat `POST /approve`, merender `sertifikat.pdf` asli, dan
 * mengadu TEKS yang tercetak ke teks lembar `SERTIFIKAT` master (`kelompok-b.json`, dicocokkan ke
 * `Range.Text` Excel). Titik nol tidak tercetak karena master menyembunyikan barisnya (Load Cell
 * baris 21, UTM 21, Proving Ring 22) — itulah jawaban rekonsiliasi "0/7 baris identik" di audit
 * 16 Nov: bukan beda angka, tetapi baris tersembunyi yang ikut terbaca.
 *
 * Toleransi nilai TERSIMPAN di metode itu (angka cetak dibandingkan persis): 6e-9 lewat
 * `assertTersimpanSama` untuk semua yang dibandingkan ke sel master di kolom `decimal(20,8)`
 * (`Correction` = sel x 0,00981, U95 UTM, `k` Proving Ring; MySQL menyimpan 8 desimal, selisih
 * sah terbesar 5e-9); 1e-8 untuk U95/k yang DIPATOK dari aplikasi (bukan dari master);
 * 1e-12 untuk komponen drift, yang tersimpan di JSON (15 digit signifikan, nilai < 1).
 */
class GayaSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use MengumpulkanGagalPerProfil;
    use RefreshDatabase;

    /** Beda yang masih diterima pada nilai penuh. Master menyimpan double. */
    private const TOLERANSI = 5e-6;

    /**
     * `SERTIFIKAT.csv` Load Cell, baris 16..25 — kolom E, L, Q (kN).
     *
     * Urutan titiknya 0, 100, lalu 2..9 kN. Itu memang urutan masternya, dan
     * sertifikatnya mencetak dalam urutan itu juga.
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function masterLoadCell(): array
    {
        return [
            [-0.0019613300000000003, 0.0, -0.0019613300000000003],
            [99.877416875, 100.0, -0.12258312500000557],
            [2.1530386700000004, 2.0, 0.15303867000000038],
            [3.4705386700000007, 3.0, 0.4705386700000007],
            [4.05803867, 4.0, 0.05803867000000018],
            [5.02053867, 5.0, 0.020538669999999648],
            [5.95053867, 6.0, -0.04946132999999975],
            [6.863038669999999, 7.0, -0.136961330000001],
            [7.870538669999998, 8.0, -0.1294613300000016],
            [8.91053867, 9.0, -0.08946133000000067],
        ];
    }

    /**
     * `SERTIFIKAT.csv` UTM, baris 16..21 — kolom E, L, Q (kgf).
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function masterUtm(): array
    {
        return [
            [0.0, 0.0, 0.0],
            [100.37602628174375, 100.0, 0.37602628174374786],
            [200.7852728972789, 200.0, 0.7852728972789009],
            [300.70213452561035, 300.0, 0.7021345256103473],
            [400.41035757182976, 400.0, 0.41035757182976],
            [500.5508158311059, 500.0, 0.5508158311059],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Angka CETAK (teks renderer asli) ketiga alat gaya = teks master, dan hasil tersimpan.
     *
     * Semua baris tabel hasil cocok di ketiganya. Dua hal yang dikunci sebagai selisih (bukan
     * disetel supaya hijau):
     *
     * - **Proving Ring U95**: tercetak 2,3 kgf, master 22,4 kgf (G14: master membaca pita CMC dari
     *   baris yang salah). Jejak sesinya (`penyimpangan_master`) belum mencatat G14 (PK5-2).
     * - **Drift standar Load Cell & UTM (PK5-1)**: `drift_standar` master `PERHITUNGAN U95%!N13` =
     *   `VLOOKUP(Z3; Tabel_Drift; 2; 0)`, dan `Tabel_Drift` = `STANDAR_LOADCELL!Q5:R7` — kolom
     *   "Tekan" (R). Kolom "Tarik" (S) tidak pernah dibaca master. Aplikasi memakai kolom sesuai
     *   arah (Tarik) untuk Load Cell & UTM. Load Cell: drift 0,034272 (S6) lawan 0,031834 (R6)
     *   -> U 0,29761 lawan 0,29734 kN dan k 2,0796 lawan 2,0860; UTM: 0 (S5) lawan 0,069282 (R5)
     *   -> k 1,97166 lawan 1,96986. Tidak tampak di cetak (0,30 / 2,1 / k = 2) dan tidak tercatat
     *   di jejak sesi.
     */
    public function test_angka_cetak_tiga_alat_gaya_sama_dengan_master_kecuali_yang_tercatat(): void
    {
        // Yang diuji di sini angka/cetak sertifikat, bukan penahanan TM (itu `ProfilDitahanTest`):
        // daftar `profil_ditahan` dikosongkan supaya sesi contoh bisa diterbitkan.
        config(['sertifikat.profil_ditahan' => []]);
        $this->freezeTime();
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $this->periksaSemuaProfil([
            'gaya/load_cell' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('DEMO-LC-001');
                $this->assertCetakSamaMaster('gaya/load_cell', $html, 11);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(10, $hitung, 'Load Cell: sepuluh titik tersimpan (titik nol tersimpan, tidak dicetak).');
                $this->assertDriftDanHasil(
                    $hitung,
                    drift: 0.034272153479393656, masterDrift: 0.031834452342817454,
                    u: 0.29761028637804, masterU: PembandingCetakMaster::nilaiSel('gaya/load_cell', 'P35'),
                    k: 2.0796138447276, masterK: PembandingCetakMaster::nilaiSel('gaya/load_cell', 'T36'),
                );
                $this->assertKoreksiTersimpan($hitung, 'gaya/load_cell', barisTitik2: 22, faktorKeKn: 1.0);
            },
            'gaya/utm' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('DEMO-UTM-001');
                $this->assertCetakSamaMaster('gaya/utm', $html, 7);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(6, $hitung);
                // U95 tersimpan dalam kN = lantai CMC 2,1 kgf x 0,00981 (master mencetak 2,1 kgf).
                $this->assertDriftDanHasil(
                    $hitung,
                    drift: 0.0, masterDrift: 0.0692820323027551,
                    u: 2.1 * 0.00981, masterU: null,
                    k: 1.9716608894927, masterK: PembandingCetakMaster::nilaiSel('gaya/utm', 'T36'),
                );
                $this->assertKoreksiTersimpan($hitung, 'gaya/utm', barisTitik2: 22, faktorKeKn: 0.00981);
                $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('gaya/utm', 'P35') * 0.00981, $hitung[0]->ketidakpastian_diperluas, 'UTM: U95 tersimpan = 2,1 kgf master dalam kN.');
            },
            'gaya/proving_ring' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('DEMO-PR-001');
                $this->assertCetakSamaMaster('gaya/proving_ring', $html, 11, [
                    38 => ['G14: master 22,4 kgf (pita CMC 0,22 kN dari baris yang salah); aplikasi 2,3 kgf (0-500 kgf).', ['2.3']],
                ]);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(10, $hitung);
                $this->assertKoreksiTersimpan($hitung, 'gaya/proving_ring', barisTitik2: 23, faktorKeKn: 0.00981);

                foreach ($hitung as $h) {
                    $this->assertEqualsWithDelta(0.022563, (float) $h->ketidakpastian_diperluas, 1e-8, "Proving Ring titik {$h->titik_ke}: U95 tersimpan = CMC 2,3 kgf dalam kN (master 0,22 kN).");
                    $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('gaya/proving_ring', 'U39'), $h->faktor_cakupan_k, "Proving Ring titik {$h->titik_ke}: k tersimpan");
                    $this->assertNull($h->keputusan, 'Proving Ring: master tidak mencetak vonis; aplikasi pun tidak.');
                }
            },
        ]);
    }

    /**
     * `Correction` TERSIMPAN (kolom `koreksi`, kN) sama dengan sel `Q` master — termasuk yang tidak
     * tampak di teks cetak karena cetak diturunkan dari kolom lain. Titik 1 (beban nol) baris master
     * tersembunyi, jadi dilewati; Proving Ring menyimpan faktor kalibrasi (kgf/div) dalam kN/div.
     *
     * @param  Collection<int, UncertaintyCalculation>  $hitung
     */
    private function assertKoreksiTersimpan(Collection $hitung, string $kunci, int $barisTitik2, float $faktorKeKn): void
    {
        foreach ($hitung as $i => $h) {
            if ($i === 0) {
                continue;
            }

            $this->assertTersimpanSama(
                PembandingCetakMaster::nilaiSel($kunci, 'Q'.($barisTitik2 + $i - 1)) * $faktorKeKn,
                $h->koreksi,
                "{$kunci} titik {$h->titik_ke}: Correction tersimpan",
            );
        }
    }

    /**
     * @param  Collection<int, UncertaintyCalculation>  $hitung
     */
    private function assertDriftDanHasil(Collection $hitung, float $drift, float $masterDrift, float $u, ?float $masterU, float $k, float $masterK): void
    {
        foreach ($hitung as $h) {
            $komponen = collect($h->type_b_components['komponen'] ?? [])->firstWhere('sumber', 'drift_standar');
            $this->assertNotNull($komponen, 'Komponen drift_standar tidak ada di jejak.');
            $this->assertEqualsWithDelta($drift, (float) $komponen['u'], 1e-12, "titik {$h->titik_ke}: drift standar yang dipakai aplikasi (kolom Tarik tabel standar)");
            $this->assertGreaterThan(1e-3, abs($masterDrift - (float) $komponen['u']), 'Selisih drift terhadap master (kolom Tekan, `VLOOKUP(...;2;0)`) hilang — hapus catatan PK5-1 bila ditutup.');
            $this->assertEqualsWithDelta($u, (float) $h->ketidakpastian_diperluas, 1e-8, "titik {$h->titik_ke}: U95 tersimpan");
            $this->assertEqualsWithDelta($k, (float) $h->faktor_cakupan_k, 1e-8, "titik {$h->titik_ke}: k tersimpan");
            $this->assertGreaterThan(1e-3, abs($masterK - (float) $h->faktor_cakupan_k), 'Selisih k terhadap master hilang — hapus catatan PK5-1 bila ditutup.');

            if ($masterU !== null) {
                $this->assertGreaterThan(1e-4, abs($masterU - (float) $h->ketidakpastian_diperluas), 'Selisih U95 terhadap master hilang — hapus catatan PK5-1 bila ditutup.');
            }

            $this->assertNull($h->keputusan, 'Master gaya tidak mencetak vonis; aplikasi pun tidak.');
        }
    }

    public function test_load_cell_kolom_sertifikat_cocok_master(): void
    {
        $this->adu('DEMO-LC-001', self::masterLoadCell(), 'kN', 2);
    }

    public function test_utm_kolom_sertifikat_cocok_master(): void
    {
        $this->adu('DEMO-UTM-001', self::masterUtm(), 'kgf', 1);
    }

    /**
     * Kolomnya tidak boleh tertukar, dan penjaganya bukan cuma angka.
     *
     * Di sesi contoh Load Cell titik 100 kN, `Standard Value` (99,877) dan
     * `Unit Under Test` (100) beda kurang dari 0,13 % — tertukar, keduanya
     * masih kelihatan wajar. Yang membuat pertukaran itu ketahuan justru
     * hubungan antar kolomnya: `Correction` HARUS sama dengan
     * `Standard Value − Unit Under Test`, dan itu berlaku di ketiga workbook
     * (`SERTIFIKAT!Q = E − L`).
     */
    public function test_correction_selalu_standard_dikurangi_uut(): void
    {
        foreach (['DEMO-UTM-001', 'DEMO-LC-001'] as $nomorSesi) {
            foreach ($this->snapshot($nomorSesi) as $baris) {
                $this->assertEqualsWithDelta(
                    $baris['standard_value'] - $baris['unit_under_test'],
                    $baris['correction'],
                    self::TOLERANSI,
                    "{$nomorSesi} titik {$baris['titik_ke']}: Correction bukan Standard Value − UUT. "
                    .'Tersangka pertama: kedua kolomnya tertukar.',
                );
            }
        }
    }

    /**
     * Dua alat gaya mencetak KOLOM lembar perhitungan yang berbeda.
     *
     * UTM mencetak `Z` (sesudah koreksi termal), Load Cell `Y` (sebelum).
     * Dua workbook, satu rumus, dua jawaban — G12. Kalau suatu saat lab
     * menyeragamkannya, yang merah duluan test ini, bukan sertifikat pelanggan.
     */
    public function test_kedua_alat_gaya_memakai_kolom_yang_berbeda(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        $this->assertTrue(
            $registry->untukKode('utm')?->pakaiKoreksiTermalDiSertifikat(),
            'UTM harus mencetak Z — sertifikat masternya 200,785 kgf, itu nilai sesudah koreksi termal.',
        );

        $this->assertFalse(
            $registry->untukKode('load_cell')?->pakaiKoreksiTermalDiSertifikat(),
            'Load Cell harus mencetak Y — sertifikat masternya 2,15303867 kN, itu nilai sebelum koreksi termal.',
        );
    }

    /**
     * Penyimpangan master yang DISENGAJA wajib kebaca dari jejak sesi.
     *
     * AGENTS.md §Olah data butir 4: "tulis selisihnya" pernah dibaca sebagai
     * komentar di kode — dan komentar tidak sampai ke orang yang menyetujui
     * sesi. Jadi yang dijaga di sini bukan ADA-nya penyimpangan, tapi bahwa
     * penyimpangannya terbaca tanpa membuka kode.
     */
    public function test_penyimpangan_master_terbaca_di_jejak_sesi(): void
    {
        $jejak = $this->hitunganSesi('DEMO-LC-001')->first()->type_b_components;

        $this->assertArrayHasKey('penyimpangan_master', $jejak);
        $penyimpangan = $jejak['penyimpangan_master'];

        $this->assertArrayHasKey('standard_value_hanya_cabang_kn', $penyimpangan);
        $this->assertStringContainsString('cabang satuan kN', $penyimpangan['standard_value_hanya_cabang_kn']);
        $this->assertStringContainsString('G12', $penyimpangan['standard_value_hanya_cabang_kn']);

        // Catatan `correction_memakai_y` cuma berlaku buat alat yang mencetak
        // Z. Muncul di sesi Load Cell, dia jadi peringatan yang salah alamat —
        // dan peringatan palsu melatih admin berhenti membacanya.
        $this->assertArrayNotHasKey('correction_memakai_y', $penyimpangan);

        $jejakUtm = $this->hitunganSesi('DEMO-UTM-001')->first()->type_b_components;
        $this->assertArrayHasKey('correction_memakai_y', $jejakUtm['penyimpangan_master']);
        $this->assertArrayNotHasKey('standard_value_hanya_cabang_kn', $jejakUtm['penyimpangan_master']);
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float}>  $master
     * @param  string  $satuan  satuan yang HARUS tercetak di tiap baris
     * @param  int  $desimal  angka di belakang koma yang HARUS dipakai
     */
    private function adu(string $nomorSesi, array $master, string $satuan, int $desimal): void
    {
        $hasil = $this->snapshot($nomorSesi);

        $this->assertCount(
            count($master),
            $hasil,
            "Jumlah titik {$nomorSesi} tidak sama dengan sertifikat master.",
        );

        foreach ($hasil as $i => $baris) {
            [$standarMaster, $uutMaster, $koreksiMaster] = $master[$i];
            $titik = $i + 1;

            $this->assertSame($satuan, $baris['satuan'],
                "{$nomorSesi} titik {$titik}: satuan cetak bukan `{$satuan}`.");
            $this->assertSame($desimal, $baris['desimal'],
                "{$nomorSesi} titik {$titik}: jumlah desimal cetak bukan {$desimal}.");

            $this->assertEqualsWithDelta($standarMaster, $baris['standard_value'], self::TOLERANSI,
                "{$nomorSesi} titik {$titik}: kolom Standard Value meleset dari master.");
            $this->assertEqualsWithDelta($uutMaster, $baris['unit_under_test'], self::TOLERANSI,
                "{$nomorSesi} titik {$titik}: kolom Unit Under Test meleset dari master.");
            $this->assertEqualsWithDelta($koreksiMaster, $baris['correction'], self::TOLERANSI,
                "{$nomorSesi} titik {$titik}: kolom Correction meleset dari master.");

            // Dan yang benar-benar dibaca pelanggan: bentuk bulatnya.
            $this->assertSame(
                round($standarMaster, $desimal),
                round((float) $baris['standard_value'], $desimal),
                "{$nomorSesi} titik {$titik}: angka CETAK Standard Value beda dari master di {$desimal} desimal.",
            );
            $this->assertSame(
                round($koreksiMaster, $desimal),
                round((float) $baris['correction'], $desimal),
                "{$nomorSesi} titik {$titik}: angka CETAK Correction beda dari master di {$desimal} desimal.",
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(string $nomorSesi): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        $snapshot = app(CertificateSnapshotBuilder::class)->bangun(
            $sesi,
            new Certificate(['nomor' => 'UJI-GAYA', 'qr_token' => 'uji-gaya']),
        );

        return $snapshot['hasil'];
    }

    /** @return Collection<int, UncertaintyCalculation> */
    private function hitunganSesi(string $nomorSesi): Collection
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        return $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
    }
}
