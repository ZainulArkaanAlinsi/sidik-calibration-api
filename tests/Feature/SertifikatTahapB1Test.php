<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\CertificateSnapshotBuilder;
use App\Support\StandarCetakMaster;
use App\Support\TataLetakSertifikat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Tahap B1 (sertifikat persis master, hanya snapshot BARU):
 *
 *  - R2: baris Standard Used yang master SELALU cetak — Termometer & Sensor
 *    Std. + Caliper (hydrometer) dan sensor acuan menurut `tipe_sensor` sesi
 *    (oven, inkubator, tids, thermocouple, thermometer glass);
 *  - R7: Capacity/Graduation bertingkat (autoclave, spectrophotometer,
 *    thermohygro, gas detector) dan dua baris berlabel (viscometer);
 *  - R8: minus nol jangka sorong.
 *
 * Sumber: `SERTIFIKAT!B29:B31` hydrometer, `B53:B54` enclosure, `B41:B42`
 * tids/thermocouple, `B42:B43` thermometer glass, `F14:J15` autoclave, dst.
 * (`_agents_workspace/audit-sertifikat-20261109/master/<slug>/sertifikat*.json`).
 * Cetak ulang snapshot lama dijaga `SertifikatBekuTidakBerubahTest`.
 */
class SertifikatTahapB1Test extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
    }

    private function profil(string $kode): CalibrationProfile
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
        $this->assertNotNull($profil, $kode);

        return $profil;
    }

    private function standar(string $nama, ?string $serial, ?string $tertelusur = 'LK-285-IDN'): Standard
    {
        return Standard::factory()->create([
            'organization_id' => $this->org->id, 'nama' => $nama, 'merk' => null, 'model' => null,
            'serial_number' => $serial, 'tertelusur_ke' => $tertelusur,
        ]);
    }

    private function sesi(?string $tipeSensor = null): CalibrationSession
    {
        $sesi = new CalibrationSession;
        $sesi->organization_id = $this->org->id;
        $sesi->tipe_sensor = $tipeSensor;

        return $sesi;
    }

    /** @return list<int> */
    private function idSelalu(string $kode, CalibrationSession $sesi): array
    {
        return array_map(fn (Standard $s): int => $s->id, $this->profil($kode)->standarSelaluTercetakSesi($sesi));
    }

    // ---- R2 -----------------------------------------------------------------

    public function test_hydrometer_selalu_mencetak_termometer_lalu_caliper_urutan_master(): void
    {
        $caliper = $this->standar('Digital Caliper', 'LPI-0368');
        $termometer = $this->standar('Termometer & Sensor Std.', '23P1005');
        $this->standar('Temperature Calibrator Yokogawa CA 150 Handy Cal', '23P1005');

        $this->assertSame([$termometer->id, $caliper->id], $this->idSelalu('hydrometer', $this->sesi()));
    }

    public function test_sensor_acuan_enclosure_tids_thermocouple_menurut_tipe_sensor_sesi(): void
    {
        $k = $this->standar('Thermocouple Type K', 'TC-01,02', 'LK-064-IDN');
        $n = $this->standar('Thermocouple Type N', 'TCN-06,11', 'LK-064-IDN');
        $prt = $this->standar('PRT Pt-100', 'SH1/20', 'SNSU-BSN');

        $this->assertSame([$k->id], $this->idSelalu('oven', $this->sesi('Type K')));
        $this->assertSame([$n->id], $this->idSelalu('inkubator', $this->sesi('Type N')));
        // Alias `n`/`k` yang beredar di enclosure.
        $this->assertSame([$n->id], $this->idSelalu('oven', $this->sesi('n')));

        $this->assertSame([$k->id], $this->idSelalu('tids', $this->sesi('Type K')));
        // TIDS menerima ejaan kertas & master.
        $this->assertSame([$n->id], $this->idSelalu('tids', $this->sesi('Thermocouple Type-N')));
        $this->assertSame([$prt->id], $this->idSelalu('tids', $this->sesi('PRT PT100')));

        $this->assertSame([$k->id], $this->idSelalu('thermocouple', $this->sesi('Type K')));
        $this->assertSame([$prt->id], $this->idSelalu('thermocouple', $this->sesi('RTD')));
    }

    public function test_termometer_gelas_selalu_prt_pt100_apa_pun_isi_sesi(): void
    {
        $this->standar('Thermocouple Type K', 'TC-01,02', 'LK-064-IDN');
        $prt = $this->standar('PRT Pt-100', 'SH1/20', 'SNSU-BSN');

        $this->assertSame([$prt->id], $this->idSelalu('thermometer_glass', $this->sesi()));
        $this->assertSame([$prt->id], $this->idSelalu('thermometer_glass', $this->sesi('Type K')));
    }

    public function test_tipe_sensor_kosong_atau_tak_dikenal_tidak_ditebak_dan_standar_lab_lain_dilewati(): void
    {
        $this->standar('Thermocouple Type K', 'TC-01,02', 'LK-064-IDN');

        foreach (['oven', 'inkubator', 'tids', 'thermocouple'] as $kode) {
            $this->assertSame([], $this->idSelalu($kode, $this->sesi()), "{$kode}: tipe kosong");
            $this->assertSame([], $this->idSelalu($kode, $this->sesi('Type Z')), "{$kode}: tipe asing");
        }

        $lain = Organization::factory()->create();
        Standard::factory()->create(['organization_id' => $lain->id, 'nama' => 'PRT Pt-100', 'serial_number' => 'SH1/20']);
        $this->assertSame([], $this->idSelalu('thermometer_glass', $this->sesi()));
    }

    public function test_profil_di_luar_cakupan_b1_tetap_tanpa_baris_sensor(): void
    {
        $this->standar('Thermocouple Type K', 'TC-01,02', 'LK-064-IDN');

        foreach (['thermohygro', 'timbangan', 'labu_ukur', 'micrometer'] as $kode) {
            $this->assertSame([], $this->idSelalu($kode, $this->sesi('Type K')), $kode);
        }
        $this->assertArrayHasKey('hydrometer', StandarCetakMaster::PER_PROFIL);
    }

    public function test_builder_menaruh_baris_selalu_tercetak_di_ekor_urutan_master_walau_dicentang(): void
    {
        $neraca = $this->standar('Analytical Balance Fujitsu FS-AR210', 'INS-N1600555');
        $termometer = $this->standar('Termometer & Sensor Std.', '23P1005');
        $caliper = $this->standar('Digital Caliper', 'LPI-0368');
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id, 'nama_alat' => 'Hydrometer', 'nama_alat_kemampuan' => 'Hydrometer',
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id, 'equipment_id' => $alat->id, 'standard_id' => $neraca->id,
        ]);

        $nama = fn (): array => array_column($this->standarDigunakan($sesi->fresh()), 'name');
        $this->assertSame(['Analytical Balance Fujitsu FS-AR210', 'Termometer & Sensor Std.', 'Digital Caliper'], $nama());

        // Caliper dicentang teknisi (id lebih besar dari termometer tidak menolong):
        // tetap satu baris dan tetap urutan master.
        $sesi->standarDicek()->attach($caliper->id, ['dipakai' => true]);
        $sesi->standarDicek()->attach($termometer->id, ['dipakai' => true]);
        $this->assertSame(['Analytical Balance Fujitsu FS-AR210', 'Termometer & Sensor Std.', 'Digital Caliper'], $nama());
    }

    public function test_builder_enclosure_mencetak_kalibrator_lalu_sensor_dan_tidak_menggandakan_yang_dicentang(): void
    {
        $recorder = Standard::factory()->create([
            'organization_id' => $this->org->id, 'nama' => 'Temperature Recorder Graphtech GL840', 'merk' => 'Graphtech',
            'model' => 'GL840', 'serial_number' => 'C305B1470',
        ]);
        $sensor = $this->standar('Thermocouple Type K', 'TC-01,02', 'LK-064-IDN');
        $alat = Equipment::factory()->create([
            'organization_id' => $this->org->id, 'nama_alat' => 'Oven', 'nama_alat_kemampuan' => 'Oven',
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $this->org->id, 'equipment_id' => $alat->id, 'standard_id' => $recorder->id,
            'tipe_sensor' => 'Type K',
        ]);

        $this->assertSame(
            ['Temperature Recorder Graphtech GL840', 'Thermocouple Type K'],
            array_column($this->standarDigunakan($sesi->fresh()), 'name'),
        );

        $sesi->standarDicek()->attach($sensor->id, ['dipakai' => true]);
        $baris = $this->standarDigunakan($sesi->fresh());
        $this->assertSame(['Temperature Recorder Graphtech GL840', 'Thermocouple Type K'], array_column($baris, 'name'));
        $this->assertSame('TC-01,02', $baris[1]['serial_number']);
        $this->assertSame('LK-064-IDN', $baris[1]['traceable_to']);
    }

    // ---- R7 -----------------------------------------------------------------

    /** @param array<string, mixed> $alat */
    private function kapasitas(string $kode, array $alat, array $spesifikasi = [], ?array $hasilAutoclave = null): ?string
    {
        $profil = $this->profil($kode);
        $tl = TataLetakSertifikat::normalisasi($profil->tataLetakSertifikat(new CalibrationSession), $this->kunciTambahan($profil));
        $this->assertSame(['graduasi' => 'baris'], $tl['kapasitas_master'], $kode);

        $sesi = new CalibrationSession;
        $sesi->spesifikasi_alat = $spesifikasi;
        $sesi->hasil_autoclave = $hasilAutoclave;
        $sesi->setRelation('equipment', (new Equipment)->forceFill($alat));

        $metode = new ReflectionMethod(CertificateSnapshotBuilder::class, 'kapasitasGraduasiMaster');

        return $metode->invoke(app(CertificateSnapshotBuilder::class), $sesi, $profil, $tl['kapasitas_master']['graduasi']);
    }

    /** @return list<string> */
    private function kunciTambahan(CalibrationProfile $profil): array
    {
        $kunci = array_filter(array_column(array_merge(...$profil->tataLetakSertifikat(new CalibrationSession)['kepala']), 'kunci'));

        return array_values(array_diff($kunci, TataLetakSertifikat::KUNCI_KEPALA));
    }

    public function test_autoclave_dua_baris_suhu_dan_tekanan_tekanan_tanpa_kapasitas_tidak_ditebak(): void
    {
        $alat = ['satuan' => '°C', 'range_min' => 105, 'range_max' => 135, 'resolusi' => 0.1];
        $lembar = ['suhu' => ['resolusi_alat' => 0.01], 'tekanan' => ['satuan' => 'MPa', 'resolusi_alat' => 0.001]];

        $this->assertSame(
            "135 °C / 0,01 °C\n- MPa / 0,001 MPa",
            $this->kapasitas('autoclave', $alat, [], ['lembar' => $lembar]),
        );
        // Tanpa blok tekanan di sesi: satu baris suhu. Resolusi cadangan = config/autoclave.php (0,01).
        $this->assertSame('135 °C / 0,01 °C', $this->kapasitas('autoclave', $alat, [], ['lembar' => ['suhu' => ['indikator' => [121]]]]));
        // Sesi tanpa lembar (data lama): bentuk lama dari rentang alat.
        $this->assertSame('105–135 °C / 0,1 °C', $this->kapasitas('autoclave', $alat, [], null));
    }

    public function test_spectrophotometer_dua_baris_dari_isian_teknisi_dan_bentuk_lama_bila_belum_lengkap(): void
    {
        $alat = ['satuan' => 'nm', 'range_min' => 200, 'range_max' => 700, 'resolusi' => 0.01];
        $spek = [
            'rentang_ukur_panjang_gelombang' => '200-700', 'resolusi_panjang_gelombang' => '0.01',
            'rentang_ukur_transmitan' => '0-100', 'resolusi_transmitan' => '0.001',
        ];

        $this->assertSame("200-700 nm / 0.01 nm\n0-100 %T / 0.001 %T", $this->kapasitas('spectrophotometer', $alat, $spek));

        unset($spek['resolusi_transmitan']);
        $this->assertSame('200–700 nm / 0,01 nm', $this->kapasitas('spectrophotometer', $alat, $spek));
    }

    public function test_thermohygro_dua_baris_suhu_dan_rh_ketikan_teknisi(): void
    {
        $alat = ['satuan' => '°C', 'range_min' => 15, 'range_max' => 50, 'resolusi' => 0.1];
        $spek = ['kapasitas' => '-10 - 60', 'resolusi' => '0.1', 'kapasitas_kelembaban' => '10 - 95', 'resolusi_kelembaban' => '1'];

        $this->assertSame("-10 - 60 °C / 0.1 °C\n10 - 95 %RH / 1 %RH", $this->kapasitas('thermohygro', $alat, $spek));

        unset($spek['kapasitas_kelembaban']);
        $this->assertSame('15–50 °C / 0,1 °C', $this->kapasitas('thermohygro', $alat, $spek));
    }

    public function test_gas_detector_empat_baris_per_gas(): void
    {
        $this->assertSame(
            "1999 CO / 1 ppm\n200 H2S / 1 ppm\n100 CH4 / 1 %LEL\n30 O2 / 0,1 %",
            $this->kapasitas('gas_detector', ['satuan' => 'ppm', 'range_min' => 0, 'range_max' => 1999, 'resolusi' => 1]),
        );
    }

    public function test_viscometer_dua_baris_berlabel_capacity_dan_graduation(): void
    {
        $profil = $this->profil('viscometer');
        $tl = TataLetakSertifikat::normalisasi($profil->tataLetakSertifikat(new CalibrationSession), $this->kunciTambahan($profil));
        $kunci = array_filter(array_column(array_merge(...$tl['kepala']), 'kunci'));

        $this->assertContains('capacity', $kunci);
        $this->assertContains('graduation', $kunci);
        $this->assertNotContains('capacity_graduation', $kunci);

        $label = collect(array_merge(...$tl['kepala']))->filter()->pluck('label', 'kunci');
        $this->assertSame('Capacity', $label['capacity']);
        $this->assertSame('Graduation', $label['graduation']);

        $sesi = new CalibrationSession;
        $sesi->setRelation('equipment', (new Equipment)->forceFill(['resolusi' => 0.1]));

        // Master lama: resolusi alat; capacity selalu `-`.
        $this->assertSame(['capacity' => '- cP', 'graduation' => '0,1 cP'], $profil->isiTambahanKepala($sesi));

        // Seragam per titik: tetap angka. Beragam per titik: `Variable` (master terbaru).
        $sesi->spesifikasi_alat = ['resolusi_titik_1' => '0.1', 'resolusi_titik_2' => 0.1];
        $this->assertSame('0,1 cP', $profil->isiTambahanKepala($sesi)['graduation']);
        $sesi->spesifikasi_alat = ['resolusi_titik_1' => '0.1', 'resolusi_titik_3' => '1'];
        $this->assertSame('Variable cP', $profil->isiTambahanKepala($sesi)['graduation']);
    }

    public function test_graduasi_baris_diterima_validasi_dan_profil_lain_belum_menyatakan(): void
    {
        $this->assertSame(['graduasi' => 'baris'], TataLetakSertifikat::normalisasi(['kapasitas_master' => ['graduasi' => 'baris']])['kapasitas_master']);

        foreach (['buret', 'micrometer', 'timbangan', 'viscometer'] as $kode) {
            $this->assertArrayNotHasKey(
                'kapasitas_master',
                TataLetakSertifikat::normalisasi($this->profil($kode)->tataLetakSertifikat(new CalibrationSession), $this->kunciTambahan($this->profil($kode))),
                $kode,
            );
        }
    }

    // ---- R8 -----------------------------------------------------------------

    public function test_jangka_sorong_minus_nol_dicetak_nol_dan_turbidimeter_tetap_ditahan(): void
    {
        $this->assertFalse($this->profil('jangka_sorong')->tandaNolDicetak());
        $this->assertTrue($this->profil('turbidimeter')->tandaNolDicetak());
    }

    /** @return list<array<string, mixed>> */
    private function standarDigunakan(CalibrationSession $sesi): array
    {
        $builder = app(CertificateSnapshotBuilder::class);
        $metode = new ReflectionMethod($builder, 'standarDigunakan');
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        return $metode->invoke($builder, $sesi->load(['equipment', 'standard', 'standarDicek', 'uncertaintyCalculations']), $profil);
    }
}
