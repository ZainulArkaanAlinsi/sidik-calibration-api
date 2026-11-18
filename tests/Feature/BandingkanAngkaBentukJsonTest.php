<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUG-6 uji E2E-QA 18 Nov 2026, lewat HTTP: bila `input_asli` kembali dari
 * kolom JSON dengan angka bulat sebagai integer (TiDB menormalkan `50.0` →
 * `50`; MySQL 8 menyimpan `50.0`), layar `GET /calibrations/{id}/bandingkan`
 * tidak boleh menandai sel yang nilainya sama sebagai berubah.
 *
 * Logika perbandingannya diuji murni di `Tests\Unit\BandingkanLembarAngkaTest`;
 * di sini dibuktikan pemakaiannya di jalur sesungguhnya.
 */
class BandingkanAngkaBentukJsonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Equipment $alat;

    private Standard $standar;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create(['organization_id' => $org->id]);
        $this->teknisi = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_TEKNISI]);
        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $org->id, 'kode' => 'panjang'])->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);
        $this->standar = Standard::factory()->create(['organization_id' => $org->id]);
    }

    /** @param  list<float>  $baca */
    private function payload(array $baca, array $ubah = []): array
    {
        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar->id,
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => $baca]],
            ...$ubah,
        ];
    }

    /** Tiru normalisasi JSON TiDB: angka bulat di `input_asli` kembali sebagai integer. */
    private function jadikanAngkaBulatInteger(CalibrationSession $sesi): void
    {
        $asli = $sesi->fresh()->input_asli;

        foreach ($asli['pembacaan'] as $i => $b) {
            if (is_float($b['pembacaan']) && floor($b['pembacaan']) === $b['pembacaan']) {
                $asli['pembacaan'][$i]['pembacaan'] = (int) $b['pembacaan'];
            }
        }

        DB::table('calibration_sessions')->where('id', $sesi->id)->update([
            // Teks JSON mentah, supaya `50` benar-benar tersimpan tanpa `.0`.
            'input_asli' => json_encode($asli),
        ]);
    }

    public function test_sel_yang_nilainya_sama_tidak_ditandai_berubah_walau_bentuk_angkanya_beda(): void
    {
        // Pembacaan pertama 50.0 (bulat) — itu yang berubah jadi int di TiDB.
        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', $this->payload([50.0, 50.01, 50.03]))
            ->assertCreated()->json('data.id');
        $sesi = CalibrationSession::findOrFail($id);

        $this->jadikanAngkaBulatInteger($sesi);
        $this->assertSame(50, $sesi->fresh()->input_asli['pembacaan'][0]['pembacaan'], 'prasyarat: asli kembali sebagai int');

        // Admin mengoreksi SATU sel (pembacaan ke-2); sel lain tidak disentuh.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/calibrations/{$sesi->id}", $this->payload([50.0, 50.05, 50.03], [
                'alasan_koreksi' => 'Salah ketik, angka benar dari lembar kertas.',
            ]))->assertOk();

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/calibrations/{$sesi->id}/bandingkan")
            ->assertOk()
            ->assertJsonPath('data.total_sel', 3)
            ->assertJsonPath('data.jumlah_berubah', 1)
            ->json('data');

        $berubah = array_values(array_filter($data['sel'], static fn (array $s): bool => $s['berubah']));

        $this->assertCount(1, $berubah, 'hanya koreksi sungguhan yang ditandai');
        $this->assertEquals(50.01, $berubah[0]['asli']);
        $this->assertEquals(50.05, $berubah[0]['sekarang']);
        $this->assertSame($this->admin->id, $berubah[0]['diubah_oleh']['id']);
    }
}
