<?php

namespace App\Filament\Resources\CalibrationSessions;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\CalibrationSessions\Pages\ListCalibrationSessions;
use App\Filament\Resources\CalibrationSessions\Tables\CalibrationSessionsTable;
use App\Models\CalibrationSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Sesi kalibrasi datang dari mobile (bareng pengukuran + hasil GUM). Admin di
 * sini cuma me-review: approve (nerbitin sertifikat) atau reject (minta revisi).
 *
 * SENGAJA nggak ada create/edit dari web — ngedit pengukuran dari sini bakal
 * ngebypass perhitungan GUM & jejak input teknisi. Kalau salah, teknisi yang
 * revisi lewat mobile.
 */
class CalibrationSessionResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = CalibrationSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Kalibrasi';

    protected static ?string $modelLabel = 'sesi kalibrasi';

    protected static ?string $pluralModelLabel = 'sesi kalibrasi';

    protected static ?string $recordTitleAttribute = 'nomor_sesi';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return CalibrationSessionsTable::configure($table);
    }

    /** Lencana angka di menu: berapa sesi yang nunggu di-approve. */
    public static function getNavigationBadge(): ?string
    {
        $jumlah = static::getEloquentQuery()
            ->where('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)
            ->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Selain nomor sesi, tambahin nama alat & teknisi — admin sering nyarinya
     * dari situ, bukan hafal nomor sesi.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['nomor_sesi', 'equipment.nama_alat', 'teknisi.name'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Alat' => $record->equipment?->nama_alat ?? '—',
            'Status' => ucfirst(str_replace('_', ' ', $record->status)),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalibrationSessions::route('/'),
        ];
    }
}
