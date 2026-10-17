<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paket data acuan satu profil alat per organisasi (Studio Data Acuan,
 * 03-SDD §2.1). Wadahnya; isinya ada di `PaketAcuanVersi`.
 *
 * `kode_profil` yang dirujuk kode program, bukan `nama` — pola yang sama
 * dengan `Formula::kode`.
 *
 * @mixin IdeHelperPaketAcuan
 */
#[Fillable(['organization_id', 'kode_profil', 'nama', 'skema_versi'])]
class PaketAcuan extends Model
{
    use Diaudit, SoftDeletes;

    protected $table = 'paket_acuan';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skema_versi' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<PaketAcuanVersi, $this> */
    public function versi(): HasMany
    {
        return $this->hasMany(PaketAcuanVersi::class);
    }
}
