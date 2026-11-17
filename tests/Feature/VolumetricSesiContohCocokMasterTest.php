<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat Volumetric Glassware (Pipet Volume, Labu Ukur, Gelas
 * Ukur, Buret, Pipet Ukur) diadu ke lembar `SERTIFIKAT` kedua workbook master
 * `Volumetric_Glassware_2026` (Fixed & Graduated) lewat renderer asli.
 * PK-4, 17 Nov 2026. Picnometer sengaja tidak termasuk (ditahan, PK-3).
 *
 * ## Siapa sampel masternya
 *
 * Workbook master hanya memuat DUA sampel: Pipet Volume 1 mL (Fixed, sel
 * `INPUT DATA`) dan Gelas Ukur 100 mL titik 10/50/100 (Graduated). Seeder
 * menanamnya apa adanya untuk `DEMO-VOL-001` (Pipet Volume) dan `DEMO-VOL-004`
 * (Gelas Ukur), dan MEMINJAM masukan itu untuk tiga alat lain: Labu Ukur
 * (`DEMO-VOL-002`, masukan Fixed), Buret (`-005`) dan Pipet Ukur (`-006`,
 * masukan Graduated dua titik, resolusi 0,1 mL).
 *
 * ## Temuan yang dikunci
 *
 * 1. **Pipet Volume & Labu Ukur: V20 1,0039 mL, master 1,0043 mL** (Correction
 *    0,0039 lawan 0,0043 — selisih 0,0004 mL = 9 % dari koreksinya). Sebabnya
 *    keputusan pemilik 8 Okt 2026: Fixed mengikuti workbook lab Rev.7 (LU-200/
 *    250/500, PV 0,5/2/3/4) yang mengetik `H40`/`J40` = 25,5 °C, bukan template
 *    2026 yang berumus (`pertanyaan-lab-volumetric.md` no. 14). Cabang "suhu
 *    terukur" tetap dihitung dan tercatat di jejak sesi — dan cabang itu
 *    mencetak 1,0043, PERSIS master. Dikunci di bawah.
 * 2. **Labu Ukur: U95 0,0067 mL, master 0,0030**: lantai CMC mengikuti baris
 *    Labu Ukur lampiran LK-285-IDN (0,0067 mL untuk 1 mL), sedangkan pita
 *    master Fixed (`DATABASE`) tunggal 0,003 mL untuk semua jenis.
 * 3. **Buret & Pipet Ukur: U95 tidak ada acuan master yang sah.** Master
 *    Graduated dirancang untuk TIGA titik terisi: Excel yang diberi dua titik
 *    (titik 3 dikosongkan) melonjakkan komponen keterulangan 20x (`PERHITUNGAN
 *    U95%!D40` 0,37 -> 7,38), jadi hasil hitung ulangnya (U 0,0683 mL, k 2,447)
 *    bukan bukti. Volume aktual, koreksi, dan `k` (cetak "2") tetap diadu;
 *    U95 ditahan, dan dicatat sebagai pertanyaan lab baru.
 * 4. Gelas Ukur: U hitung 0,33703 mL (master 0,33731 mL; sel
 *    `H55` tanpa lima nol, `pertanyaan-lab-volumetric.md` no. 2). Angka CETAK
 *    sama karena lantai CMC 0,34 menang.
 */
class VolumetricSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSesiContoh();
    }

    public function test_angka_cetak_lima_profil_sama_dengan_master_kecuali_temuan_yang_tercatat(): void
    {
        $rev7 = 'Fixed mengikuti workbook lab Rev.7 (H40/J40 = 25,5 °C), bukan template 2026; keputusan pemilik 8 Okt 2026, pertanyaan lab no. 14.';

        // ---- Pipet Volume 1 mL (sampel master Fixed apa adanya) --------------
        [, $html, $sesi] = $this->terbitkanDanRender('DEMO-VOL-001');
        $this->assertCetakSamaMaster('volumetric/fixed', $html, 3, [
            21 => [$rev7, ['1.0', '1.0039', '0.0039']],
        ]);

        // Cabang "suhu terukur" yang tercatat di jejak sesi mencetak angka MASTER.
        $jejak = collect($sesi->uncertaintyCalculations()->first()->type_b_components)
            ->firstWhere('sumber', 'volumetric_rev7_suhu_densitas_air');
        $this->assertNotNull($jejak, 'Jejak cabang suhu terukur hilang — selisih V20 tidak lagi terbaca di jejak sesi.');
        $this->assertSame(
            '1.0043',
            number_format((float) $jejak['nilai'], 4, '.', ''),
            'V20 cabang suhu terukur harus mencetak angka master (N21 = 1.0043).',
        );
        $this->assertEqualsWithDelta(
            PembandingCetakMaster::nilaiSel('volumetric/fixed', 'Q22'),
            (float) $sesi->uncertaintyCalculations()->first()->ketidakpastian_diperluas,
            1e-8,
            'U95 tersimpan Pipet Volume = U95 master (lantai CMC 0,003).',
        );

        // ---- Labu Ukur (masukan Fixed dipinjam) -----------------------------
        [, $html] = $this->terbitkanDanRender('DEMO-VOL-002');
        $this->assertCetakSamaMaster('volumetric/fixed', $html, 2, [
            21 => [$rev7, ['1.0', '1.0039', '0.0039']],
            22 => ['Lantai CMC baris Labu Ukur lampiran LK-285-IDN (0,0067 mL), master Fixed satu pita 0,003 mL.', ['0.0067']],
        ]);

        // ---- Gelas Ukur 100 mL (sampel master Graduated apa adanya) ----------
        [, $html, $sesi] = $this->terbitkanDanRender('DEMO-VOL-004');
        $this->assertCetakSamaMaster('volumetric/graduated', $html, 6, barisGalat: [24, 25]);
        $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $this->assertCount(3, $hitung);
        foreach ($hitung as $h) {
            $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('volumetric/graduated', 'Q26'), (float) $h->ketidakpastian_diperluas, 1e-8, 'U95 tersimpan Gelas Ukur');
            $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel('volumetric/graduated', 'V27'), (float) $h->faktor_cakupan_k, 1e-8, 'k tersimpan Gelas Ukur');
            $this->assertNull($h->keputusan, 'Master Graduated tidak mencetak vonis.');
        }

        // ---- Buret & Pipet Ukur (masukan Graduated dua titik dipinjam) -------
        // Baris 23-25 (titik ke-3 dan dua baris kosong) dan 26 (U95) tidak diadu: lihat temuan 3.
        foreach (['DEMO-VOL-005', 'DEMO-VOL-006'] as $nomor) {
            [, $html] = $this->terbitkanDanRender($nomor);
            $this->assertCetakSamaMaster('volumetric/graduated', $html, 4, barisDibuang: [23, 24, 25, 26]);
        }
    }
}
