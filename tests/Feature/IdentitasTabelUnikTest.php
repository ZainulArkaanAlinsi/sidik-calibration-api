<?php

namespace Tests\Feature;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Ocr\FormulirAsli;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan pemilik: tidak ada nilai yang boleh mendarat di kolom yang salah.
 *
 * FM-0515 (Centrifuge & Tachometer) mencetak DUA tabel (Before/After
 * adjustment) dengan `grup` yang sama. Dulu `tabel_id` = `grup`, jadi kunci
 * sel kembar: sel tahap After menimpa Before di template, dan HP mencocokkan
 * tabel lewat `tabel_id` saja.
 */
class IdentitasTabelUnikTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabel_id_unik_di_setiap_profil_dan_tidak_ada_sel_saling_menimpa(): void
    {
        $registry = app(CalibrationProfileRegistry::class);
        $template = app(TemplateLembarKerja::class);

        foreach ($registry->semua() as $profil) {
            $hasil = $template->dariProfil($profil);
            $id = array_column($hasil['tabel'], 'tabel_id');

            $this->assertSame(
                count($id),
                count(array_unique($id)),
                "Profil {$profil->kode()} punya tabel_id kembar: ".implode(', ', $id),
            );

            // Tiap sel menunjuk tabel_id yang ada, dan jumlah sel = jumlah
            // baris x kolom x pengulangan semua tabel (tidak ada yang tertimpa).
            $ditunjuk = array_unique(array_column($hasil['sel'], 'tabel_id'));
            $this->assertSame([], array_diff($ditunjuk, $id), "Profil {$profil->kode()}: sel menunjuk tabel tak dikenal.");
        }
    }

    public function test_before_dan_after_0515_punya_tabel_id_dan_sel_sendiri(): void
    {
        foreach (['centrifuge', 'tachometer'] as $kode) {
            $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
            $hasil = app(TemplateLembarKerja::class)->dariProfil($profil);

            $id = array_column($hasil['tabel'], 'tabel_id', 'tahap');
            $this->assertSame('pembacaan_standar_sebelum_adjustment', $id['sebelum_adjustment'], $kode);
            $this->assertSame('pembacaan_standar_sesudah_adjustment', $id['sesudah_adjustment'], $kode);

            foreach ($hasil['sel'] as $kunci => $sel) {
                $this->assertStringStartsWith($sel['tabel_id'].'|', $kunci);
                $this->assertSame(
                    $sel['tahap'] === 'sebelum_adjustment'
                        ? 'pembacaan_standar_sebelum_adjustment'
                        : 'pembacaan_standar_sesudah_adjustment',
                    $sel['tabel_id'],
                    "{$kode}: {$kunci} salah tabel.",
                );
            }

            $per = array_count_values(array_column($hasil['sel'], 'tabel_id'));
            $this->assertSame(
                $per['pembacaan_standar_sebelum_adjustment'],
                $per['pembacaan_standar_sesudah_adjustment'],
                "{$kode}: Before dan After harus sama banyak selnya.",
            );
        }
    }

    public function test_formulir_asli_0515_hanya_memetakan_after(): void
    {
        foreach (['centrifuge', 'tachometer'] as $kode) {
            $template = app(FormulirAsli::class)->untukKode($kode);

            $this->assertNotNull($template, $kode);
            $this->assertNotSame([], $template['sel']);

            foreach ($template['sel'] as $kunci => $sel) {
                $this->assertSame('pembacaan_standar_sesudah_adjustment', $sel['tabel_id'], "{$kode}: {$kunci}");
                $this->assertSame('sesudah_adjustment', $sel['tahap'], "{$kode}: {$kunci}");
            }

            $this->assertCount(2, $template['tabel']);
        }
    }

    public function test_formulir_dengan_asumsi_posisional_bertanda_perlu_konfirmasi_lab(): void
    {
        $dibekukan = ['conductivity_meter', 'refractometer', 'chlorine_meter', 'do_meter', 'turbidimeter'];

        foreach ($dibekukan as $kode) {
            $template = app(FormulirAsli::class)->untukKode($kode);

            $this->assertNotNull($template, $kode);
            $this->assertTrue($template['perlu_konfirmasi_lab'], "{$kode} wajib menunggu konfirmasi lab.");
        }

        foreach (['ph_meter', 'centrifuge', 'tachometer', 'timer_stopwatch', 'viscometer'] as $kode) {
            $template = app(FormulirAsli::class)->untukKode($kode);

            $this->assertNotNull($template, $kode);
            $this->assertFalse($template['perlu_konfirmasi_lab'], $kode);
        }
    }

    public function test_hanya_formulir_yang_menyatakan_tanpa_revisi_tercetak_melewati_syarat_revisi(): void
    {
        foreach (['flowmeter_flowrate', 'flowmeter_totalizer'] as $kode) {
            $template = app(FormulirAsli::class)->untukKode($kode, null, null, 'SIDIK-FM-CAL-0538');

            $this->assertIsArray($template, $kode);
            $this->assertFalse($template['revisi_tercetak'], $kode);
        }

        foreach (['ph_meter', 'centrifuge', 'tachometer', 'conductivity_meter', 'timer_stopwatch'] as $kode) {
            $this->assertTrue(app(FormulirAsli::class)->untukKode($kode)['revisi_tercetak'], $kode);
        }
    }
}
