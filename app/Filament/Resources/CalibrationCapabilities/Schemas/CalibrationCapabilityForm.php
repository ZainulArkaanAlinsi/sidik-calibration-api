<?php

namespace App\Filament\Resources\CalibrationCapabilities\Schemas;

use App\Models\CalibrationCapability;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class CalibrationCapabilityForm
{
    /**
     * Nama kolom pembuka kunci. BUKAN kolom database — `dehydrated(false)` di
     * bawah bikin dia nggak pernah ikut disimpan.
     */
    private const KUNCI = 'izin_ubah_cmc';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => User::yangLogin()?->organization_id),

                Section::make('Identitas kemampuan')
                    ->description('Nama alat di sini yang dipilih teknisi waktu nautin alat pelanggan '
                        .'(kolom "Jenis kemampuan kalibrasi" di master Alat).')
                    ->columns(2)
                    ->schema([
                        Select::make('equipment_category_id')
                            ->label('Kategori')
                            ->relationship('category', 'nama')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('nama_alat')
                            ->label('Nama alat')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Persis kayak yang bakal dipilih teknisi. Beda satu huruf = alat yang beda.'),
                        TextInput::make('parameter')
                            ->label('Parameter')
                            ->maxLength(255)
                            ->helperText('Isi kalau satu nama alat punya beberapa besaran (mis. Autoklaf: Suhu / Tekanan).'),
                        TextInput::make('keterangan')
                            ->label('Keterangan')
                            ->maxLength(255),
                    ]),

                Section::make('Rentang & ketidakpastian terbaik (CMC)')
                    ->description('Angka di blok ini MASUK LANGSUNG ke sertifikat. Ngubah satu digit di sini '
                        .'ngubah U95 di semua sesi berikutnya yang pakai baris ini — tanpa error di mana pun. '
                        .'Makanya dikunci; buka sakelarnya cuma kalau memang mau ngubah.')
                    // Ditutup duluan waktu ngedit, kebuka waktu bikin baru.
                    //
                    // Kelihatan sepele, tapi ini lapis pertama dari "jangan
                    // gampang kesenggol": blok yang kebuka di layar itu blok
                    // yang jari bisa nyampe waktu lagi cuma mau mbenerin salah
                    // ketik nama alat. Waktu bikin baru nggak ada yang bisa
                    // kesenggol — belum ada isinya.
                    ->collapsed(fn (string $operation): bool => $operation === 'edit')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        // Lapis kedua, dan yang beneran nahan.
                        //
                        // Semua field angka di bawah `disabled()` selama sakelar
                        // ini mati. Di Filament, field yang `disabled()` NGGAK
                        // ikut dikirim waktu simpan — jadi nilainya bukan cuma
                        // "nggak bisa diketik", tapi beneran nggak kesentuh
                        // proses simpan. Itu penting: form yang cuma bikin
                        // input read-only masih ngirim nilainya, dan satu bug
                        // rendering aja cukup buat nulis null ke kolom CMC.
                        //
                        // Bawaannya NYALA waktu bikin baru — nggak ada yang
                        // bisa kesenggol di baris yang isinya belum ada, dan
                        // maksa admin nyentang sakelar buat ngisi form kosong
                        // cuma bikin sakelarnya jadi kebiasaan yang dicentang
                        // tanpa dibaca.
                        Toggle::make(self::KUNCI)
                            ->label('Buka kunci angka CMC')
                            ->helperText('Selama mati, angka di bawah nggak akan berubah walau kesenggol.')
                            ->dehydrated(false)
                            ->live()
                            ->default(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),

                        // Lapis ketiga, khusus baris lampiran akreditasi.
                        //
                        // Angka baris ini BUKAN milik aplikasi — dia salinan
                        // dokumen akreditasi KAN LK-285-IDN. Ngubahnya di sini
                        // bikin sistem nyatain kemampuan yang beda dari yang
                        // diakui asesor, dan yang nemuin bedanya biasanya
                        // asesornya sendiri, waktu surveilan. Sakelar di atas
                        // nahan tangan; kalimat ini yang nahan niat.
                        Callout::make('Baris ini salinan lampiran akreditasi')
                            ->description('Angkanya datang dari dokumen KAN LK-285-IDN, bukan dari aplikasi ini. '
                                .'Ngubahnya cuma sah kalau lampirannya sendiri direvisi — kalau nggak, sistem '
                                .'bakal ngeklaim kemampuan yang beda dari yang diakui asesor.')
                            ->icon(Heroicon::OutlinedShieldExclamation)
                            ->color('warning')
                            ->visible(fn (?Model $record): bool => $record instanceof CalibrationCapability
                                && $record->dariAkreditasi())
                            ->columnSpanFull(),

                        TextInput::make('range_min')
                            ->label('Batas bawah')
                            ->numeric()
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI))
                            ->helperText('Kosongin buat kemampuan titik tunggal — titiknya ditulis di batas atas.'),
                        TextInput::make('range_max')
                            ->label('Batas atas / titik')
                            ->numeric()
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI)),
                        TextInput::make('range_note')
                            ->label('Catatan rentang')
                            ->maxLength(255)
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI))
                            ->helperText('Teks asli kalau batas bawahnya non-numerik, mis. "ambient".'),
                        TextInput::make('satuan')
                            ->label('Satuan besaran')
                            ->maxLength(50)
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI)),
                        TextInput::make('ketidakpastian_terbaik')
                            ->label('Ketidakpastian terbaik (U, diperluas)')
                            ->numeric()
                            ->minValue(0)
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI))
                            // Sengaja NGGAK `required()`. Baris tanpa CMC itu
                            // keadaan yang sah & sering (tiap nama alat yang
                            // baru ditambah teknisi lahirnya begitu), dan
                            // maksa diisi cuma bikin orang ngarang angka —
                            // yang jauh lebih berbahaya daripada kolom kosong
                            // yang jujur & ketauan di lencana menu.
                            ->helperText('Boleh kosong kalau memang belum diturunkan. JANGAN diisi 0 — '
                                .'nol itu klaim "ketidakpastian terbaik lab = nol" dan bikin lantai CMC hilang.'),
                        TextInput::make('satuan_ketidakpastian')
                            ->label('Satuan ketidakpastian')
                            ->maxLength(50)
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI)),
                        TextInput::make('faktor_cakupan')
                            ->label('Faktor cakupan (k)')
                            ->numeric()
                            ->minValue(1)
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI)),
                        Textarea::make('metode')
                            ->label('Metode (nomor IK)')
                            ->rows(2)
                            ->disabled(fn (Get $get): bool => ! $get(self::KUNCI))
                            ->helperText('Dicetak di sertifikat sebagai "Calibration Method".')
                            ->columnSpanFull(),
                    ]),

                Section::make('Asal-usul')
                    ->description('Cuma buat dibaca. Diisi sistem, nggak bisa diketik — kalau bisa, '
                        .'baris tanpa CMC bakal bisa menyamar jadi baris lampiran akreditasi.')
                    ->columns(2)
                    ->visibleOn('edit')
                    ->schema([
                        TextInput::make('sumber')
                            ->label('Asal')
                            ->disabled()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                CalibrationCapability::SUMBER_AKREDITASI => 'Lampiran akreditasi (LK-285-IDN)',
                                CalibrationCapability::SUMBER_ADMIN => 'Ditambah admin',
                                CalibrationCapability::SUMBER_TEKNISI => 'Ditambah teknisi dari lapangan',
                                default => (string) $state,
                            }),
                        TextInput::make('pembuat.name')
                            ->label('Ditambah oleh')
                            ->disabled()
                            ->placeholder('— seeder / lampiran akreditasi —'),
                    ]),
            ]);
    }
}
