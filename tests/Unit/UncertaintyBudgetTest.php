<?php

namespace Tests\Unit;

use App\Services\GumCalculator;
use App\Services\StudentTDistribution;
use PHPUnit\Framework\TestCase;

/**
 * Budget ketidakpastian penuh gaya lampiran akreditasi pH.
 *
 * Angka acuan di sini DISALIN dari workbook asli PT Sidik (sheet
 * `PERHITUNGAN U95%`, lihat `Project-PT-Sidik/Master Olah Data_pH for trial_CSV/`)
 * — bukan dari keluaran kode. Tiga titik buffer pH 4/7/10 dari sertifikat
 * 012-CAL-524. Kalau engine-nya bener, dia reproduksi Uc, veff, dan U95%
 * sertifikat ini persis.
 *
 * Komponen tiap titik (u = ketidakpastian baku, ci = koef. sensitivitas, vi = dof):
 *   1. Sertifikat kalibrator : u = U95%_buffer / 2
 *   2. Daya baca alat        : u = (resolusi/2)/√3            (resolusi 0.01)
 *   3. Temperature           : u = UTemperature / 2 ; ci = koef@25°C
 *   4. Pengaruh perbedaan suhu: u = U_lab/√3 ; ci per titik
 *   5. Pengulangan (Type A)  : u = stdev/√5 ; vi = 4
 *
 *   UTemperature = √((0.72/2)² + (0.06/2)²) = 0.36124784  (thermo + sensor std)
 */
class UncertaintyBudgetTest extends TestCase
{
    private GumCalculator $gum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gum = new GumCalculator;
    }

    /** UTemperature gabungan dari sertifikat termometer (0.72°C) + sensor (0.06°C), k=2. */
    private function uTemperature(): float
    {
        return sqrt((0.72 / 2) ** 2 + (0.06 / 2) ** 2);
    }

    /**
     * @param  float  $uKalibrator95  U95% sertifikat buffer
     * @param  float  $ciSuhu  koef. sensitivitas suhu @25°C
     * @param  float  $uPerbedaan95  konstanta pengaruh perbedaan suhu (lab)
     * @param  float  $ciPerbedaan  koef. pengaruh perbedaan suhu
     * @param  float  $stdev  standar deviasi 5 pembacaan
     * @return list<array{u: float, ci: float, vi: float}>
     */
    private function komponen(
        float $uKalibrator95,
        float $ciSuhu,
        float $uPerbedaan95,
        float $ciPerbedaan,
        float $stdev,
    ): array {
        $sqrt3 = sqrt(3);

        return [
            ['u' => $uKalibrator95 / 2, 'ci' => 1.0, 'vi' => 200],
            ['u' => (0.01 / 2) / $sqrt3, 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => $this->uTemperature() / 2, 'ci' => $ciSuhu, 'vi' => 200],
            ['u' => $uPerbedaan95 / $sqrt3, 'ci' => $ciPerbedaan, 'vi' => 50],
            ['u' => $stdev / sqrt(5), 'ci' => 1.0, 'vi' => 4],
        ];
    }

    public function test_utemperature_gabungan_dari_dua_sertifikat(): void
    {
        // √(0.36² + 0.03²) = 0.36124784
        $this->assertEqualsWithDelta(0.36124784, $this->uTemperature(), 1e-8);
    }

    public function test_titik_ph4_reproduksi_budget_sertifikat(): void
    {
        // stdev 0 (5x baca 4.00 semua sesudah adjustment).
        $r = $this->gum->agregasiBudget($this->komponen(0.02, 0.00077, 0.01, 1.0, 0.0));

        $this->assertEqualsWithDelta(0.011903193270260157, $r['ketidakpastian_gabungan'], 1e-9);
        $this->assertEqualsWithDelta(277.96023159, $r['derajat_kebebasan_efektif'], 1e-4);
        // U95% = k·Uc. Sertifikat mencetak 0.023432 (6 desimal).
        $this->assertSame('0.023432', number_format($r['ketidakpastian_diperluas'], 6, '.', ''));
    }

    public function test_titik_ph7_reproduksi_budget_sertifikat(): void
    {
        // stdev 0.005477 (7.01,7.01,7.00,7.00,7.00).
        $r = $this->gum->agregasiBudget($this->komponen(0.02, 0.00352, 0.02, 0.00304, 0.005477225575051544));

        $this->assertEqualsWithDelta(0.010711619968364562, $r['ketidakpastian_gabungan'], 1e-9);
        $this->assertEqualsWithDelta(223.13211788, $r['derajat_kebebasan_efektif'], 1e-4);
        $this->assertSame('0.021109', number_format($r['ketidakpastian_diperluas'], 6, '.', ''));
    }

    public function test_titik_ph10_reproduksi_budget_sertifikat(): void
    {
        // stdev 0 (10.11 semua). Buffer 10 cert U95% = 0.03.
        $r = $this->gum->agregasiBudget($this->komponen(0.03, 0.01021, 0.05, 0.00949, 0.0));

        $this->assertEqualsWithDelta(0.015388610956781186, $r['ketidakpastian_gabungan'], 1e-9);
        $this->assertEqualsWithDelta(221.49458541, $r['derajat_kebebasan_efektif'], 1e-4);
        // U hitung 0.030327 < CMC 0.031 → yang DILAPORKAN CMC-nya (aturan max).
        // Di sini kita cek U hitungnya dulu; aturan max diuji di level hitungTitik.
        $this->assertEqualsWithDelta(0.030327, $r['ketidakpastian_diperluas'], 1e-5);
    }

    public function test_faktor_cakupan_dari_veff_bukan_dikunci_dua(): void
    {
        // veff ratusan → k harus DEKAT 2 tapi di atasnya, bukan persis 2.
        $r = $this->gum->agregasiBudget($this->komponen(0.02, 0.00077, 0.01, 1.0, 0.0));

        $this->assertGreaterThan(1.96, $r['faktor_cakupan_k']);
        $this->assertLessThan(2.0, $r['faktor_cakupan_k']);
    }

    /*
     * CATATAN: test U95% kondisi lingkungan yang dulu ada di sini DIBUANG waktu
     * budget ini di-port ke main.
     *
     * Dia manggil `GumCalculator::ketidakpastianLingkungan()`, dan di main
     * rumusnya udah pindah ke service `KondisiLingkungan` — bukan hilang, cuma
     * beda rumah.
     *
     * Cakupannya JUGA nggak hilang: `PerhitunganTest` udah ngunci angkanya
     * lewat endpoint lembar perhitungan, dan lebih ketat daripada test yang
     * dibuang ini — `u95_sertifikat` dicocokin ke 1.7117242768623688 (suhu) &
     * 5.660388679233963 (kelembaban) dengan delta 1e-9, lawan 1e-4 di sini.
     *
     * Ditulis eksplisit karena gampang salah kira: nyari `KondisiLingkungan`
     * di folder `tests/` nggak ngasih hasil apa-apa, padahal rumusnya keuji.
     * Yang diuji nilainya lewat endpoint, bukan nama kelasnya.
     */


    public function test_student_t_quantile_cocok_nilai_tabel(): void
    {
        $t = new StudentTDistribution;

        // Nilai tabel t dua-sisi 95% (p=0.975).
        $this->assertEqualsWithDelta(2.228139, $t->quantile(0.975, 10), 1e-4);
        $this->assertEqualsWithDelta(2.042272, $t->quantile(0.975, 30), 1e-4);
        // df → ∞ jatuh ke z 95% = 1.959964.
        $this->assertEqualsWithDelta(1.959964, $t->quantile(0.975, 1e9), 1e-4);
    }

    /**
     * Reproduksi angka lembar olah data manual lab, titik demi titik.
     *
     * Ini test paling penting di file ini: dia yang mbuktiin budget di kode
     * ngasih angka yang SAMA dengan yang dihitung lab pakai Excel-nya sendiri.
     * Kalau ada yang ngubah susunan komponen, pembagi, atau derajat kebebasan,
     * di sinilah ketahuannya — bukan waktu sertifikat udah dikirim ke pelanggan.
     *
     * Sumber: sheet `PERHITUNGAN U95%`, sertifikat 0558-CAL-525.
     * Toleransinya ketat (1e-9 buat uc) karena ini bukan angka hampiran —
     * rumusnya deterministik, jadi selisih sekecil apa pun berarti ada yang
     * geser.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: list<float>, 5: float, 6: float}>
     */
    public static function titikLembarManual(): array
    {
        // [uKalibrator95, ciSuhu, uPerbedaan95, ciPerbedaan, pembacaan, ucHarusnya, veffHarusnya]
        return [
            'pH 4' => [0.02, 0.00077, 0.01, 1.0, [4.01, 4.009, 4.01, 4.01, 4.009], 0.011553616928549545, 246.71502225955618],
            'pH 7' => [0.02, 0.00352, 0.02, 0.00304, [7.0, 6.999, 7.0, 7.0, 6.999], 0.010017033162901414, 201.36173725372464],
            'pH 10' => [0.03, 0.01021, 0.05, 0.00949, [10.002, 10.001, 9.998, 10.0, 10.002], 0.015078814688219583, 204.16236023814994],
        ];
    }

    /**
     * @param  list<float>  $pembacaan
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('titikLembarManual')]
    public function test_budget_reproduksi_lembar_manual_lab(
        float $uKalibrator95,
        float $ciSuhu,
        float $uPerbedaan95,
        float $ciPerbedaan,
        array $pembacaan,
        float $ucHarusnya,
        float $veffHarusnya,
    ): void {
        $n = count($pembacaan);
        $rata = array_sum($pembacaan) / $n;
        $stdev = sqrt(array_sum(array_map(fn (float $x): float => ($x - $rata) ** 2, $pembacaan)) / ($n - 1));

        // Komponennya disusun EKSPLISIT di sini, bukan lewat `komponen()` di
        // atas: helper itu buat lembar PT Tirta Gracia (termometer 0,72 °C,
        // alat resolusi 0,01), sementara angka acuan di test ini dari lembar
        // PT Magnum (termometer 0,5 °C, alat SI Analytics resolusi 0,001).
        // Dua lembar beda, dua alat beda — dicampur, hasilnya nggak reproducible
        // ke mana pun.
        $sqrt3 = sqrt(3);
        $uTemperature = sqrt((0.5 / 2) ** 2 + (0.06 / 2) ** 2);

        $hasil = $this->gum->agregasiBudget([
            ['u' => $uKalibrator95 / 2, 'ci' => 1.0, 'vi' => 200],
            ['u' => (0.001 / 2) / $sqrt3, 'ci' => 1.0, 'vi' => 1_000_000],
            ['u' => $uTemperature / 2, 'ci' => $ciSuhu, 'vi' => 200],
            ['u' => $uPerbedaan95 / $sqrt3, 'ci' => $ciPerbedaan, 'vi' => 50],
            ['u' => $stdev / sqrt($n), 'ci' => 1.0, 'vi' => $n - 1],
        ]);

        $this->assertEqualsWithDelta($ucHarusnya, $hasil['ketidakpastian_gabungan'], 1e-9);
        $this->assertEqualsWithDelta($veffHarusnya, $hasil['derajat_kebebasan_efektif'], 1e-6);

        // k dihitung dari veff, bukan dikunci 2. Lembar Excel-nya nulis
        // 1,9697/1,9718/1,9717 — punya kita beda ~3e-5 karena Excel pakai
        // hampiran tabel, sementara implementasi ini akurat ~1e-10 lawan nilai
        // t baku. Selisih segitu nggak kelihatan di presisi sertifikat, tapi
        // ditulis di sini biar yang berikutnya nggak ngira ada yang salah.
        $this->assertEqualsWithDelta(1.97, $hasil['faktor_cakupan_k'], 5e-3);
    }
}
