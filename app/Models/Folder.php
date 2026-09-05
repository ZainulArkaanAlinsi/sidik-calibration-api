<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
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
    use Diaudit, HasFactory, SoftDeletes;

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

    /**
     * Jalur folder ini dari AKAR ke dirinya sendiri — bahan breadcrumb.
     *
     * Naik lewat `parent` satu-satu, bukan sekali query rekursif: pohon arsip
     * dangkal (PT -> tahun -> paling banter satu-dua folder manual), jadi
     * dalamnya 2-4 baris dan biayanya nggak sepadan dengan CTE.
     *
     * Ada penjaga siklus. Folder yang `parent_id`-nya nunjuk balik ke
     * keturunannya sendiri lepas dari pohon dan HILANG dari semua layar —
     * `pindah()` udah nolak bentuk itu, tapi baris yang terlanjur ada di
     * produksi sebelum penjaganya dipasang nggak kesentuh, dan di sini dia
     * bakal jadi loop tak berujung yang mematikan request-nya.
     *
     * @return list<Folder>
     */
    public function jalurKeAkar(): array
    {
        $jalur = [];
        $terlihat = [];

        for ($f = $this; $f !== null; $f = $f->parent) {
            if (isset($terlihat[$f->id])) {
                break;
            }
            $terlihat[$f->id] = true;
            $jalur[] = $f;
        }

        return array_reverse($jalur);
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
