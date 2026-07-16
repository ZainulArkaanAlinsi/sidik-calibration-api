<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Data PT yang dicetak di kop sertifikat (nama, alamat, akreditasi) + logo.
 * Satu instalasi = satu organisasi, jadi ini halaman tunggal (bukan resource
 * ber-list): admin ngedit baris organisasinya sendiri, nggak bikin/hapus.
 *
 * Logo diupload ke disk `public` di folder `logos/` — persis path yang dibaca
 * job GenerateCertificate buat naruh logo di kop PDF.
 */
class PengaturanOrganisasi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Organisasi';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Pengaturan Organisasi';

    protected string $view = 'filament.pages.pengaturan-organisasi';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public Organization $record;

    public function mount(): void
    {
        $this->record = Organization::findOrFail(auth()->user()->organization_id);
        $this->form->fill($this->record->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Identitas laboratorium')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nama')
                            ->label('Nama PT / laboratorium')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('alamat')->maxLength(255)->columnSpanFull(),
                        TextInput::make('telepon')->maxLength(50),
                        TextInput::make('email')->email()->maxLength(255),
                    ]),

                Section::make('Akreditasi')
                    ->description('Dicetak di kop sertifikat. Kalau tanggal berakhir kelewat, sertifikat terakreditasi nggak boleh terbit.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('no_akreditasi')
                            ->label('No. akreditasi KAN')
                            ->maxLength(255),
                        TextInput::make('standar_akreditasi')
                            ->label('Standar akreditasi')
                            ->maxLength(255)
                            ->placeholder('SNI ISO/IEC 17025:2017'),
                        DatePicker::make('akreditasi_mulai')->label('Berlaku mulai'),
                        DatePicker::make('akreditasi_berakhir')->label('Berlaku sampai'),
                    ]),

                Section::make('Logo')
                    ->description('Muncul di kop sertifikat PDF, panel admin, dan halaman verifikasi QR.')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->hiddenLabel()
                            ->image()
                            ->disk('public')
                            ->directory('logos')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->helperText('PNG/JPG, maksimal 2 MB.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $this->record->update($this->form->getState());

        Notification::make()
            ->title('Pengaturan organisasi disimpan.')
            ->success()
            ->send();
    }
}
