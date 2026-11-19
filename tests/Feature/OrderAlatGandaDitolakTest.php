<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji kontrak app lawan API (S-1): `POST /orders` dengan alat GANDA dalam satu order
 * menjawab 500 (pelanggaran indeks unik `order_items_order_id_equipment_id_unique`),
 * bukan 422. Layar mencegah dobel, tetapi klien lain / balapan bisa mengirimnya.
 */
class OrderAlatGandaDitolakTest extends TestCase
{
    use RefreshDatabase;

    public function test_alat_ganda_di_satu_order_ditolak_422_pada_baris_kedua(): void
    {
        $lab = Organization::factory()->create();
        $admin = User::factory()->admin()->create(['organization_id' => $lab->id]);
        $pt = Customer::factory()->create(['organization_id' => $lab->id]);
        $kategori = EquipmentCategory::factory()->create(['organization_id' => $lab->id]);
        $alat = Equipment::factory()->create(['organization_id' => $lab->id, 'customer_id' => $pt->id, 'equipment_category_id' => $kategori->id]);
        $lain = Equipment::factory()->create(['organization_id' => $lab->id, 'customer_id' => $pt->id, 'equipment_category_id' => $kategori->id]);

        $badan = fn (array $items): array => [
            'customer_id' => $pt->id,
            'tanggal_masuk' => now()->toDateString(),
            'items' => $items,
        ];

        $respons = $this->actingAs($admin)->postJson('/api/orders', $badan([
            ['equipment_id' => $alat->id], ['equipment_id' => $alat->id],
        ]));

        $respons->assertUnprocessable()->assertJsonValidationErrors(['items.0.equipment_id', 'items.1.equipment_id']);
        $this->assertSame(0, Order::query()->count(), 'Order tidak boleh setengah jadi.');

        // Dua alat BERBEDA tetap diterima.
        $this->actingAs($admin)->postJson('/api/orders', $badan([
            ['equipment_id' => $alat->id], ['equipment_id' => $lain->id],
        ]))->assertCreated();
    }
}
