<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')
                    ->label('Nama pelanggan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_person')
                    ->label('Narahubung')
                    ->searchable(),
                TextColumn::make('telepon')
                    ->searchable(),
                TextColumn::make('email')
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
                    // Pelanggan yang masih punya alat ditolak di API; di panel,
                    // hapus massal dibiarin tapi FK bakal nahan yang masih kepakai.
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
