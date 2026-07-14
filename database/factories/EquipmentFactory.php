<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'customer_id' => fn () => Customer::query()->value('id') ?? Customer::factory(),
            'equipment_category_id' => fn () => EquipmentCategory::query()->value('id') ?? EquipmentCategory::factory(),
            'nama_alat' => fake()->randomElement(['Jangka Sorong', 'Micrometer', 'Timbangan', 'Oven']),
            'merk' => fake()->randomElement(['Mitutoyo', 'Ohaus', 'Memmert']),
            'serial_number' => strtoupper(fake()->unique()->bothify('??-####-##')),
            'satuan' => 'mm',
            'tanggal_kalibrasi_terakhir' => now()->subMonths(6),
            'tanggal_jatuh_tempo' => now()->addMonths(6),
            'status' => Equipment::STATUS_AKTIF,
        ];
    }

    /** Alat yang udah lewat jatuh tempo — di API statusnya jadi `overdue`. */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'tanggal_jatuh_tempo' => now()->subMonth(),
            'status' => Equipment::STATUS_AKTIF,
        ]);
    }
}
