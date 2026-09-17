<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerMember>
 */
class CustomerMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'customer_id' => fn () => Customer::factory(),
            'user_id' => fn () => User::factory(),
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ];
    }

    public function picUtama(): static
    {
        return $this->state(fn (): array => ['peran' => CustomerMember::PERAN_PIC_UTAMA]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn (): array => [
            'status' => CustomerMember::STATUS_NONAKTIF,
            'dinonaktifkan_pada' => now(),
        ]);
    }
}
