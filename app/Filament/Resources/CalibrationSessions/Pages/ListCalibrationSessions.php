<?php

namespace App\Filament\Resources\CalibrationSessions\Pages;

use App\Filament\Resources\CalibrationSessions\CalibrationSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCalibrationSessions extends ListRecords
{
    protected static string $resource = CalibrationSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
