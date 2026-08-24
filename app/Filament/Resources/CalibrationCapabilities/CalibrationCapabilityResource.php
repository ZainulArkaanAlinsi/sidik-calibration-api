<?php

namespace App\Filament\Resources\CalibrationCapabilities;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\CalibrationCapabilities\Pages\CreateCalibrationCapability;
use App\Filament\Resources\CalibrationCapabilities\Pages\EditCalibrationCapability;
use App\Filament\Resources\CalibrationCapabilities\Pages\ListCalibrationCapabilities;
use App\Filament\Resources\CalibrationCapabilities\Schemas\CalibrationCapabilityForm;
use App\Filament\Resources\CalibrationCapabilities\Tables\CalibrationCapabilitiesTable;
use App\Models\CalibrationCapability;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Master daftar nama alat + rentang kemampuan kalibrasi (CMC).
 *
 * Sebelum ada layar ini, satu-satunya cara nambah nama alat itu nulis seeder
 * atau SQL langsung ke database — artinya tiap kali lab nerima jenis alat yang
 * lampiran akreditasinya nggak nyebut, kerjaannya nunggu programmer. Sekarang
 * admin bisa dari komputer, dan teknisi bisa nambah NAMA-nya sendiri dari HP
 * (`POST /api/categories/{kode}/kemampuan`) supaya nggak mentok di lapangan.
 *
 * ## Dua hal yang bikin layar ini beda dari master data lain
 *
 * 1. **Isinya campuran.** Sebagian baris salinan lampiran akreditasi KAN
 *    LK-285-IDN — angkanya bukan milik aplikasi ini, dia milik dokumen
 *    akreditasi. Sebagian lagi tambahan admin & teknisi yang belum punya angka
 *    sama sekali. Dua-duanya kelihatan sama kalau nggak ditandain, dan yang
 *    kena akibatnya bukan tampilan: baris tanpa CMC bikin sesi jatuh ke jalur
 *    hitung generik tanpa lantai CMC. Makanya kolom `Asal` ada di paling depan
 *    dan `CMC` nampilin "belum ada" dengan warna bahaya, bukan strip netral.
 *
 * 2. **Angkanya nyentuh dokumen resmi.** Ngubah satu digit
 *    `ketidakpastian_terbaik` ngubah U95 di SEMUA sertifikat berikutnya yang
 *    pakai baris itu — tanpa error, tanpa notifikasi, dan tanpa cara gampang
 *    nyadarinnya. Makanya blok angkanya dikunci di form (lihat
 *    `CalibrationCapabilityForm`) dan hapus massal sengaja NGGAK disediakan
 *    (lihat `CalibrationCapabilitiesTable`).
 */
class CalibrationCapabilityResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = CalibrationCapability::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVariable;

    protected static ?string $navigationLabel = 'Kemampuan Kalibrasi';

    protected static ?string $modelLabel = 'kemampuan kalibrasi';

    protected static ?string $pluralModelLabel = 'kemampuan kalibrasi';

    protected static ?string $recordTitleAttribute = 'nama_alat';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return CalibrationCapabilityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalibrationCapabilitiesTable::configure($table);
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['nama_alat', 'parameter'];
    }

    /**
     * Lencana angka di menu: berapa nama alat yang masih nunggu dilengkapi
     * CMC-nya.
     *
     * Ini yang bikin tumpukan baris tanpa CMC nggak bisa jadi utang senyap.
     * Teknisi nambah nama alat sepanjang minggu dan nggak ada yang ngingetin
     * admin; lencana di menu itu satu-satunya tempat yang kelihatan tanpa ada
     * yang harus inget buka layarnya.
     */
    public static function getNavigationBadge(): ?string
    {
        $jumlah = static::getEloquentQuery()
            ->whereNull('ketidakpastian_terbaik')
            ->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Kategori' => $record->category?->nama ?? '—',
            'Asal' => ucfirst((string) $record->sumber),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalibrationCapabilities::route('/'),
            'create' => CreateCalibrationCapability::route('/create'),
            'edit' => EditCalibrationCapability::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
