<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\WorksheetScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorksheetScan>
 */
class WorksheetScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),

            // `ph_meter` karena itu satu-satunya lembar yang berkas geometrinya
            // `terverifikasi: true` DAN dipakai fixture `WorksheetScanTest`.
            // Bikin default-nya lembar yang belum terverifikasi bikin tiap
            // pemakai factory ini harus tau soal itu duluan.
            'template_id' => 'ph_meter',
            'template_versi' => 1,
            'pipeline_versi' => '1.0',
            'aturan_versi' => '1.0',
            'status' => 'ok',
        ];
    }
}
