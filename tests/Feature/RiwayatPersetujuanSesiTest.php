<?php

namespace Tests\Feature;

use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Models\AuditLog;
use App\Models\CalibrationSession;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Riwayat persetujuan sesi: tiap penolakan beserta alasannya tetap terbaca
 * walau kolom `catatan_revisi` sesi ditimpa penolakan berikutnya.
 *
 * Sumbernya `audit_logs` (trait Diaudit) — test ini juga penjaga jejaknya:
 * kalau penolakan suatu saat diubah ke query builder, baris audit tidak lahir
 * dan riwayatnya kosong tanpa error.
 */
class RiwayatPersetujuanSesiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Organization, 1: User, 2: User, 3: CalibrationSession} */
    private function siapkan(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $org->id, 'name' => 'Admin Pemeriksa']);
        $teknisi = User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
            'name' => 'Teknisi Lapangan',
        ]);
        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => EquipmentCategory::factory()->create(['organization_id' => $org->id])->id,
        ]);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        return [$org, $admin, $teknisi, $sesi];
    }

    private function tolak(User $admin, CalibrationSession $sesi, string $alasan, ?array $kolom = null): void
    {
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/reject", array_filter([
                'catatan_revisi' => $alasan,
                'revisi_field' => $kolom,
            ], fn ($v) => $v !== null))
            ->assertOk();
    }

    /** Teknisi mengirim ulang — lewat model, persis jalur `update()` controller. */
    private function ajukanUlang(User $teknisi, CalibrationSession $sesi): void
    {
        $this->be($teknisi);
        $sesi->fresh()->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);
    }

    public function test_tiap_penolakan_terbaca_lengkap_walau_catatan_sesi_ditimpa(): void
    {
        [, $admin, $teknisi, $sesi] = $this->siapkan();

        $this->tolak($admin, $sesi, 'ALASAN PERTAMA: titik 3 meleset', ['alat_merk', 'sel:sesudah_adjustment:7:pembacaan:1']);
        $this->ajukanUlang($teknisi, $sesi);
        $this->tolak($admin, $sesi, 'ALASAN KEDUA: suhu ruang kosong');
        $this->ajukanUlang($teknisi, $sesi);
        // Alasan SAMA persis dengan penolakan sebelumnya: `new_data` audit
        // tidak membawa `catatan_revisi`, riwayat wajib tetap menyebutnya.
        $this->tolak($admin, $sesi, 'ALASAN KEDUA: suhu ruang kosong');

        $this->assertSame('ALASAN KEDUA: suhu ruang kosong', $sesi->fresh()->catatan_revisi, 'Prasyarat: kolom sesi hanya menyimpan alasan terakhir.');

        $riwayat = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/calibrations/{$sesi->id}/riwayat-persetujuan")
            ->assertOk()
            ->json('data');

        $tolak = array_values(array_filter($riwayat, fn (array $p): bool => $p['jenis'] === 'ditolak'));
        $this->assertCount(3, $tolak);
        $this->assertSame('ALASAN PERTAMA: titik 3 meleset', $tolak[0]['alasan']);
        $this->assertSame(['alat_merk', 'sel:sesudah_adjustment:7:pembacaan:1'], $tolak[0]['kolom']);
        $this->assertSame('ALASAN KEDUA: suhu ruang kosong', $tolak[1]['alasan']);
        $this->assertSame([], $tolak[1]['kolom']);
        $this->assertSame('ALASAN KEDUA: suhu ruang kosong', $tolak[2]['alasan']);
        foreach ($tolak as $p) {
            $this->assertSame('Admin Pemeriksa', $p['oleh']['nama']);
            $this->assertArrayNotHasKey('email', $p['oleh']);
            $this->assertNotNull($p['waktu']);
        }

        $ulang = array_values(array_filter($riwayat, fn (array $p): bool => $p['jenis'] === 'diajukan_ulang'));
        $this->assertCount(2, $ulang);
        $this->assertSame('Teknisi Lapangan', $ulang[0]['oleh']['nama']);

        // Urut waktu: tolak → ajukan ulang → tolak → ajukan ulang → tolak.
        $urutan = array_column(array_values(array_filter(
            $riwayat,
            fn (array $p): bool => in_array($p['jenis'], ['ditolak', 'diajukan_ulang'], true),
        )), 'jenis');
        $this->assertSame(['ditolak', 'diajukan_ulang', 'ditolak', 'diajukan_ulang', 'ditolak'], $urutan);
    }

    /**
     * Sesi asli lahir sebagai DRAFT (`CalibrationController::store`). Baris
     * audit kelahirannya bukan peristiwa "kembali ke draft" (tinjauan 6 Okt).
     */
    public function test_sesi_yang_lahir_sebagai_draft_tidak_diawali_kembali_ke_draft(): void
    {
        [, $admin, $teknisi, $sesi] = $this->siapkan();
        $draft = CalibrationSession::factory()->create([
            'organization_id' => $sesi->organization_id,
            'equipment_id' => $sesi->equipment_id,
            'teknisi_id' => $teknisi->id,
            'status' => CalibrationSession::STATUS_DRAFT,
        ]);
        $this->ajukanUlang($teknisi, $draft);
        $this->tolak($admin, $draft, 'Alasan sesudah draft diajukan');

        $jenis = array_column(
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/calibrations/{$draft->id}/riwayat-persetujuan")
                ->assertOk()
                ->json('data'),
            'jenis',
        );

        $this->assertSame(['diajukan', 'ditolak'], $jenis);
    }

    public function test_penolakan_meninggalkan_jejak_audit_atas_nama_admin(): void
    {
        [, $admin, , $sesi] = $this->siapkan();

        $this->tolak($admin, $sesi, 'Alasan yang wajib tercatat');

        $baris = AuditLog::where('entity_type', 'calibration_sessions')
            ->where('entity_id', $sesi->id)
            ->where('action', AuditLog::ACTION_DIUBAH)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, (int) $baris->changed_by);
        $this->assertSame(CalibrationSession::STATUS_PERLU_REVISI, $baris->new_data['status']);
        $this->assertSame('Alasan yang wajib tercatat', $baris->new_data['catatan_revisi']);
    }

    public function test_hanya_admin_dan_super_admin_yang_boleh_membaca(): void
    {
        [$org, $admin, $teknisi, $sesi] = $this->siapkan();
        $this->tolak($admin, $sesi, 'Alasan untuk uji akses');

        $url = "/api/calibrations/{$sesi->id}/riwayat-persetujuan";

        $this->actingAs($teknisi, 'sanctum')->getJson($url)->assertForbidden();

        $viewer = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer, 'sanctum')->getJson($url)->assertForbidden();

        $super = User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($super, 'sanctum')->getJson($url)->assertOk();

        $adminLain = User::factory()->admin()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($adminLain, 'sanctum')->getJson($url)->assertNotFound();
    }

    /**
     * Modal panel membaca kunci dari pembaca riwayat — satu nama kunci meleset
     * membuatnya kosong di produksi tanpa error di test rute.
     */
    public function test_modal_riwayat_di_panel_kerender_dengan_alasan_lama(): void
    {
        [, $admin, $teknisi, $sesi] = $this->siapkan();
        $this->tolak($admin, $sesi, 'ALASAN LAMA YANG DITIMPA');
        $this->ajukanUlang($teknisi, $sesi);
        $this->tolak($admin, $sesi, 'ALASAN TERBARU');

        Livewire::actingAs($admin)
            ->test(ListCalibrationSessions::class)
            ->mountAction(TestAction::make('riwayat')->table($sesi->fresh()))
            ->assertSuccessful()
            ->assertMountedActionModalSee(['ALASAN LAMA YANG DITIMPA', 'ALASAN TERBARU', 'Ditolak 2 kali']);
    }

    public function test_alasan_lebih_dari_2000_karakter_ditolak_422(): void
    {
        [, $admin, , $sesi] = $this->siapkan();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/calibrations/{$sesi->id}/reject", ['catatan_revisi' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('catatan_revisi');
    }
}
