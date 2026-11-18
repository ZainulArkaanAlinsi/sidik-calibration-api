<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperEquipmentCategory
 */
#[Fillable(['organization_id', 'kode', 'nama', 'worksheet_schema'])]
class EquipmentCategory extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'worksheet_schema' => 'array',
            // Nonaktif != dihapus: kategori nonaktif tetap terbaca alat & sesi
            // lamanya, hanya berhenti ditawarkan di form baru. Ditulis lewat
            // `CategoryController` (query builder + `catatAudit`), bukan
            // mass-assignment, jadi TIDAK ada di `#[Fillable]`.
            'nonaktif_pada' => 'datetime',
        ];
    }

    public function aktif(): bool
    {
        return $this->nonaktif_pada === null;
    }

    /**
     * Hanya kategori yang masih ditawarkan di form baru.
     *
     * @param  Builder<EquipmentCategory>  $query
     * @return Builder<EquipmentCategory>
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('equipment_categories.nonaktif_pada');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<CalibrationCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(CalibrationCapability::class);
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
