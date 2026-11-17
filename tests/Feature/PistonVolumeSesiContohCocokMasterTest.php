<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat keluarga Piston Volume (Piston Pipette, Dispensett,
 * Buret Digital) diadu ke lembar `SERTIFIKAT` workbook master Fixed dan
 * Graduated lewat renderer asli. PK-4, 17 Nov 2026.
 *
 * Sampelnya master apa adanya: `DEMO-PP-FIX-001` (Piston Pipette) dan
 * `DEMO-DS-FIX-001` (Dispensett — SINTETIS: masukan Fixed yang sama, supaya
 * profil ketiga punya sesi; angka master Fixed tetap pembanding yang sah
 * karena keluarganya satu tata letak) memakai sampel Fixed 10 mL;
 * `DEMO-BD-GRD-001` (Buret Digital) sampel Graduated 2/4/10 mL.
 *
 * Hasilnya: SEMUA baris cocok — volume aktual, koreksi, U95, `k`, dan suhu
 * dasar — dan hasil tersimpan (U95, k) sama dengan sel master. Master tidak
 * mencetak vonis lulus/tidak (STATUS Graduated `#N/A`), aplikasi pun tidak.
 */
class PistonVolumeSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSesiContoh();
    }

    public function test_angka_cetak_tiga_profil_sama_dengan_master(): void
    {
        // [nomor sesi => [kunci master, baris cocok, sel U95, sel k]]
        foreach ([
            'DEMO-PP-FIX-001' => ['piston_volume/fixed', 4, 'Q22', 'V23'],
            'DEMO-DS-FIX-001' => ['piston_volume/fixed', 4, 'Q22', 'V23'],
            'DEMO-BD-GRD-001' => ['piston_volume/graduated', 6, 'Q24', 'V25'],
        ] as $nomor => [$kunci, $cocok, $selU, $selK]) {
            [, $html, $sesi] = $this->terbitkanDanRender($nomor);

            $this->assertCetakSamaMaster($kunci, $html, $cocok);

            $hitung = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();
            $this->assertNotEmpty($hitung, "{$nomor}: tidak ada hasil tersimpan.");

            foreach ($hitung as $h) {
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel($kunci, $selU), (float) $h->ketidakpastian_diperluas, 1e-8, "{$nomor} titik {$h->titik_ke}: U95 tersimpan");
                $this->assertEqualsWithDelta(PembandingCetakMaster::nilaiSel($kunci, $selK), (float) $h->faktor_cakupan_k, 1e-8, "{$nomor} titik {$h->titik_ke}: k tersimpan");
                $this->assertNull($h->keputusan, "{$nomor}: master tidak mencetak vonis; aplikasi pun tidak.");
            }
        }
    }
}
