<?php

namespace App\Filament\Resources\Certificates;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Resources\Certificates\Schemas\CertificateInfolist;
use App\Filament\Resources\Certificates\Tables\CertificatesTable;
use App\Models\Certificate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Sertifikat terbit dari sesi yang disetujui. Sama kayak sesi kalibrasi, admin
 * di sini cuma me-review: lihat daftar, unduh PDF-nya, atau coba terbitin ulang
 * yang generate-nya gagal.
 *
 * SENGAJA nggak ada create/edit dari web — sertifikat lahir otomatis dari job
 * GenerateCertificate pas sesi di-approve, bukan diketik tangan. Ngedit nomor/
 * tanggal dari sini bakal mecahin ketertelusuran ke sesi asalnya.
 */
class CertificateResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = Certificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Sertifikat';

    protected static ?string $modelLabel = 'sertifikat';

    protected static ?string $pluralModelLabel = 'sertifikat';

    protected static ?string $recordTitleAttribute = 'nomor';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return CertificatesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CertificateInfolist::configure($schema);
    }

    /** Lencana angka: berapa sertifikat yang generate-nya gagal & perlu retry. */
    public static function getNavigationBadge(): ?string
    {
        $jumlah = static::getEloquentQuery()
            ->where('status', Certificate::STATUS_GAGAL)
            ->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificates::route('/'),
            'view' => ViewCertificate::route('/{record}'),
        ];
    }
}
