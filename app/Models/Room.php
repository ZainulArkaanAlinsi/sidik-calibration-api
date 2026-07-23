<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ruangan lab — master data, dipakai buat nyatet di mana sesi dikerjain.
 *
 * @mixin IdeHelperRoom
 */
#[Fillable([
    'organization_id', 'kode', 'nama', 'lokasi', 'suhu_min', 'suhu_max',
    'kelembaban_min', 'kelembaban_max', 'keterangan', 'aktif',
])]
class Room extends Model
{
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'suhu_min' => 'float',
            'suhu_max' => 'float',
            'kelembaban_min' => 'float',
            'kelembaban_max' => 'float',
            'aktif' => 'boolean',
        ];
    }

    /**
     * Ruangan yang masih dipakai. Ruangan lama sengaja dinonaktifin, bukan
     * dihapus — sesi tahun lalu yang nunjuk ke situ harus tetap kebaca pas audit.
     *
     * @param  Builder<Room>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
