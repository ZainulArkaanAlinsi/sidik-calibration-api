<?php

namespace Database\Factories;

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
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'nama' => 'Arsip '.fake()->unique()->word(),
            'tipe' => Folder::TIPE_MANUAL,
        ];
    }

    /** Folder yang kebentuk otomatis dari data — nggak boleh direname. */
    public function sistem(): static
    {
        return $this->state(fn (array $attributes) => ['tipe' => Folder::TIPE_SISTEM]);
    }
}
