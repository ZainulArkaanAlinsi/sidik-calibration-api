<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_category_id', 'nama_alat', 'parameter', 'range_min', 'range_max', 'range_note',
    'satuan', 'ketidakpastian_terbaik', 'satuan_ketidakpastian', 'faktor_cakupan', 'metode', 'keterangan',
    // Konstanta budget ketidakpastian penuh (mis. buffer pH) — lihat migrasi.
    'u_temperature', 'ci_suhu', 'u_perbedaan_suhu', 'ci_perbedaan_suhu',
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
            'u_temperature' => 'float',
            'ci_suhu' => 'float',
            'u_perbedaan_suhu' => 'float',
            'ci_perbedaan_suhu' => 'float',
        ];
    }

    /**
     * Budget penuh dirinci buat titik ini kalau semua konstanta suhu-nya keisi.
     * Kalau nggak, GumCalculator balik ke CMC-langsung / jalur generik.
     */
    public function punyaBudgetPenuh(): bool
    {
        return $this->u_temperature !== null
            && $this->ci_suhu !== null
            && $this->u_perbedaan_suhu !== null
            && $this->ci_perbedaan_suhu !== null;
    }

    /** @return BelongsTo<EquipmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }
}
