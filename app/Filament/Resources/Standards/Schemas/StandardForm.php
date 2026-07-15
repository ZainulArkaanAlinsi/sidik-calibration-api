<?php

namespace App\Filament\Resources\Standards\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StandardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => auth()->user()->organization_id),

                Section::make('Identitas standar')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nama')
                            ->label('Nama standar')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('serial_number')
                            ->label('Nomor seri')
                            ->maxLength(100),
                        TextInput::make('merk')->maxLength(255),
                        TextInput::make('model')->maxLength(255),
                        TextInput::make('no_sertifikat')
                            ->label('No. sertifikat')
                            ->maxLength(100),
                        TextInput::make('tertelusur_ke')
                            ->label('Tertelusur ke')
                            ->maxLength(255),
                        DatePicker::make('berlaku_sampai')
                            ->helperText('Lewat tanggal ini, standar nggak boleh dipakai kalibrasi.'),
                    ]),

                Section::make('Ketidakpastian')
                    ->columns(2)
                    ->schema([
                        // Angka dari sertifikat standar = ketidakpastian DIPERLUAS
                        // (udah dikali faktor cakupan). Backend yang bagi balik pas
                        // ngitung Type B — isi apa adanya dari sertifikat.
                        TextInput::make('ketidakpastian')
                            ->label('Ketidakpastian (U, diperluas)')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('satuan_ketidakpastian')
                            ->label('Satuan')
                            ->maxLength(50),
                        TextInput::make('faktor_cakupan')
                            ->label('Faktor cakupan (k)')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5)
                            ->default(2)
                            ->helperText('Biasanya 2. Dipakai sebagai pembagi — nggak boleh 0.'),
                        TextInput::make('drift')
                            ->numeric()
                            ->minValue(0),
                    ]),
            ]);
    }
}
