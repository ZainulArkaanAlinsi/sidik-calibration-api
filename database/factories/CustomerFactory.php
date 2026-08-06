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
            // `unique()` bukan hiasan: `customers` punya indeks unik
            // (organization_id, nama), dan `company()` sesekali ngasih nama yang
            // sama dua kali. Test yang bikin 20 pelanggan sekaligus jadi gagal
            // acak — kelihatannya kayak bug kode, padahal cuma tabrakan faker.
            'nama' => 'PT '.fake()->unique()->company(),
            'alamat' => fake()->address(),
            'contact_person' => fake()->name(),
            'telepon' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
        ];
    }
}
