<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nempel ke organisasi yang udah ada kalau ada — biar test nggak
            // kebanjiran organisasi baru tiap bikin 1 ruangan.
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'kode' => 'R-'.fake()->unique()->numberBetween(10, 99),
            'nama' => 'Ruang '.fake()->randomElement(['Kalibrasi Massa', 'Kalibrasi Suhu', 'Kalibrasi Tekanan', 'Terima Alat']),
            'lokasi' => 'Lantai '.fake()->numberBetween(1, 3),
            'suhu_min' => 18,
            'suhu_max' => 25,
            'kelembaban_min' => 40,
            'kelembaban_max' => 60,
            'aktif' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['aktif' => false]);
    }
}
