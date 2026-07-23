<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperCalibrationCapability
 */
#[Fillable([
    'equipment_category_id', 'nama_alat', 'parameter', 'range_min', 'range_max', 'range_note',
    'satuan', 'ketidakpastian_terbaik', 'satuan_ketidakpastian', 'faktor_cakupan', 'metode', 'keterangan',
])]
class CalibrationCapability extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'range_min' => 'float',
            'range_max' => 'float',
            'ketidakpastian_terbaik' => 'float',
            'faktor_cakupan' => 'float',
        ];
    }

    /** @return BelongsTo<EquipmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }
}
