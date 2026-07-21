<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Folder arsip yang bisa disusun bebas sama user.
 *
 * Tiap folder kekunci ke satu perusahaan (`customer_id`) — lihat alasannya di
 * migrasi `create_folders_table`. Folder akar (`is_root`) dibikin sistem, satu
 * per perusahaan, dan jadi tempat jatuh otomatis buat sesi baru.
 */
#[Fillable(['organization_id', 'customer_id', 'parent_id', 'nama', 'is_root'])]
class Folder extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Batas kedalaman. Bukan batas teknis — pohon sedalam apa pun sebenernya
     * jalan. Ini jaring pengaman UI: breadcrumb sepuluh tingkat udah nggak
     * kebaca di layar HP, dan pohon yang kelewat dalem biasanya tanda salah
     * susun, bukan kebutuhan beneran.
     */
    public const MAKS_KEDALAMAN = 10;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_root' => 'boolean'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Folder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /** @return HasMany<Folder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    /** @return HasMany<CalibrationSession, $this> */
    public function calibrationSessions(): HasMany
    {
        return $this->hasMany(CalibrationSession::class);
    }

    /**
     * @param  Builder<Folder>  $query
     */
    public function scopeAkar(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Folder akar milik satu perusahaan — dibikin kalau belum ada.
     *
     * Dipanggil waktu sesi baru disimpen, jadi perusahaan yang belum pernah
     * dikalibrasi nggak perlu punya folder duluan. `firstOrCreate` bukan
     * `create`: dua sesi yang masuk barengan buat perusahaan yang sama nggak
     * boleh bikin dua akar.
     */
    public static function akarUntuk(Customer $customer): self
    {
        return static::firstOrCreate(
            ['customer_id' => $customer->id, 'parent_id' => null],
            [
                'organization_id' => $customer->organization_id,
                'nama' => $customer->nama,
                'is_root' => true,
            ],
        );
    }

    /**
     * Rantai folder dari akar sampai folder ini — buat breadcrumb.
     *
     * @return Collection<int, Folder>
     */
    public function breadcrumb(): Collection
    {
        $rantai = collect([$this]);
        $folder = $this;

        // Dibatesin MAKS_KEDALAMAN, bukan `while ($folder->parent)` polos:
        // kalau data terlanjur bengkok dan ada siklus, loop tanpa batas bikin
        // request-nya gantung sampai timeout, bukan ngasih error yang kebaca.
        for ($i = 0; $i < self::MAKS_KEDALAMAN && $folder->parent_id; $i++) {
            $folder = $folder->parent()->withTrashed()->firstOrFail();
            $rantai->prepend($folder);
        }

        return $rantai;
    }

    /** Jarak folder ini dari akar. Akar = 0. */
    public function kedalaman(): int
    {
        return $this->breadcrumb()->count() - 1;
    }

    /**
     * ID folder ini + seluruh keturunannya.
     *
     * Dipakai buat nolak "pindahin folder ke dalam anaknya sendiri" — kalau
     * kejadian, cabang itu lepas dari akar dan isinya ilang dari arsip tanpa
     * pernah kehapus.
     *
     * @return list<int>
     */
    public function idBesertaKeturunan(): array
    {
        $terkumpul = [$this->id];
        $lapisan = [$this->id];

        // Ditelusuri per lapisan (BFS), bukan rekursi per folder: pohon
        // seratus folder jadi beberapa query, bukan seratus.
        while ($lapisan !== []) {
            $lapisan = static::query()
                ->whereIn('parent_id', $lapisan)
                ->pluck('id')
                ->all();

            $terkumpul = [...$terkumpul, ...$lapisan];
        }

        return $terkumpul;
    }

    /** Folder ini masih ada isinya (subfolder atau berkas)? */
    public function adaIsinya(): bool
    {
        return $this->children()->exists() || $this->calibrationSessions()->exists();
    }
}
