<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Services\CertificateSnapshotBuilder;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

                Section::make('Penerbitan sertifikat')
                    ->description('Dicetak di footer sertifikat. Yang tanda tangan itu penanggung jawab teknis, belum tentu admin yang mencet tombol setujui.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('settings.penandatangan_nama')
                            ->label('Nama penandatangan')
                            ->maxLength(255)
                            ->placeholder('Alex Misramto'),
                        TextInput::make('settings.penandatangan_jabatan')
                            ->label('Jabatan')
                            ->maxLength(255)
                            ->placeholder('Technical Manager'),
                        TextInput::make('settings.kode_dokumen_form')
                            ->label('Kode dokumen form')
                            ->maxLength(255)
                            ->placeholder(CertificateSnapshotBuilder::KODE_DOKUMEN_DEFAULT)
                            ->columnSpanFull(),
                        Toggle::make('settings.tampilkan_qr_di_pdf')
                            ->label('Cetak QR Code di sertifikat')
                            ->default(true)
                            ->helperText('QR-nya buat verifikasi & unduh cepat oleh pelanggan.'),
                        // Struktur baku sertifikat pH nggak punya baris keputusan.
                        // Buat alat lain yang formatnya nggak dikunci pelanggan,
                        // ini informasi paling penting di dokumennya.
                        Toggle::make('settings.tampilkan_keputusan_di_pdf')
                            ->label('Cetak keputusan PASS/FAIL')
                            ->default(false)
                            ->helperText('Matikan kalau layout sertifikat dikunci pelanggan — struktur baku pH nggak punya baris ini.'),
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
