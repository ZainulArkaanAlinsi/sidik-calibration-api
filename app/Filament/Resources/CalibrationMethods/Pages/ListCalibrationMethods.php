<?php

namespace App\Filament\Resources\CalibrationMethods\Pages;

use App\Filament\Resources\CalibrationMethods\CalibrationMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCalibrationMethods extends ListRecords
{
    protected static string $resource = CalibrationMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
