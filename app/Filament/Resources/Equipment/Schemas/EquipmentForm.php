<?php

namespace App\Filament\Resources\Equipment\Schemas;

use App\Models\CalibrationCapability;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                        // Dua dropdown di bawah DISARING per organisasi. Bukan
                        // kerapian tampilan.
                        //
                        // `organization_id` alat ini dicap dari admin yang login
                        // (Hidden di atas), sementara dua dropdown ini dulu
                        // nawarin isi SELURUH lab. Alat lab A yang kategorinya
                        // milik lab B bikin `GumCalculator::kemampuanUntukTitik()`
                        // — yang nyaring kandidat CMC ke organisasi ALAT — nggak
                        // nemu kandidat sama sekali, jadi sesinya jatuh ke jalur
                        // generik tanpa lantai CMC dan U95 yang terbit lebih
                        // kecil daripada yang diakreditasi lab. Diam, tanpa
                        // error, di sertifikat yang ngaku terakreditasi.
                        Select::make('equipment_category_id')
                            ->label('Kategori')
                            ->relationship(
                                'category',
                                'nama',
                                fn (Builder $query): Builder => $query->where(
                                    'organization_id',
                                    User::yangLogin()?->organization_id,
                                ),
                            )
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
                        // yang lagi dipilih DAN milik organisasi ini, biar nggak
                        // salah link/typo. Saringan organisasinya bukan dobel:
                        // baris kemampuan bisa punya `organization_id` lab lain
                        // sambil nunjuk kategori di sini, dan nama kayak gitu
                        // yang ketautan bikin angka CMC lab sebelah mendarat di
                        // sertifikat lab ini.
                        Select::make('nama_alat_kemampuan')
                            ->label('Jenis kemampuan kalibrasi (CMC)')
                            ->helperText('Opsional. Kosongkan kalau alat ini belum punya data kemampuan kalibrasi khusus — kalibrasinya tetap jalan lewat perhitungan Type A+B generik.')
                            ->options(fn (Get $get): array => CalibrationCapability::query()
                                ->where('equipment_category_id', $get('equipment_category_id'))
                                ->milikOrganisasi(User::yangLogin()?->organization_id)
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
                            ->helperText('Wajib diisi sebelum alat bisa dikalibrasi. Kosongkan kalau jenis alat ini memang tidak divonis PASS/FAIL (mis. Conductivity Meter).'),
                    ]),

                Section::make('Resolusi per titik')
                    ->description('Isi kalau alat ini TIDAK seragam — resolusi atau satuannya berubah antar titik. Kosongkan kalau resolusi tunggal di atas sudah mewakili.')
                    ->schema([
                        Repeater::make('resolusi_rentang')
                            ->hiddenLabel()
                            ->addActionLabel('Tambah baris resolusi')
                            ->columns(4)
                            ->schema([
                                TextInput::make('titik')
                                    ->label('Titik standar')
                                    ->numeric()
                                    ->helperText('mis. 25 / 1412 / 111'),
                                TextInput::make('maks')
                                    ->label('Batas atas')
                                    ->numeric()
                                    ->helperText('Alternatif dari titik. Kosongkan = golongan terakhir.'),
                                TextInput::make('resolusi')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('satuan')
                                    ->maxLength(20)
                                    ->helperText('mis. µS/cm'),
                            ])
                            // Dua bentuk baris, dan keduanya harus bisa
                            // disimpan tanpa saling merusak:
                            //
                            //  - **Titik standar** — buat alat yang SATUANNYA
                            //    beda antar titik. Conductivity meter baca 25 &
                            //    1412 dalam µS/cm lalu pindah sendiri ke mS/cm
                            //    di standar ketiga, dan sertifikatnya wajib
                            //    ikut yang tampil di layar alat pelanggan.
                            //    Ambang angka nggak bisa dipakai di sini:
                            //    `111 mS/cm` secara ANGKA lebih kecil dari
                            //    `1412 µS/cm`, jadi dia bakal nyangkut ke
                            //    golongan yang salah.
                            //
                            //  - **Batas atas** — buat alat yang satuannya
                            //    seragam tapi resolusinya berubah menurut besar
                            //    pembacaan (Turbidimeter: 0,01 di bawah 10 NTU,
                            //    0,1 di 10–100, 1 di atasnya).
                            //
                            // Dua-duanya ditaruh di form yang sama supaya alat
                            // lama yang pakai `maks` nggak kehilangan datanya
                            // begitu dibuka & disimpan lewat panel ini.
                            ->helperText(
                                'Pakai **Titik standar** kalau satuannya beda antar titik (Conductivity Meter). '
                                .'Pakai **Batas atas** kalau satuannya seragam tapi resolusinya berubah menurut besar '
                                .'pembacaan (Turbidimeter). Isi salah satu, jangan dua-duanya.'
                            )
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => match (true) {
                                filled($state['titik'] ?? null) => trim(($state['titik'] ?? '').' '.($state['satuan'] ?? '')),
                                filled($state['maks'] ?? null) => 'sampai '.$state['maks'].' '.($state['satuan'] ?? ''),
                                default => null,
                            })
                            ->columnSpanFull(),
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
