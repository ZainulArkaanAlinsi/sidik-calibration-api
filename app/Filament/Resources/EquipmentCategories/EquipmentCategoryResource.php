<?php

namespace App\Filament\Resources\EquipmentCategories;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\EquipmentCategories\Pages\CreateEquipmentCategory;
use App\Filament\Resources\EquipmentCategories\Pages\EditEquipmentCategory;
use App\Filament\Resources\EquipmentCategories\Pages\ListEquipmentCategories;
use App\Filament\Resources\EquipmentCategories\Schemas\EquipmentCategoryForm;
use App\Filament\Resources\EquipmentCategories\Tables\EquipmentCategoriesTable;
use App\Models\EquipmentCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EquipmentCategoryResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = EquipmentCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Kategori Alat';

    protected static ?string $modelLabel = 'kategori alat';

    protected static ?string $pluralModelLabel = 'kategori alat';

    protected static ?string $recordTitleAttribute = 'nama';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return EquipmentCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EquipmentCategoriesTable::configure($table);
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['nama', 'kode'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipmentCategories::route('/'),
            'create' => CreateEquipmentCategory::route('/create'),
            'edit' => EditEquipmentCategory::route('/{record}/edit'),
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
