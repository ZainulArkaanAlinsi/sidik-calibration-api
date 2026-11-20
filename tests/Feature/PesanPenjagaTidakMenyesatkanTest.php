<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua pesan penjaga yang menuduh sebab yang salah (simulasi input data nyata,
 * 20 Nov 2026):
 *
 *  - FA-04 — `titik_tidak_terhitung` menulis "pengulangan kurang dari 5, standar
 *    acuan belum kebaca" seolah itu sebabnya, padahal Micrometer dengan
 *    pra-evaluasi identik ditahan penjaga profil dengan pengulangan 10 dan
 *    standar terbaca. Alasan sebenarnya hanya ada di `belum_dihitung`.
 *  - F-05 — titik ukur kosong pada Flowmeter dijawab "nilai larutan standar
 *    (Solution Standard)", istilah lembar pH yang tidak dimiliki Flowmeter.
 *
 * Yang diubah cuma kalimatnya; tidak ada logika penjaga yang bergeser.
 */
class PesanPenjagaTidakMenyesatkanTest extends TestCase
{
    use RefreshDatabase;

    public function test_titik_tidak_terhitung_tidak_mengaku_tahu_sebab_pastinya(): void
    {
        $this->seed(DatabaseSeeder::class);
        $sesi = CalibrationSession::where('nomor_sesi', '2405.03.AV')->firstOrFail();

        // Satu titik ada pembacaannya tapi tidak ada hitungannya.
        $sesi->uncertaintyCalculations()->delete();

        $admin = User::where('organization_id', $sesi->organization_id)->where('role', User::ROLE_ADMIN)->firstOrFail();
        $pesan = collect($this->actingAs($admin)->getJson("/api/calibrations/{$sesi->id}/validasi")->assertOk()->json('data.temuan'))
            ->where('kode', 'titik_tidak_terhitung')
            ->pluck('pesan');

        $this->assertNotEmpty($pesan, 'Pesannya tidak lahir — test ini tidak menguji apa pun.');

        foreach ($pesan as $p) {
            // Sebab yang disebut tetap tebakan yang berlaku untuk lembar ini
            // (`PeringatanPalsuTest`, `PengulanganMinimumIKTest`)...
            $this->assertStringContainsString('kurang dari 4', $p);

            // ...tapi tidak lagi disajikan sebagai satu-satunya jawaban: penjaga
            // profil juga bisa menahan titik, dan alasan pastinya ada di
            // `belum_dihitung`.
            $this->assertStringContainsString('penjaga profil', $p);
            $this->assertStringContainsString('belum_dihitung', $p);
            $this->assertStringNotContainsString(
                '(pengulangan kurang dari',
                $p,
                'Sebab dikurung kurung seolah daftar pasti, padahal cuma kemungkinan.',
            );
        }
    }

    public function test_titik_ukur_kosong_flowmeter_tidak_menyebut_larutan_standar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $alat = CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')->firstOrFail()->equipment;
        $teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $respons = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-05-05',
            'suhu_awal' => 20.2, 'suhu_akhir' => 20.3,
            'kelembaban_awal' => 44, 'kelembaban_akhir' => 55,
            'measurements' => [['pembacaan' => [100.0, 100.0, 100.0]]],
        ])->assertStatus(422)->assertJsonValidationErrors(['measurements.0.titik_ukur']);

        // Kunci galat Laravel datar (`measurements.0.titik_ukur`), bukan bersarang.
        $pesan = (string) ($respons->json('errors')['measurements.0.titik_ukur'][0] ?? '');

        $this->assertNotSame('', $pesan);
        $this->assertStringNotContainsString('Solution Standard', $pesan, 'Istilah lembar pH untuk alat yang tidak punya larutan standar.');
        $this->assertStringContainsString('titik ukur', mb_strtolower($pesan));
    }
}
