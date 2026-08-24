<?php

namespace App\Filament\Resources\CalibrationCapabilities\Pages;

use App\Filament\Resources\CalibrationCapabilities\CalibrationCapabilityResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCalibrationCapability extends EditRecord
{
    protected static string $resource = CalibrationCapabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
