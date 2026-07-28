<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Instruksi Kerja kalibrasi — "Calibration Method" di sertifikat.
 *
 * @mixin IdeHelperCalibrationMethod
 */
#[Fillable([
    'organization_id', 'equipment_category_id', 'kode', 'revisi', 'nama',
    'berlaku_mulai', 'aktif', 'keterangan',
])]
class CalibrationMethod extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_mulai' => 'date',
            'aktif' => 'boolean',
        ];
    }

    /**
     * Bentuk yang dicetak di sertifikat: `SIDIK-IK-CAL-0506_Rev.6`. Revisi
     * kosong = kodenya doang, bukan `_Rev.` gantung.
     */
    public function kodeLengkap(): string
    {
        return $this->revisi ? "{$this->kode}_Rev.{$this->revisi}" : (string) $this->kode;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<EquipmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }
}
