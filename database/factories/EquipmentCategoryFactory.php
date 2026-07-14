<?php

namespace Database\Factories;

use App\Models\EquipmentCategory;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EquipmentCategory>
 */
class EquipmentCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nama = fake()->unique()->randomElement(['Panjang', 'Massa', 'Volume', 'Tekanan', 'Gaya', 'Aliran']);

        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'kode' => Str::slug($nama),
            'nama' => $nama,
        ];
    }
}
