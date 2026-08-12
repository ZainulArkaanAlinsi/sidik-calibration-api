<?php

namespace App\Filament\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Batasi resource cuma ke data organisasi si admin yang login.
 *
 * Instalasi ini satu-PT, tapi scoping-nya tetap dipasang sebagai jaring pengaman
 * yang sama kayak di API — biar kalau nanti jadi multi-PT, admin nggak keburu
 * kebiasa lihat data PT lain.
 */
trait ScopesToOrganization
{
    /**
     * Disediain `Resource` bawaan Filament. Dideklarasi ulang di sini biar
     * ketergantungan trait ini kelihatan — tanpa itu, `static::getModel()` di
     * bawah cuma "kebetulan ada" dan analyzer nggak bisa mastiin trait-nya
     * dipasang di kelas yang bener.
     *
     * @return class-string<Model>
     */
    abstract public static function getModel(): string;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(static::getModel()::make()->getTable().'.organization_id', User::yangLogin()?->organization_id);
    }
}
