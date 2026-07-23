<?php

namespace App\Filament\Resources\CalibrationMethods;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\CalibrationMethods\Pages\CreateCalibrationMethod;
use App\Filament\Resources\CalibrationMethods\Pages\EditCalibrationMethod;
use App\Filament\Resources\CalibrationMethods\Pages\ListCalibrationMethods;
use App\Filament\Resources\CalibrationMethods\Schemas\CalibrationMethodForm;
use App\Filament\Resources\CalibrationMethods\Tables\CalibrationMethodsTable;
use App\Models\CalibrationMethod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Instruksi Kerja kalibrasi — yang dicetak jadi "Calibration Method" di
 * sertifikat.
 */
class CalibrationMethodResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = CalibrationMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Metode Kalibrasi';

    protected static ?string $modelLabel = 'metode kalibrasi';

    protected static ?string $pluralModelLabel = 'metode kalibrasi';

    protected static ?string $recordTitleAttribute = 'kode';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return CalibrationMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalibrationMethodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['kode', 'nama'];
    }

    /**
     * @param  CalibrationMethod  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Dicetak sebagai' => $record->kodeLengkap(),
            'Aktif' => $record->aktif ? 'Ya' : 'Tidak',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalibrationMethods::route('/'),
            'create' => CreateCalibrationMethod::route('/create'),
            'edit' => EditCalibrationMethod::route('/{record}/edit'),
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
