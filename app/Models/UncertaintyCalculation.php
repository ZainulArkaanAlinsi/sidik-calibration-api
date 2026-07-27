<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperUncertaintyCalculation
 */
#[Fillable([
    'calibration_session_id', 'standard_id', 'titik_ke', 'titik_ukur', 'rata_rata', 'error', 'koreksi',
    'standar_deviasi', 'jumlah_pengulangan', 'type_a', 'type_b_components', 'type_b',
    'ketidakpastian_gabungan', 'faktor_cakupan_k', 'derajat_kebebasan_efektif',
    'ketidakpastian_diperluas', 'toleransi', 'keputusan', 'metode', 'calculated_at',
    'formula_version_id',
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

    /**
     * Standar yang beneran dipakai buat titik ini — bisa beda dari standar
     * default sesi. `withTrashed()` — sama kayak `CalibrationSession::standard()`
     * — karena standar yang udah di-retire tetap harus bisa ditelusuri di
     * sertifikat lama.
     */
    /** @return BelongsTo<Standard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class)->withTrashed();
    }

    /**
     * Versi rumus yang MENGHASILKAN angka di baris ini (Keputusan 5).
     *
     * Ini yang bikin hasil hitung lama tetap bisa dijelasin sesudah rumusnya
     * diubah. `null` cuma buat baris yang udah ada sebelum fitur ini dipasang —
     * dan itu jujur: versinya beneran nggak diketahui, dan nebak lebih buruk.
     *
     * @return BelongsTo<FormulaVersion, $this>
     */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }
}
