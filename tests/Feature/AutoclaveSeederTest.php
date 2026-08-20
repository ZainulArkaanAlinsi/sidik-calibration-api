<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\AutoclaveProfile;
use Database\Seeders\AutoclaveSeeder;
use Database\Seeders\CalibrationCapabilitySeeder;
use Database\Seeders\ThermohygroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `AutoclaveSeeder` beneran bikin Autoklaf yang bisa dipakai.
 *
 * Sampai 20 Agu 2026 Autoklaf jadi satu-satunya dari sembilan alat yang nggak
 * punya baris `equipments` sama sekali — fiturnya udah jadi lengkap, tapi di
 * layar teknisi alatnya nggak bisa dipilih. Test ini nahan supaya itu nggak
 * terulang diam-diam.
 *
 * Yang paling gampang rusak tanpa ketahuan: NAMA & SERIAL kalibratornya.
 * Angkanya ada di `config/autoclave.php`, kotak centang lembar kerjanya
 * nyocokin lewat `AutoclaveProfile::STANDARD_TERCETAK`, dan barisnya di DB
 * ditulis seeder ini. Tiga tempat, satu ejaan — beda satu karakter dan
 * standarnya nggak ketemu, tanpa error di mana pun.
 */
class AutoclaveSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['id' => 1]);
        User::factory()->create([
            'organization_id' => 1,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ]);

        // Urutan yang sama dengan `DatabaseSeeder` & docblock AutoclaveSeeder:
        // butuh kategori `instrumen-analitik` + baris CMC Autoklaf, dan TH-2
        // buat koreksi kondisi lingkungan.
        $this->seed(CalibrationCapabilitySeeder::class);
        $this->seed(ThermohygroSeeder::class);
        $this->seed(AutoclaveSeeder::class);
    }

    public function test_alat_autoklaf_ada_dan_bisa_dipilih(): void
    {
        $alat = Equipment::where('nama_alat_kemampuan', 'Autoklaf')->first();

        $this->assertNotNull(
            $alat,
            'Tanpa baris equipments ber-nama_alat_kemampuan "Autoklaf", teknisi nggak bisa milih autoklaf sama sekali.',
        );
        $this->assertNull(
            $alat->toleransi,
            'Autoklaf nggak divonis PASS/FAIL, jadi toleransinya NULL — bukan 0, yang kebaca "batasnya nol °C".',
        );
    }

    public function test_hasil_olah_data_lahir_dari_kalkulator_asli(): void
    {
        $sesi = CalibrationSession::where('nomor_sesi', '2406.51.S')->firstOrFail();

        $this->assertTrue($sesi->adalahAutoclave());
        $this->assertNull($sesi->keputusan, 'Autoklaf tidak divonis PASS/FAIL.');

        // Angka yang sama persis dengan `AutoclaveStoreTest` — buktinya seeder
        // lewat AutoclaveInputBuilder + AutoclaveCalculator, bukan nempel
        // angka jadi yang bakal basi begitu rumusnya berubah.
        $this->assertEqualsWithDelta(
            0.4419439029528431,
            $sesi->hasil_autoclave['suhu']['u95'],
            1e-9,
        );

        // 0,464 — SENGAJA beda dari master (0,466), sel disk master kegeser.
        // Lihat docblock AutoclaveCalculator.
        $this->assertEqualsWithDelta(0.464, $sesi->hasil_autoclave['suhu']['keseragaman'], 1e-9);
        $this->assertEqualsWithDelta(0.0059, $sesi->hasil_autoclave['tekanan']['u95'], 1e-9);

        // Baris kertas yang nggak ikut ngitung harus tetap kesimpen, kalau
        // nggak sertifikatnya nggak bisa diadu balik ke lembar kerjanya.
        $this->assertArrayHasKey('waktu', $sesi->hasil_autoclave['lembar']);
        $this->assertArrayHasKey('indikator_pressure', $sesi->hasil_autoclave['lembar']['tekanan']);
        $this->assertArrayHasKey('tekanan_atm_awal', $sesi->hasil_autoclave['lembar']['tekanan']);
    }

    public function test_kondisi_lingkungan_dihitung_bukan_diketik(): void
    {
        $sesi = CalibrationSession::where('nomor_sesi', '2406.51.S')->firstOrFail();

        // Seeder cuma nulis awal/akhir; `suhu_ruang` & U95-nya lahir dari
        // KondisiLingkungan + koreksi sertifikat thermohygro.
        $this->assertEqualsWithDelta(24.97, (float) $sesi->suhu_ruang, 0.01);
        $this->assertNotNull($sesi->suhu_ketidakpastian);
        $this->assertNotNull($sesi->kelembaban_ketidakpastian);
    }

    /**
     * Empat kalibrator harus nyambung ke Usage Check — itu SATU-SATUNYA jalan
     * mereka nyampe tabel STANDARD USED di sertifikat, karena sesi Autoklaf
     * nggak punya `uncertainty_calculations`.
     */
    public function test_empat_kalibrator_nempel_sebagai_usage_check(): void
    {
        $sesi = CalibrationSession::where('nomor_sesi', '2406.51.S')->firstOrFail();
        $dipakai = $sesi->standarDicek->filter(fn (Standard $s): bool => (bool) $s->pivot->dipakai);

        $this->assertCount(4, $dipakai, 'Tiga Temperature Calibrator + satu Pressure Disk Logger.');
    }

    public function test_nama_dan_serial_kalibrator_cocok_config_dan_profil(): void
    {
        $config = config('autoclave');

        $harusAda = [
            ...array_map(
                static fn (array $k): array => [$k['nama'], $k['serial']],
                $config['suhu'],
            ),
            [$config['tekanan']['nama'], $config['tekanan']['serial']],
        ];

        foreach ($harusAda as [$nama, $serial]) {
            $std = Standard::where('nama', $nama)->first();

            $this->assertNotNull($std, "Standar `{$nama}` (config/autoclave.php) nggak diseed.");
            $this->assertSame(
                $serial,
                $std->serial_number,
                "Serial `{$nama}` beda dari config/autoclave.php — ketertelusuran sertifikatnya nunjuk alat lain.",
            );
        }

        // Sisi ketiga: kotak centang lembar kerja nyocokin lewat `cocok`.
        foreach (AutoclaveProfile::STANDARD_TERCETAK as $baris) {
            $anggota = $baris['anggota'] !== [] ? $baris['anggota'] : [$baris];

            foreach ($anggota as $satu) {
                $ketemu = Standard::query()
                    ->where(function ($q) use ($satu) {
                        foreach ($satu['cocok'] as $kunci) {
                            $q->orWhere('nama', $kunci)->orWhere('serial_number', $kunci);
                        }
                    })
                    ->exists();

                $this->assertTrue(
                    $ketemu,
                    "Kotak centang `{$satu['label']}` di lembar kerja nggak ketemu standarnya di DB.",
                );
            }
        }
    }
}
