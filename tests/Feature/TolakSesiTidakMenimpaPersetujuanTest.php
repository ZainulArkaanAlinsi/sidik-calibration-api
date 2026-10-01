<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tolak yang kalah balapan tidak boleh menurunkan sesi yang sudah disetujui.
 *
 * Temuan B08 (paket 30 Sep). `reject()` memeriksa status di awal, lalu menulis
 * `perlu_revisi` lewat `update()` polos. Admin B yang layarnya basi menekan
 * Tolak tepat sesudah admin A menyetujui: pemeriksaan awal B lolos (model yang
 * dimuat masih `menunggu_approval`), lalu penulisannya menimpa persetujuan A
 * sementara job sertifikat sudah jalan. Penjaga `CalibrationSession::booted()`
 * tidak menolong karena membaca status asli DI MEMORI, bukan di database.
 *
 * Balapannya dibuat deterministik: begitu route model binding memuat sesinya,
 * status di database dipindah ke `disetujui` lewat query builder — persis yang
 * dilihat admin B kalau persetujuan A mendarat di antara dua langkah itu.
 */
class TolakSesiTidakMenimpaPersetujuanTest extends TestCase
{
    use RefreshDatabase;

    public function test_tolak_sesudah_sesi_disetujui_dijawab_409_dan_status_tidak_turun(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $org->id]);
        $teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
        ]);
        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create([
                'organization_id' => $org->id,
            ])->id,
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        $sudahDisetujui = false;
        CalibrationSession::retrieved(function (CalibrationSession $dimuat) use ($sesi, &$sudahDisetujui): void {
            if ($sudahDisetujui || $dimuat->getKey() !== $sesi->getKey()) {
                return;
            }

            $sudahDisetujui = true;
            CalibrationSession::whereKey($sesi->getKey())
                ->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        });

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/reject", [
                'catatan_revisi' => 'Titik 3 tidak masuk akal.',
            ])
            ->assertStatus(409);

        $this->assertTrue($sudahDisetujui);
        $this->assertSame(CalibrationSession::STATUS_DISETUJUI, $sesi->fresh()->status);
    }
}
