<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Folder di Folder Manager. Pohonnya dangkal & disengaja: PT → tahun → file.
 *
 * @mixin IdeHelperFolder
 */
#[Fillable(['organization_id', 'parent_id', 'customer_id', 'nama', 'tipe', 'keterangan'])]
class Folder extends Model
{
    use HasFactory, SoftDeletes;

    /** Kebentuk sendiri dari data — nggak boleh dihapus/dipindah admin. */
    public const TIPE_SISTEM = 'sistem';

    public const TIPE_MANUAL = 'manual';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Folder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Folder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    /** @return HasMany<FolderFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(FolderFile::class);
    }

    /** Jalur yang dibaca manusia: `PT Tirta Gracia / 2026`. */
    public function jalur(): string
    {
        $bagian = [$this->nama];
        $induk = $this->parent;

        // Pohonnya dua tingkat, tapi tetap dibatesin biar data rusak
        // (folder nunjuk ke dirinya sendiri) nggak bikin loop tak hingga.
        for ($i = 0; $induk !== null && $i < 10; $i++) {
            array_unshift($bagian, $induk->nama);
            $induk = $induk->parent;
        }

        return implode(' / ', $bagian);
    }
}
