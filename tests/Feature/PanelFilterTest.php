<?php

namespace Tests\Feature;

use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Resources\Standards\Pages\ListStandards;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filter "Alat overdue" & "Kadaluarsa" — dua toggle yang nyontek scope/method
 * yang udah dipakai di widget dashboard & API, biar admin bisa langsung liat
 * daftarnya di panel, bukan cuma angkanya doang.
 */
class PanelFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_alat_overdue_di_resource_equipment(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $category = EquipmentCategory::factory()->create();

        $overdue = Equipment::factory()->overdue()->create([
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
        ]);
        $aktif = Equipment::factory()->create([
            'customer_id' => $customer->id,
            'equipment_category_id' => $category->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ListEquipment::class)
            ->assertCanSeeTableRecords([$overdue, $aktif])
            ->filterTable('overdue')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$aktif]);
    }

    public function test_filter_standar_kadaluarsa_di_resource_standards(): void
    {
        Organization::factory()->create();
        $admin = User::factory()->admin()->create();

        $kadaluarsa = Standard::factory()->kadaluarsa()->create();
        $berlaku = Standard::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListStandards::class)
            ->assertCanSeeTableRecords([$kadaluarsa, $berlaku])
            ->filterTable('kadaluarsa')
            ->assertCanSeeTableRecords([$kadaluarsa])
            ->assertCanNotSeeTableRecords([$berlaku]);
    }
}
