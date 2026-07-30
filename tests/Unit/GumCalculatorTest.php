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

        // Pembacaan rapat: Type A kecil banget dibanding CMC, jadi hasilnya
        // praktis sama kayak CMC apa adanya — inilah kasus normal sehari-hari.
        $rapat = $this->gum->hitungTitik(1, 4.00, [4.01, 4.01, 4.01], $alat, $this->standar());

        $this->assertEqualsWithDelta(0.02343221, $rapat['ketidakpastian_diperluas'], 1e-9);
        $this->assertSame('cmc_kemampuan_kalibrasi', $rapat['type_b_components'][0]['sumber']);
    }

    /**
     * Pembacaan yang BERSERAK harus bikin U95 ikut membesar.
     *
     * Sampai 29 Juli 2026 jalur CMC ngelaporin CMC apa adanya dan Type A cuma
     * disimpen "buat QC" — jadi U95 di sertifikat sama persis buat sebaran
     * serapat apa pun maupun seberantakan apa pun. Itu klaim presisi yang
     * nggak pernah terjadi: CMC nyakup kemampuan LAB, bukan perilaku alat
     * pelanggan yang lagi dikalibrasi (ILAC-P14).
     */
    public function test_sebaran_pembacaan_yang_lebar_bikin_u95_ikut_membesar(): void
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

        // Satu pembacaan meleset jauh (5.0 di antara 4.04) — elektroda mau mati.
        $hasil = $this->gum->hitungTitik(1, 4.009244572, [4.04, 4.04, 4.04, 5.0, 4.04], $alat, $this->standar());

        // Type A di sini ~0.19; U95 wajib ikut kebawa, bukan tetap di CMC.
        $this->assertGreaterThan(0.1, $hasil['type_a']);
        $this->assertGreaterThan(
            0.3,
            $hasil['ketidakpastian_diperluas'],
            'U95 mesti nyerminin sebaran yang beneran keukur, bukan CMC doang.',
        );

        // CMC tetap kecatat sebagai komponen Type B-nya.
        $this->assertSame('cmc_kemampuan_kalibrasi', $hasil['type_b_components'][0]['sumber']);
        $this->assertEqualsWithDelta(0.02343221 / 2, $hasil['type_b'], 1e-9);
    }

    /** U95 nggak boleh lebih kecil dari CMC, walau pembacaannya mulus sempurna. */
    public function test_u95_dilantai_ke_cmc_walau_semua_pembacaan_identik(): void
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

        // Type A = 0 persis. Tanpa lantai, U95 bakal jatuh ke 0 dan lab ngeklaim
        // lebih baik dari kemampuan terakreditasinya sendiri.
        $hasil = $this->gum->hitungTitik(1, 4.00, [4.01, 4.01, 4.01, 4.01, 4.01], $alat, $this->standar());

        $this->assertEqualsWithDelta(0.0, $hasil['type_a'], 1e-12);
        $this->assertEqualsWithDelta(0.02343221, $hasil['ketidakpastian_diperluas'], 1e-9);
    }

    /**
     * Regresi: `nama_alat` "pH Meter" bisa punya DUA baris CalibrationCapability
     * buat titik bulat yang sama — satu generik dibulatkan 3 desimal (dari
     * lampiran akreditasi, `range_min` null, konvensi `CalibrationCapabilitySeeder`)
     * dan satu presisi 8 desimal (dari perhitungan GUM sendiri, `range_min ==
     * range_max`, konvensi `PhMeterCapabilitySeeder`) — persis kejadian nyata di
     * data pH Meter PT Sidik. Baris presisi WAJIB menang, bukan baris generik
     * yang kebetulan lebih dulu ke-insert.
     */
    public function test_baris_cmc_presisi_menang_dibanding_baris_cmc_generik_buat_titik_sama(): void
    {
        $kategori = EquipmentCategory::factory()->create(['kode' => 'instrumen-analitik']);

        // Generik dulu yang dibikin (id lebih kecil) — kalau kodenya salah,
        // `first()` polos bakal milih ini duluan.
        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter',
            'range_min' => null,
            'range_max' => 4,
            'satuan' => 'pH',
            'ketidakpastian_terbaik' => 0.023,
            'satuan_ketidakpastian' => 'pH',
            'faktor_cakupan' => 2,
        ]);

        CalibrationCapability::create([
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'pH Meter',
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

        $hasil = $this->gum->hitungTitik(1, 4.009244572, [4.0, 4.0, 4.0], $alat, $this->standar());

        $this->assertEqualsWithDelta(0.02343221, $hasil['ketidakpastian_diperluas'], 1e-12);
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

        $hasil = $this->gum->hitungTitik(1, 50.0, [50.01, 50.00, 50.01], $jangkaSorong, $this->standar());

        // Yang diuji di sini: BARIS CMC MANA yang kepilih. Titik 50mm jatuh di
        // rentang Sieve (45-4000, U=4.0mm) maupun Caliper (0-300, U=0.015mm);
        // `nama_alat_kemampuan` yang bikin nggak ambigu.
        //
        // Dicek lewat `type_b` (= CMC/k), bukan `ketidakpastian_diperluas`:
        // U95 sekarang ikut sebaran pembacaan (lihat
        // test_sebaran_pembacaan_yang_lebar_bikin_u95_ikut_membesar), jadi
        // ngunci U95 ke angka tetap di sini bakal nguji dua hal sekaligus dan
        // pecah tiap kali sebaran contohnya diubah.
        $this->assertSame('cmc_kemampuan_kalibrasi', $hasil['type_b_components'][0]['sumber']);
        $this->assertEqualsWithDelta(0.015 / 2, $hasil['type_b'], 1e-12);
        $this->assertStringContainsString(
            'Vernier Caliper',
            $hasil['type_b_components'][0]['keterangan'],
        );
    }
}
