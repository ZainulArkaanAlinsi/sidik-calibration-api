<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baris pertama kertas varian A (0-25 mm) adalah titik NOL mikrometer: nominal
 * 0,0 mm, rahang tertutup, tanpa keping balok ukur. `MicrometerCalculator::
 * totalNominal()` sudah menyatakan titik itu sah (totalnya nol), tapi tabel
 * varian menyimpannya dengan tumpukan KOSONG — dan tumpukan kosong terbaca
 * "tidak ada keping sama sekali", jadi titiknya ditolak dengan alasan yang
 * menyesatkan ("nominal tidak ada di tabel standar") dan lenyap dari sertifikat.
 *
 * Ketahuan dari lembar 0-25 mm nyata (input data pusat, 20 Nov 2026): satu-satunya
 * varian yang tidak pernah punya sesi contoh ber-hitungan utuh.
 */
class MicrometerTitikNolTest extends TestCase
{
    use RefreshDatabase;

    /** Sebelas titik varian A (nominal kertas, mm). */
    private const NOMINAL_A = [0.0, 2.5, 5.1, 7.7, 10.3, 12.9, 15.0, 17.6, 20.2, 22.8, 25.0];

    /** @return array{Equipment, User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        $alat = Equipment::where('serial_number', 'ZQ-100')->firstOrFail();
        // Varian A: kapasitas 25 mm (pita 0-25), bukan B yang dibawa seeder.
        $alat->update(['range_min' => 0, 'range_max' => 25]);

        return [
            $alat,
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /** @return array<string, mixed> */
    private function payloadVarianA(Equipment $alat): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-10-20',
            'suhu_awal' => 20.6, 'suhu_akhir' => 20.7,
            'kelembaban_awal' => 54, 'kelembaban_akhir' => 55,
            // Penunjukan = nominal kertas; yang diuji jalur titik nol, bukan angka alat.
            'measurements' => array_map(
                static fn (float $n): array => ['titik_ukur' => $n, 'pembacaan' => array_fill(0, 5, $n)],
                self::NOMINAL_A,
            ),
            'spesifikasi_alat' => [
                'rentang_ukur' => '0-25', 'kapasitas' => '25', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 25.0,
                    'resolusi_mm' => 0.001,
                    // Ada sebaran (>= 2 nilai berbeda) supaya budget sesi utuh.
                    'pra_evaluasi' => [25.0, 25.0, 25.001, 25.0, 25.0, 24.999, 25.0, 25.0, 25.001, 25.0],
                ],
            ],
        ];
    }

    public function test_titik_nol_varian_a_ikut_terhitung_dengan_koreksi_nol_dan_bisa_dihitung_ulang(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payloadVarianA($alat))
            ->assertSuccessful();
        $id = $respons->json('data.id');

        $hitungan = UncertaintyCalculation::where('calibration_session_id', $id)->orderBy('titik_ke')->get();

        $this->assertCount(11, $hitungan, 'kesebelas titik varian A terhitung, termasuk titik nol');
        $this->assertSame(1, (int) $hitungan->first()->titik_ke);
        $this->assertEqualsWithDelta(0.0, (float) $hitungan->first()->titik_ukur, 1e-9, 'titik nol: standar terkoreksi 0 mm');
        $this->assertEqualsWithDelta(0.0, (float) $hitungan->first()->koreksi, 1e-9, 'penunjukan 0,000 pada nominal 0 = koreksi nol');

        // Jalur hitung ulang harus menyusun titik nol yang sama (nol titik gagal).
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])->assertSuccessful();

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, CalibrationSession::findOrFail($id)->status);
    }

    public function test_pratinjau_varian_a_tidak_menolak_titik_nol(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $belum = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $this->payloadVarianA($alat))
            ->assertSuccessful()
            ->json('data.belum_dihitung');

        $this->assertSame([], $belum ?? [], 'tidak ada titik yang ditolak untuk data yang utuh');
    }

    /**
     * Titik nol dengan penunjukan TIDAK nol (mikrometer yang nolnya bergeser):
     * standar terkoreksi tetap 0 mm, rata-rata = penunjukan, dan koreksi
     * = standar - rata-rata = NEGATIF. Test lama hanya mengisi 0,000 sehingga
     * tanda koreksi dan rata-rata titik nol tidak pernah diadu.
     */
    public function test_titik_nol_dengan_penunjukan_bukan_nol_bertanda_koreksi_negatif(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payloadVarianA($alat);
        $payload['measurements'][0]['pembacaan'] = [0.002, 0.002, 0.002, 0.001, 0.003];

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)->assertSuccessful()->json('data.id');

        $nol = UncertaintyCalculation::where('calibration_session_id', $id)->where('titik_ke', 1)->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $nol->titik_ukur, 1e-9, 'standar terkoreksi titik nol tetap 0 mm');
        $this->assertEqualsWithDelta(0.002, (float) $nol->rata_rata, 1e-9, 'rata-rata = penunjukan alat');
        $this->assertEqualsWithDelta(-0.002, (float) $nol->koreksi, 1e-9, 'koreksi = standar - rata-rata, bertanda negatif');

        // Titik lain tidak ikut bergeser, dan hitung ulang menyusun titik nol yang sama.
        $this->assertCount(11, UncertaintyCalculation::where('calibration_session_id', $id)->get());
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id]])->assertSuccessful();
        $this->assertEqualsWithDelta(
            -0.002,
            (float) UncertaintyCalculation::where('calibration_session_id', $id)->where('titik_ke', 1)->firstOrFail()->koreksi,
            1e-9,
        );
    }
}
