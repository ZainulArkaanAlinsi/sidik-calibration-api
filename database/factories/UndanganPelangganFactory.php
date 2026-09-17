<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Organization;
use App\Models\UndanganPelanggan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<UndanganPelanggan>
 */
class UndanganPelangganFactory extends Factory
{
    /**
     * Kode mentahnya disimpan di `$kodeTerakhir` supaya test bisa menukarnya.
     * Yang masuk database tetap hash-nya saja.
     */
    public static ?string $kodeTerakhir = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kode = Str::upper(Str::random(8));
        self::$kodeTerakhir = $kode;

        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'customer_id' => fn () => Customer::factory(),
            'email' => fake()->unique()->safeEmail(),
            'kode_hash' => Hash::make($kode),
            'peran' => CustomerMember::PERAN_STAF,
            'kedaluwarsa_pada' => now()->addDays(UndanganPelanggan::BERLAKU_HARI),
        ];
    }

    public function kedaluwarsa(): static
    {
        return $this->state(fn (): array => ['kedaluwarsa_pada' => now()->subDay()]);
    }

    public function sudahDipakai(): static
    {
        return $this->state(fn (): array => ['dipakai_pada' => now()]);
    }
}
