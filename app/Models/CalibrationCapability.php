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
    // Kurva nilai standar terhadap suhu larutan: y = a·x² + b·x + c.
    'koef_suhu_a', 'koef_suhu_b', 'koef_suhu_c',
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
            'koef_suhu_a' => 'float',
            'koef_suhu_b' => 'float',
            'koef_suhu_c' => 'float',
        ];
    }

    /**
     * Nilai standar pada suhu larutan tertentu: y = a·x² + b·x + c.
     *
     * Contoh (buffer pH 4, koefisien 3e-5 / −0.0023 / 4.0455):
     *   22.2 °C → 4.0092252 — bukan 3.99 yang tertulis di botol.
     *
     * Balik null kalau kurvanya nggak didefinisiin buat titik ini; pemanggilnya
     * wajib nganggep itu "nggak bisa diperiksa", bukan "nilainya nol".
     */
    public function nilaiPadaSuhu(float $suhu): ?float
    {
        if ($this->koef_suhu_a === null || $this->koef_suhu_b === null || $this->koef_suhu_c === null) {
            return null;
        }

        return $this->koef_suhu_a * $suhu ** 2 + $this->koef_suhu_b * $suhu + $this->koef_suhu_c;
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
