<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CertificateSnapshotBuilder;
use App\Support\StandarCetakMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G1 / R2 (K-50-15, sertifikat persis master): baris "Termometer & Sensor
 * Std." di tabel Standard Used SELALU tercetak di master (`'INPUT DATA'!X22`,
 * `DATABASE!V16/V21`, ...), walau teknisi tidak mencentangnya. Datanya tabel
 * per kode profil `StandarCetakMaster`; jalurnya satu hook di kelas dasar
 * (`CalibrationProfile::standarSelaluTercetakSesi`) yang dipanggil
 * `CertificateSnapshotBuilder`.
 */
class SertifikatStandarSelaluTercetakTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
    }

    private function termometer(Organization $org): Standard
    {
        return Standard::factory()->create([
            'organization_id' => $org->id,
            'nama' => 'Termometer & Sensor Std.', 'merk' => 'Yokogawa', 'model' => 'CA 150 Handy Cal',
            'serial_number' => '23P1005', 'tertelusur_ke' => 'LK-285-IDN',
        ]);
    }

    public function test_tiap_profil_di_tabel_mencetak_termometer_walau_tidak_dicentang(): void
    {
        $termometer = $this->termometer($this->org);
        // Sensor RTD berseri sama (23P1005 dipakai dua baris master lab): yang
        // dicetak harus baris kalibrator itu sendiri, cocok lewat NAMA.
        Standard::factory()->create([
            'organization_id' => $this->org->id, 'nama' => 'RTD Sensor', 'serial_number' => '23P1005',
        ]);
        $sesi = new CalibrationSession;
        $sesi->organization_id = $this->org->id;

        $this->assertCount(15, StandarCetakMaster::PER_PROFIL);

        foreach (array_keys(StandarCetakMaster::PER_PROFIL) as $kode) {
            // Hydrometer (B1) mencetak dua baris tetap: termometer + caliper;
            // caliper tidak ada di master standar tes ini, jadi cuma termometer.
            $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
            $this->assertNotNull($profil, "{$kode}: kode profil tidak ada di registry.");

            $hasil = $profil->standarSelaluTercetakSesi($sesi);

            $this->assertSame([$termometer->id], array_map(fn (Standard $s): int => $s->id, $hasil), "{$kode}: baris termometer master.");
        }
    }

    public function test_profil_di_luar_tabel_dan_labu_pipet_rev7_tidak_berubah(): void
    {
        $this->termometer($this->org);
        $sesi = new CalibrationSession;
        $sesi->organization_id = $this->org->id;

        // Rev.7 punya jalurnya sendiri (`standarTambahanSesi`) — tidak boleh tercetak dua kali.
        foreach (['labu_ukur', 'pipet_volume', 'micrometer', 'timbangan', 'tids', 'thermohygro'] as $kode) {
            $this->assertSame(
                [],
                app(CalibrationProfileRegistry::class)->untukKode($kode)->standarSelaluTercetakSesi($sesi),
                "{$kode}: tidak ada di StandarCetakMaster.",
            );
        }
    }

    public function test_standar_lab_lain_dan_baris_yang_tidak_terdaftar_dilewati(): void
    {
        $lain = Organization::factory()->create();
        $this->termometer($lain);
        $sesi = new CalibrationSession;
        $sesi->organization_id = $this->org->id;

        $this->assertSame([], app(CalibrationProfileRegistry::class)->untukKode('chlorine_meter')->standarSelaluTercetakSesi($sesi));

        $sesi->organization_id = null;
        $this->assertSame([], app(CalibrationProfileRegistry::class)->untukKode('chlorine_meter')->standarSelaluTercetakSesi($sesi));
    }

    public function test_builder_menaruh_termometer_di_baris_terakhir_dan_tidak_menggandakan_yang_dicentang(): void
    {
        $termometer = $this->termometer($this->org);
        $neraca = Standard::factory()->create([
            'organization_id' => $this->org->id, 'nama' => 'Analytical Balance', 'serial_number' => '1129063525',
        ]);
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id, 'nama_alat' => 'Gelas Ukur', 'nama_alat_kemampuan' => 'Gelas Ukur',
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id, 'equipment_id' => $alat->id, 'standard_id' => $neraca->id,
        ]);

        $baris = $this->standarDigunakan($sesi);

        $this->assertSame(['Analytical Balance', 'Termometer & Sensor Std.'], array_column($baris, 'name'));
        $this->assertSame('Yokogawa/CA 150 Handy Cal', $baris[1]['merk_type']);
        $this->assertSame('23P1005', $baris[1]['serial_number']);
        $this->assertSame('LK-285-IDN', $baris[1]['traceable_to']);

        // Sudah dicentang "Dipakai" -> tetap satu baris.
        $sesi->standarDicek()->attach($termometer->id, ['dipakai' => true]);
        $baris = $this->standarDigunakan($sesi->fresh());

        $this->assertSame(1, count(array_filter($baris, fn (array $b): bool => $b['name'] === 'Termometer & Sensor Std.')));
    }

    /** @return list<array<string, mixed>> */
    private function standarDigunakan(CalibrationSession $sesi): array
    {
        $builder = app(CertificateSnapshotBuilder::class);
        $metode = new \ReflectionMethod($builder, 'standarDigunakan');
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        return $metode->invoke($builder, $sesi->load(['equipment', 'standard', 'standarDicek', 'uncertaintyCalculations']), $profil);
    }
}
