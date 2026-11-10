<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope PEREKAM untuk membuktikan `lockForUpdate()` di SQLite.
 *
 * Tata bahasa SQLite membuang klausa `FOR UPDATE`, jadi `DB::listen` tak bisa
 * melihat kunci; menghapus `lockForUpdate()` dari kode produksi lolos diam-diam.
 * Scope ini dipasang ke model (`Model::addGlobalScope(new PerekamKunci)`) dan
 * mencatat, tiap kali kueri model itu disusun, apakah `lock` pada builder-nya
 * sudah menyala beserta kolom `where`-nya. `lockForUpdate()` menyetel
 * `$query->lock = true` SEBELUM `first()`/`max()` menyusun kueri, jadi nilainya
 * sudah benar ketika `apply()` dipanggil.
 *
 * Statis: dipakai lintas model; panggil [reset] sebelum tiap pengamatan. Scope
 * global statis di Eloquent dibersihkan `Model::clearBootedModels()` yang
 * dipanggil tiap aplikasi tes dibuat ulang (DatabaseServiceProvider).
 */
final class PerekamKunci implements Scope
{
    /** @var list<array{model: class-string, lock: bool|string|null, kolom: list<string>}> */
    public static array $catatan = [];

    public static function reset(): void
    {
        self::$catatan = [];
    }

    /** @param  Builder<Model>  $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $query = $builder->getQuery();
        $kolom = [];

        foreach ($query->wheres as $w) {
            if (isset($w['column']) && is_string($w['column'])) {
                $kolom[] = preg_replace('/^.*\./', '', $w['column']);
            }
        }

        self::$catatan[] = ['model' => $model::class, 'lock' => $query->lock, 'kolom' => $kolom];
    }

    /**
     * Indeks kueri pertama model ini (opsional: yang terkunci / yang berkolom tertentu).
     *
     * @param  class-string  $model
     * @param  list<string>|null  $kolomPersis  daftar kolom where urut persis
     */
    public static function pertama(string $model, ?bool $terkunci = null, ?array $kolomPersis = null): ?int
    {
        foreach (self::$catatan as $i => $c) {
            if ($c['model'] !== $model) {
                continue;
            }

            if ($terkunci !== null && ($c['lock'] === true) !== $terkunci) {
                continue;
            }

            if ($kolomPersis !== null && $c['kolom'] !== $kolomPersis) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
