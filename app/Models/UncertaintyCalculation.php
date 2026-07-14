<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'calibration_session_id', 'titik_ke', 'titik_ukur', 'rata_rata', 'error', 'koreksi',
    'standar_deviasi', 'jumlah_pengulangan', 'type_a', 'type_b_components', 'type_b',
    'ketidakpastian_gabungan', 'faktor_cakupan_k', 'derajat_kebebasan_efektif',
    'ketidakpastian_diperluas', 'toleransi', 'keputusan', 'calculated_at',
])]
class UncertaintyCalculation extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type_b_components' => 'array',
            'calculated_at' => 'datetime',
            'titik_ukur' => 'float',
            'rata_rata' => 'float',
            'error' => 'float',
            'koreksi' => 'float',
            'standar_deviasi' => 'float',
            'type_a' => 'float',
            'type_b' => 'float',
            'ketidakpastian_gabungan' => 'float',
            'faktor_cakupan_k' => 'float',
            'ketidakpastian_diperluas' => 'float',
            'toleransi' => 'float',
        ];
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }
}
