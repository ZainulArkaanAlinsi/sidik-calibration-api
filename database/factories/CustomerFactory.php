<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nempel ke organisasi yang udah ada kalau ada — biar test nggak
            // kebanjiran organisasi baru tiap bikin 1 pelanggan.
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'nama' => 'PT '.fake()->company(),
            'alamat' => fake()->address(),
            'contact_person' => fake()->name(),
            'telepon' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
        ];
    }
}
