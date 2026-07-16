<?php

namespace App\Filament\Resources\EquipmentCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EquipmentCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')
                    ->label('Nama kategori')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kode')
                    ->label('Kode')
                    ->badge()
                    ->searchable(),
                TextColumn::make('equipments_count')
                    ->label('Jumlah alat')
                    ->counts('equipments')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
