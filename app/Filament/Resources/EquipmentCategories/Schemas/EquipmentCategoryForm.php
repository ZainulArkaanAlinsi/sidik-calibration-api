<?php

namespace App\Filament\Resources\EquipmentCategories\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class EquipmentCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => auth()->user()->organization_id),

                Section::make('Identitas kategori')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nama')
                            ->label('Nama kategori')
                            ->required()
                            ->maxLength(255)
                            // Bantu isi `kode` otomatis dari nama pas bikin baru —
                            // tetap bisa diedit tangan.
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, callable $set): void {
                                if ($operation === 'create') {
                                    $set('kode', Str::slug($state));
                                }
                            }),
                        TextInput::make('kode')
                            ->label('Kode')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Dipakai internal buat nyocokin alat ke kategori. Unik per organisasi.'),
                    ]),

                // Kolom worksheet baku per kategori (opsional). Struktur ini yang
                // nanti dipakai layar input kalibrasi buat nentuin field mana yang
                // muncul. Disimpen sebagai JSON di `worksheet_schema`.
                Section::make('Kolom worksheet baku')
                    ->description('Opsional. Definisi kolom yang muncul di worksheet kalibrasi buat kategori ini.')
                    ->schema([
                        Repeater::make('worksheet_schema')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('nama')
                                    ->label('Nama kolom')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('satuan')
                                    ->label('Satuan')
                                    ->maxLength(50),
                            ])
                            ->columns(2)
                            ->addActionLabel('Tambah kolom')
                            ->reorderable()
                            ->defaultItems(0),
                    ]),
            ]);
    }
}
