<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kertas Turbidimeter (FM-0530) dan Conductivity (FM-0510) mencetak kotak
 * "Resolusi:" di tiap titik dengan perintah "tulis resolusi UUT di
 * masing-masing titik". Sampai 15 Sep 2026 lembar HP tidak punya kotaknya.
 *
 * Nilainya dicatat dan diadu ke resolusi yang dipakai budget — beda naik
 * jadi peringatan, angka tidak bergeser (pola Viscometer).
 */
class ResolusiPerTitikLembarTest extends TestCase
{
    use RefreshDatabase;

    private const JUMLAH_TITIK = ['turbidimeter' => 3, 'conductivity_meter' => 3];

    public function test_lembar_punya_kotak_resolusi_tiap_titik(): void
    {
        foreach (self::JUMLAH_TITIK as $kode => $jumlah) {
            $bentuk = app(CalibrationProfileRegistry::class)->untukKode($kode)->bentukLembarKerja();
            $hasil = collect($bentuk['bagian'])->firstWhere('kode', 'hasil');
            $kodeField = array_column($hasil['field'], 'kode');

            for ($ke = 1; $ke <= $jumlah; $ke++) {
                $this->assertContains("spesifikasi_alat.resolusi_titik_{$ke}", $kodeField, "{$kode}: titik {$ke}");
            }
        }
    }

    public function test_resolusi_sama_dengan_master_tidak_memberi_peringatan(): void
    {
        $turbidimeter = app(CalibrationProfileRegistry::class)->untukKode('turbidimeter');
        $sesi = new CalibrationSession(['spesifikasi_alat' => [
            'resolusi_titik_1' => '0,01', 'resolusi_titik_2' => 0.1, 'resolusi_titik_3' => '1',
        ]]);

        $this->assertSame([], $turbidimeter->peringatanSesi($sesi));

        $conductivity = app(CalibrationProfileRegistry::class)->untukKode('conductivity_meter');
        // Titik tengah boleh ditulis dalam bentuk µS/cm (1) maupun mS/cm (0,001).
        foreach ([1.0, '0,001'] as $tengah) {
            $sesi = new CalibrationSession(['spesifikasi_alat' => [
                'resolusi_titik_1' => 0.1, 'resolusi_titik_2' => $tengah, 'resolusi_titik_3' => 0.01,
            ]]);

            $this->assertSame([], $conductivity->peringatanSesi($sesi));
        }
    }

    /**
     * Resolusi per titik MASUK budget, tapi cuma ke arah yang membesarkan U.
     *
     * Jawaban lab 16 Sep 2026 §3.3: EA-4/02 menghitung `δx/(2√3)` dari resolusi
     * pada pembacaan itu, dan alat autorange berganti resolusi per rentang.
     * Sampai Manajer Teknis menyetujui, yang dipakai yang lebih besar antara
     * tulisan teknisi dan angka master — perbaikan rumus tidak boleh diam-diam
     * mengecilkan ketidakpastian yang sudah tercetak.
     */
    public function test_resolusi_tulisan_teknisi_masuk_budget_hanya_kalau_lebih_besar(): void
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode('turbidimeter');
        $alat = new Equipment(['resolusi' => 0.01]);

        // Titik 100 NTU: master 0,1. Tulisan lebih KASAR dipakai.
        $this->assertSame(1.0, $profil->resolusiDipakai(100.0, $alat, [
            'spesifikasi_alat' => ['resolusi_titik_2' => '1'],
        ]));

        // Tulisan lebih HALUS diabaikan — master menang.
        $this->assertSame(0.1, $profil->resolusiDipakai(100.0, $alat, [
            'spesifikasi_alat' => ['resolusi_titik_2' => 0.001],
        ]));

        // Kotak kosong: perilaku lama, persis seperti sebelum kotaknya ada.
        $this->assertSame(0.1, $profil->resolusiDipakai(100.0, $alat, []));
    }

    public function test_resolusi_beda_memberi_peringatan_yang_menyebut_titiknya(): void
    {
        foreach (['turbidimeter' => 'titik 100 NTU', 'conductivity_meter' => 'titik 1412'] as $kode => $sebut) {
            $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
            $sesi = new CalibrationSession(['spesifikasi_alat' => ['resolusi_titik_2' => 5]]);

            $temuan = $profil->peringatanSesi($sesi);

            $this->assertCount(1, $temuan, $kode);
            $this->assertSame('resolusi_titik_beda_dari_master', $temuan[0]['kode']);
            $this->assertStringContainsString($sebut, $temuan[0]['pesan']);
        }
    }
}
