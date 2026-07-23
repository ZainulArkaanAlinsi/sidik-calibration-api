<?php

namespace App\Filament\Resources\Equipment\Schemas;

use App\Models\CalibrationCapability;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EquipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => User::yangLogin()?->organization_id),

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
                            ->required()
                            ->live()
                            // Kategori ganti → jenis kemampuan lama kemungkinan
                            // besar udah nggak nyambung, jangan dibiarin nyangkut.
                            ->afterStateUpdated(fn (callable $set) => $set('nama_alat_kemampuan', null)),
                        // Nunjuk ke CalibrationCapability.nama_alat biar
                        // GumCalculator tau CMC mana yang beneran punya jenis
                        // alat yang sama, bukan cuma kategori yang sama — lihat
                        // komentar GumCalculator::kemampuanUntukTitik(). Opsinya
                        // dibatasin ke nama_alat yang beneran ada di kategori
                        // yang lagi dipilih, biar nggak salah link/typo.
                        Select::make('nama_alat_kemampuan')
                            ->label('Jenis kemampuan kalibrasi (CMC)')
                            ->helperText('Opsional. Kosongkan kalau alat ini belum punya data kemampuan kalibrasi khusus — kalibrasinya tetap jalan lewat perhitungan Type A+B generik.')
                            ->options(fn (Get $get): array => CalibrationCapability::query()
                                ->where('equipment_category_id', $get('equipment_category_id'))
                                ->distinct()
                                ->pluck('nama_alat', 'nama_alat')
                                ->all())
                            ->searchable(),
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
