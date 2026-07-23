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

                // Cuma relevan buat larutan buffer pH. Standar lain (gauge
                // block, anak timbangan) nilainya nggak bergantung suhu larutan
                // — dikosongin, dan perhitungan otomatis jatuh ke nilai nominal.
                Section::make('Kurva suhu buffer (khusus larutan pH)')
                    ->description('Persamaan dari sertifikat Merck: y = a·x² + b·x + c, '
                        .'dengan x = suhu larutan (°C) dan y = nilai pH buffer pada suhu itu. '
                        .'Ini yang bikin nilai Standard di lembar perhitungan jadi 4,0092 di 22,2 °C, bukan 4,00 mentah.')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('koefisien_suhu.a')
                            ->label('a (koefisien x²)')
                            ->numeric()
                            ->helperText('Buffer pH 4: 0,00003'),
                        TextInput::make('koefisien_suhu.b')
                            ->label('b (koefisien x)')
                            ->numeric()
                            ->helperText('Buffer pH 4: -0,0023'),
                        TextInput::make('koefisien_suhu.c')
                            ->label('c (konstanta)')
                            ->numeric()
                            ->helperText('Buffer pH 4: 4,0455'),
                    ]),

                // Cuma relevan buat thermohygro (TH-1..TH-7). Begitu diisi,
                // blok "Perhitungan Kondisi Lingkungan" di lembar perhitungan
                // langsung dapat koreksi & U95%-nya — tanpa ini, yang dilaporin
                // pembacaan mentah tanpa koreksi.
                Section::make('Data sertifikat thermohygro')
                    ->description('Diisi dari sertifikat thermohygro-nya sendiri (LK-285-IDN). '
                        .'Ambil titik kalibrasi yang paling dekat sama kondisi ruang lab sehari-hari '
                        .'(biasanya titik ~20 °C / ~50 %RH). Koreksi boleh negatif.')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('parameter_kondisi.suhu.indexed_value')
                            ->label('Suhu — indexed value (°C)')
                            ->numeric(),
                        TextInput::make('parameter_kondisi.suhu.correction')
                            ->label('Suhu — correction (°C)')
                            ->numeric(),
                        TextInput::make('parameter_kondisi.suhu.u95')
                            ->label('Suhu — U95% (°C)')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('parameter_kondisi.kelembaban.indexed_value')
                            ->label('Kelembaban — indexed value (%RH)')
                            ->numeric(),
                        TextInput::make('parameter_kondisi.kelembaban.correction')
                            ->label('Kelembaban — correction (%RH)')
                            ->numeric(),
                        TextInput::make('parameter_kondisi.kelembaban.u95')
                            ->label('Kelembaban — U95% (%RH)')
                            ->numeric()
                            ->minValue(0),
                    ]),
            ]);
    }
}
