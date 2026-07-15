<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Batasi resource cuma ke data organisasi si admin yang login.
 *
 * Instalasi ini satu-PT, tapi scoping-nya tetap dipasang sebagai jaring pengaman
 * yang sama kayak di API — biar kalau nanti jadi multi-PT, admin nggak keburu
 * kebiasa lihat data PT lain.
 */
trait ScopesToOrganization
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(static::getModel()::make()->getTable().'.organization_id', auth()->user()?->organization_id);
    }
}
