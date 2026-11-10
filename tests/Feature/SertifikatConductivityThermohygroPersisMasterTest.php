<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\TataLetakSertifikat;
use Tests\TestCase;

/**
 * G3 / R13 + R14 (K-50-15, sertifikat persis master).
 *
 * R13 Conductivity: Technician ID di KOLOM KANAN tepat di bawah Env. Condition
 * (`SERTIFIKAT STYLE 1!O16:S16`, kedua style).
 * R14 Thermohygro: master `SERTIFIKAT!M63` = `=#REF!` ("Uncertainty 95% ±
 * #REF! %RH") — `#REF!` tidak pernah dicetak; U95 kelembapan yang benar
 * dicetak dan penyimpangannya tertulis di jejak sesi.
 */
class SertifikatConductivityThermohygroPersisMasterTest extends TestCase
{
    private function profil(string $kode)
    {
        return app(CalibrationProfileRegistry::class)->untukKode($kode);
    }

    public function test_conductivity_technician_id_di_kolom_kanan_di_bawah_env_condition(): void
    {
        $kepala = $this->profil('conductivity_meter')->tataLetakSertifikat(new CalibrationSession)['kepala'];

        $kiri = array_column(array_map(static fn ($b) => $b[0] ?? [], $kepala), 'kunci');
        $kanan = array_map(static fn ($b) => $b[1]['kunci'] ?? null, $kepala);

        $this->assertNotContains('technician_id', $kiri, 'Technician ID tidak lagi di kolom kiri.');
        $posisiEnv = array_search('env_condition', $kanan, true);
        $this->assertNotFalse($posisiEnv);
        $this->assertSame('technician_id', $kanan[$posisiEnv + 1], 'Master O16: tepat di bawah Env. Condition (O14).');
        // Enam belas field baku tetap enam belas, tidak ada yang hilang atau ganda.
        $semua = array_values(array_filter([...$kiri, ...$kanan]));
        $this->assertEqualsCanonicalizing(TataLetakSertifikat::KUNCI_KEPALA, $semua);

        TataLetakSertifikat::normalisasi(['kepala' => $kepala]);
    }

    public function test_alat_lain_tidak_ikut_berubah_kepalanya(): void
    {
        $kepala = $this->profil('chlorine_meter')->tataLetakSertifikat(new CalibrationSession)['kepala'];

        $this->assertSame('technician_id', $kepala[9][0]['kunci'], 'Bentuk mayoritas: Technician ID kolom kiri baris ke-10.');
    }

    private function sesiThermohygro(): CalibrationSession
    {
        $baris = static function (int $titikKe, string $satuan): RawMeasurement {
            $m = new RawMeasurement;
            $m->titik_ke = $titikKe;
            $m->peran_sensor = 'standar';
            $m->pembacaan = 50.0;
            $m->pembacaan_ke = 1;
            $m->satuan = $satuan;

            return $m;
        };
        $titik = static function (int $titikKe, float $u95): UncertaintyCalculation {
            $t = new UncertaintyCalculation;
            $t->titik_ke = $titikKe;
            $t->ketidakpastian_diperluas = $u95;

            return $t;
        };

        $sesi = new CalibrationSession;
        $sesi->setRelation('rawMeasurements', collect([$baris(1, '°C'), $baris(2, '%RH'), $baris(3, '%RH')]));
        $sesi->setRelation('uncertaintyCalculations', collect([$titik(1, 0.4), $titik(2, 3.04), $titik(3, 3.04)]));

        return $sesi;
    }

    public function test_thermohygro_mencetak_u95_rh_yang_benar_bukan_ref(): void
    {
        $blok = $this->profil('thermohygro')->catatanSertifikat($this->sesiThermohygro());

        $this->assertSame([[
            'posisi' => TataLetakSertifikat::POSISI_BAWAH_HASIL,
            'tabel' => [['Uncertainty 95% ±', '3.0', '%RH']],
        ]], $blok);
        $this->assertStringNotContainsString('REF', json_encode($blok));

        TataLetakSertifikat::normalisasiBlok($blok);
    }

    public function test_thermohygro_tanpa_titik_kelembapan_tidak_mencetak_baris_itu(): void
    {
        $sesi = $this->sesiThermohygro();
        $sesi->setRelation('rawMeasurements', collect([$sesi->rawMeasurements[0]]));

        $this->assertSame([], $this->profil('thermohygro')->catatanSertifikat($sesi));
    }

    public function test_blok_u95_rh_tercetak_di_html_sebagai_baris_tabel(): void
    {
        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/sertifikat-beku/micrometer.snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
        $snapshot['catatan_blok'] = TataLetakSertifikat::normalisasiBlok($this->profil('thermohygro')->catatanSertifikat($this->sesiThermohygro()));
        app()->setLocale('en');

        $html = view('sertifikat.pdf', [
            'sertifikat' => new Certificate, 'snapshot' => $snapshot, 'logo' => null, 'kop' => null,
            'tandaTangan' => null, 'posisiTtd' => [], 'ukuranTtd' => [], 'qr' => null, 'keputusan' => null,
            'web' => false, 'paksaPadat' => false,
        ])->render();

        $this->assertMatchesRegularExpression('/<td>Uncertainty 95% ±<\/td>\s*<td>3\.0<\/td>\s*<td>%RH<\/td>/u', $html);
        $this->assertStringNotContainsString('#REF!', $html);
    }
}
