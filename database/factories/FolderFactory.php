<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Folder;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nempel ke organisasi yang udah ada kalau ada — biar test nggak
            // kebanjiran organisasi baru tiap bikin 1 folder.
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'customer_id' => fn () => Customer::query()->value('id') ?? Customer::factory(),
            'parent_id' => null,
            'nama' => 'Folder '.fake()->unique()->numberBetween(1, 9999),
            'is_root' => false,
        ];
    }

    /** Folder akar perusahaan — dibikin sistem, dikunci dari rename/pindah/hapus. */
    public function akar(): static
    {
        return $this->state(fn () => ['is_root' => true, 'parent_id' => null]);
    }
}
