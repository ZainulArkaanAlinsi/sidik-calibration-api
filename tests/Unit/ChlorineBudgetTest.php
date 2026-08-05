<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\ChlorineProfile;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Budget ketidakpastian Chlorin Meter, dicocokin ke `Master Olah Data_Chlorine
 * Meter.xlsm` (sheet `PERHITUNGAN U95%`). Angka acuan DISALIN dari workbook,
 * bukan dari keluaran kode — kalau engine-nya bener, dia reproduksi persis.
 *
 * **5 komponen** per titik — satu lebih banyak dari Turbidimeter:
 *   1. Sertifikat kalibrator : u = U95%_std / 2                          (vi 200)
 *   2. Daya baca alat        : u = (0,01/2)/√3                           (vi 1e6)
 *   3. Temperature           : u = UTemp/2 ; ci = (UTemp/400)·titik      (vi 200)
 *   4. Faktor pengenceran    : u = Uc_peng/2 ; ci = 0,00028720439420210824 (vi 200)
 *   5. Pengulangan (Type A)  : u = stdev/√5                              (vi 4)
 *
 *   UTemperature   = √((0,72/2)² + (0,06/2)²) = 0,36124783736376886
 *   Uc pengenceran = √((0,00089/2)² + (0,033/2)²)/1000 = 1,650599966678783e-05 L
 *
 * Bedanya dari Turbidimeter yang gampang ketuker: komponen suhunya di sini
 * `normal ÷2`, di Turbidimeter `persegi ÷√3`. Ikut yang salah, uc-nya meleset.
 */
class ChlorineBudgetTest extends TestCase
{
    // `bentukLembarKerja()` nembak tabel `standards` buat nautin baris STANDARD.
    use RefreshDatabase;

    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gum = new GumCalculator;
    }

    private function uTemperature(): float
    {
        return sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2);
    }

    /**
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(float $uStd95, float $titik, float $stdev): array
    {
        $uTemp = $this->uTemperature();
        $ucPeng = ChlorineProfile::ucPengenceran();

        return [
            ['u' => $uStd95 / 2, 'ci' => 1.0, 'vi' => 200],
            ['u' => (0.01 / 2) / sqrt(3), 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => $uTemp / 2, 'ci' => ($uTemp / 400) * $titik, 'vi' => 200],
            ['u' => $ucPeng / 2, 'ci' => ChlorineProfile::CI_PENGENCERAN, 'vi' => 200],
            ['u' => $stdev / sqrt(5), 'ci' => 1.0, 'vi' => 4],
        ];
    }

    public function test_konstanta_kepala_sheet_reproduksi_workbook(): void
    {
        // Dua angka ini dipakai SEMUA titik; kalau salah, semua titik meleset.
        $this->assertEqualsWithDelta(0.36124783736376886, $this->uTemperature(), 1e-15);
        $this->assertEqualsWithDelta(1.650599966678783e-05, ChlorineProfile::ucPengenceran(), 1e-18);
    }

    public function test_titik_174_reproduksi_workbook(): void
    {
        // stdev [1.76,1.76,1.76,1.76,1.75] = 0.004472135954999583 (sel PERHITUNGAN G44).
        $r = $this->gum->agregasiBudget($this->komponen(0.09, 1.74, 0.004472135954999583));

        // Sel "Ketidakpastian Baku Gabungan, Uc" kolom titik 1,74.
        $this->assertEqualsWithDelta(0.045137721442932245, $r['ketidakpastian_gabungan'], 1e-12);
        $this->assertEqualsWithDelta(1.971777384669986, $r['faktor_cakupan_k'], 1e-6);
        $this->assertEqualsWithDelta(0.08900153833670729, $r['ketidakpastian_diperluas'], 1e-9);
    }

    /**
     * Titik 1,83 pembacaannya seragam 1,86 semua → STDEV 0 → Type A 0.
     *
     * Sheet workbook di kolom ini nulis ui = 0,0244949 buat baris pengulangan,
     * yang NGGAK ngikut dari pembacaannya sendiri (STDEV 0 mestinya Type A 0) —
     * kelihatannya sel ketinggalan. Yang dites di sini hasil dari pembacaan
     * BENERAN, jadi sengaja beda dari sel "Uc" workbook (0,03883841457600245).
     * Empat komponen sisanya tetap dicocokin ke workbook.
     */
    public function test_titik_183_type_a_nol_karena_pembacaannya_seragam(): void
    {
        $r = $this->gum->agregasiBudget($this->komponen(0.06, 1.83, 0.0));

        // √(0,03² + 0,002886751345948129² + 0,00029851875²) = 0,030140...
        $this->assertEqualsWithDelta(0.03014010, $r['ketidakpastian_gabungan'], 1e-7);

        // Dan ini yang penting: HASILNYA TETAP DI BAWAH CMC 0,08, jadi yang
        // dilaporkan di sertifikat CMC-nya — beda angka uc nggak ngubah apa pun
        // yang dilihat pelanggan.
        $this->assertLessThan(0.08, $r['ketidakpastian_diperluas']);
    }

    public function test_ci_suhu_turunan_titik_cocok_dua_titik(): void
    {
        $profil = new ChlorineProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => $this->uTemperature()]);
        $equipment = new Equipment(['satuan' => 'mg/L', 'resolusi' => 0.01]);

        foreach ([[1.74, 0.0015714280925323944], [1.83, 0.0016527088559392426]] as [$titik, $ciHarapan]) {
            $standard = new Standard(['nama' => 'Chlorine', 'ketidakpastian' => 0.09, 'faktor_cakupan' => 2]);
            $komponen = $profil->komponenBudget($kemampuan, $equipment, $standard, $titik, 0.002, 5);

            $suhu = collect($komponen)->firstWhere('sumber', 'ketidakpastian_temperature');
            $this->assertEqualsWithDelta($ciHarapan, $suhu['ci'], 1e-15, "ci suhu titik $titik");

            // Divisor suhunya ÷2 (normal), BUKAN ÷√3 kayak Turbidimeter.
            $this->assertEqualsWithDelta($this->uTemperature() / 2, $suhu['u'], 1e-15);
        }
    }

    public function test_budget_punya_lima_komponen_termasuk_pengenceran(): void
    {
        $profil = new ChlorineProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => $this->uTemperature()]);
        $standard = new Standard(['nama' => 'Chlorine', 'ketidakpastian' => 0.09, 'faktor_cakupan' => 2]);
        $equipment = new Equipment(['satuan' => 'mg/L', 'resolusi' => 0.01]);

        $komponen = $profil->komponenBudget($kemampuan, $equipment, $standard, 1.74, 0.002, 5);

        $this->assertCount(5, $komponen);
        $this->assertSame([
            'ketidakpastian_standar',
            'resolusi_alat',
            'ketidakpastian_temperature',
            'faktor_pengenceran',
            'pengulangan_pembacaan',
        ], array_column($komponen, 'sumber'));

        $peng = collect($komponen)->firstWhere('sumber', 'faktor_pengenceran');
        $this->assertEqualsWithDelta(8.252999833393915e-06, $peng['u'], 1e-18);
        $this->assertEqualsWithDelta(2.3702978175e-09, $peng['u'] * $peng['ci'], 1e-18);
    }

    public function test_tanpa_u_temperature_jatuh_ke_jalur_cmc_bukan_ngarang(): void
    {
        $profil = new ChlorineProfile;
        $kemampuan = new CalibrationCapability(['u_temperature' => null]);
        $standard = new Standard(['nama' => 'Chlorine', 'ketidakpastian' => 0.09, 'faktor_cakupan' => 2]);
        $equipment = new Equipment(['satuan' => 'mg/L', 'resolusi' => 0.01]);

        $this->assertNull(
            $profil->komponenBudget($kemampuan, $equipment, $standard, 1.74, 0.002, 5),
        );
    }

    /**
     * Lampiran akreditasi nulis "Chlorin Meter" (tanpa 'e'), lembar kerjanya
     * "Chlorine Meter". Registry mesti nemu profilnya dari nama lampiran —
     * kalau meleset, alatnya diam-diam jatuh ke profil pH (default) dan
     * dihitung pakai budget yang salah tanpa ada yang error.
     */
    public function test_registry_nemu_profil_dari_nama_lampiran(): void
    {
        $registry = new CalibrationProfileRegistry;

        $this->assertInstanceOf(ChlorineProfile::class, $registry->untukNamaAlat('Chlorin Meter'));
        $this->assertInstanceOf(ChlorineProfile::class, $registry->untukKode('chlorine_meter'));
    }

    /**
     * Titiknya 1,74 & 1,83 — BUKAN 0,40 & 4,00 yang tercetak di lembar kerja
     * Rev.2. Alasannya di docblock `ChlorineProfile`; ringkasnya lembar cetaknya
     * ketinggalan set standar dan yang mengikat itu lampiran akreditasi.
     * Kalau ada yang "mbenerin" ini ngikut kertasnya, test ini yang teriak.
     */
    public function test_titik_ikut_lampiran_akreditasi_bukan_yang_tercetak(): void
    {
        $nilai = array_column(ChlorineProfile::TITIK, 'nilai');

        $this->assertSame([1.74, 1.83], $nilai);
        $this->assertNotContains(0.40, $nilai);
        $this->assertNotContains(4.00, $nilai);
    }

    /**
     * **Regresi: titik rapat ketuker kemampuannya.**
     *
     * Dua titik chlorine cuma 0,09 apart — DI BAWAH `MAX_DRIFT_TITIK_TUNGGAL`
     * (0,1) punya `GumCalculator`. Waktu matcher-nya masih `->first(...)`,
     * titik 1,83 nyangkut ke kemampuan 1,74 dan kepasang CMC 0,091, padahal
     * punya dia 0,08. Nggak ada error — cuma angka ketidakpastian yang salah
     * di sertifikat berakreditasi.
     *
     * pH (4/7/10) & Turbidimeter (1/100/1000) nggak pernah mancing ini karena
     * titiknya berjauhan. Chlorine alat pertama yang titiknya lebih rapat dari
     * toleransi matcher-nya sendiri.
     */
    public function test_titik_rapat_dapat_cmc_nya_sendiri_bukan_tetangganya(): void
    {
        $org = Organization::factory()->create();
        $kategori = EquipmentCategory::create([
            'organization_id' => $org->id,
            'kode' => 'instrumen-analitik',
            'nama' => 'Instrumen Analitik',
        ]);

        foreach ([[1.74, 0.091], [1.83, 0.08]] as [$titik, $cmc]) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Chlorin Meter',
                'parameter' => 'Chlorine',
                'range_min' => $titik,
                'range_max' => $titik,
                'satuan' => 'mg/L',
                'ketidakpastian_terbaik' => $cmc,
                'satuan_ketidakpastian' => 'mg/L',
                'faktor_cakupan' => 2,
                'metode' => 'SIDIK-IK-CAL-0524',
                'u_temperature' => $this->uTemperature(),
            ]);
        }

        $equipment = new Equipment([
            'nama_alat' => 'Chlorine Meter',
            'nama_alat_kemampuan' => 'Chlorin Meter',
            'satuan' => 'mg/L',
            'resolusi' => 0.01,
            'toleransi' => 0.15,
        ]);
        $equipment->equipment_category_id = $kategori->id;

        $standard = new Standard([
            'nama' => 'Chlorine Standar Cuvettes 1.83 mg/L',
            'ketidakpastian' => 0.06,
            'faktor_cakupan' => 2,
        ]);

        $hasil = (new GumCalculator)->hitungTitik(
            2, 1.83, [1.86, 1.86, 1.86, 1.86, 1.86], $equipment, $standard,
        );

        // 0,08 = CMC titik 1,83. Kalau ini balik 0,091, matcher-nya balik
        // ngambil tetangganya lagi.
        $this->assertEqualsWithDelta(0.08, $hasil['ketidakpastian_diperluas'], 1e-9);
    }

    /**
     * Resolusi seragam → `resolusi`/`desimal` per baris NGGAK ikut dikirim.
     * Mobile baca `null` sebagai "seragam"; ngirim angka bikin dia mad per
     * baris padahal nggak perlu. Ada test kembarannya di sisi mobile.
     */
    public function test_tabel_hasil_nggak_bawa_resolusi_per_baris(): void
    {
        $bentuk = (new ChlorineProfile)->bentukLembarKerja();
        $hasil = collect($bentuk['bagian'])->firstWhere('kode', 'hasil');
        $baris = $hasil['tabel'][0]['baris'];

        $this->assertSame([1.74, 1.83], array_column($baris, 'titik_ukur'));
        $this->assertArrayNotHasKey('resolusi', $baris[0]);
        $this->assertArrayNotHasKey('desimal', $baris[0]);
        $this->assertNull((new ChlorineProfile)->desimalTitik(1.74));
    }
}
