<?php

namespace App\Filament\Resources\Standards;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\Standards\Pages\CreateStandard;
use App\Filament\Resources\Standards\Pages\EditStandard;
use App\Filament\Resources\Standards\Pages\ListStandards;
use App\Filament\Resources\Standards\Schemas\StandardForm;
use App\Filament\Resources\Standards\Tables\StandardsTable;
use App\Models\Standard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StandardResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = Standard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Standar Acuan';

    protected static ?string $modelLabel = 'standar acuan';

    protected static ?string $pluralModelLabel = 'standar acuan';

    protected static ?string $recordTitleAttribute = 'nama';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return StandardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StandardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStandards::route('/'),
            'create' => CreateStandard::route('/create'),
            'edit' => EditStandard::route('/{record}/edit'),
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
