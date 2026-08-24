<?php

namespace App\Filament\Resources\CalibrationCapabilities\Pages;

use App\Filament\Resources\CalibrationCapabilities\CalibrationCapabilityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCalibrationCapabilities extends ListRecords
{
    protected static string $resource = CalibrationCapabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah nama alat'),
        ];
    }
}
