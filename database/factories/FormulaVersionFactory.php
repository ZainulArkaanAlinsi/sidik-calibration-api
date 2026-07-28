<?php

namespace Database\Factories;

use App\Models\Formula;
use App\Models\FormulaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaVersion> */
class FormulaVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $formula = Formula::factory();

        return [
            'formula_id' => $formula,
            'organization_id' => fn (array $a) => Formula::find($a['formula_id'])?->organization_id,
            'nomor_versi' => 1,
            'sumber' => FormulaVersion::SUMBER_KODE,
            'parameter' => ['faktor_cakupan_k' => 2],
            'ekspresi' => null,
            'status' => FormulaVersion::STATUS_AKTIF,
            'effective_from' => '2000-01-01',
            'effective_until' => null,
            'catatan' => null,
            'created_by' => null,
        ];
    }
}
