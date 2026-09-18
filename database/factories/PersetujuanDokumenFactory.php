<?php

namespace Database\Factories;

use App\Models\PersetujuanDokumen;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersetujuanDokumen>
 */
class PersetujuanDokumenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory(),
            'jenis' => PersetujuanDokumen::JENIS_KEBIJAKAN_PRIVASI,
            'versi' => '1.0',
            'disetujui_pada' => now(),
            'ip' => '203.0.113.10',
        ];
    }
}
