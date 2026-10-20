<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua bug Proving Ring yang lolos `ProvingRingMasterTest` karena test itu
 * menyuntik blok sesi langsung ke kalkulator (parita 21 Okt 2026,
 * `_agents_workspace/revisi-1510/04-paritas-hook-2.md` A & D). Di sini
 * semuanya lewat jalur NYATA: `spesifikasi_alat` sesi seeder → hitung →
 * `GayaMentah::blokSesi` → `GayaCalculator` → `uncertainty_calculations`,
 * lalu hitung ulang validator dan snapshot sertifikat.
 *
 * - D: `GayaMentah::blokSesi` membuang `kapasitas_dial_mm` &
 *   `resolusi_dial_mm`, jadi komponen daya baca dial = 0. Master
 *   `162!'PERHITUNGAN U95%'!U10` = ((0,002/25)·100/2)/√3 = 0,0023094.
 * - A: Standard Value tercetak `24209,46` (divisi + faktor, dibagi 0,00981).
 *   Master `162!E23` = `'PERHITUNGAN FC'!Z31 / DATABASE!S23` = 29,99919.
 */
class ProvingRingDayaBacaDialTest extends TestCase
{
    use RefreshDatabase;

    public function test_daya_baca_dial_dan_standard_value_lewat_jalur_nyata(): void
    {
        $this->seed(DatabaseSeeder::class);
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-PR-001')->firstOrFail();
        $titik = $sesi->uncertaintyCalculations->sortBy('titik_ke')->values();

        // ---- D: komponen daya baca dial ----------------------------------
        foreach ($titik as $t) {
            $komponen = collect($t->type_b_components['komponen'] ?? [])->firstWhere('sumber', 'daya_baca_uut');
            $this->assertNotNull($komponen, "titik {$t->titik_ke}: komponen daya_baca_uut ada.");
            $this->assertEqualsWithDelta(0.0023094010767585, (float) $komponen['u'], 1e-12,
                "titik {$t->titik_ke}: u daya baca dial = master U10.");
        }

        // Hitung ulang validator (jalur kedua) sepakat dengan yang tersimpan.
        $temuan = collect(app(CalibrationValidator::class)->periksa($sesi->fresh())['temuan']);
        $this->assertSame([], $temuan->where('tingkat', 'error')->pluck('kode')->all(), 'Hitung ulang tidak berselisih.');

        // ---- A: Standard Value = Z / 0,00981 --------------------------------
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun(
            $sesi->fresh(),
            new Certificate(['nomor' => 'UJI-PR', 'qr_token' => 'uji-pr']),
        );
        $standar = array_column($snapshot['hasil'], 'standard_value');

        // `162!E23:E25` 30.00 / 90.00 / 120.00 (nilai 29,99919 dst.).
        $this->assertEqualsWithDelta(0.0, $standar[0], 1e-9, 'Titik nol.');
        $this->assertEqualsWithDelta(29.99919, $standar[1], 5e-5);
        $this->assertSame(['30.00', '90.00', '120.00'], array_map(
            static fn (float $v): string => number_format($v, 2, '.', ''),
            array_slice($standar, 1, 3),
        ));

        foreach ($titik as $i => $t) {
            $this->assertEqualsWithDelta((float) $t->titik_ukur / 0.00981, $standar[$i], 1e-9,
                "titik {$t->titik_ke}: Standard Value = Z (kN) / DATABASE!S23.");
            $this->assertLessThan(600.0, $standar[$i], 'Tidak ada lagi angka divisi yang dibagi 0,00981.');
        }
    }
}
