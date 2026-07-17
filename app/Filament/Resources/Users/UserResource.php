<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?string $modelLabel = 'pengguna';

    protected static ?string $pluralModelLabel = 'pengguna';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    /** Lencana angka di menu: berapa akun yang nunggu disetujui. */
    public static function getNavigationBadge(): ?string
    {
        $jumlah = static::getEloquentQuery()->where('status', User::STATUS_PENDING)->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
        return ['name', 'employee_id', 'email'];
    }

    /**
     * @param  User  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Role' => ucfirst($record->role),
            'Status' => ucfirst($record->status),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
