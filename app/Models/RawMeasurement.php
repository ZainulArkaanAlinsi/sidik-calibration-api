<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'calibration_session_id', 'titik_ke', 'pembacaan_ke', 'tahap', 'titik_ukur', 'pembacaan', 'satuan',
    'input_source', 'ocr_confidence', 'ocr_raw_text', 'photo_path', 'is_verified',
])]
class RawMeasurement extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'titik_ukur' => 'float',
            'pembacaan' => 'float',
            'ocr_confidence' => 'float',
            'is_verified' => 'boolean',
        ];
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }
}
