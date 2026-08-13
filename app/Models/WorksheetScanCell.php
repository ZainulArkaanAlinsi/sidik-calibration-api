<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu sel lembar kerja pada satu kali pindai — termasuk apa yang akhirnya
 * dipakai teknisi (ground truth).
 *
 * @mixin IdeHelperWorksheetScanCell
 */
#[Fillable([
    'worksheet_scan_id', 'kunci', 'tabel_id', 'baris_ke', 'repeat_no', 'field_id',
    'titik_ukur', 'standard_id', 'teks_mentah', 'teks_normal', 'nilai',
    'confidence_ocr', 'confidence_akhir', 'status', 'alasan', 'normalisasi', 'kotak',
    'nilai_final', 'cocok', 'dikoreksi_oleh', 'dikoreksi_pada', 'crop_path',
])]
class WorksheetScanCell extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'baris_ke' => 'integer',
            'repeat_no' => 'integer',
            'titik_ukur' => 'float',
            'nilai' => 'float',
            'nilai_final' => 'float',
            'confidence_ocr' => 'float',
            'confidence_akhir' => 'float',
            'alasan' => 'array',
            'normalisasi' => 'array',
            'kotak' => 'array',
            'cocok' => 'boolean',
            'dikoreksi_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorksheetScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(WorksheetScan::class, 'worksheet_scan_id');
    }

    /** @return BelongsTo<Standard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dikoreksiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh');
    }
}
