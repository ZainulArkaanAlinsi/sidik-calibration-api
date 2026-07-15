<?php

namespace App\Filament\Resources\Equipment\Tables;

use App\Models\Equipment;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama_alat')
                    ->label('Nama alat')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('serial_number')
                    ->label('Nomor seri')
                    ->searchable(),
                TextColumn::make('customer.nama')
                    ->label('Pelanggan')
                    ->searchable(),
                TextColumn::make('category.nama')
                    ->label('Kategori')
                    ->badge(),
                TextColumn::make('tanggal_jatuh_tempo')
                    ->label('Jatuh tempo')
                    ->date()
                    ->sortable(),
                // Status yang dilihat: `overdue` menang di atas `aktif` kalau
                // alatnya udah lewat jatuh tempo. Sama persis kayak yang API kirim.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Equipment $record): string => $record->statusUntukApi())
                    ->color(fn (string $state): string => match ($state) {
                        'overdue' => 'danger',
                        'nonaktif' => 'gray',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']),
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
