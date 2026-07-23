<?php

namespace Database\Factories;

use App\Models\CalibrationMethod;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalibrationMethod>
 */
class CalibrationMethodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'kode' => 'SIDIK-IK-CAL-'.fake()->unique()->numberBetween(1000, 9999),
            'revisi' => '6',
            'nama' => 'Instruksi Kerja Kalibrasi pH Meter',
            'berlaku_mulai' => now()->subYear(),
            'aktif' => true,
        ];
    }
}
