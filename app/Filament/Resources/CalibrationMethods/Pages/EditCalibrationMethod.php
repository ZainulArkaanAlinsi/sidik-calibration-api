<?php

namespace App\Filament\Resources\CalibrationMethods\Pages;

use App\Filament\Resources\CalibrationMethods\CalibrationMethodResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCalibrationMethod extends EditRecord
{
    protected static string $resource = CalibrationMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
