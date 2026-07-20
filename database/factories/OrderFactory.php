<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nempel ke organisasi & pelanggan yang udah ada kalau ada — biar
            // test nggak kebanjiran organisasi baru tiap bikin 1 order.
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'customer_id' => fn () => Customer::query()->value('id') ?? Customer::factory(),
            'nomor' => 'ORD/'.now()->format('Y/m').'/'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'tanggal_masuk' => now()->subDays(fake()->numberBetween(0, 14)),
            'status' => Order::STATUS_BARU,
        ];
    }

    public function selesai(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Order::STATUS_SELESAI]);
    }
}
