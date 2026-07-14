<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Standard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Standard>
 */
class StandardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'nama' => 'Gauge Block Set Grade 0',
            'merk' => 'Mitutoyo',
            'serial_number' => strtoupper(fake()->unique()->bothify('STD-####')),
            'no_sertifikat' => 'SNSU/2025/P-0142',
            'tertelusur_ke' => 'SNSU-BSN',
            'berlaku_sampai' => now()->addYear(),
            // Angka dari sertifikat standar itu ketidakpastian DIPERLUAS (udah
            // dikali k), jadi dua-duanya harus ada biar bisa dibagi balik.
            'ketidakpastian' => 0.0004,
            'satuan_ketidakpastian' => 'mm',
            'faktor_cakupan' => 2,
        ];
    }

    /** Standar yang sertifikatnya udah lewat — nggak boleh dipakai kalibrasi. */
    public function kadaluarsa(): static
    {
        return $this->state(fn (array $attributes) => [
            'berlaku_sampai' => now()->subMonth(),
        ]);
    }
}
