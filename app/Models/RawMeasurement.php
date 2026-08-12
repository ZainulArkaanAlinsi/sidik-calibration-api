<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperRawMeasurement
 */
#[Fillable([
    'calibration_session_id', 'titik_ke', 'pembacaan_ke', 'tahap', 'titik_ukur', 'standard_id',
    'pembacaan', 'suhu', 'satuan', 'input_source', 'ocr_confidence', 'ocr_raw_text', 'photo_path',
    'is_verified',
])]
class RawMeasurement extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'titik_ukur' => 'float',
            'pembacaan' => 'float',
            // Suhu larutan waktu pembacaan diambil — pH bergantung suhu, jadi
            // ini bagian dari data, bukan catatan tambahan.
            'suhu' => 'float',
            'ocr_confidence' => 'float',
            'is_verified' => 'boolean',
        ];
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /**
     * Standar acuan yang dicentang buat titik ini.
     *
     * Null itu sah — baris draft yang standarnya belum dipilih. Yang dihitung
     * pakai standar mana tetap dicatat di `uncertainty_calculations`; kolom di
     * sini gunanya mulangin CENTANGNYA waktu lembarnya dibuka lagi, termasuk
     * buat titik yang belum pernah kehitung.
     *
     * @return BelongsTo<Standard, $this>
     */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }
}
