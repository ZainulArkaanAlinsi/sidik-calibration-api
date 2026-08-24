<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\User;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => User::yangLogin()?->organization_id),

                Section::make('Identitas ruangan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('kode')
                            ->label('Kode ruangan')
                            ->required()
                            ->maxLength(50)
                            ->placeholder('R-01')
                            // Unik PER organisasi, sama persis kayak indeks
                            // `['organization_id', 'kode']` di tabelnya dan
                            // aturan di `RoomRequest`. Tanpa `where()` ini,
                            // Filament ngecek unik se-tabel dan bakal nolak
                            // "R-01" cuma gara-gara lab organisasi lain udah
                            // punya kode yang sama.
                            //
                            // `whereNull('deleted_at')` biar kode ruangan yang
                            // udah dihapus bisa dipakai lagi — kalau nggak,
                            // baris yang nggak kelihatan di mana pun ngeblokir
                            // kode itu selamanya dan admin nggak punya cara
                            // ngerti kenapa.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule
                                    ->where('organization_id', User::yangLogin()?->organization_id)
                                    ->whereNull('deleted_at'),
                            )
                            // Bawaan Filament buat `unique` itu "The kode has
                            // already been taken." — bahasa Inggris, nyebut nama
                            // kolom, dan nggak ngasih tau kodenya bentrok sama
                            // apa. Disamain sama pesan yang API kirim pas 422.
                            ->validationMessages([
                                'unique' => 'Kode ruangan ini sudah dipakai.',
                                'required' => 'Kode ruangan wajib diisi.',
                            ])
                            ->helperText('Singkatan yang dipakai orang lab, mis. R-01. Unik per organisasi.'),
                        TextInput::make('nama')
                            ->label('Nama ruangan')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ruang Kalibrasi Massa')
                            ->validationMessages(['required' => 'Nama ruangan wajib diisi.'])
                            // Nama inilah yang kecetak sebagai "Calibration
                            // Location" di sertifikat lab (lihat
                            // `CertificateSnapshotBuilder::lokasiKalibrasi()`),
                            // bukan kodenya — jadi nulisnya harus kayak di
                            // dokumen, bukan singkatan internal.
                            ->helperText('Ini yang kecetak di sertifikat sebagai tempat kalibrasi.'),
                        TextInput::make('lokasi')
                            ->label('Letak')
                            ->maxLength(255)
                            ->placeholder('Lantai 2, sayap timur')
                            ->helperText('Buat orang nyari ruangannya. Nggak ikut kecetak di sertifikat.')
                            ->columnSpanFull(),
                    ]),

                // Ini BATAS SYARAT ruangan, bukan hasil ukur — angka yang dicatat
                // teknisi di sesi nanti diadu ke sini waktu audit. Boleh dikosongin
                // buat ruangan yang emang nggak terkendali (gudang, ruang terima alat).
                Section::make('Syarat kondisi ruangan')
                    ->description('Opsional. Batas resmi ruangan ini, bukan hasil ukur — dipakai buat ngadu kondisi yang dicatat di sesi.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('suhu_min')
                            ->label('Suhu minimum')
                            ->numeric()
                            ->suffix('°C'),
                        TextInput::make('suhu_max')
                            ->label('Suhu maksimum')
                            ->numeric()
                            ->suffix('°C')
                            ->rules([
                                fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    self::tolakRentangKebalik($value, $get('suhu_min'), 'Suhu', $fail);
                                },
                            ]),
                        TextInput::make('kelembaban_min')
                            ->label('Kelembaban minimum')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%RH')
                            ->validationMessages(['max' => 'Kelembaban relatif itu persen — maksimal 100.']),
                        TextInput::make('kelembaban_max')
                            ->label('Kelembaban maksimum')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%RH')
                            ->validationMessages(['max' => 'Kelembaban relatif itu persen — maksimal 100.'])
                            ->rules([
                                fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    self::tolakRentangKebalik($value, $get('kelembaban_min'), 'Kelembaban', $fail);
                                },
                            ]),
                    ]),

                Section::make('Status & catatan')
                    ->schema([
                        // Sakelar ini pengganti tombol hapus, bukan pelengkapnya —
                        // makanya resource-nya sengaja nggak punya aksi hapus sama
                        // sekali (lihat `RoomsTable`).
                        Toggle::make('aktif')
                            ->label('Aktif')
                            ->default(true)
                            ->helperText('Yang nonaktif ilang dari dropdown ruangan di HP, tapi sesi lama yang nunjuk ke sini tetap kebaca.'),
                        Textarea::make('keterangan')
                            ->label('Keterangan')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Rentang kebalik (max < min) itu syarat yang nggak mungkin dipenuhi siapa
     * pun: tiap sesi di ruangan itu bakal ketulis melanggar syarat selamanya.
     * `RoomRequest` udah nolak ini buat jalur API, tapi form Filament nyimpen
     * langsung ke model tanpa lewat sana — tanpa aturan kembarannya di sini,
     * panel admin jadi satu-satunya pintu yang bisa nulis ruangan mustahil.
     */
    private static function tolakRentangKebalik(mixed $max, mixed $min, string $label, Closure $fail): void
    {
        if (! is_numeric($max) || ! is_numeric($min)) {
            return;
        }

        if ((float) $max < (float) $min) {
            $fail("{$label} maksimum nggak boleh lebih kecil dari minimum.");
        }
    }
}
