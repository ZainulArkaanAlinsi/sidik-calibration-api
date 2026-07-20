<?php

namespace App\Filament\Resources\CalibrationSessions\Pages;

use App\Filament\Resources\CalibrationSessions\CalibrationSessionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Sengaja TANPA CreateAction — sesi kalibrasi cuma datang dari mobile (lihat
 * docblock `CalibrationSessionResource`), dan `getPages()` di resource itu
 * memang nggak daftarin route `create`. Sebelumnya ada `CreateAction::make()`
 * di sini yang nunjuk ke route yang nggak ada — tombolnya bakal ngelempar
 * `RouteNotFoundException` kalau diklik dari panel admin.
 */
class ListCalibrationSessions extends ListRecords
{
    protected static string $resource = CalibrationSessionResource::class;
}
