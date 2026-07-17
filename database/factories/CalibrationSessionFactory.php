<?php

namespace Database\Factories;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalibrationSession>
 */
class CalibrationSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'equipment_id' => fn () => Equipment::factory(),
            'teknisi_id' => fn () => User::factory(),
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'keputusan' => 'PASS',
            'tanggal_kalibrasi' => now()->subDay(),
        ];
    }
}
