<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu kali AI Vision baca lembar kerja dari foto.
 *
 * @mixin IdeHelperWorksheetExtractionLog
 */
#[Fillable([
    'calibration_session_id', 'user_id', 'model', 'status',
    'raw_model_response', 'extracted', 'technician_corrections',
    'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'error',
    // Audit OCR awan (Google Vision): tanpa isi gambar dan tanpa teks hasil.
    'unit_vision', 'ukuran_gambar_byte', 'durasi_ms',
])]
class WorksheetExtractionLog extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw_model_response' => 'array',
            'extracted' => 'array',
            'technician_corrections' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'unit_vision' => 'integer',
            'ukuran_gambar_byte' => 'integer',
            'durasi_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
