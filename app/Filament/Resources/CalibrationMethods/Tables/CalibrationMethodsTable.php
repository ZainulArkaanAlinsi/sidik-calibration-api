<?php

namespace App\Filament\Resources\CalibrationMethods\Tables;

use App\Models\CalibrationMethod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CalibrationMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('kode')
            ->columns([
                TextColumn::make('kode')
                    ->label('Kode IK')
                    ->searchable()
                    ->sortable(),
                // Bentuk yang beneran dicetak di sertifikat — biar admin bisa
                // nyocokin langsung sama dokumen fisiknya.
                TextColumn::make('kode_lengkap')
                    ->label('Dicetak sebagai')
                    ->state(fn (CalibrationMethod $record): string => $record->kodeLengkap())
                    ->badge(),
                TextColumn::make('nama')
                    ->label('Judul')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('category.nama')
                    ->label('Kategori')
                    ->placeholder('Semua kategori'),
                TextColumn::make('berlaku_mulai')
                    ->label('Berlaku mulai')
                    ->date()
                    ->placeholder('—'),
                IconColumn::make('aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('aktif')->label('Aktif'),
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
