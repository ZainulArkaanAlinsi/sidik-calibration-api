<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\MengumpulkanGagalPerProfil;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat Enclosure (Inkubator Yokogawa/Type N, Oven Recorder/Type K),
 * Thermocouple, dan Termometer Gelas diadu ke lembar `SERTIFIKAT` workbook master lewat
 * jalur produksi (seeder -> `POST /approve` -> `DataTampilanSertifikat` -> `sertifikat.pdf`).
 * PK-5, 18 Nov 2026.
 *
 * ## Bahan
 *
 * Sesi `2405.03.AV` (Constant Yokogawa), `2406.25.AI` (Recorder), `0513-CAL-1124`
 * (Thermocouple), `0135-CAL-125` (Termometer Gelas) ditanam seeder dari `INPUT DATA`
 * masternya. Pembanding `tests/Fixtures/cetak-master/kelompok-b.json`
 * (`docs/skrip/gen-cetak-master-kelompok-b.py`): sel angka lembar `SERTIFIKAT` + number_format,
 * teks cetaknya dicocokkan ke `Range.Text` Excel (COM; 468 sel di 14 master, 467 identik).
 * BARIS TERSEMBUNYI di master tidak diadu — Excel tidak mencetaknya.
 *
 * ## Hasil, dan yang dikunci di sini (bukan disetel supaya hijau)
 *
 * - Thermocouple: SEMUA baris cocok.
 * - Enclosure Constant Yokogawa: enam baris cocok; delapan baris beda = sembilan sel yang
 *   sudah DIPUTUSKAN pemilik 15 Okt 2026 (`pertanyaan-lab-enclosure.md` §4: master membuang
 *   pembacaan ke-5 dan menggandakan ke-3; sistem memakai semua pembacaan; jejak sesi
 *   `peta_kolom_master_rusak`).
 * - Enclosure Recorder: sebab yang sama untuk Variasi baris 1-2 (2,65 lawan 2,64), ditambah
 *   blok SET POINT 3 master yang rusak (§5): koreksi sensor 1-4 jatuh ke 0 sehingga Terukur
 *   dan Koreksi empat sensor itu +0,08 dan Variasi 2,72. §5 menyebut "tidak mengubah
 *   sertifikat" — itu benar untuk U95, TIDAK benar untuk Sebaran Suhu (PK5-3).
 * - Termometer Gelas: master memformat `J21:J23` General (tercetak `30`, `51`, `61`) dan
 *   `J24:J25` `0.0` (tercetak `82.0`, `99.0`); aplikasi seragam satu desimal (PK5-5). U95
 *   tersimpan 1,1262 lawan master 1,1174 = keputusan ÷√5 (`pertanyaan-lab-suhu-3alat.md`).
 */
class SuhuSesiContohCocokMasterTest extends TestCase
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
            'enclosure/constant_yokogawa' => fn () => $this->aduEnclosure('enclosure/constant_yokogawa', '2405.03.AV', cocok: 6, selisih: [
                25 => ['§4 (15 Okt 2026): SP1 sensor 8 Terukur 14,4 -> 14,5.', ['15.0', '14.3', '14.5', '14.4', '14.4', '14.2', '14.5', '14.4', '14.5', '14.2', '1.4']],
                26 => ['§4: SP1 sensor 8 Koreksi -0,6 -> -0,5.', ['-0.7', '-0.5', '-0.6', '-0.6', '-0.8', '-0.5', '-0.6', '-0.5', '-0.8']],
                29 => ['§4: SP3 sensor 9 Terukur 75,0 -> 75,1.', ['75.0', '74.7', '74.8', '75.1', '74.9', '74.8', '75.2', '74.6', '75.0', '75.1', '1.4']],
                30 => ['§4: SP3 sensor 9 Koreksi 0,0 -> 0,1.', ['-0.3', '-0.2', '0.1', '-0.1', '-0.2', '0.2', '-0.4', '0.0', '0.1']],
                31 => ['§4: SP4 sensor 6 Terukur 99,6 -> 99,7.', ['100.0', '99.8', '99.8', '100.2', '100.2', '99.9', '99.7', '99.8', '100.1', '100.1', '1.4']],
                32 => ['§4: SP4 sensor 6 Koreksi -0,4 -> -0,3.', ['-0.2', '-0.2', '0.2', '0.2', '-0.1', '-0.3', '-0.2', '0.1', '0.1']],
                42 => ['§4: SP1 Keseragaman 0,2 -> 0,3 dan Variasi 0,4 -> 0,5.', ['15.0', '15.0', '0.3', '0.1', '0.5']],
                44 => ['§4: SP3 Variasi 0,7 -> 0,8.', ['75.0', '75.0', '0.6', '0.2', '0.8']],
            ], u: ['U25', 'U27', 'U29', 'U31'], k: 'P37'),

            'enclosure/recorder' => fn () => $this->aduEnclosure('enclosure/recorder', '2406.25.AI', cocok: 6, selisih: [
                29 => ['§5(b) PK5-3: blok SP3 master rusak, koreksi sensor 1-4 = 0 (seharusnya -0,08 seperti SP1): Terukur +0,08.', ['67.4', '67.03', '67.54', '68.76', '67.76', '66.14', '67.45', '68.03', '66.63', '67.03', '1.5']],
                30 => ['§5(b) PK5-3: Koreksi sensor 1-4 SP3 (-0,25 -> -0,33 dst).', ['-0.33', '0.18', '1.40', '0.40', '-1.22', '0.09', '0.67', '-0.73', '-0.33']],
                41 => ['§4: Variasi SP1 2,64 -> 2,65 (memakai kelima pembacaan).', ['67', '67.36', '1.75', '0.02', '2.65']],
                42 => ['§4: Variasi SP2 2,64 -> 2,65.', ['67', '67.36', '1.75', '0.02', '2.65']],
                43 => ['§4 + §5(b) PK5-3: Variasi SP3 master 2,72 (blok rusak) lawan 2,65.', ['67', '67.36', '1.75', '0.02', '2.65']],
            ], u: ['U25', 'U27', 'U29'], k: 'N47'),

            'suhu/thermocouple' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('0513-CAL-1124');
                $this->assertCetakSamaMaster('suhu/thermocouple', $html, 5);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(3, $hitung, 'Thermocouple: tiga titik kalibrasi.');

                foreach ($hitung as $h) {
                    $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('suhu/thermocouple', 'L34'), (float) $h->ketidakpastian_diperluas, 1e-8, "Thermocouple titik {$h->titik_ke}: U95 tersimpan");
                    $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('suhu/thermocouple', 'O35'), (float) $h->faktor_cakupan_k, 5e-6, "Thermocouple titik {$h->titik_ke}: k tersimpan");
                    $this->assertNull($h->keputusan, 'Thermocouple: master tidak mencetak vonis; aplikasi pun tidak.');
                }
            },

            'suhu/thermometer_glass' => function (): void {
                [, $html, $sesi] = $this->terbitkanDanRender('0135-CAL-125');
                $this->assertCetakSamaMaster('suhu/thermometer_glass', $html, 4, [
                    21 => ['PK5-5: master memformat J21 General (`30`), aplikasi satu desimal.', ['30.5', '30.0', '0.5']],
                    22 => ['PK5-5: J22 General (`51`).', ['51.9', '51.0', '0.9']],
                    23 => ['PK5-5: J23 General (`61`).', ['62.1', '61.0', '1.1']],
                ]);

                $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
                $this->assertCount(5, $hitung);
                $this->assertTrue($this->jejakMemuat($hitung, 'pengulangan_standar_dibagi_n'), 'Termometer Gelas: penyimpangan ÷√5 harus terbaca di jejak sesi (AGENTS §Olah data butir 4).');

                foreach ($hitung as $h) {
                    // Selisih dikunci DUA ARAH: U95 hitung ÷√5 = 1,1262 (master ÷n: 1,1174). Cetak 1,1 sama.
                    $this->assertEqualsWithDelta(1.1261987699519, (float) $h->ketidakpastian_diperluas, 1e-6, "Termometer Gelas titik {$h->titik_ke}: U95 tersimpan");
                    $this->assertGreaterThan(5e-3, (float) $h->ketidakpastian_diperluas - PembandingCetakMaster::nilaiSel('suhu/thermometer_glass', 'L35'), 'Selisih U95 terhadap master (1,1174) hilang — hapus catatan PK5-5/suhu-3alat bila ditutup.');
                    $this->assertEqualsWithDelta(1.9653419836652, (float) $h->faktor_cakupan_k, 1e-6, "Termometer Gelas titik {$h->titik_ke}: k tersimpan (master 1,96529; selisih 5e-5 dari ÷√5 pada veff)");
                    $this->assertNull($h->keputusan);
                }
            },
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: list<string>|null}>  $selisih
     * @param  list<string>  $u  sel U95 master per set point (urut set point)
     */
    private function aduEnclosure(string $kunci, string $nomor, int $cocok, array $selisih, array $u, string $k): void
    {
        [, $html, $sesi] = $this->terbitkanDanRender($nomor);
        $this->assertCetakSamaMaster($kunci, $html, $cocok, $selisih);

        $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(count($u), $hitung, "{$kunci}: jumlah set point tersimpan.");

        foreach ($hitung as $i => $h) {
            $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel($kunci, $u[$i]), (float) $h->ketidakpastian_diperluas, 1e-8, "{$kunci} set point ".($i + 1).': U95 tersimpan (lantai CMC)');
            $this->assertNull($h->keputusan, "{$kunci}: master tidak mencetak vonis; aplikasi pun tidak.");
        }

        // Master mencetak SATU k untuk semua set point (`P37`/`N47`); itu k set point pertama.
        $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel($kunci, $k), (float) $hitung[0]->faktor_cakupan_k, 5e-6, "{$kunci}: k tersimpan");
        $this->assertTrue($this->jejakMemuat($hitung, 'peta_kolom_master_rusak'), "{$kunci}: penyimpangan peta kolom master harus terbaca di jejak sesi.");
    }
}
