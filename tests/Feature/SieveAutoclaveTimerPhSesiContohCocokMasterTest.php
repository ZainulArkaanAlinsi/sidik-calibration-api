<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\MengumpulkanGagalPerProfil;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat Sieve (`0736-CAL-526`), Autoclave (`2406.51.S`), Timer/Stopwatch
 * (`015-CAL-424`), dan pH Meter (`2405.13.A`, master "pH for trial") diadu ke lembar `SERTIFIKAT`
 * masternya lewat jalur produksi. PK-5, 18 Nov 2026. Pembanding:
 * `tests/Fixtures/cetak-master/kelompok-b.json` (`docs/skrip/gen-cetak-master-kelompok-b.py`).
 *
 * ## Hasil
 *
 * - **pH**: SEMUA baris cocok (U95 per titik, `k`), dan U95/k tersimpan sama dengan sel master
 *   sampai 6e-9 (kuantum decimal(20,8)).
 * - **Sieve**: dua baris beda = Standard Indication Wrap 19,1487 dan Weft 19,1927 (master 19,1387
 *   dan 19,1827): `VLOOKUP(...; 3; 0)` master menunjuk kolom kosong, sistem menambah koreksi
 *   caliper +0,01 mm (`pertanyaan-lab-sieve.md` §4; jejak sesi `penyimpangan_master`).
 *   U95 tersimpan, `k`, dan vonis PASS sama dengan master.
 * - **Autoclave**: tiga baris beda = keputusan pemilik "titik waktu ke-5 ikut dirata-rata"
 *   (docblock `AutoclaveCalculator`; master membuang ke-5 dan menggandakan ke-3): Terukur disk 1
 *   121,39 -> 121,40, disk 2 121,47 -> 121,46, Koreksi 0,39/0,47 -> 0,40/0,46, Keseragaman
 *   0,47 -> 0,46. U95 suhu/tekanan dan `k` tersimpan sama dengan master. Temuan PK5-4: keputusan
 *   itu belum tercatat di hasil sesi (hanya di komentar kode).
 * - **Timer**: lima titik cocok. `k` master berformat General: Excel mencetak `1.9652`
 *   (`Range.Text`, COM), aplikasi `1.964` (tiga desimal, B3a); nilainya pun beda (1,96400 lawan
 *   1,96517). Penyebab nilai = DRIFT stopwatch, bukan uHRTB: master `Drift Stopwatch!K26` =
 *   MAX(K12:K25) = 298 ms, aplikasi 322 ms (kolom K master berrumus sebagian; keputusan
 *   `pertanyaan-lab-waktu-frekuensi.md` §3). Dihitung ulang penjaga angka dari sel master: menukar
 *   HANYA drift -> veff 587 (dari 589), k 1,964014; menukar HANYA uHRTB -> veff 459, k 1,965146.
 *   uc aplikasi 6,5 % lebih besar dari master (konservatif); U95 tercetak sama (lantai CMC 0,8).
 *   Yang masih terbuka hanya jumlah desimal `k` (PK5-6).
 *
 * ## Toleransi nilai TERSIMPAN (angka cetak selalu dibandingkan persis, tanpa toleransi)
 *
 * - 6e-9 (`assertTersimpanSama`): kolom `decimal(20,8)` — MySQL menyimpan 8 desimal, SQLite float
 *   penuh; selisih sah terbesar 5e-9. U95/k Sieve, pH, dan U95 Timer.
 * - 1e-9: `hasil_autoclave` (kolom JSON, 15 digit signifikan, nilai < 3).
 * - 1e-8: `k` titik 1 Timer yang DIPATOK dari aplikasi (bukan dari master).
 */
class SieveAutoclaveTimerPhSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use MengumpulkanGagalPerProfil;
    use RefreshDatabase;

    /** `Range.Text` Excel (COM) `SERTIFIKAT!V29` master Timer, 18 Nov 2026 — General, jadi lebar kolom yang menentukan. */
    private const K_TIMER_TEKS_MASTER = '1.9652';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSesiContoh();
    }

    public function test_angka_cetak_dan_hasil_tersimpan_sama_dengan_master_kecuali_yang_tercatat(): void
    {
        $this->periksaSemuaProfil([
            'ph/trial' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('2405.13.A');
                $this->assertCetakSamaMaster('ph/trial', $html, 4);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(3, $hitung, 'pH: tiga titik buffer.');

                foreach (['S21', 'S22', 'S23'] as $i => $sel) {
                    $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('ph/trial', $sel), $hitung[$i]->ketidakpastian_diperluas, 'pH titik '.($i + 1).': U95 tersimpan');
                }

                // Master mencetak SATU k (`U24`) — milik titik kedua.
                $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('ph/trial', 'U24'), $hitung[1]->faktor_cakupan_k, 'pH: k tersimpan');
            },

            'sieve/mesh' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('0736-CAL-526');
                $this->assertCetakSamaMaster('sieve/mesh', $html, 6, [
                    20 => ['§4: Wrap, koreksi caliper +0,01 mm (master VLOOKUP kolom kosong): 19,1387 -> 19,1487.', ['19.0000', '19.1487', '0.1487', '0.0262']],
                    21 => ['§4: Weft 19,1827 -> 19,1927.', ['19.0000', '19.1927', '0.1927', '0.0463']],
                ]);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(3, $hitung, 'Sieve: Wrap, Weft, Ø kawat.');
                $this->assertTrue($this->jejakMemuat($hitung, 'penyimpangan_master'), 'Sieve: koreksi +0,01 harus terbaca di jejak sesi.');

                foreach (['N20', 'N21', 'N22'] as $i => $sel) {
                    $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('sieve/mesh', $sel), $hitung[$i]->ketidakpastian_diperluas, 'Sieve parameter '.($i + 1).': U95 tersimpan');
                    $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('sieve/mesh', 'O23'), $hitung[$i]->faktor_cakupan_k, 'Sieve: k tersimpan');
                    // Vonis master `AE20:AE22` = PASS (kolom di luar area cetak, dibaca langsung dari master 18 Nov 2026).
                    $this->assertSame('PASS', $hitung[$i]->keputusan, 'Sieve parameter '.($i + 1).': vonis');
                }
            },

            'autoclave/autoclave' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('2406.51.S');
                $this->assertCetakSamaMaster('autoclave/autoclave', $html, 4, [
                    26 => ['PK5-4: titik ke-5 ikut dirata-rata — Terukur disk 1 121,39 -> 121,40, disk 2 121,47 -> 121,46.', ['121.00', '121.40', '121.46', '121.44', '0.44']],
                    27 => ['PK5-4: Koreksi 0,39/0,47 -> 0,40/0,46.', ['0.40', '0.46', '0.44']],
                    32 => ['PK5-4: Keseragaman 0,466 -> 0,464 (tercetak 0,47 -> 0,46).', ['121.00', '121.00', '0.46', '0.04', '0.10']],
                ]);

                $hasil = $sesi->hasil_autoclave;
                $this->assertIsArray($hasil, 'Autoclave: hasil tersimpan di hasil_autoclave.');

                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'U26'), (float) $hasil['suhu']['u95'], 1e-9, 'Autoclave: U95 suhu tersimpan');
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'P33'), (float) $hasil['suhu']['k'], 1e-9, 'Autoclave: k suhu tersimpan');
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'P39'), (float) $hasil['tekanan']['u95'], 1e-9, 'Autoclave: U95 tekanan tersimpan');
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'P43'), (float) $hasil['tekanan']['k'], 1e-9, 'Autoclave: k tekanan tersimpan');

                // Kestabilan dan Variasi sama dengan master; Keseragaman SENGAJA 0,464 (master 0,466).
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'L32'), (float) $hasil['suhu']['kestabilan'], 1e-9, 'Autoclave: Kestabilan');
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'O32'), (float) $hasil['suhu']['variasi'], 1e-9, 'Autoclave: Variasi');
                $this->assertEqualsWithDelta(0.464, (float) $hasil['suhu']['keseragaman'], 1e-9, 'Autoclave: Keseragaman 0,464 (keputusan pemilik)');
                $this->assertEqualsWithDelta(0.002, PembandingCetakMaster::nilaiSel('autoclave/autoclave', 'J32') - (float) $hasil['suhu']['keseragaman'], 1e-9, 'Autoclave: selisih Keseragaman terhadap master (0,466) berubah.');
            },

            'timer/stopwatch' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('015-CAL-424');
                // Baris 29 = kalimat `k` (General): teks master tidak ditebak generator; dicek terpisah di bawah.
                $this->assertCetakSamaMaster('timer/stopwatch', $html, 5, barisGalat: [29]);

                $kalimat = collect(PembandingCetakMaster::barisCetak($html))->pluck('teks')->first(fn (string $t): bool => str_contains($t, 'Coverage Factor'));
                $this->assertNotNull($kalimat, 'Timer: kalimat k tidak tercetak.');
                $this->assertStringContainsString('( k ) = 1.964', (string) $kalimat, 'Timer: k tercetak 1.964 (tiga desimal, B3a).');
                $this->assertStringNotContainsString(self::K_TIMER_TEKS_MASTER, (string) $kalimat, 'Timer: aplikasi tidak mencetak k master (1.9652).');

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(5, $hitung, 'Timer: lima titik.');

                foreach ($hitung as $h) {
                    $this->assertTersimpanSama(PembandingCetakMaster::nilaiSel('timer/stopwatch', 'X19'), $h->ketidakpastian_diperluas, "Timer titik {$h->titik_ke}: U95 tersimpan (lantai CMC)");
                    $this->assertNull($h->keputusan, 'Timer: master tidak mencetak vonis; aplikasi pun tidak.');
                }

                // Selisih `k` dikunci dua arah: titik 1 = 1,96400 (master 1,96517; drift 322 ms lawan 298 ms, §3 waktu-frekuensi).
                $this->assertEqualsWithDelta(1.963999758093, (float) $hitung[0]->faktor_cakupan_k, 1e-8, 'Timer: k titik 1 tersimpan');
                $this->assertGreaterThan(1e-3, PembandingCetakMaster::nilaiSel('timer/stopwatch', 'V29') - (float) $hitung[0]->faktor_cakupan_k, 'Selisih k terhadap master hilang — hapus catatan PK5-6 bila ditutup.');
            },
        ]);
    }
}
