<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\TataLetakSertifikat;
use Tests\TestCase;

/**
 * G3 / R12 (K-50-15, sertifikat persis master): Gas Detector `SERTIFIKAT!
 * C22:U40` = SATU tabel `Reference Gas | Unit | Standard | Unit Under Test |
 * Correction | U95% (±)`, kalimat `k` SATU kali (`U28`), catatan konversi
 * %LEL di BAWAH tabel (`C29:J30`), kolom standar `Gas Composition` (`I36`).
 *
 * Snapshot LAMA (empat tabel per gas) tidak boleh berubah — dijaga
 * `SertifikatBekuTidakBerubahTest` (fixture `gas_detector`).
 */
class SertifikatGasDetectorPersisMasterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snapshotBaru(): array
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode('gas_detector');
        $sesi = new CalibrationSession;
        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/sertifikat-beku/gas_detector.snapshot.json')), true, 512, JSON_THROW_ON_ERROR);

        $tataLetak = $profil->tataLetakSertifikat($sesi);
        $catatan = $tataLetak['catatan'];
        unset($tataLetak['catatan']);
        $snapshot['tata_letak'] = TataLetakSertifikat::normalisasi($tataLetak);
        $snapshot['catatan'] = $catatan;
        $snapshot['catatan_blok'] = TataLetakSertifikat::normalisasiBlok($profil->catatanSertifikat($sesi));
        $snapshot['catatan_atas_hasil'] = $profil->catatanAtasTabelHasil($sesi);

        // Titik ukur gas: 101 / 25 / 50 / 17,9 (botol Rigas, `INPUT DATA!G36:M36`).
        foreach ([101.0, 25.0, 50.0, 17.9] as $i => $titik) {
            $snapshot['hasil'][$i]['tambahan'] = $profil->kolomTambahanTitik($sesi, $titik, $i + 1);
        }

        foreach ($snapshot['standar_digunakan'] as $i => $baris) {
            $snapshot['standar_digunakan'][$i]['komposisi_gas'] = substr($baris['merk_type'], strlen('Rigas/'));
        }

        return $snapshot;
    }

    private function render(array $snapshot): string
    {
        app()->setLocale('en');

        $html = view('sertifikat.pdf', [
            'sertifikat' => new Certificate, 'snapshot' => $snapshot, 'logo' => null, 'kop' => null,
            'tandaTangan' => null, 'posisiTtd' => [], 'ukuranTtd' => [], 'qr' => null, 'keputusan' => null,
            'web' => false, 'paksaPadat' => false,
        ])->render();

        return substr($html, (int) strpos($html, '<body'));
    }

    public function test_kolom_dan_isi_gas_dari_profil(): void
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode('gas_detector');
        $sesi = new CalibrationSession;

        $this->assertSame(['gas' => 'CO', 'satuan_gas' => 'ppm'], $profil->kolomTambahanTitik($sesi, 101.0, 1));
        $this->assertSame(['gas' => 'H₂S', 'satuan_gas' => 'ppm'], $profil->kolomTambahanTitik($sesi, 25.0, 2));
        $this->assertSame(['gas' => 'CH₄', 'satuan_gas' => '%LEL'], $profil->kolomTambahanTitik($sesi, 50.0, 3));
        $this->assertSame(['gas' => 'O₂', 'satuan_gas' => '%'], $profil->kolomTambahanTitik($sesi, 17.9, 4));
        $this->assertNull($profil->catatanAtasTabelHasil($sesi));
    }

    public function test_satu_tabel_empat_gas_dengan_kolom_reference_gas_dan_unit(): void
    {
        $html = $this->render($this->snapshotBaru());

        // Satu tabel hasil: satu kepala `Reference Gas`, tidak ada judul per gas.
        $this->assertSame(1, substr_count($html, '>Reference Gas<'));
        $this->assertSame(1, substr_count($html, '>Unit<'));
        $this->assertStringNotContainsString('judul-kelompok', substr($html, (int) strpos($html, 'Reference Gas') - 400, 800));

        foreach (['>CO<', '>H₂S<', '>CH₄<', '>O₂<', '>ppm<', '>%LEL<', '>%<'] as $sel) {
            $this->assertStringContainsString($sel, $html, "sel {$sel}");
        }

        // Urutan kolom master: Reference Gas, Unit, Standard, Unit Under Test, Correction, U95.
        $this->assertSame(
            1,
            preg_match('/Reference Gas.*?Unit<.*?Standard<.*?Unit Under Test<.*?Correction<.*?U95% \(±\)</s', $html),
        );
        // Satuan di kolom Unit, bukan baris satuan di bawah judul.
        $this->assertStringNotContainsString('class="satuan"', $html);
        // U95 per baris -> tidak ada baris ringkas "Uncertainty U95% = ±".
        $this->assertStringNotContainsString('class="u95"', $html);
    }

    public function test_kalimat_k_satu_kali_dan_catatan_lel_di_bawah_tabel(): void
    {
        $html = $this->render($this->snapshotBaru());

        $this->assertSame(1, substr_count($html, 'Coverage Factor ( k )'), 'Kalimat k satu kali (U28), bukan empat.');
        $this->assertSame(1, substr_count($html, 'Correction factor from % to %LEL'));

        $posTabel = (int) strpos($html, 'Reference Gas');
        $posK = (int) strpos($html, 'Coverage Factor ( k )');
        $posLel = (int) strpos($html, 'Correction factor from % to %LEL');
        $posPenafian = (int) strpos($html, 'not to be announced');

        $this->assertGreaterThan($posTabel, $posK);
        $this->assertGreaterThan($posK, $posLel, 'Catatan %LEL sesudah kalimat k (master C29:J30 di bawah C28).');
        $this->assertGreaterThan($posLel, $posPenafian, 'Catatan %LEL sebelum penafian (C33).');
        $this->assertStringContainsString('2.5% = 50%LEL', $html);
        $this->assertStringNotContainsString('catatan-atas-hasil', $html);
    }

    public function test_label_kolom_standar_gas_composition_dengan_isi_komposisi_saja(): void
    {
        $html = $this->render($this->snapshotBaru());

        $this->assertStringContainsString('>Gas Composition<', $html);
        $this->assertStringNotContainsString('>Merk/Type :<', $html);
        $this->assertStringContainsString('>Carbon Monoxide (CO) 101 Ppm<', $html);
        $this->assertStringNotContainsString('Rigas/Carbon Monoxide', $html);
    }
}
