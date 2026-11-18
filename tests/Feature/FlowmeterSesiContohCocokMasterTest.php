<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\MengumpulkanGagalPerProfil;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat Flowmeter Ultrasonic (Totalizer `DEMO-FM-TOT-001`, Flowrate
 * `DEMO-FM-FLW-001`) diadu ke lembar `SERTIFIKAT` kedua workbook master lewat jalur produksi.
 * PK-5, 18 Nov 2026. Pembanding: `tests/Fixtures/cetak-master/kelompok-b.json`.
 *
 * ## Hasil
 *
 * Totalizer titik 1 dan Flowrate titik 1 cocok sampai digit cetak, termasuk `k`. Dua baris beda
 * SENGAJA dan sudah tertulis di docblock `FlowmeterSeeder`:
 *
 * - Totalizer titik 2: Standard Indication 1883,88 dan Correction -18,89 (master 1883,86 dan
 *   -18,91) — salah satu dari tiga perbaikan `FlowmeterCalculator` (rentang densitas Totalizer,
 *   `Ut-water` §12, lantai CMC §2); angkanya di docblock `FlowmeterSeeder`.
 * - Flowrate titik 2: U95 1,2 %OR (master 1,0): lantai CMC 1,2 % yang master lupa pasang (§2).
 *
 * U95 tersimpan dalam satuan alat (L / Lpm); master mencetak %OR terhadap bacaan UUT, jadi
 * perbandingannya `U / UUT x 100`. Master tidak mencetak vonis; aplikasi pun tidak.
 *
 * Temuan PK5-7: ketiga perbaikan itu belum punya catatan di `type_b_components` sesi (hanya
 * baris `jejak_titik`), padahal AGENTS.md §Olah data butir 4 mewajibkannya.
 *
 * ## Toleransi nilai TERSIMPAN (angka cetak selalu dibandingkan persis, tanpa toleransi)
 *
 * 5e-6 untuk U95 %OR dan `k`: sama dengan yang dipakai `FlowmeterMasterTest` untuk tiap kolom
 * turunan dan tiap komponen budget kedua workbook; %OR dihitung ulang dari U tersimpan dan
 * bacaan UUT master, jadi galat pembulatan kolom ikut terhitung.
 */
class FlowmeterSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use MengumpulkanGagalPerProfil;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSesiContoh();
    }

    public function test_angka_cetak_dan_hasil_tersimpan_sama_dengan_master_kecuali_yang_tercatat(): void
    {
        $this->periksaSemuaProfil([
            'flowmeter/ultrasonic_totalizer' => fn () => $this->adu(
                'flowmeter/ultrasonic_totalizer', 'DEMO-FM-TOT-001', cocok: 2,
                selisih: [23 => ['Titik 2: standar 1883,88 dan koreksi -18,89 (master 1883,86 / -18,91) — koreksi Totalizer yang dicatat docblock FlowmeterSeeder (-18,907 -> -18,891 L).', ['1902.77', '1883.88', '-18.89', '1.3']]],
                uut: ['D22', 'D23'], persen: ['O22', 'O23'], k: 'Q26', persenSama: [true, true],
            ),
            'flowmeter/ultrasonic_flowrate' => fn () => $this->adu(
                'flowmeter/ultrasonic_flowrate', 'DEMO-FM-FLW-001', cocok: 2,
                selisih: [27 => ['Titik 2: U95 1,2 %OR (master 1,0) — lantai CMC 1,2 % yang master lupa pasang (§2).', ['310.643', '306.828', '-3.815', '1.2']]],
                barisGalat: [28], uut: ['E26', 'E27'], persen: ['N26', 'N27'], k: 'Q29', persenSama: [true, false],
            ),
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: list<string>|null}>  $selisih
     * @param  list<int>  $barisGalat
     * @param  list<string>  $uut  sel bacaan UUT master per titik
     * @param  list<string>  $persen  sel U95 %OR master per titik
     * @param  list<bool>  $persenSama  apakah %OR tersimpan harus sama dengan master (false = selisih sengaja, dikunci di bawah 1 %OR)
     */
    private function adu(string $kunci, string $nomor, int $cocok, array $selisih, array $uut, array $persen, string $k, array $persenSama, array $barisGalat = []): void
    {
        [, $html, $sesi] = $this->terbitkanDanRender($nomor);
        $this->assertCetakSamaMaster($kunci, $html, $cocok, $selisih, barisGalat: $barisGalat);

        $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(count($uut), $hitung, "{$kunci}: jumlah titik tersimpan.");

        foreach ($hitung as $i => $h) {
            $bacaan = PembandingCetakMaster::nilaiSel($kunci, $uut[$i]);
            $persenApp = (float) $h->ketidakpastian_diperluas / $bacaan * 100;
            $persenMaster = PembandingCetakMaster::nilaiSel($kunci, $persen[$i]);

            if ($persenSama[$i]) {
                $this->assertEqualsWithDelta($persenMaster, $persenApp, 5e-6, "{$kunci} titik ".($i + 1).': U95 %OR tersimpan');
            } else {
                // Selisih sengaja: aplikasi LEBIH BESAR (lantai CMC), tidak pernah lebih kecil.
                $this->assertGreaterThan($persenMaster + 0.1, $persenApp, "{$kunci} titik ".($i + 1).': selisih U95 terhadap master hilang — hapus catatan §2 bila ditutup.');
            }

            $this->assertNull($h->keputusan, "{$kunci}: master tidak mencetak vonis; aplikasi pun tidak.");
        }

        // Master mencetak SATU `k` (titik 1).
        $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel($kunci, $k), (float) $hitung[0]->faktor_cakupan_k, 5e-6, "{$kunci}: k tersimpan");
    }
}
