<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali pindai lembar kerja lewat OCR template lokal.
 *
 * @mixin IdeHelperWorksheetScan
 */
#[Fillable([
    'organization_id', 'calibration_session_id', 'user_id',
    'template_id', 'template_versi', 'pipeline_versi', 'aturan_versi',
    'status', 'alasan_gagal', 'pesan',
    'total_sel', 'sel_hijau', 'sel_kuning', 'sel_merah', 'sel_kosong',
    'kualitas', 'geometri', 'perangkat', 'payload', 'hasil',
    'citra_path', 'citra_warp_path', 'diambil_pada',
])]
class WorksheetScan extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'template_versi' => 'integer',
            'total_sel' => 'integer',
            'sel_hijau' => 'integer',
            'sel_kuning' => 'integer',
            'sel_merah' => 'integer',
            'sel_kosong' => 'integer',
            'kualitas' => 'array',
            'geometri' => 'array',
            'perangkat' => 'array',
            'payload' => 'array',
            'hasil' => 'array',
            'diambil_pada' => 'datetime',
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

    /** @return HasMany<WorksheetScanCell, $this> */
    public function cells(): HasMany
    {
        return $this->hasMany(WorksheetScanCell::class);
    }

    /**
     * Boleh diisikan ke lembar kerja atau nggak.
     *
     * Scan yang ditolak TETAP disimpen (bahan nyetel ambang), jadi keberadaan
     * barisnya bukan tanda hasilnya kepakai — statusnya yang nentuin.
     */
    public function bisaDipakai(): bool
    {
        return in_array($this->status, ['ok', 'perlu_review'], true);
    }
}
