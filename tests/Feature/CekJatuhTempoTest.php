<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CekJatuhTempoTest extends TestCase
{
    use RefreshDatabase;

    private function alat(array $ubah = []): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => Customer::query()->value('id') ?? Customer::factory()->create()->id,
            'equipment_category_id' => EquipmentCategory::query()->value('id') ?? EquipmentCategory::factory()->create()->id,
            ...$ubah,
        ]);
    }

    public function test_admin_dapet_notifikasi_soal_alat_lewat_jatuh_tempo(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->alat(['tanggal_jatuh_tempo' => now()->subMonth()]); // overdue

        // Queue sync di test, jadi notifikasi database langsung ketulis.
        $this->artisan('alat:cek-jatuh-tempo')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('lewat jatuh tempo', $admin->notifications()->first()->data['body']);
    }

    public function test_alat_yang_masih_jauh_jatuh_tempo_nggak_bikin_notifikasi(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->alat(['tanggal_jatuh_tempo' => now()->addMonths(6)]); // masih lama

        $this->artisan('alat:cek-jatuh-tempo')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_notifikasi_cuma_ke_admin_bukan_teknisi(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $teknisi = User::factory()->create();
        $this->alat(['tanggal_jatuh_tempo' => now()->subWeek()]);

        $this->artisan('alat:cek-jatuh-tempo')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $teknisi->notifications()->count());
    }
}
