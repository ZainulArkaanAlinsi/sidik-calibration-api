<?php

namespace App\Filament\Resources\CalibrationMethods\Schemas;

use App\Models\EquipmentCategory;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CalibrationMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => User::yangLogin()?->organization_id),

                Section::make('Identitas Instruksi Kerja')
                    ->description('Kode + revisi inilah yang dicetak di sertifikat, mis. SIDIK-IK-CAL-0506_Rev.6.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('kode')
                            ->label('Kode IK')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('SIDIK-IK-CAL-0506'),
                        TextInput::make('revisi')
                            ->label('Revisi')
                            ->maxLength(20)
                            ->placeholder('6')
                            // Revisi baru = BARIS BARU, bukan edit baris lama.
                            // Sertifikat yang udah terbit harus tetap nunjuk
                            // revisi yang berlaku waktu kalibrasinya dikerjain.
                            ->helperText('Ganti revisi? Bikin baris BARU — jangan edit yang lama, sertifikat lama masih nunjuk ke sini.'),
                        TextInput::make('nama')
                            ->label('Judul IK')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('equipment_category_id')
                            ->label('Kategori alat')
                            ->options(fn () => EquipmentCategory::query()
                                ->where('organization_id', User::yangLogin()?->organization_id)
                                ->pluck('nama', 'id'))
                            ->searchable()
                            ->helperText('Kosongin kalau IK ini kepakai lintas kategori.'),
                        DatePicker::make('berlaku_mulai')->label('Berlaku mulai'),
                        Toggle::make('aktif')
                            ->default(true)
                            ->helperText('Yang nonaktif nggak muncul di pilihan sesi baru, tapi tetap kebaca di sertifikat lama.'),
                        Textarea::make('keterangan')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
