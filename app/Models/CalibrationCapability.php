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
     * Konstanta budgetnya lengkap, jadi `GumCalculator` boleh nyusun budget
     * 5 komponen buat titik yang pakai kemampuan ini.
     *
     * Wajib LENGKAP keempatnya, bukan sebagian: budget setengah jadi ngasih
     * angka yang kelihatan sah tapi ngelewatin komponen yang nggak keisi, dan
     * itu lebih berbahaya daripada balik ke jalur CMC yang jelas-jelas
     * penyederhanaan. Yang belum lengkap tetap lewat `hitungDariKemampuan()`.
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
