<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'equipment_id', 'teknisi_id', 'standard_id', 'reviewed_by', 'nomor_sesi',
    'input_method', 'status', 'keputusan', 'tanggal_kalibrasi', 'lokasi', 'suhu_ruang',
    'kelembaban', 'catatan_revisi', 'submitted_at', 'reviewed_at',
])]
class CalibrationSession extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_PERLU_REVISI = 'perlu_revisi';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_kalibrasi' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'suhu_ruang' => 'float',
            'kelembaban' => 'float',
        ];
    }

    /**
     * Titik ukur yang NENTUIN hasil sesi: yang marginnya paling mepet ke batas
     * toleransi (|error| + U terbesar).
     *
     * Sesi punya banyak titik, tapi sertifikat cuma nampilin satu keputusan —
     * dan keputusannya digerakin sama titik terburuk. Satu titik FAIL bikin
     * seluruh sesi FAIL, walaupun titik lainnya lolos semua.
     */
    public function titikPenentu(): ?UncertaintyCalculation
    {
        return $this->uncertaintyCalculations
            ->sortByDesc(fn (UncertaintyCalculation $titik): float => abs($titik->error) + $titik->ketidakpastian_diperluas)
            ->first();
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    /** @return BelongsTo<Standard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    /** @return HasMany<RawMeasurement, $this> */
    public function rawMeasurements(): HasMany
    {
        return $this->hasMany(RawMeasurement::class);
    }

    /** @return HasMany<UncertaintyCalculation, $this> */
    public function uncertaintyCalculations(): HasMany
    {
        return $this->hasMany(UncertaintyCalculation::class);
    }

    /** @return HasOne<Certificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }
}
