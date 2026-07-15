<?php

namespace App\Filament\Resources\Equipment\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EquipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => auth()->user()->organization_id),

                Section::make('Identitas alat')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nama_alat')
                            ->required()
                            ->maxLength(255),
                        Select::make('customer_id')
                            ->label('Pelanggan')
                            ->relationship('customer', 'nama')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('equipment_category_id')
                            ->label('Kategori')
                            ->relationship('category', 'nama')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('serial_number')
                            ->label('Nomor seri')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('merk')->maxLength(255),
                        TextInput::make('model')->maxLength(255),
                        TextInput::make('no_identifikasi')
                            ->label('No. identifikasi')
                            ->maxLength(100),
                        TextInput::make('lokasi')->maxLength(255),
                    ]),

                Section::make('Rentang & spesifikasi')
                    ->columns(2)
                    ->schema([
                        TextInput::make('range_min')->numeric(),
                        TextInput::make('range_max')->numeric(),
                        TextInput::make('satuan')->maxLength(50),
                        TextInput::make('resolusi')->numeric()->minValue(0),
                        // Wajib buat kalibrasi — tanpa toleransi PASS/FAIL nggak bisa
                        // diputusin. Nggak dipaksa required (alat boleh didata dulu),
                        // tapi diingetin lewat helper text.
                        TextInput::make('toleransi')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Wajib diisi sebelum alat bisa dikalibrasi.'),
                    ]),

                Section::make('Status kalibrasi')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('tanggal_kalibrasi_terakhir'),
                        DatePicker::make('tanggal_jatuh_tempo'),
                        // `overdue` sengaja nggak ada di sini — dihitung dari tanggal
                        // jatuh tempo, bukan disimpen.
                        Select::make('status')
                            ->options(['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'])
                            ->default('aktif')
                            ->required(),
                        Textarea::make('catatan')->columnSpanFull(),
                    ]),
            ]);
    }
}
