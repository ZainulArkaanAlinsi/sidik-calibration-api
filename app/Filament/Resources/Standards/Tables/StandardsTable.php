<?php

namespace App\Filament\Resources\Standards\Tables;

use App\Models\Standard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StandardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')
                    ->label('Nama standar')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('no_sertifikat')
                    ->label('No. sertifikat')
                    ->searchable(),
                TextColumn::make('tertelusur_ke')
                    ->label('Tertelusur ke')
                    ->badge(),
                TextColumn::make('ketidakpastian')
                    ->label('U (diperluas)')
                    ->numeric(decimalPlaces: 6),
                TextColumn::make('berlaku_sampai')
                    ->label('Berlaku sampai')
                    ->date()
                    ->sortable(),
                // Flag siap pakai — sama kayak `masih_berlaku` yang API kirim.
                IconColumn::make('masih_berlaku')
                    ->label('Masih berlaku')
                    ->boolean()
                    ->state(fn (Standard $record): bool => $record->masihBerlaku()),
            ])
            ->filters([
                // Standar kadaluarsa nggak boleh dipakai acuan kalibrasi
                // (lihat `masihBerlaku()`) — perlu gampang ditemukan biar
                // cepet diperpanjang/diganti sebelum kepakai teknisi.
                Filter::make('kadaluarsa')
                    ->label('Kadaluarsa')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('berlaku_sampai')
                        ->whereDate('berlaku_sampai', '<', now()))
                    ->toggle(),
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
