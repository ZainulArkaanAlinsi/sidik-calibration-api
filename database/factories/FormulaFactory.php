<?php

namespace Database\Factories;

use App\Models\Formula;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Formula> */
class FormulaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->min('id') ?? Organization::factory(),
            'kode' => 'rumus-'.fake()->unique()->numberBetween(1000, 9999),
            'nama' => 'Rumus '.fake()->word(),
            'besaran' => 'ph',
            'deskripsi' => null,
        ];
    }
}
