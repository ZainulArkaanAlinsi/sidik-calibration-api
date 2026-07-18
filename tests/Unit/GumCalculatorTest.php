<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use App\Services\GumCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Angka-angka di sini dihitung tangan, bukan disalin dari keluaran kodenya —
 * kalau test-nya cuma ngunci apa pun yang kebetulan keluar, dia nggak bisa
 * nangkep rumus yang salah.
 *
 * Kasus acuan (dipakai berulang di bawah):
 *   titik ukur 50 mm, pembacaan 50.02 / 50.01 / 50.03
 *   alat     : resolusi 0.01 mm
 *   standar  : U = 0.0004 mm, k = 2
 *
 *   rata-rata = 50.02                          error = +0.02
 *   s         = 0.01            (pembagi n-1)  u_a   = 0.01/√3   = 0.0057735
 *   u(standar)= 0.0004/2        = 0.0002
 *   u(resolusi)=(0.01/2)/√3     = 0.0028868
 *   u_b       = √(0.0002² + 0.0028868²)        = 0.0028937
 *   u_c       = √(0.0057735² + 0.0028937²)     = 0.0064581
 *   U         = 2 × u_c                        = 0.0129161
 *
 * Kalau salah satu angka di atas berubah, JANGAN samain testnya ke keluaran
 * kode — hitung ulang tangannya dulu, karena bisa jadi kodenya yang salah.
 */
class GumCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gum = new GumCalculator;
    }

    private function alat(float $toleransi = 0.05, ?float $resolusi = 0.01): Equipment
    {
        return new Equipment([
            'nama_alat' => 'Jangka Sorong',
            'satuan' => 'mm',
            'resolusi' => $resolusi,
            'toleransi' => $toleransi,
        ]);
    }

    private function standar(?float $ketidakpastian = 0.0004, float $k = 2, ?float $drift = null): Standard
    {
        return new Standard([
            'nama' => 'Gauge Block Set Grade 0',
            'ketidakpastian' => $ketidakpastian,
            'satuan_ketidakpastian' => 'mm',
            'faktor_cakupan' => $k,
            'drift' => $drift,
        ]);
    }

    /** @return array<string, mixed> */
    private function hitungKasusAcuan(float $toleransi = 0.05): array
    {
        return $this->gum->hitungTitik(1, 50.0, [50.02, 50.01, 50.03], $this->alat($toleransi), $this->standar());
    }

    public function test_type_a_dihitung_dari_standar_deviasi_sampel_bukan_populasi(): void
    {
        $hasil = $this->hitungKasusAcuan();

        // Pembagi n-1 (=2) ngasih s=0.01. Kalau kodenya salah pakai pembagi n
        // (=3), s-nya jadi 0.00816 dan semua angka di bawahnya ikut meleset.
        $this->assertEqualsWithDelta(0.01, $hasil['standar_deviasi'], 1e-9);
        $this->assertEqualsWithDelta(0.0057735026, $hasil['type_a'], 1e-9);
        $this->assertSame(3, $hasil['jumlah_pengulangan']);
    }

    public function test_ketidakpastian_standar_dibagi_balik_sama_faktor_cakupannya(): void
    {
        $hasil = $this->hitungKasusAcuan();

        $komponen = collect($hasil['type_b_components'])->firstWhere('sumber', 'ketidakpastian_standar');

        // Angka di sertifikat standar (0.0004) itu ketidakpastian DIPERLUAS, udah
        // dikali k=2. Yang boleh digabung ke Type B cuma versi bakunya: 0.0002.
        // Kalau dipakai mentah, seluruh U jadi kegedean dan alat sehat ikut FAIL.
        $this->assertEqualsWithDelta(0.0002, $komponen['nilai'], 1e-12);
    }

    public function test_resolusi_alat_pakai_setengah_lebar_dan_distribusi_persegi(): void
    {
        $hasil = $this->hitungKasusAcuan();

        $komponen = collect($hasil['type_b_components'])->firstWhere('sumber', 'resolusi_alat');

        // (0.01 / 2) / √3 — setengah resolusi, bukan resolusi penuh.
        $this->assertEqualsWithDelta(0.0028867513, $komponen['nilai'], 1e-9);
        $this->assertSame('persegi', $komponen['distribusi']);
    }

    public function test_gabungan_dan_diperluas_sesuai_hitungan_tangan(): void
    {
        $hasil = $this->hitungKasusAcuan();

        $this->assertEqualsWithDelta(0.0028936712, $hasil['type_b'], 1e-8);
        $this->assertEqualsWithDelta(0.0064580699, $hasil['ketidakpastian_gabungan'], 1e-8);
        $this->assertEqualsWithDelta(2.0, $hasil['faktor_cakupan_k'], 1e-9);
        $this->assertEqualsWithDelta(0.0129161398, $hasil['ketidakpastian_diperluas'], 1e-8);
    }

    public function test_koreksi_itu_lawan_dari_error(): void
    {
        $hasil = $this->hitungKasusAcuan();

        // Alat baca kelebihan 0.02 → koreksi yang dicetak di sertifikat -0.02.
        $this->assertEqualsWithDelta(0.02, $hasil['error'], 1e-9);
        $this->assertEqualsWithDelta(-0.02, $hasil['koreksi'], 1e-9);
    }

    public function test_lulus_kalau_error_plus_u_masih_masuk_toleransi(): void
    {
        // |0.02| + 0.0129 = 0.0329 ≤ 0.05
        $this->assertSame('PASS', $this->hitungKasusAcuan(toleransi: 0.05)['keputusan']);
    }

    public function test_guarded_acceptance_menolak_alat_yang_lulus_versi_simple(): void
    {
        $hasil = $this->hitungKasusAcuan(toleransi: 0.03);

        // Ini inti keputusan lab 14 Jul. Simple acceptance bakal bilang PASS
        // (|error| 0.02 ≤ toleransi 0.03), tapi begitu ketidakpastiannya ikut
        // dihitung — 0.02 + 0.0129 = 0.0329 — hasilnya nyebrang batas.
        $this->assertSame('FAIL', $hasil['keputusan']);
        $this->assertGreaterThan(
            $hasil['toleransi'],
            abs($hasil['error']) + $hasil['ketidakpastian_diperluas'],
        );
    }

    public function test_drift_standar_ikut_nambah_ketidakpastian_kalau_diisi(): void
    {
        $tanpaDrift = $this->gum->hitungTitik(1, 50.0, [50.02, 50.01, 50.03], $this->alat(), $this->standar());
        $denganDrift = $this->gum->hitungTitik(
            1, 50.0, [50.02, 50.01, 50.03], $this->alat(), $this->standar(drift: 0.0002),
        );

        $this->assertGreaterThan(
            $tanpaDrift['ketidakpastian_diperluas'],
            $denganDrift['ketidakpastian_diperluas'],
        );
        $this->assertCount(3, $denganDrift['type_b_components']);
    }

    public function test_derajat_kebebasan_efektif_disimpen_buat_jejak_audit(): void
    {
        $hasil = $this->hitungKasusAcuan();

        // Welch–Satterthwaite: u_c⁴ / (u_a⁴ / (n-1))
        //   = 1.7394460e-09 / (1.1111111e-09 / 2) ≈ 3.1310
        $this->assertEqualsWithDelta(3.1310, $hasil['derajat_kebebasan_efektif'], 1e-3);
    }

    public function test_alat_tanpa_resolusi_tetap_kehitung_cuma_komponennya_kurang_satu(): void
    {
        $hasil = $this->gum->hitungTitik(
            1, 50.0, [50.02, 50.01, 50.03], $this->alat(resolusi: null), $this->standar(),
        );

        $this->assertCount(1, $hasil['type_b_components']);
        $this->assertEqualsWithDelta(0.0002, $hasil['type_b'], 1e-12);
    }

    /**
     * Kasus pH: kategori alatnya punya CalibrationCapability (CMC) buat titik
     * ukur ini, jadi ketidakpastian yang dilaporkan = CMC-nya langsung, BUKAN
     * kombinasi Type A+B kayak kasus acuan di atas — walaupun pembacaannya
     * sengaja dibikin nyebar jauh (STDEV gede).
     */
    public function test_titik_yang_punya_kemampuan_kalibrasi_pakai_cmc_bukan_gabungan_type_ab(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);

        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter',
            'parameter' => 'pH',
            'range_min' => 4,
            'range_max' => 4,
            'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.02343221,
            'satuan_ketidakpastian' => 'pH',
            'faktor_cakupan' => 2,
        ]);

        $alat = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'nama_alat_kemampuan' => 'pH Meter',
            'satuan' => 'pH',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        // Pembacaan sengaja nyebar jauh (STDEV ~0.43) — kalau kodenya salah
        // masih makan jalur Type A+B generik, U bakal jauh lebih gede dari CMC.
        $hasil = $this->gum->hitungTitik(1, 4.009244572, [4.04, 4.04, 4.04, 5.0, 4.04], $alat, $this->standar());

        $this->assertEqualsWithDelta(0.02343221, $hasil['ketidakpastian_diperluas'], 1e-12);
        $this->assertEqualsWithDelta(2.0, $hasil['faktor_cakupan_k'], 1e-9);
        $this->assertNull($hasil['derajat_kebebasan_efektif']);
        $this->assertSame('cmc_kemampuan_kalibrasi', $hasil['type_b_components'][0]['sumber']);

        // Type A tetap kehitung & disimpen (QC), walaupun bukan yang dilaporkan.
        $this->assertGreaterThan(0.1, $hasil['type_a']);
    }

    /**
     * Regresi: round(3.5) kebetulan jadi 4, sama kayak titik nominal buffer
     * pH 4 — tapi 3.5 itu titik ukur yang BENERAN beda, bukan "buffer 4 yang
     * cerdiknya geser dikit" (drift asli di data pH cuma 0.009-0.021, jauh di
     * bawah 0.5). Kejadian nyata pas GumCalculator sempat kena bug ini: titik
     * 3.5 pH ikut kepasangin CMC buffer 4 padahal harusnya jalur generik.
     */
    public function test_titik_yang_geser_jauh_dari_titik_nominal_nggak_ikut_kepasangin_cmc(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);

        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter',
            'parameter' => 'pH',
            'range_min' => 4,
            'range_max' => 4,
            'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.02343221,
            'satuan_ketidakpastian' => 'pH',
            'faktor_cakupan' => 2,
        ]);

        $alat = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'nama_alat_kemampuan' => 'pH Meter',
            'satuan' => 'pH',
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        // round(3.5) == 4.0, tapi selisihnya (0.5) jauh ngelewatin
        // MAX_DRIFT_TITIK_TUNGGAL (0.1) — HARUS balik ke jalur generik.
        $hasil = $this->gum->hitungTitik(1, 3.5, [3.51, 3.52, 3.50], $alat, $this->standar());

        $this->assertSame('ketidakpastian_standar', $hasil['type_b_components'][0]['sumber']);
    }

    public function test_titik_tanpa_kemampuan_kalibrasi_tetap_pakai_jalur_generik(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);
        $alat = Equipment::factory()->create([
            'equipment_category_id' => $kategori->id,
            'resolusi' => 0.01,
            'toleransi' => 0.05,
        ]);

        // Nggak ada CalibrationCapability yang match titik ukur ini — harus
        // balik ke jalur Type A+B lama, sama persis kasus acuan di atas.
        $hasil = $this->gum->hitungTitik(1, 50.0, [50.02, 50.01, 50.03], $alat, $this->standar());

        $this->assertEqualsWithDelta(0.0129161398, $hasil['ketidakpastian_diperluas'], 1e-8);
        $this->assertSame('ketidakpastian_standar', $hasil['type_b_components'][0]['sumber']);
    }

    /**
     * Regresi: satu equipment_category_id (mis. "Panjang") nampung banyak
     * JENIS alat beda (Sieve, Micrometer, Vernier Caliper) yang rentang
     * kemampuannya suka tumpang tindih. Alat yang BELUM di-link lewat
     * `nama_alat_kemampuan` HARUS tetap balik ke jalur generik — bukan
     * nebak-nebak dari kategori + rentang doang (itu yang dulu kejadian:
     * jangka sorong 0.05mm toleransi kepasangin CMC Sieve 4mm gara-gara
     * sama-sama "Panjang" dan sama-sama nyakup 50mm). Kasus "udah di-link
     * dan kepasangin yang bener" ada di test setelah ini.
     */
    public function test_alat_yang_belum_dilink_di_kategori_ambigu_nggak_ketuker_cmc(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'panjang']);

        // Dua alat BEDA JENIS di kategori yang SAMA, rentangnya nyerempet:
        // Sieve nyakup 45-4000mm (CMC gede, buat ukur mesh), Vernier Caliper
        // nyakup 0-300mm (CMC kecil). Titik ukur 50mm masuk ke dua-duanya.
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Sieve', 'range_min' => 45, 'range_max' => 4000,
            'satuan' => 'mm', 'ketidakpastian_terbaik' => 4.0,
            'satuan_ketidakpastian' => 'mm', 'faktor_cakupan' => 2,
        ]);
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Vernier Caliper', 'range_min' => 0, 'range_max' => 300,
            'satuan' => 'mm', 'ketidakpastian_terbaik' => 0.015,
            'satuan_ketidakpastian' => 'mm', 'faktor_cakupan' => 2,
        ]);

        // nama_alat_kemampuan SENGAJA nggak diisi — alat belum di-link.
        $jangkaSorong = Equipment::factory()->create([
            'nama_alat' => 'Jangka Sorong Mitutoyo',
            'equipment_category_id' => $kategori->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $hasil = $this->gum->hitungTitik(1, 50.0, [50.02, 50.01, 50.03], $jangkaSorong, $this->standar());

        $this->assertSame('ketidakpastian_standar', $hasil['type_b_components'][0]['sumber']);
        $this->assertEqualsWithDelta(0.0129161398, $hasil['ketidakpastian_diperluas'], 1e-8);
    }

    /**
     * Begitu `nama_alat_kemampuan` diisi ('Vernier Caliper'), matching-nya
     * nggak ambigu lagi — titik 50mm boleh jatuh di rentang Sieve MAUPUN
     * Caliper, tapi karena alatnya udah dinyatain "Vernier Caliper", cuma
     * baris kemampuan punya Vernier Caliper yang dilirik. Ini generalisasi
     * yang sebenernya: rentang kontinyu (bukan cuma titik tunggal kayak pH)
     * sekarang aman dipakai karena jenis alatnya udah eksplisit.
     */
    public function test_alat_yang_udah_dilink_pakai_cmc_rentang_kontinyu_yang_bener(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'panjang']);

        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Sieve', 'range_min' => 45, 'range_max' => 4000,
            'satuan' => 'mm', 'ketidakpastian_terbaik' => 4.0,
            'satuan_ketidakpastian' => 'mm', 'faktor_cakupan' => 2,
        ]);
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Vernier Caliper', 'range_min' => 0, 'range_max' => 300,
            'satuan' => 'mm', 'ketidakpastian_terbaik' => 0.015,
            'satuan_ketidakpastian' => 'mm', 'faktor_cakupan' => 2,
        ]);

        $jangkaSorong = Equipment::factory()->create([
            'nama_alat' => 'Jangka Sorong Mitutoyo',
            'nama_alat_kemampuan' => 'Vernier Caliper',
            'equipment_category_id' => $kategori->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        // Pembacaan sengaja nyebar jauh — kalau kodenya salah masih makan
        // jalur generik, U bakal jauh lebih gede dari CMC Caliper (0.015mm).
        $hasil = $this->gum->hitungTitik(1, 50.0, [50.5, 49.5, 50.0], $jangkaSorong, $this->standar());

        $this->assertSame('cmc_kemampuan_kalibrasi', $hasil['type_b_components'][0]['sumber']);
        $this->assertEqualsWithDelta(0.015, $hasil['ketidakpastian_diperluas'], 1e-12);
    }
}
